/**
 * HbRapor — İş listesi
 *
 * - Liste her zaman veritabanından gelir (getWorkList); ekran cihazı beklemez.
 * - Cihazdan çekme (setFetchDeviceList) ayrı kuyrukta çalışır:
 *     · ekran açıkken opt.autoFetchMs'de bir otomatik (FORCE=0: başka ekran az önce çektiyse sunucu atlar)
 *     · "Cihazdan şimdi çek" düğmesiyle hemen (FORCE=1)
 * - Sekmeler aynı veriyi filtreler: onay bekleyen / kabulü olmayan sonuç / sonucu gelmeyen / onaylanan.
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/hb-elektroforez/work_list/php/workList-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','work_list/workList-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','work_list/workList-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        lang: null,
        list: { samples: [], unmatched: [] },
        tab: 'unmatched',   // ilk sekme: iş akışının ilk adımı (eşleştirilecek sonuçlar)
        autoTab: true,      // ilk yüklemede boş sekmeden dolu sekmeye geçilsin mi
        fetching: false,
        reportRunning: false,   // cihazdan raporlar tek tek alınırken
        autoTimer: null,
        reloadTimer: null,
        selected: {}        // Onaylananlar: seçilen numuneler {sampleAcceptanceId: true}; liste yenilense de korunur
    },
    opt: {
        autoFetchMs: 5 * 60 * 1000,
        reportBatch: 30,    // bir turda en fazla bu kadar rapor istenir (cihazı yormamak için); kalanı sonraki turda
        dateFormat: 'DD.MM.YYYY',
        // Tabloda kırmızı gösterim eşikleri. Yalnızca görsel uyarıdır; klinik eşikleri uzmanlar belirleyecek.
        a2High: 3.5,
        fHigh: 2.0
    },

    init: function () {
        const $t = $.DLNFrame;
        moment.locale('tr');
        // Dil dosyaları document.ready'de yüklenir; yüklenince 'langLoaded' tetiklenir ve ekran start() ile başlar
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
            $.DLN.plugin.init();   // tarih seçici (div.date-base) vb. D.Sys eklentileri
            $t.start();
        });

        $('#btnFetch').on('click', function () { $t.fetchDevice(true); });

        $('#wlTabs').on('click', '.wl-tab', function () {
            $d.tab = $(this).data('tab');
            $d.autoTab = false;
            $('#wlTabs .wl-tab').removeClass('active');
            $(this).addClass('active');
            $t.render();
        });

        // Tarih seçiciden tarih değişince liste yenilenir (iki tarih de geçerliyse; arka arkaya değişimler birleştirilir)
        $('.wl-tools .date-base').on('dp.change', function () {
            clearTimeout($d.reloadTimer);
            if ($t.getDate('#DATE_FROM') && $t.getDate('#DATE_TO')) {
                $d.reloadTimer = setTimeout($t.loadList, 300);
            }
        });

        // Uzman onay ekranı (onaylıysa salt okunur açılır)
        $('#wlBody').on('click', '.btnReview', function () { $t.openReview(Number($(this).data('id'))); });

        // Onaylananlar: tekli / tümünü seç, seçilenleri indir
        $('#wlBody').on('change', '.wlCheck', function () {
            const id = Number(this.value);
            if (this.checked) { $d.selected[id] = true; } else { delete $d.selected[id]; }
            $(this).closest('tr').toggleClass('wl-row-checked', this.checked);
            $t.updateBulkBar();
        });
        $('#wlHead').on('change', '#wlCheckAll', function () {
            const on = this.checked;
            $('#wlBody .wlCheck').each(function () {
                this.checked = on;
                if (on) { $d.selected[Number(this.value)] = true; } else { delete $d.selected[Number(this.value)]; }
                $(this).closest('tr').toggleClass('wl-row-checked', on);
            });
            $t.updateBulkBar();
        });
        $('#btnClearSel').on('click', function () {
            $d.selected = {};
            $('#wlBody .wlCheck, #wlCheckAll').prop('checked', false);
            $('#wlBody tr').removeClass('wl-row-checked');
            $t.updateBulkBar();
        });
        $('#btnDownloadSel').on('click', $t.downloadSelected);

        // Kabulü olmayan sonuç: elle eşleştir / bu barkodla yeni kabul aç
        $('#wlBody').on('click', '.btnMatch', function () { $t.clickMatch(Number($(this).data('id'))); });
        $('#wlBody').on('click', '.btnNewAcceptance', function () { $t.openNewAcceptance(String($(this).data('sample-id'))); });
        $('#btnMatchConfirm').on('click', $t.clickMatchConfirm);
    },

    /* ---------- Kabulü olmayan sonuç ---------- */

    clickMatch: function (deviceResultId) {
        const $t = $.DLNFrame;
        const run = ($t.data.list.unmatched || []).filter(function (r) { return r.deviceResultId === deviceResultId; })[0];
        if (!run) { return; }
        $t.data.matchResultId = deviceResultId;

        $('.js-match-sample').text(run.sampleId);
        $('.js-match-detail').text(moment(run.injectionDate, 'YYYY-MM-DD').format('DD.MM.YYYY') + ' · enj. ' + run.injectionNo
            + ' · A2 ' + (run.a2 || '—') + ' · F ' + (run.f || '—'));
        $('#MATCH_NOTE').val(run.suggestion ? 'Kabulde barkod yanlış yazılmış (cihaz: ' + run.sampleId + ')' : '');
        $('#matchMsg').prop('hidden', true).text('');
        const $select = $('#MATCH_ACCEPTANCE').html('<option value="">Yükleniyor…</option>');
        $('#modalMatch').modal('show');

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getMatchCandidates',
            type: 'POST',
            dataType: 'json',
            success: function (result) {
                const list = (result && result['resCode'] === 0 && result['records']) || [];
                $select.empty().append('<option value="">Kabul seçin</option>');
                // Olası yazım hatası önerisi listenin başında ve seçili gelir
                list.sort(function (a, b) { return (b.barcode === run.suggestion) - (a.barcode === run.suggestion); });
                list.forEach(function (c) {
                    const text = c.barcode + ' · ' + c.patientName + ' · ' + moment(c.acceptedAt, 'YYYY-MM-DD HH:mm:ss').format('DD.MM HH:mm')
                        + (c.barcode === run.suggestion ? '  (olası yazım hatası)' : '');
                    $select.append($('<option>').val(c.sampleAcceptanceId).text(text).prop('selected', c.barcode === run.suggestion));
                });
                if (!list.length) { $select.html('<option value="">Bağlanabilecek kabul yok</option>'); }
            }
        });
    },

    clickMatchConfirm: function () {
        const $t = $.DLNFrame;
        const saId = $('#MATCH_ACCEPTANCE').val();
        const note = $.trim($('#MATCH_NOTE').val());
        if (!saId) { $('#matchMsg').text('Bağlanacak kabulü seçin.').prop('hidden', false); return; }
        if (!note) { $('#matchMsg').text('Eşleştirme gerekçesi girin.').prop('hidden', false); return; }

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setMatchResult',
            type: 'POST',
            dataType: 'json',
            data: { DEVICE_RESULT_ID: $t.data.matchResultId, SAMPLE_ACCEPTANCE_ID: saId, MATCH_NOTE: note },
            beforeSend: function () { $('#btnMatchConfirm').prop('disabled', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $('#modalMatch').modal('hide');
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    $t.loadList();
                } else {
                    $('#matchMsg').text((result && result['resMsg']) || 'Eşleştirilemedi.').prop('hidden', false);
                }
            },
            complete: function () { $('#btnMatchConfirm').prop('disabled', false); }
        });
    },

    openReview: function (sampleAcceptanceId) {
        const url = './report_review/reportReview-1.0.0.html?id=' + sampleAcceptanceId;
        if (window.parent !== window && typeof window.parent.hbOpenPage === 'function') {
            window.parent.hbOpenPage(url);
        } else {
            location.href = '../' + url.slice(2);
        }
    },

    // Numune kabul ekranını bu barkodla açar; kayıt yapılınca sonuç anında bağlanır (1. adım)
    openNewAcceptance: function (sampleId) {
        const url = './sample_acceptance/sampleAcceptance-1.0.0.html?barcode=' + encodeURIComponent(sampleId);
        if (window.parent !== window && typeof window.parent.hbOpenPage === 'function') {
            window.parent.hbOpenPage(url);          // kabuk: çerçevede aç, ilgili sekmeyi seç
        } else {
            location.href = '../' + url.slice(2);   // çerçeve dışında açıldıysa
        }
    },

    start: function () {
        const $t = $.DLNFrame;
        $t.setDate('#DATE_FROM', moment().subtract(7, 'days'));
        $t.setDate('#DATE_TO', moment());
        clearTimeout($t.data.reloadTimer);   // varsayılan tarihlerin dp.change'i ikinci bir istek açmasın
        // Dashboard kartından gelindiyse (?tab=pending vb.) o sekme açılır, boş olsa da
        const tab = new URLSearchParams(location.search).get('tab');
        if (tab && $('#wlTabs .wl-tab[data-tab="' + tab + '"]').length) {
            $t.data.tab = tab;
            $t.data.autoTab = false;
            $('#wlTabs .wl-tab').removeClass('active').filter('[data-tab="' + tab + '"]').addClass('active');
        }
        $t.loadStatus();
        $t.loadList();
        $t.fetchDevice(false);   // açılışta: son çekme eskiyse çek
        $t.data.autoTimer = setInterval(function () { $t.fetchDevice(false); }, $t.opt.autoFetchMs);
    },

    /* ---------- Cihaz ---------- */

    fetchDevice: function (force) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if ($d.fetching || $d.reportRunning) { return; }
        $d.fetching = true;
        $('#btnFetch').prop('disabled', true).find('i').addClass('fa-spin');
        if (force) { $t.setStatus('running', 'D-10\'dan sonuçlar alınıyor… (cihaz yavaş yanıt verebilir)'); }

        // Ayrı kuyruk: uzun süren çekme, liste isteklerini ('frame' kuyruğu) bekletmesin
        $.ajaxq('device', {
            url: proxyPath + modulePath + 'setFetchDeviceList',
            type: 'POST',
            dataType: 'json',
            // Düğmeyle çekmede ekrandaki tarih aralığı gönderilir (eski tarihli sonuçlar da gelsin); otomatikte son N gün
            data: force ? {
                FORCE: 1,
                DATE_FROM: ($t.getDate('#DATE_FROM') || moment().subtract(7, 'days')).format('DD.MM.YYYY'),
                DATE_TO:   ($t.getDate('#DATE_TO') || moment()).format('DD.MM.YYYY')
            } : { FORCE: 0 },
            success: function (result) {
                const obj = (result && result['resObject']) || {};
                const ok = result && result['resCode'] === 0;
                const changed = ok && !obj['skipped'] && (obj['newCount'] > 0 || obj['linked'] > 0);

                // Düğmeyle çekildiyse liste her durumda yenilenir; otomatikte sadece değişiklik varsa
                if (force || changed) {
                    $t.loadList();
                }
                if (force) {
                    let text;
                    if (!ok) {
                        text = (result && result['resMsg']) || 'Cihaza ulaşılamadı.';
                    } else if (obj['skipped']) {
                        text = result['resMsg'];
                    } else {
                        text = 'Cihazdan ' + (obj['listCount'] || 0) + ' satır okundu · '
                             + (obj['newCount'] > 0 ? obj['newCount'] + ' yeni sonuç' : 'yeni sonuç yok')
                             + (obj['linked'] > 0 ? ' · ' + obj['linked'] + ' sonuç kabulle eşleşti' : '');
                    }
                    $.fn.ntyi({ type: ok ? 'information' : 'error', layout: 'topRight', text: text });
                }
                $d.pendingReports = (obj['reportPending'] || []).slice(0, $t.opt.reportBatch);
            },
            complete: function () {
                $d.fetching = false;
                const ids = $d.pendingReports || [];
                $d.pendingReports = [];
                if (ids.length) {
                    $t.fetchReports(ids);   // düğme raporlar bitince serbest kalır
                } else {
                    $('#btnFetch').prop('disabled', false).find('i').removeClass('fa-spin');
                    $t.loadStatus();
                }
            }
        });
    },

    // Raporu alınmamış çalışmaların cihaz raporlarını (pikler, enjeksiyon saati, kromatogram, orijinal PDF) sırayla alır.
    // Cihaz her istekte PDF üretir; aynı anda tek istek gider ('device' kuyruğu).
    fetchReports: function (ids) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        let done = 0, failed = 0, unknown = 0;
        $d.reportRunning = true;
        $('#btnFetch').prop('disabled', true).find('i').addClass('fa-spin');

        ids.forEach(function (id) {
            $.ajaxq('device', {
                url: proxyPath + modulePath + 'setFetchReport',
                type: 'POST',
                dataType: 'json',
                data: { DEVICE_RESULT_ID: id },
                beforeSend: function () {
                    $t.setStatus('running', 'Cihaz raporları alınıyor ' + (done + 1) + '/' + ids.length + '…');
                },
                success: function (result) {
                    if (!result || result['resCode'] !== 0) { failed++; return; }
                    unknown += ((result['resObject'] || {})['unknownPeaks'] || 0) > 0 ? 1 : 0;
                },
                error: function () { failed++; },
                complete: function () {
                    done++;
                    if (done < ids.length) { return; }
                    // Hepsi bitti
                    $d.reportRunning = false;
                    $('#btnFetch').prop('disabled', false).find('i').removeClass('fa-spin');
                    $t.loadList();
                    $t.loadStatus();
                    if (failed || unknown) {
                        $.fn.ntyi({
                            type: failed ? 'error' : 'warning', layout: 'topRight',
                            text: (ids.length - failed) + ' rapor alındı'
                                + (unknown ? ' · ' + unknown + ' çalışmada Unknown pik var' : '')
                                + (failed ? ' · ' + failed + ' rapor alınamadı' : '')
                        });
                    }
                }
            });
        });
    },

    loadStatus: function () {
        const $t = $.DLNFrame;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getFetchStatus',
            type: 'POST',
            dataType: 'json',
            success: function (result) {
                if (!result || result['resCode'] !== 0) { return; }
                if ($t.data.reportRunning) { return; }   // rapor ilerlemesinin üzerine yazma
                const s = result['records'] || {};
                if (s['running']) {
                    $t.setStatus('running', 'D-10\'dan sonuçlar alınıyor…');
                } else if (!s['lastAt']) {
                    $t.setStatus('running', 'Cihazdan henüz sonuç çekilmedi.');
                } else if (s['lastSuccess']) {
                    $t.setStatus('ok', 'D-10 bağlantısı çalışıyor · son çekme ' + $t.fmtTime(s['lastAt']) + ' · ' + s['lastOkNew'] + ' yeni sonuç');
                } else {
                    const ok = s['lastOkAt'] ? ' · son başarılı çekme ' + $t.fmtDateTime(s['lastOkAt']) : '';
                    $t.setStatus('error', 'D-10\'a ulaşılamadı (' + $t.fmtTime(s['lastAt']) + ')' + ok + ' — ' + (s['lastMessage'] || ''));
                }
            }
        });
    },

    setStatus: function (type, text) {
        const icon = type === 'ok' ? 'far fa-check-circle' : type === 'error' ? 'fas fa-exclamation-triangle' : 'fas fa-circle-notch fa-spin';
        $('#d10Status')
            .toggleClass('is-error', type === 'error')
            .toggleClass('is-running', type === 'running')
            .find('i').attr('class', icon);
        $('#d10Status .wl-status-text').text(text);
    },

    /* ---------- Liste ---------- */

    loadList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getWorkList',
            type: 'POST',
            dataType: 'json',
            data: {
                DATE_FROM: ($t.getDate('#DATE_FROM') || moment().subtract(7, 'days')).format('DD.MM.YYYY'),
                DATE_TO:   ($t.getDate('#DATE_TO') || moment()).format('DD.MM.YYYY')
            },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $d.list = result['records'] || { samples: [], unmatched: [] };
                    $t.render();
                } else {
                    $('#wlBody').html('<tr class="wl-empty"><td>' + $('<span>').text((result && result['resMsg']) || 'Liste alınamadı.').html() + '</td></tr>');
                }
            }
        });
    },

    // Sekmeye göre satırları ayır, sayıları yaz, tabloyu Handlebars şablonuyla çiz
    render: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const samples = ($d.list.samples || []).map($t.decorateSample);
        const unmatched = ($d.list.unmatched || []).map($t.decorateRun);

        const groups = {
            pending:   samples.filter(function (s) { return (s.status === 'RESULTED' || s.status === 'RERUN') && s.runCount > 0; })
                              .sort(function (a, b) { return b.attention - a.attention; }),   // dikkat isteyenler üstte
            waiting:   samples.filter(function (s) { return s.status === 'WAITING'; }),
            approved:  samples.filter(function (s) { return s.status === 'APPROVED' || s.status === 'REPORTED'; }),
            unmatched: unmatched,
            all:       ($d.list.all || []).map($t.decorateAllRun)   // kabulü olan/olmayan tüm çalışmalar
        };
        $.each(groups, function (key, rows) { $('[data-count="' + key + '"]').text(rows.length); });

        // İlk yüklemede seçili sekme boşsa kayıt olan ilk sekmeye geç (kullanıcı sekme seçtiyse dokunma)
        if ($d.autoTab) {
            $d.autoTab = false;
            if (!groups[$d.tab].length) {
                const first = ['unmatched', 'pending', 'waiting', 'approved'].filter(function (k) { return groups[k].length; })[0];
                if (first) {
                    $d.tab = first;
                    $('#wlTabs .wl-tab').removeClass('active').filter('[data-tab="' + first + '"]').addClass('active');
                }
            }
        }

        const isUnmatched = $d.tab === 'unmatched';
        const selectable = $d.tab === 'approved';   // toplu indirme yalnız onaylananlarda
        const tplName = isUnmatched ? 'wlUnmatched' : $d.tab === 'all' ? 'wlAll' : 'wlSample';
        const headTpl = Handlebars.compile($('#' + tplName + 'Head-template').html());
        const rowsTpl = Handlebars.compile($('#' + tplName + 'Rows-template').html());
        let rows = groups[$d.tab] || [];

        // Seçim: listede artık olmayanlar (tarih aralığı değişti vb.) seçimden düşer
        const approvedIds = {};
        groups.approved.forEach(function (s) { approvedIds[s.sampleAcceptanceId] = true; });
        Object.keys($d.selected).forEach(function (id) { if (!approvedIds[id]) { delete $d.selected[id]; } });
        if (selectable) {
            rows = rows.map(function (s) { return $.extend({}, s, { selectable: true, checked: !!$d.selected[s.sampleAcceptanceId] }); });
        }

        $('#wlHead').html(headTpl({ selectable: selectable }));
        $('#wlBody').html(rows.length ? rowsTpl(rows) : '<tr class="wl-empty"><td colspan="' + (selectable ? 12 : 11) + '">Bu sekmede kayıt yok.</td></tr>');
        $('#wlBulk').prop('hidden', !selectable || !rows.length);
        $t.updateBulkBar();
    },

    /* ---------- Onaylananlar: toplu indirme ---------- */

    updateBulkBar: function () {
        const n = Object.keys($.DLNFrame.data.selected).length;
        const total = $('#wlBody .wlCheck').length;
        $('#wlSelCount').text(n);
        $('#btnDownloadSel').prop('disabled', n === 0);
        $('#wlCheckAll').prop('checked', total > 0 && n === total).prop('indeterminate', n > 0 && n < total);
    },

    // Seçilen raporların PDF'leri sunucuda tek dosyada (1 → PDF, çok → ZIP) hazırlanır, tarayıcı adıyla indirir
    downloadSelected: function () {
        const $t = $.DLNFrame;
        const ids = Object.keys($t.data.selected);
        if (!ids.length) { return; }
        const $btn = $('#btnDownloadSel');
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getReportDownload',
            type: 'POST',
            dataType: 'json',
            data: { IDS: ids.join(',') },
            beforeSend: function () { $btn.prop('disabled', true).find('i').attr('class', 'fas fa-circle-notch fa-spin'); },
            success: function (result) {
                if (!result || result['resCode'] !== 0) {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Raporlar indirilemedi.' });
                    return;
                }
                const o = result['resObject'];
                const a = document.createElement('a');
                a.href = o.url;
                a.download = o.fileName;
                document.body.appendChild(a);
                a.click();
                a.remove();

                let text = o.count + ' rapor indiriliyor: ' + o.fileName;
                if (o.skipped && o.skipped.length) { text += ' · PDF\'i olmadığı için atlananlar: ' + o.skipped.join(', '); }
                if (o.broken && o.broken.length) { text += ' · Arşiv dosyası doğrulanamadı: ' + o.broken.join(', '); }
                $.fn.ntyi({ type: (o.skipped.length || o.broken.length) ? 'warning' : 'information', layout: 'topRight', text: text });
            },
            error: function () { $.fn.ntyi({ type: 'error', layout: 'topRight', text: 'Raporlar indirilemedi.' }); },
            complete: function () { $btn.find('i').attr('class', 'fas fa-download'); $t.updateBulkBar(); }
        });
    },

    // Tüm sonuçlar: çalışma + bağlı olduğu kabulün durumu
    decorateAllRun: function (r) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const matched = !!r.sampleAcceptanceId;
        return $.extend($t.decorateRun(r), {
            matched: matched,
            manual: r.matchType === 'MANUAL',
            statusText: matched ? (($d.lang && $d.lang['STATUS_' + r.status]) || r.status) : 'Kabulü yok',
            statusCls: !matched ? 'wl-badge-warn' : r.status === 'WAITING' ? 'wl-badge-wait' : r.status === 'RESULTED' || r.status === 'RERUN' ? 'wl-badge-info' : 'wl-badge-ok',
            reviewText: r.status === 'APPROVED' || r.status === 'REPORTED' ? 'Görüntüle' : 'İncele'
        });
    },

    decorateSample: function (s) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const run = s.lastRun || {};
        const flags = [];

        if (s.runCount > 1) {
            flags.push({ cls: 'wl-badge-danger', text: s.runCount + ' çalışma' });
        }
        // Barkodu farklı olup elle bağlanan sonuç: uzman bilsin
        (s.runs || []).filter(function (r) { return r.matchType === 'MANUAL'; }).forEach(function (r) {
            flags.push({ cls: 'wl-badge-info', text: 'Elle eşleştirildi (cihaz: ' + r.sampleId + ')' });
        });
        (s.runs || []).map(function (r) { return r.comment; })
            .filter(function (c, i, all) { return c && all.indexOf(c) === i; })   // tekrarsız cihaz yorumları
            .forEach(function (c) { flags.push({ cls: 'wl-badge-warn', text: c }); });
        $t.reportFlags(s.runs || []).forEach(function (f) { flags.push(f); });

        const a2High = $t.toNumber(run.a2) !== null && $t.toNumber(run.a2) >= $t.opt.a2High;
        const fHigh  = $t.toNumber(run.f)  !== null && $t.toNumber(run.f)  >= $t.opt.fHigh;

        return $.extend({}, s, {
            ageText: s.ageYears > 0 ? s.ageYears + ' y' : (s.ageMonths + ' ay'),
            acceptedText: moment(s.acceptedAt, 'YYYY-MM-DD HH:mm:ss').format('DD.MM HH:mm'),
            a2Text: run.a2 || '—',
            fText: run.f || '—',
            a1cText: run.a1c || '—',
            injectedText: run.injectedAt ? moment(run.injectedAt, 'YYYY-MM-DD HH:mm').format('DD.MM HH:mm') : '',
            a2High: a2High,
            fHigh: fHigh,
            flags: flags,
            attention: flags.length > 0 || a2High || fHigh ? 1 : 0,
            statusText: ($d.lang && $d.lang['STATUS_' + s.status]) || s.status,
            statusCls: s.status === 'WAITING' ? 'wl-badge-wait' : s.status === 'RESULTED' ? 'wl-badge-info' : 'wl-badge-ok',
            // Onay bekleyen: "İncele"; onaylı/raporlu: "Görüntüle" (düzeltme oradan yapılır)
            canReview: ((s.status === 'RESULTED' || s.status === 'RERUN') && s.runCount > 0) || s.status === 'APPROVED' || s.status === 'REPORTED',
            reviewText: s.status === 'APPROVED' || s.status === 'REPORTED' ? 'Görüntüle' : 'İncele',
            blockText: s.block ? (s.block.kind === 'manual' ? (s.block.text ? s.block.text + ' · ' : '') + 'uzman seçmeli' : s.block.text) : '',
            blockCls: !s.block ? '' : s.block.kind === 'approved' ? 'wl-badge-ok' : s.block.kind === 'manual' ? 'wl-badge-warn' : 'wl-badge-info'
        });
    },

    decorateRun: function (r) {
        const $t = $.DLNFrame;
        return $.extend({}, r, {
            // Rapor alındıysa saat de görünür
            injectionText: (r.injectedAt ? moment(r.injectedAt, 'YYYY-MM-DD HH:mm').format('DD.MM.YYYY HH:mm')
                                         : moment(r.injectionDate, 'YYYY-MM-DD').format('DD.MM.YYYY')) + ' · enj. ' + r.injectionNo,
            a2Text: r.a2 || '—',
            fText: r.f || '—',
            a1cText: r.a1c || '—',
            flags: $t.reportFlags([r])
        });
    },

    // Cihaz raporundan gelen uyarılar: Unknown pik, rapor bekleniyor, rapor hatası
    reportFlags: function (runs) {
        const flags = [];
        runs.forEach(function (r) {
            const prefix = runs.length > 1 ? 'enj. ' + r.injectionNo + ': ' : '';
            (r.unknownPeaks || []).forEach(function (p) {
                flags.push({ cls: 'wl-badge-danger', text: prefix + p.name + ' %' + (p.areaPct || '?') + (p.rt ? ' (RT ' + p.rt + ')' : ''),
                             title: 'Cihaz raporunda anormal pik: ' + p.name });
            });
            if (r.reportError) {
                flags.push({ cls: 'wl-badge-warn', text: prefix + (r.reportGaveUp ? 'Rapor alınamadı' : 'Rapor hatası'), title: r.reportError });
            } else if (!r.reportFetched) {
                flags.push({ cls: 'wl-badge-wait', text: prefix + 'Rapor bekleniyor', title: 'Cihaz raporu (pikler, kromatogram) henüz alınmadı' });
            }
        });
        return flags;
    },

    /* ---------- Yardımcılar ---------- */

    // Tarih seçici kuruluysa onun üzerinden, değilse doğrudan alana yaz
    setDate: function (inputSel, m) {
        const picker = $(inputSel).closest('.date-base').data('DateTimePicker');
        if (picker) { picker.date(m); } else { $(inputSel).val(m.format($.DLNFrame.opt.dateFormat)); }
    },
    // Seçicideki tarih (moment). Seçicinin gösterim biçimi dile göre değişir; PHP'ye her zaman gg.aa.yyyy gider.
    getDate: function (inputSel) {
        const picker = $(inputSel).closest('.date-base').data('DateTimePicker');
        const m = picker ? picker.date() : moment($(inputSel).val(), $.DLNFrame.opt.dateFormat, true);
        return m && m.isValid() ? m : null;
    },

    // "< 0.8" gibi değerler eşik karşılaştırmasında sayı sayılmaz
    toNumber: function (v) {
        if (v === null || v === undefined || /[<>]/.test(String(v))) { return null; }
        const n = parseFloat(String(v).replace(',', '.'));
        return isNaN(n) ? null : n;
    },
    fmtTime: function (v) { return moment(v, 'YYYY-MM-DD HH:mm:ss').format('HH:mm'); },
    fmtDateTime: function (v) { return moment(v, 'YYYY-MM-DD HH:mm:ss').format('DD.MM HH:mm'); }
};
