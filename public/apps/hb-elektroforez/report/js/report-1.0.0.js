/**
 * HbRapor — Raporlar (arşiv / arama)
 *
 * - Geçerli rapor revizyonları listelenir; barkod / ad / dosya no aranınca tarih aralığına bakılmaz.
 * - PDF arşivden gelir (onay anında üretilen, SHA-256 doğrulanır); aç ya da indir.
 * - Birden fazla revizyon varsa satırın altında geçmiş açılır; her revizyonun kendi PDF'i vardır.
 * - Düzeltme bu ekrandan yapılmaz: "Uzman onay ekranında aç" düğmesiyle yeni revizyon açılır.
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/hb-elektroforez/report/php/report-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','report/report-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','report/report-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        lang: null,
        rows: [],
        fileCache: {}
    },
    opt: {
        dateFormat: 'DD.MM.YYYY',
        defaultDays: 30,
        reviewUrl: './report_review/reportReview-1.0.0.html?id='
    },

    init: function () {
        moment.locale('tr');
        $.DLNFrame.setEvent();
    },

    setEvent: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        const body = $('body');
        body.off('langLoaded.lang');
        body.on('langLoaded.lang', function () {
            $d.lang = getLangDatas() || {};
            $.DLN.LangManager.setLanguageTemplate($(document));
            $.DLN.plugin.init();   // tarih seçici (div.date-base)
            $t.start();
        });

        $('#rpFilters').on('submit', function () { $t.loadList(); return false; });
        $('#BLOCK').on('change', $t.loadList);
        $('#btnClear').on('click', function () {
            $('#Q').val('');
            $('#BLOCK').val('');
            $t.setDefaultDates();
            $t.loadList();
        });

        $('#rpBody').on('click', '.btnPdfOpen', function (e) { e.preventDefault(); $t.openPdf(Number($(this).data('id')), false); });
        $('#rpBody').on('click', '.btnPdfDownload', function (e) { e.preventDefault(); $t.openPdf(Number($(this).data('id')), true); });
        $('#rpBody').on('click', '.btnRevisions', function (e) { e.preventDefault(); $t.toggleRevisions($(this).closest('tr')); });
        $('#rpBody').on('click', '.btnOpenReview', function () { $t.openReview(Number($(this).data('sa'))); });
    },

    start: function () {
        const $t = $.DLNFrame;
        $t.setDefaultDates();
        $t.loadBlocks();
        $t.loadList();
        $('#Q').trigger('focus');
    },

    setDefaultDates: function () {
        const $t = $.DLNFrame;
        $t.setDate('#DATE_FROM', moment().subtract($t.opt.defaultDays, 'days'));
        $t.setDate('#DATE_TO', moment());
    },

    // Blok filtresi: önce gruplar, sonra tek tek kodlar
    loadBlocks: function () {
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getBlockList',
            type: 'POST',
            dataType: 'json',
            success: function (result) {
                if (!result || result['resCode'] !== 0) { return; }
                const $sel = $('#BLOCK');
                const groups = { A: 'A blokları (< 1 yaş)', B: 'B blokları (1–6 yaş)', C: 'C blokları (≥ 6 yaş)', V: 'Varyant (V) içerenler' };
                let html = '<option value="">Tümü</option><optgroup label="Grup">';
                $.each(groups, function (code, label) { html += '<option value="' + code + '">' + label + '</option>'; });
                html += '</optgroup><optgroup label="Blok">';
                (result['records'] || []).forEach(function (b) {
                    html += '<option value="' + b.code + '">' + $('<span>').text(b.code + ' — ' + b.title).html() + '</option>';
                });
                $sel.html(html + '</optgroup>');
            }
        });
    },

    loadList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const from = $t.getDate('#DATE_FROM') || moment().subtract($t.opt.defaultDays, 'days');
        const to = $t.getDate('#DATE_TO') || moment();

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getReportList',
            type: 'POST',
            dataType: 'json',
            data: { Q: $.trim($('#Q').val()), DATE_FROM: from.format('DD.MM.YYYY'), DATE_TO: to.format('DD.MM.YYYY'), BLOCK: $('#BLOCK').val() || '' },
            beforeSend: function () { $('#btnSearch').prop('disabled', true); },
            success: function (result) {
                if (!result || result['resCode'] !== 0) {
                    $('#rpBody').html('<tr class="rp-empty"><td colspan="13">' + $('<span>').text((result && result['resMsg']) || 'Liste alınamadı.').html() + '</td></tr>');
                    return;
                }
                const r = result['records'] || {};
                $d.rows = r.rows || [];
                $t.render();
                $('#rpInfo').text($d.rows.length + ' rapor' +
                    (r.dateUsed ? ' · onay tarihi ' + r.dateFrom + ' – ' + r.dateTo : ' · arama tüm tarihlerde yapıldı') +
                    (r.limited ? ' · ilk 500 gösteriliyor, aramayı daraltın' : ''));
            },
            complete: function () { $('#btnSearch').prop('disabled', false); }
        });
    },

    render: function () {
        const $d = $.DLNFrame.data;
        const tpl = Handlebars.compile($('#rpRows-template').html());
        const rows = $d.rows.map(function (r) {
            return $.extend({}, r, {
                approvedText: moment(r.approvedAt, 'YYYY-MM-DD HH:mm').format('DD.MM.YYYY HH:mm'),
                a2High: parseFloat(r.hba2) >= 3.5,
                multiRevision: r.revisionCount > 1
            });
        });
        $('#rpBody').html(rows.length ? tpl(rows) : '<tr class="rp-empty"><td colspan="13">Bu ölçütlere uyan rapor yok.</td></tr>');
    },

    toggleRevisions: function ($tr) {
        const sa = Number($tr.data('sa'));
        const $open = $tr.next('.rp-rev-row');
        if ($open.length) { $open.remove(); return; }

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getRevisions',
            type: 'POST',
            dataType: 'json',
            data: { ID: sa },
            success: function (result) {
                if (!result || result['resCode'] !== 0) {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Revizyonlar alınamadı.' });
                    return;
                }
                const tpl = Handlebars.compile($('#rpRevisions-template').html());
                const rows = (result['records'] || []).map(function (r) {
                    return $.extend({}, r, { approvedText: moment(r.approvedAt, 'YYYY-MM-DD HH:mm').format('DD.MM.YYYY HH:mm') });
                });
                $tr.after(tpl({ sa: sa, rows: rows }));
            }
        });
    },

    /* ---------- PDF ---------- */

    // Aç: yeni sekmede (sunucu adresinden; tarayıcı dosya adını oradan alır). İndir: dosya adıyla kaydet (ör. 11713441-21.10.2026.pdf)
    openPdf: function (sampleReportId, download) {
        const $t = $.DLNFrame;
        const win = download ? null : window.open('', '_blank');
        if (win) { win.document.body.innerHTML = '<p style="font-family:sans-serif;padding:24px;color:#667085">Rapor açılıyor…</p>'; }
        const fail = function (msg) {
            if (win) { win.close(); }
            $.fn.ntyi({ type: 'error', layout: 'topRight', text: msg });
        };
        const show = function (f) {
            if (!download && win && f.url) { win.location.href = f.url; return; }
            const bytes = Uint8Array.from(atob(f.base64), function (c) { return c.charCodeAt(0); });
            const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
            if (download) {
                const a = document.createElement('a');
                a.href = url;
                a.download = f.fileName || ('rapor-' + sampleReportId + '.pdf');
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
            } else if (win) {
                win.location.href = url;
            } else {
                location.href = url;
            }
        };

        // Önbellek yalnız indirmede: açma adresi (jeton) 10 dakika geçerli, her açışta yenisi alınır
        if (download && $t.data.fileCache[sampleReportId]) { show($t.data.fileCache[sampleReportId]); return; }
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getReportPdf',
            type: 'POST',
            dataType: 'json',
            data: { SAMPLE_REPORT_ID: sampleReportId },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $t.data.fileCache[sampleReportId] = result['resObject'];
                    show(result['resObject']);
                } else {
                    fail((result && result['resMsg']) || 'Rapor PDF\'i açılamadı.');
                }
            },
            error: function () { fail('Rapor PDF\'i açılamadı.'); }
        });
    },

    openReview: function (sampleAcceptanceId) {
        const url = $.DLNFrame.opt.reviewUrl + sampleAcceptanceId;
        if (window.parent !== window && typeof window.parent.hbOpenPage === 'function') {
            window.parent.hbOpenPage(url);
        } else {
            location.href = '../' + url.slice(2);
        }
    },

    /* ---------- Yardımcılar (iş listesiyle aynı) ---------- */

    setDate: function (inputSel, m) {
        const picker = $(inputSel).closest('.date-base').data('DateTimePicker');
        if (picker) { picker.date(m); } else { $(inputSel).val(m.format($.DLNFrame.opt.dateFormat)); }
    },
    getDate: function (inputSel) {
        const picker = $(inputSel).closest('.date-base').data('DateTimePicker');
        const m = picker ? picker.date() : moment($(inputSel).val(), $.DLNFrame.opt.dateFormat, true);
        return m && m.isValid() ? m : null;
    }
};
