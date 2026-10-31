/**
 * HbRapor — Uzman onay ekranı
 *
 * İş listesinden "İncele" ile açılır: reportReview-1.0.0.html?id=<SAMPLE_ACCEPTANCE_ID>
 * - Çalışma seçilir (tek çalışma varsa seçili gelir; birden fazlaysa uzman seçer).
 * - Kurallar sunucuda çalışır (getEvaluation): HbA, HbS/HbD, blok önerisi, uyarılar, yorum taslağı.
 *   Uzman HbA / blok değiştirdikçe yeniden istenir.
 * - Onaylı numunede rapor salt okunur gösterilir; "Düzeltme yap" yeni revizyon açar.
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/hb-elektroforez/report_review/php/reportReview-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','report_review/reportReview-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','report_review/reportReview-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        lang: null,
        id: 0,
        review: null,         // getReview yanıtı
        runId: 0,             // seçili çalışma
        mode: 'edit',         // edit = onay/düzeltme formu, view = onaylı rapor
        ev: null,             // son değerlendirme
        picked: {},           // uzmanın elle seçtiği bloklar (AGE_BLOCK / NOTE_BLOCK / V_BLOCK)
        commentDirty: false,  // yorum elle değiştirildi mi
        lastDraft: '',
        evalTimer: null,
        fileCache: {}
    },
    opt: {
        listUrl: './work_list/workList-1.0.0.html'
    },

    init: function () {
        const $t = $.DLNFrame;
        moment.locale('tr');
        $t.data.id = Number(new URLSearchParams(location.search).get('id')) || 0;
        $t.setEvent();
    },

    setEvent: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        const body = $('body');
        body.off('langLoaded.lang');
        body.on('langLoaded.lang', function () {
            $d.lang = getLangDatas() || {};
            $.DLN.LangManager.setLanguageTemplate($(document));
            $t.loadReview();
        });

        $('#btnBack').on('click', $t.backToList);

        $('#rrRuns').on('click', '.rr-run', function () {
            if ($d.mode === 'view' || $(this).hasClass('is-disabled')) { return; }
            $t.selectRun(Number($(this).data('id')));
        });
        $('#btnPdf').on('click', $t.openPdf);

        // HbA girişi ve blok seçimleri: değerlendirme yeniden istenir (arka arkaya yazımlar birleştirilir)
        $('#rrResult').on('input', '#HBA', function () { $t.scheduleEvaluate(); });
        $('#rrResult').on('change', '#HBS_HBD', function () {
            $('#HBS_HBD_OTHER').prop('hidden', $(this).val() !== 'other');
        });
        $('.rr-block-select').on('change', function () {
            $d.picked[this.id] = true;
            $t.evaluate();
        });
        $('#COMMENT').on('input', $t.updateEditedState);
        $('#btnRedraft').on('click', function () {
            $('#COMMENT').val($d.lastDraft);
            $t.updateEditedState();
        });

        $('#btnApprove').on('click', $t.clickApprove);
        $('#btnPreview').on('click', $t.previewPdf);
        $('#rrView').on('click', '.btnReportPdf', function (e) { e.preventDefault(); $t.openReportPdf(Number($(this).data('id')), false); });
        $('#rrView').on('click', '.btnReportPdfDownload', function (e) { e.preventDefault(); $t.openReportPdf(Number($(this).data('id')), true); });
        $('#btnCancelRevision').on('click', function () { $t.setMode('view'); });
        $('#rrView').on('click', '#btnRevise', function () { $t.startRevision(); });

        $('#btnRerun').on('click', function () {
            $('#RERUN_REASON_SELECT').val('');
            $('#RERUN_REASON').val('').prop('hidden', true);
            $('#rerunMsg').prop('hidden', true);
            $('#modalRerun').modal('show');
        });
        $('#RERUN_REASON_SELECT').on('change', function () { $('#RERUN_REASON').prop('hidden', $(this).val() !== 'other'); });
        $('#btnRerunConfirm').on('click', $t.clickRerunConfirm);
    },

    backToList: function () {
        const $t = $.DLNFrame;
        if (window.parent !== window && typeof window.parent.hbOpenPage === 'function') {
            window.parent.hbOpenPage($t.opt.listUrl);
        } else {
            location.href = '../' + $t.opt.listUrl.slice(2);
        }
    },

    /* ---------- Yükleme ---------- */

    loadReview: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$d.id) { $('#rrPatient').html('<span class="text-danger">Numune belirtilmedi.</span>'); return; }

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getReview',
            type: 'POST',
            dataType: 'json',
            data: { ID: $d.id },
            success: function (result) {
                if (!result || result['resCode'] !== 0) {
                    $('#rrPatient').html($('<span class="text-danger">').text((result && result['resMsg']) || 'Numune yüklenemedi.'));
                    return;
                }
                $d.review = result['records'];
                $('#EXPERT_NOTE').val('');   // düzeltmede geçerli revizyondan, yeni onayda boş gelir
                $t.renderPatient();

                const current = $t.currentReport();
                if (current) {
                    $d.runId = current.deviceResultId;
                    $t.setMode('view');
                } else {
                    // Tek çalışma varsa seçili gelir; birden fazlaysa uzman seçer (kural 8)
                    const runs = $d.review.runs || [];
                    $d.runId = runs.length === 1 ? runs[0].deviceResultId : 0;
                    $t.setMode('edit');
                }
            }
        });
    },

    currentReport: function () {
        const reports = ($.DLNFrame.data.review && $.DLNFrame.data.review.reports) || [];
        return reports.filter(function (r) { return r.current; })[0] || null;
    },

    setMode: function (mode) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const s = $d.review.sample;
        const current = $t.currentReport();
        $d.mode = mode;

        $('#rrView').prop('hidden', mode !== 'view');
        $('#rrEdit').prop('hidden', mode !== 'edit');

        if (mode === 'view') {
            $d.runId = current.deviceResultId;
            $t.renderView(current);
        } else {
            const revising = !!current;
            $('#rrRevisionWrap').prop('hidden', !revising);
            $('#btnCancelRevision').prop('hidden', !revising);
            $('#btnRerun').prop('hidden', revising || !s.canRerun);
            $('#btnApproveText').text(revising ? 'Düzeltmeyi kaydet' : 'Onayla');
            $('#btnApprove').prop('disabled', !(revising ? s.canRevise : s.canApprove));
            $('#rrMsg').prop('hidden', true);
        }
        $t.renderRuns();
        $t.selectRun($d.runId, true);
    },

    // Onaylı raporu düzeltmek: form, geçerli revizyonun değerleriyle açılır
    startRevision: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const cur = $t.currentReport();
        const blocks = (cur.selectedBlocks || '').split('+');
        $d.preset = {
            hba: cur.hbaManual ? cur.hba : null,
            hbsHbd: cur.hbsHbd,
            age:  blocks.filter(function (c) { return /^[ABC]\d$/.test(c); })[0] || '',
            note: blocks.filter(function (c) { return /N$/.test(c); })[0] || 'NONE',
            v:    blocks.filter(function (c) { return /^V\d$/.test(c); })[0] || 'NONE',
            comment: cur.comment,
            expertNote: cur.expertNote
        };
        $('#REVISION_REASON').val('');
        $t.setMode('edit');
    },

    /* ---------- Hasta ve çalışmalar ---------- */

    renderPatient: function () {
        const $d = $.DLNFrame.data;
        const s = $d.review.sample;
        const esc = function (v) { return $('<span>').text(v == null || v === '' ? '—' : v).html(); };
        const statusText = ($d.lang && $d.lang['STATUS_' + s.status]) || s.status;
        $('#rrPatient').html(
            '<span class="rr-barcode rr-mono">' + esc(s.barcode) + '</span>' +
            '<span class="rr-name">' + esc(s.patientName) + '</span>' +
            '<span class="rr-field"><span>Cinsiyet · yaş</span>' + esc(s.gender) + ' · ' + esc(s.ageText) +
                ' <span class="rr-badge rr-badge-info">' + esc(s.ageGroup) + ' grubu</span></span>' +
            '<span class="rr-field"><span>Dosya no</span>' + esc(s.fileNo) + '</span>' +
            '<span class="rr-field"><span>Kurum</span>' + esc(s.institution) + '</span>' +
            '<span class="rr-field"><span>Kabul</span>' + esc(moment(s.acceptedAt, 'YYYY-MM-DD HH:mm').format('DD.MM.YYYY HH:mm')) + '</span>' +
            '<span class="rr-badge rr-badge-wait">' + esc(statusText) + '</span>' +
            (s.status === 'RERUN' && s.rerunReason ? '<span class="rr-badge rr-badge-warn">Tekrar çalışma: ' + esc(s.rerunReason) + '</span>' : '')
        );
    },

    renderRuns: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const runs = $d.review.runs || [];
        const current = $t.currentReport();
        const tpl = Handlebars.compile($('#rrRun-template').html());

        $('#rrRuns').html(runs.length ? tpl(runs.map(function (r) {
            return $.extend({}, r, {
                selected: r.deviceResultId === $d.runId,
                reported: current && current.deviceResultId === r.deviceResultId,
                disabled: $d.mode === 'view',
                timeText: r.injectedAt ? moment(r.injectedAt, 'YYYY-MM-DD HH:mm').format('DD.MM.YYYY HH:mm')
                                       : moment(r.injectionDate, 'YYYY-MM-DD').format('DD.MM.YYYY'),
                a2Text: r.a2 || '—', fText: r.f || '—', a1cText: r.a1c || '—'
            });
        })) : '<span class="rr-sub">Bu numunenin cihaz sonucu yok.</span>');

        $('#rrRunHint').text(runs.length > 1 && $d.mode === 'edit' ? runs.length + ' çalışma · raporlanacak olanı seçin' : '');
    },

    selectRun: function (id, keepPreset) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $d.runId = id || 0;
        if (!keepPreset) { $d.preset = null; }
        $('#rrRuns .rr-run').removeClass('is-selected').filter('[data-id="' + $d.runId + '"]').addClass('is-selected');

        const run = $t.selectedRun();
        $t.renderPeaks(run);
        $t.loadChromatogram(run);
        $('#btnPdf').prop('disabled', !(run && run.hasPdf));

        if ($d.mode !== 'edit') { return; }
        // Çalışma değişince uzmanın blok seçimleri ve taslak sıfırlanır (öneri yeni çalışmaya göre gelir)
        $d.picked = {};
        $d.commentDirty = false;
        $('#CONFIRMED').prop('checked', false);
        $('#rrResult').empty();   // önceki çalışmanın HbA girişi / HbS-HbD seçimi taşınmasın
        if (!run) {
            $('#rrResult').html('<tr><td class="rr-sub">Raporlanacak çalışmayı soldan seçin.</td></tr>');
            $('#rrWarnings').empty();
            $('#rrConfirmWrap').prop('hidden', true);
            $('.rr-block-select').empty();
            $('#COMMENT').val('');
            return;
        }
        $t.evaluate();
    },

    selectedRun: function () {
        const $d = $.DLNFrame.data;
        return (($d.review && $d.review.runs) || []).filter(function (r) { return r.deviceResultId === $d.runId; })[0] || null;
    },

    renderPeaks: function (run) {
        const $peaks = $('#rrPeaks');
        if (!run) { $peaks.empty(); return; }
        if (!run.peaks || !run.peaks.length) {
            $peaks.html('<tr><td class="rr-sub">' + (run.reportFetched ? 'Pik tablosu okunamadı.' : 'Cihaz raporu henüz alınmadı.') + '</td></tr>');
            return;
        }
        const esc = function (v) { return $('<span>').text(v == null ? '' : v).html(); };
        let html = '<thead><tr><th>Pik</th><th class="text-right">RT</th><th class="text-right">Alan</th><th class="text-right">%</th></tr></thead><tbody>';
        run.peaks.forEach(function (p) {
            html += '<tr class="' + (p.abnormal ? 'is-abnormal' : '') + '"><td>' + esc(p.name) + '</td>' +
                    '<td class="text-right rr-mono">' + esc(p.rt) + '</td>' +
                    '<td class="text-right rr-mono">' + esc(p.area) + '</td>' +
                    '<td class="text-right rr-mono">' + esc(p.areaPct) + '</td></tr>';
        });
        html += '</tbody>';
        if (run.totalArea) { html += '<tfoot><tr><td class="rr-sub" colspan="2">Toplam alan</td><td class="text-right rr-mono rr-sub">' + run.totalArea + '</td><td></td></tr></tfoot>'; }
        $peaks.html(html);
    },

    /* ---------- Dosyalar ---------- */

    loadChromatogram: function (run) {
        const $t = $.DLNFrame;
        const $box = $('#rrChromo');
        if (!run) { $box.text('Çalışma seçin'); return; }
        if (!run.hasChromatogram) { $box.text(run.reportFetched ? 'Kromatogram yok' : 'Cihaz raporu henüz alınmadı'); return; }
        $box.text('Yükleniyor…');
        $t.getFile(run.deviceResultId, 'CHROMATOGRAM_PNG', function (f) {
            if ($t.data.runId !== run.deviceResultId) { return; }   // bu arada başka çalışma seçildi
            $box.empty().append($('<img>').attr({ src: 'data:' + f.mimeType + ';base64,' + f.base64, alt: 'Kromatogram' }));
        }, function (msg) { $box.text(msg); });
    },

    // Cihazın orijinal PDF'i (arşiv; rapor değildir)
    openPdf: function () {
        const $t = $.DLNFrame;
        const run = $t.selectedRun();
        if (!run) { return; }
        $t.openPdfWindow(function (show, fail) { $t.getFile(run.deviceResultId, 'DEVICE_PDF', show, fail); });
    },

    // Pencere tıklama anında açılır (açılır pencere engellenmesin); PDF (base64) gelince içine yüklenir.
    // download = true: pencere açılmaz, dosya sunucunun verdiği adla kaydedilir (ör. 11714240-21.10.2026.pdf)
    openPdfWindow: function (load, download) {
        const win = download ? null : window.open('', '_blank');
        if (win) { win.document.title = 'Rapor hazırlanıyor…'; win.document.body.innerHTML = '<p style="font-family:sans-serif;padding:24px;color:#667085">Rapor hazırlanıyor…</p>'; }
        load(function (f) {
            // Sunucu adresi verdiyse (api/pdf.php) sekme oradan açılır: tarayıcı dosya adını sunucudan alır
            if (!download && win && f.url) { win.location.href = f.url; return; }
            const bytes = Uint8Array.from(atob(f.base64), function (c) { return c.charCodeAt(0); });
            const file = new File([bytes], f.fileName || 'rapor.pdf', { type: f.mimeType || 'application/pdf' });
            const url = URL.createObjectURL(file);
            if (download) {
                const a = document.createElement('a');
                a.href = url;
                a.download = file.name;
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
            } else if (win) {
                win.location.href = url;
            } else {
                location.href = url;
            }
        }, function (msg) {
            if (win) { win.close(); }
            $.fn.ntyi({ type: 'error', layout: 'topRight', text: msg });
        });
    },

    // Bizim rapor PDF'i, ekrandaki seçimlerle ("TASLAK" filigranlı, kaydedilmez)
    previewPdf: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$d.runId) { $('#rrMsg').text('Önizleme için çalışma seçin.').prop('hidden', false); return; }
        const hbs = $('#HBS_HBD').val() === 'other' ? $.trim($('#HBS_HBD_OTHER').val()) : $('#HBS_HBD').val();
        $t.openPdfWindow(function (show, fail) {
            $.ajaxq('frame', {
                url: proxyPath + modulePath + 'getPreview',
                type: 'POST',
                dataType: 'json',
                data: {
                    ID: $d.id,
                    DEVICE_RESULT_ID: $d.runId,
                    HBA: $('#HBA').length ? $('#HBA').val() : '',
                    HBS_HBD: hbs || '',
                    AGE_BLOCK: $('#AGE_BLOCK').val() || '',
                    NOTE_BLOCK: $('#NOTE_BLOCK').val() || 'NONE',
                    V_BLOCK: $('#V_BLOCK').val() || 'NONE',
                    COMMENT: $('#COMMENT').val(),
                    EXPERT_NOTE: $('#EXPERT_NOTE').val()
                },
                success: function (result) {
                    if (result && result['resCode'] === 0) { show(result['resObject']); }
                    else { fail((result && result['resMsg']) || 'Önizleme oluşturulamadı.'); }
                },
                error: function () { fail('Önizleme oluşturulamadı.'); }
            });
        });
    },

    // Arşivdeki rapor PDF'i (onay anında üretilen): aç ya da indir
    openReportPdf: function (sampleReportId, download) {
        const $t = $.DLNFrame;
        $t.openPdfWindow(function (show, fail) {
            $.ajaxq('frame', {
                url: proxyPath + modulePath + 'getReportPdf',
                type: 'POST',
                dataType: 'json',
                data: { SAMPLE_REPORT_ID: sampleReportId },
                success: function (result) {
                    if (result && result['resCode'] === 0) { show(result['resObject']); }
                    else { fail((result && result['resMsg']) || 'Rapor PDF\'i açılamadı.'); }
                },
                error: function () { fail('Rapor PDF\'i açılamadı.'); }
            });
        }, download);
    },

    getFile: function (id, type, done, fail) {
        const cache = $.DLNFrame.data.fileCache;
        const key = id + '_' + type;
        if (cache[key]) { done(cache[key]); return; }
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getFile',
            type: 'POST',
            dataType: 'json',
            data: { DEVICE_RESULT_ID: id, TYPE: type },
            success: function (result) {
                if (result && result['resCode'] === 0) { cache[key] = result['resObject']; done(cache[key]); }
                else { fail((result && result['resMsg']) || 'Dosya alınamadı.'); }
            },
            error: function () { fail('Dosya alınamadı.'); }
        });
    },

    /* ---------- Değerlendirme ---------- */

    scheduleEvaluate: function () {
        const $t = $.DLNFrame;
        clearTimeout($t.data.evalTimer);
        $t.data.evalTimer = setTimeout($t.evaluate, 400);
    },

    evaluate: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$d.runId || $d.mode !== 'edit') { return; }
        const preset = $d.preset;
        const pickedVal = function (key, presetVal) {
            if ($d.picked[key]) { return $('#' + key).val() || 'NONE'; }
            return presetVal || '';   // boş = sunucu öneriyi kullanır
        };
        const runId = $d.runId;

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getEvaluation',
            type: 'POST',
            dataType: 'json',
            data: {
                ID: $d.id,
                DEVICE_RESULT_ID: runId,
                HBA: $('#HBA').length ? $('#HBA').val() : (preset && preset.hba != null ? preset.hba : ''),
                AGE_BLOCK:  pickedVal('AGE_BLOCK', preset && preset.age),
                NOTE_BLOCK: pickedVal('NOTE_BLOCK', preset && preset.note),
                V_BLOCK:    pickedVal('V_BLOCK', preset && preset.v)
            },
            success: function (result) {
                if (runId !== $d.runId) { return; }
                if (!result || result['resCode'] !== 0) {
                    $('#rrMsg').text((result && result['resMsg']) || 'Değerlendirme alınamadı.').prop('hidden', false);
                    return;
                }
                const o = result['resObject'];
                $d.ev = o.evaluation;
                $t.renderResult(o.evaluation);
                $t.renderWarnings(o.evaluation.warnings);
                $t.renderBlocks(o.evaluation, o.chosen);

                // Düzeltmede ilk açılışta yorum geçerli rapordan gelir; sonra bloklardan
                $d.lastDraft = o.draftComment;
                if (preset && preset.comment != null) {
                    $('#COMMENT').val(preset.comment);
                    $('#EXPERT_NOTE').val(preset.expertNote || '');
                    preset.comment = null;
                } else if (!$d.commentDirty) {
                    $('#COMMENT').val(o.draftComment);
                }
                $t.updateEditedState();
            }
        });
    },

    // Algoritma yorumu blok taslağından farklı mı? (boşluk farkları sayılmaz; sunucu da aynı karşılaştırmayı yapar)
    updateEditedState: function () {
        const $d = $.DLNFrame.data;
        const norm = function (s) { return $.trim(String(s || '').replace(/\s+/g, ' ')); };
        $d.commentDirty = norm($('#COMMENT').val()) !== norm($d.lastDraft);
        $('#btnRedraft').prop('hidden', !$d.commentDirty);
        $('#rrEditedBadge').prop('hidden', !$d.commentDirty);
    },

    renderResult: function (ev) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const fmt = function (v) { return v == null ? '—' : Number(v).toFixed(1); };
        const esc = function (v) { return $('<span>').text(v == null ? '' : v).html(); };
        const hadHba = $('#HBA').length ? $('#HBA').val() : null;
        const prevHbs = $('#HBS_HBD').val();
        const prevOther = $('#HBS_HBD_OTHER').val();

        let hbaCell;
        if (ev.hbaDraft) {
            const preset = $d.preset && $d.preset.hba != null ? $d.preset.hba : '';
            hbaCell = '<input type="text" id="HBA" class="form-control form-control-sm rr-hba-input" inputmode="decimal" placeholder="girin" value="' +
                      esc(hadHba != null ? hadHba : preset) + '">' +
                      ' <span class="rr-badge rr-badge-warn">taslak ' + fmt(ev.hbaCalc) + '</span>' +
                      '<div class="rr-sub">Cihaz A0: <b class="rr-mono">%' + esc(ev.a0 || '—') + '</b> · kromatogramı kontrol edip raporlanacak değeri girin</div>';
        } else {
            hbaCell = '<span class="rr-val">' + fmt(ev.hbaCalc) + '</span> <span class="rr-sub">= 100 − (HbA2 + HbF)</span>';
        }

        const bandCls = !ev.a2Band ? 'rr-badge-wait' : ev.a2Band.code === 'N' ? 'rr-badge-ok' : ev.a2Band.code === 'BL' ? 'rr-badge-warn' : 'rr-badge-danger';
        const opts = (($d.review && $d.review.hbsHbdOptions) || []);
        let hbs = '<select id="HBS_HBD" class="form-control form-control-sm d-inline-block" style="width:auto">' +
                  '<option value="">Seçin</option>';
        opts.forEach(function (o) { hbs += '<option>' + esc(o) + '</option>'; });
        hbs += '<option value="other">Diğer (metin)</option></select>' +
               ' <input type="text" id="HBS_HBD_OTHER" class="form-control form-control-sm mt-1" maxlength="100" placeholder="HbS / HbD sonucu" hidden>';

        $('#rrAgeGroup').text(ev.ageGroup + ' grubu · ' + ev.ageMonths + ' ay' + (ev.ageGroup !== 'C' ? ' · HbF ref. ' + ev.hbfRef.label : ''));
        $('#rrResult').html(
            '<tr><td class="rr-label">HbA2</td><td><span class="rr-val">' + fmt(ev.a2) + '</span> ' +
                (ev.a2Band ? '<span class="rr-badge ' + bandCls + '">' + esc(ev.a2Band.label) + '</span>' : '') + '</td></tr>' +
            '<tr><td class="rr-label">HbF</td><td><span class="rr-val">' + fmt(ev.f) + '</span>' +
                (ev.fBelow ? ' <span class="rr-sub">cihaz: ' + esc(ev.fDevice || 'tespit edilmedi') + '</span>' : '') + '</td></tr>' +
            '<tr><td class="rr-label">HbA</td><td>' + hbaCell + '</td></tr>' +
            '<tr><td class="rr-label">HbS / HbD</td><td>' + hbs +
                (ev.hbsHbd.needsVerify ? '<div class="rr-sub text-danger">Anormal pik var: otomatik "SAPTANMADI" yazılmadı</div>' : '') + '</td></tr>'
        );

        // Seçim korunur; ilk açılışta öneri (ya da düzeltmede geçerli rapor)
        const presetHbs = $d.preset && $d.preset.hbsHbd;
        const want = prevHbs !== undefined ? prevHbs : (presetHbs || ev.hbsHbd.suggested || '');
        if (want && want !== 'other' && opts.indexOf(want) < 0) {
            $('#HBS_HBD').val('other');
            $('#HBS_HBD_OTHER').val(want).prop('hidden', false);
        } else {
            $('#HBS_HBD').val(want);
            $('#HBS_HBD_OTHER').val(prevOther || '').prop('hidden', want !== 'other');
        }
    },

    renderWarnings: function (warnings) {
        const icons = { block: 'fas fa-ban', confirm: 'fas fa-exclamation-triangle', info: 'fas fa-info-circle' };
        const esc = function (v) { return $('<span>').text(v).html(); };
        $('#rrWarnings').html(warnings.length ? warnings.map(function (w) {
            return '<div class="rr-warn rr-warn-' + w.level + '"><i class="' + icons[w.level] + '"></i><div>' + esc(w.text) + '</div></div>';
        }).join('') : '<div class="rr-sub">Uyarı yok.</div>');

        const needConfirm = warnings.some(function (w) { return w.level !== 'info'; });
        $('#rrConfirmWrap').prop('hidden', !needConfirm);
        if (!needConfirm) { $('#CONFIRMED').prop('checked', false); }
        const nBlock = warnings.filter(function (w) { return w.level === 'block'; }).length;
        $('#rrWarnCount').text(nBlock ? nBlock + ' giderilmesi gereken' : '');
    },

    // Blok seçimleri: yalnızca hastanın yaş grubu; önerilen işaretli, kriterin kapsamadığı durumda adaylar önde
    renderBlocks: function (ev, chosen) {
        const $d = $.DLNFrame.data;
        const blocks = ($d.review && $d.review.blocks) || [];
        const esc = function (v) { return $('<span>').text(v == null ? '' : v).html(); };
        const option = function (b, suggested, candidates) {
            const tag = b.code === suggested ? ' · önerilen' : (candidates.indexOf(b.code) >= 0 ? ' · aday' : '');
            return '<option value="' + b.code + '" title="' + esc(b.criteria) + '">' + esc(b.code + ' — ' + b.title + tag) + '</option>';
        };

        const ageBlocks = blocks.filter(function (b) { return b.type === 'AGE' && b.group === ev.ageGroup; });
        let html = '<option value="">Seçin</option>';
        ageBlocks.forEach(function (b) { html += option(b, ev.ageBlock.code, ev.ageBlock.candidates); });
        $('#AGE_BLOCK').html(html).val(chosen.age || '');

        html = '<option value="NONE">Ek not yok</option>';
        blocks.filter(function (b) { return b.type === 'NOTE'; }).forEach(function (b) { html += option(b, ev.noteBlock, []); });
        $('#NOTE_BLOCK').html(html).val(chosen.note || 'NONE');

        html = (ev.hasVariant && !ev.vBlock.code ? '<option value="">Seçin</option>' : '') + '<option value="NONE">V bloğu yok</option>';
        blocks.filter(function (b) { return b.type === 'VARIANT'; }).forEach(function (b) { html += option(b, ev.vBlock.code, ev.vBlock.candidates); });
        $('#V_BLOCK').html(html).val(chosen.v || (ev.hasVariant && !ev.vBlock.code && !$d.picked.V_BLOCK ? '' : 'NONE'));

        $('#rrSuggested').text(ev.suggestedBlocks ? 'Öneri: ' + ev.suggestedBlocks : 'Öneri yok · uzman seçmeli');
        const reasons = [ev.ageBlock.reason];
        if (ev.hasVariant) { reasons.push(ev.vBlock.reason); }
        $('#rrBlockReason').text(reasons.filter(Boolean).join(' · '));
    },

    /* ---------- Onay ---------- */

    clickApprove: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const current = $t.currentReport();
        const hbs = $('#HBS_HBD').val() === 'other' ? $.trim($('#HBS_HBD_OTHER').val()) : $('#HBS_HBD').val();
        const showMsg = function (m) { $('#rrMsg').text(m).prop('hidden', false); };

        // Hızlı ön denetim; asıl denetim sunucuda (kurallar orada yeniden çalışır)
        if (!$d.runId) { showMsg('Raporlanacak çalışmayı seçin.'); return; }
        if ($d.ev && $d.ev.hbaDraft && !$.trim($('#HBA').val())) { showMsg('Raporlanacak HbA değerini girin (A0 kontrolü).'); return; }
        if (!hbs) { showMsg('HbS / HbD sonucunu seçin.'); return; }
        if (!$('#AGE_BLOCK').val()) { showMsg('Yaş bloğunu seçin.'); return; }
        if ($('#V_BLOCK').val() === '') { showMsg('Varyant bloğunu seçin (ya da "V bloğu yok").'); return; }
        if (!$('#rrConfirmWrap').prop('hidden') && !$('#CONFIRMED').prop('checked')) { showMsg('Uyarıları kontrol ettiğinizi işaretleyin.'); return; }
        if (!$.trim($('#COMMENT').val())) { showMsg('Rapor yorumu boş olamaz.'); return; }
        if (current && !$.trim($('#REVISION_REASON').val())) { showMsg('Düzeltme gerekçesi girin.'); return; }

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setApprove',
            type: 'POST',
            dataType: 'json',
            data: {
                ID: $d.id,
                DEVICE_RESULT_ID: $d.runId,
                HBA: $('#HBA').length ? $('#HBA').val() : '',
                HBS_HBD: hbs,
                AGE_BLOCK: $('#AGE_BLOCK').val(),
                NOTE_BLOCK: $('#NOTE_BLOCK').val() || 'NONE',
                V_BLOCK: $('#V_BLOCK').val() || 'NONE',
                COMMENT: $('#COMMENT').val(),
                EXPERT_NOTE: $('#EXPERT_NOTE').val(),
                CONFIRMED: $('#CONFIRMED').prop('checked') ? 1 : 0,
                REVISION_REASON: $('#REVISION_REASON').val()
            },
            beforeSend: function () { $('#btnApprove').prop('disabled', true); $('#rrMsg').prop('hidden', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    $d.preset = null;
                    $t.loadReview();
                } else {
                    showMsg((result && result['resMsg']) || 'Onaylanamadı.');
                    $('#btnApprove').prop('disabled', false);
                }
            },
            error: function () { $('#btnApprove').prop('disabled', false); }
        });
    },

    clickRerunConfirm: function () {
        const $t = $.DLNFrame;
        const sel = $('#RERUN_REASON_SELECT').val();
        const reason = sel === 'other' ? $.trim($('#RERUN_REASON').val()) : sel;
        if (!reason) { $('#rerunMsg').text('Gerekçe seçin.').prop('hidden', false); return; }

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setRerun',
            type: 'POST',
            dataType: 'json',
            data: { ID: $t.data.id, REASON: reason },
            beforeSend: function () { $('#btnRerunConfirm').prop('disabled', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $('#modalRerun').modal('hide');
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    $t.loadReview();
                } else {
                    $('#rerunMsg').text((result && result['resMsg']) || 'Kaydedilemedi.').prop('hidden', false);
                }
            },
            complete: function () { $('#btnRerunConfirm').prop('disabled', false); }
        });
    },

    /* ---------- Onaylı rapor ---------- */

    renderView: function (rp) {
        const $d = $.DLNFrame.data;
        const s = $d.review.sample;
        const esc = function (v) { return $('<span>').text(v == null || v === '' ? '—' : v).html(); };
        const reports = $d.review.reports || [];

        let html =
            '<div class="rr-card"><div class="rr-card-head"><span>Onaylı rapor · revizyon ' + rp.revisionNo + '</span>' +
            '<span class="rr-sub">' + esc(rp.approvedBy) + ' · ' + esc(moment(rp.approvedAt, 'YYYY-MM-DD HH:mm').format('DD.MM.YYYY HH:mm')) + '</span></div>' +
            '<div class="rr-card-body"><table class="w-100 rr-result">' +
            '<tr><td class="rr-label">HbA2</td><td class="rr-val">' + esc(rp.hba2) + '</td></tr>' +
            '<tr><td class="rr-label">HbF</td><td><span class="rr-val">' + esc(rp.hbf) + '</span>' +
                (rp.hbfDevice && rp.hbfDevice !== rp.hbf ? ' <span class="rr-sub">cihaz: ' + esc(rp.hbfDevice) + '</span>' : '') + '</td></tr>' +
            '<tr><td class="rr-label">HbA</td><td><span class="rr-val">' + esc(rp.hba) + '</span>' +
                (rp.hbaManual ? ' <span class="rr-badge rr-badge-warn">uzman girdi · hesaplanan ' + esc(rp.hbaCalculated) + ' · A0 %' + esc(rp.a0) + '</span>' : '') + '</td></tr>' +
            '<tr><td class="rr-label">HbS / HbD</td><td><b>' + esc(rp.hbsHbd) + '</b></td></tr>' +
            '<tr><td class="rr-label">Bloklar</td><td>' + esc(rp.selectedBlocks) +
                (rp.suggestedBlocks !== rp.selectedBlocks ? ' <span class="rr-sub">(öneri: ' + esc(rp.suggestedBlocks) + ')</span>' : '') + '</td></tr>' +
            '</table></div></div>' +
            '<div class="rr-card"><div class="rr-card-head"><span>Algoritma yorumu</span>' +
                (rp.commentEdited ? '<span class="rr-badge rr-badge-warn">Blok metni düzenlendi</span>' : '') +
            '</div><div class="rr-card-body rr-comment-view">' + esc(rp.comment) + '</div></div>' +
            (rp.expertNote ? '<div class="rr-card"><div class="rr-card-head"><span>Uzman notu</span><span class="rr-sub">' + esc(rp.approvedBy) + '</span></div>' +
                             '<div class="rr-card-body rr-comment-view">' + esc(rp.expertNote) + '</div></div>' : '');

        if (reports.length > 1) {
            html += '<div class="rr-card"><div class="rr-card-head">Revizyonlar</div><div class="rr-card-body"><ul class="mb-0 pl-3 rr-revisions">';
            reports.forEach(function (r) {
                html += '<li>Rev. ' + r.revisionNo + ' · ' + esc(moment(r.approvedAt, 'YYYY-MM-DD HH:mm').format('DD.MM.YYYY HH:mm')) + ' · ' + esc(r.approvedBy) +
                        (r.revisionReason ? ' · gerekçe: ' + esc(r.revisionReason) : '') + (r.current ? ' <b>(geçerli)</b>' : '') +
                        ' · <a href="#" class="btnReportPdf" data-id="' + r.sampleReportId + '">PDF</a> · <a href="#" class="btnReportPdfDownload" data-id="' + r.sampleReportId + '">indir</a></li>';
            });
            html += '</ul></div></div>';
        }
        html += '<div class="rr-actions">' +
                (s.canRevise ? '<button type="button" class="btn btn-outline-secondary" id="btnRevise"><i class="fas fa-pen"></i> Düzeltme yap (yeni revizyon)</button>' : '') +
                '<button type="button" class="btn btn-primary btnReportPdf" data-id="' + rp.sampleReportId + '"><i class="far fa-file-pdf"></i> Rapor PDF\'i</button>' +
                '<button type="button" class="btn btn-outline-primary btnReportPdfDownload" data-id="' + rp.sampleReportId + '" title="Barkod-tarih adıyla kaydet"><i class="fas fa-download"></i> İndir</button></div>';
        $('#rrView').html(html);
    }
};
