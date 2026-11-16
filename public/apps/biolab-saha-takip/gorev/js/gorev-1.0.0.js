/**
 * Saha Takip — Görevler
 *
 * - Solda görev listesi (jqGrid). Süzgeç: planlanan gün aralığı (varsayılan bugün ±7), personel, durum, arama.
 *   "Yola çıktı · Vardı · Bitti" sütunu adımların saatini gösterir; Vardım firmaya 300 m'den uzaksa ⚠.
 *   Liste 30 sn'de bir kendiliğinden yenilenir (personel telefondan adım ilerletince görünsün).
 * - Sağda görev formu: firma, görev, gün / saat, personel, açıklama.
 *   Firma seçilince personel listesi firmaya EN YAKINDAN uzağa sıralanır (önce mesaide ve boşta olanlar).
 *   Personel yola çıktıysa firma / gün / personel kilitlenir; gerekirse görev iptal edilip yenisi açılır.
 * Sunucu tarafı: php/gorev-1.0.0.php · Ortak yardımcılar: ../common/sht-common.js ($.SHT)
 */
const modulePath = 'app=../../../apps/biolab-saha-takip/gorev/php/gorev-1.0.0@';
const langFiles = ['general.json', 'sht_gorev/gorev-1.0.0.json'];
const REFRESH_MS = 30000;

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(langFiles);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(langFiles);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        selectedId:null
    },
    form:'#editFormGorev',

    init: function () {
        const $t = $.DLNFrame;
        $('#DATE_FROM').val(moment().subtract(7, 'days').format('YYYY-MM-DD'));
        $('#DATE_TO').val(moment().add(7, 'days').format('YYYY-MM-DD'));

        $('body').off('langLoaded.lang').on('langLoaded.lang', function () {
            $t.data.lang = getLangDatas();
            $.DLN.LangManager.setLanguageTemplate($(document));
            $.DLN.plugin.init();
            $t.initSelects();
            $t.initGridList();
            $t.setValidation();
            $t.clearForm();
        });

        $('#btnNew').click(function () { $t.clearForm(); });
        $('#btnSave').click(function () { $t.save('setSave', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAdd').click(function () { $t.save('setAdd', 'INF_ADD_WARNING_MESSAGE'); });
        $('#btnCancelTask').click($t.cancelTask);
        $('#DATE_FROM, #DATE_TO, #FILTER_STATUS, #FILTER_PERSONEL_ID').on('change', $t.reload);
        $('#gridSearchField').keyup($t.reload);
        // Firma değişince personel listesi o firmaya uzaklığa göre yeniden sıralanır
        $('#CUSTOMER_ID').on('change', function () { $t.loadPersonel($('#PERSONEL_ID').val()); });

        setInterval(function () {
            if (document.hidden || !$t.data.lang) { return; }
            $('#jqGrid').jqGrid('setGridParam', { datatype: 'json', postData: $t.postData() }).trigger('reloadGrid', [{ current: true }]);
        }, REFRESH_MS);
    },

    initSelects: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $('#CUSTOMER_ID').slb({
            field: { value: 'customerId', text: 'customerText', disabledFL: 'activeFl' },
            select2: { placeholder: '', minimumResultsForSearch: 0 },
            ajax: { url: proxyPath + modulePath + 'getCUSTOMER_ID' }
        });
        $('#FILTER_PERSONEL_ID').slb({
            field: { value: 'personelId', text: 'personelText', disabledFL: 'activeFl' },
            select2: { placeholder: $d.lang['FILTER_ALL_PERSONEL'], allowClear: true, minimumResultsForSearch: 0 },
            ajax: { url: proxyPath + modulePath + 'getPERSONEL_ID' }
        });
        $t.loadPersonel(null);
    },

    /** Personel seçimini (seçili firmaya uzaklıkla) yükler; keep: yüklendikten sonra seçili kalacak personel */
    loadPersonel: function (keep) {
        $('#PERSONEL_ID').slb({
            field: { value: 'personelId', text: 'personelText', disabledFL: 'activeFl' },
            select2: { placeholder: '', allowClear: true, minimumResultsForSearch: 0 },
            ajax: { url: proxyPath + modulePath + 'getPERSONEL_ID', postData: { CUSTOMER_ID: $('#CUSTOMER_ID').val() || '' } },
            event: { afterLoad: function (el) { if (keep) { $(el).val(String(keep)).trigger('change.select2'); } } }
        });
    },

    postData: function () {
        return {
            DATE_FROM: $('#DATE_FROM').val(),
            DATE_TO: $('#DATE_TO').val(),
            PERSONEL_ID: $('#FILTER_PERSONEL_ID').val() || '',
            STATUS: $('#FILTER_STATUS').val() || 'ALL',
            Q: $('#gridSearchField').val()
        };
    },

    reload: function () {
        $('#jqGrid').jqGrid('setGridParam', { page: 1, datatype: 'json', postData: $.DLNFrame.postData() }).trigger('reloadGrid');
    },

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
        // "13.11.2026 10:00–12:00" (sunucu hazırlar; eski görevlerde yalnız gün / başlangıç)
        function planned(v, o, r) { return esc(r.plannedRange || ''); }
        // İptal edilmişse altında ne zaman · kim ("13.11.2026 14:49 · Sabiha Nur")
        function status(v, o, r) {
            return '<span class="st-badge st-' + v + '">' + $d.lang['ST_' + v] + '</span>'
                + (r.cancelInfo ? '<span class="st-cancel-info" title="' + esc(r.cancelInfo) + '">' + esc(r.cancelInfo) + '</span>' : '');
        }
        function steps(v, o, r) {
            const warn = r.arriveWarn === 'Y'
                ? ' <i class="fas fa-exclamation-triangle st-warn" title="' + esc($d.lang['ARRIVE_FAR'].replace('%s', r.arriveDistanceM + ' m')) + '"></i>' : '';
            return '<span class="st-steps">' + (r.onTheWayAt || '–') + ' · ' + (r.arrivedAt || '–') + warn + ' · ' + (r.doneAt || '–') + '</span>';
        }
        // Ziyaret süresi; bitiş saatinden önce gerekçeyle bitirildiyse "Erken" etiketi (üstüne gelince gerekçe)
        function visit(v, o, r) {
            const early = r.doneEarly === 'Y'
                ? '<span class="st-early" title="' + esc($d.lang['EARLY_REASON'].replace('%s', r.earlyReason || '')) + '">' + esc($d.lang['EARLY']) + '</span>' : '';
            if (v == null || v === '') { return early; }
            const m = parseInt(v, 10);
            return (m >= 60 ? Math.floor(m / 60) + ' sa ' : '') + (m % 60) + ' dk' + early;
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            sortname: 'mt.plannedAt',
            sortorder: 'asc',
            postData: $t.postData(),
            colModel: [
                { label: 'ID',                    name: 'taskId',       index: 'mt.taskId',       key: true, hidden: true },
                { label: $d.lang['PLANNED_AT'],   name: 'plannedAt',    index: 'mt.plannedAt',    width: 125, formatter: planned },
                { label: $d.lang['CUSTOMER_ID'],  name: 'customerName', index: 'c.customerName',  width: 170 },
                { label: $d.lang['TITLE'],        name: 'title',        index: 'mt.title',        width: 150 },
                { label: $d.lang['PERSONEL'],     name: 'personel',     index: 'u.fullName',      width: 140 },
                { label: $d.lang['STATUS'],       name: 'status',       index: 'mt.status',       width: 105, formatter: status },
                { label: $d.lang['STEPS'],        name: 'steps',        index: 'steps',           width: 130, sortable: false, formatter: steps },
                { label: $d.lang['VISIT'],        name: 'visitMinutes', index: 'visitMinutes',    width: 95, align: 'right', sortable: false, formatter: visit },
                { name: 'plannedRange', hidden: true }, { name: 'doneEarly', hidden: true }, { name: 'earlyReason', hidden: true },
                { name: 'onTheWayAt', hidden: true }, { name: 'arrivedAt', hidden: true }, { name: 'doneAt', hidden: true },
                { name: 'arriveWarn', hidden: true }, { name: 'arriveDistanceM', hidden: true }, { name: 'cancelInfo', hidden: true }
            ],
            pager: '#jqGridPager',
            fluidHeight: false,
            height: '470',
            rowNum: 50,
            rowList: [25, 50, 100, 200],
            onSelectRow: function (rowid) {
                $d.selectedId = $(this).getRowData(rowid)['taskId'];
                $t.load();
            }
        });
    },

    setValidation: function () {
        const $d = $.DLNFrame.data;
        // Bitiş başlangıçtan sonra olmalı (aynı gün)
        $.validator.addMethod('afterStart', function (v) { const s = $('#PLANNED_TIME').val(); return !v || !s || v > s; });
        $($.DLNFrame.form).validate({
            rules: {
                CUSTOMER_ID: { required: true }, TITLE: { required: true, maxlength: 200 }, PLANNED_DATE: { required: true },
                PLANNED_TIME: { required: true }, PLANNED_END_TIME: { required: true, afterStart: true }
            },
            messages: {
                CUSTOMER_ID: $d.lang['ERR_CUSTOMER_ID'], TITLE: $d.lang['ERR_TITLE'], PLANNED_DATE: $d.lang['ERR_PLANNED_DATE'],
                PLANNED_TIME: $d.lang['ERR_PLANNED_TIME'],
                PLANNED_END_TIME: { required: $d.lang['ERR_PLANNED_END_TIME'], afterStart: $d.lang['ERR_END_BEFORE_START'] }
            }
        });
    },

    load: function () {
        const $t = $.DLNFrame;
        $($t.form).clearValidation();
        $.SHT.post(modulePath, 'getSelectedId', { SELECTED_ID: $t.data.selectedId }, function (result) {
            const r = (result['records'] || [])[0];
            if (!r) { return; }
            $('#TASK_ID').val(r.taskId);
            $('#TITLE').val(r.title);
            $('#DESCRIPTION').val(r.description);
            $('#PLANNED_DATE').val(r.plannedDate);
            $('#PLANNED_TIME').val(r.plannedTime);
            $('#PLANNED_END_TIME').val(r.plannedEndTime);
            $('#CUSTOMER_ID').val(String(r.customerId)).trigger('change.select2');
            $t.loadPersonel(r.personelId);   // firmaya göre sırala, kayıtlı personeli seçili bırak
            const editable = r.editable === 'Y';
            $('#CUSTOMER_ID, #PLANNED_DATE, #PLANNED_TIME, #PLANNED_END_TIME, #PERSONEL_ID').prop('disabled', !editable);
            $('#notEditable').prop('hidden', editable || r.status === 'DONE' || r.status === 'CANCELLED');
            const finished = r.status === 'DONE' || r.status === 'CANCELLED';
            $('#btnSave').prop('disabled', !editable);
            $('#btnCancelTask').prop('disabled', finished);
            $('#btnNew').prop('disabled', false);
            // İptal edilmiş: kim / ne zaman (ayrıntısı aşağıdaki geçmişte)
            const esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
            $('#cancelledInfo').prop('hidden', r.status !== 'CANCELLED')
                .html(r.status === 'CANCELLED'
                    ? $t.data.lang['CANCELLED_INFO'].replace('%s', esc(r.cancelledAt || '–')).replace('%s', esc(r.cancelledByName || '–'))
                    : '');
        });
        $t.loadHistory();
    },

    /** Görev geçmişi: eskiden yeniye; her satırda ne oldu, ne zaman, kim, nereden */
    loadHistory: function () {
        const $t = $.DLNFrame;
        const L_ = $t.data.lang;
        const id = $t.data.selectedId;
        if (!id) { $('#historyCard').prop('hidden', true); return; }
        $.SHT.post(modulePath, 'getHistory', { TASK_ID: id }, function (result) {
            if (String($t.data.selectedId) !== String(id)) { return; }   // bu arada başka görev seçildi
            const rows = result['records'] || [];
            const $ul = $('#historyList').empty();
            if (!rows.length) { $ul.append($('<li class="th-empty">').text(L_['HISTORY_EMPTY'])); }
            rows.forEach(function (h) {
                // Ne: "İptal edildi", "Başka personele verildi: Ayşe → Mehmet"
                let what = L_['H_' + h.action] || h.action;
                if (h.action === 'REASSIGNED') { what += ': ' + (h.personelFrom || '–') + ' → ' + (h.personelTo || '–'); }
                else if (h.action === 'ASSIGNED' || (h.action === 'CREATED' && h.personelTo)) { what += ' → ' + h.personelTo; }
                else if (h.action === 'UNASSIGNED') { what += ' (' + (h.personelFrom || '–') + ')'; }
                // Ne zaman · kim · nereden. Mobilde olay telefondaki saat; portala geç ulaştıysa o da yazılır.
                const at = moment(h.eventAt, 'YYYY-MM-DD HH:mm:ss');
                let when = at.format('DD.MM.YYYY HH:mm');
                if (h.source === 'MOBILE' && h.loggedAt && Math.abs(moment(h.loggedAt, 'YYYY-MM-DD HH:mm:ss').diff(at, 'minutes')) >= 2) {
                    when += ' (' + L_['H_PHONE_TIME'].replace('%s', moment(h.loggedAt, 'YYYY-MM-DD HH:mm:ss').format('HH:mm')) + ')';
                }
                const meta = [when, h.byName || L_['H_SYSTEM'], L_['SRC_' + h.source] || h.source].join(' · ');
                const $li = $('<li>').addClass('th-' + h.action)
                    .append($('<div class="th-act">').text(what))
                    .append($('<div class="th-meta">').text(meta));
                if (h.note) { $li.append($('<div class="th-note">').text(h.note)); }
                $li.appendTo($ul);
            });
            $('#historyCard').prop('hidden', false);
        });
    },

    save: function (action, warningKey) {
        const $t = $.DLNFrame;
        if (!$($t.form).valid()) { return; }
        $.SHT.confirm('information', $t.data.lang[warningKey], function () {
            // Kilitli alanlar da gitsin (disabled alanlar serialize'a girmez)
            const $locked = $($t.form).find(':disabled').prop('disabled', false);
            const data = $($t.form).serialize();
            $locked.prop('disabled', true);
            $.SHT.post(modulePath, action, data, function (result) {
                $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                $t.reload();
                $t.data.selectedId = result['resObject']['TASK_ID'];
                $t.load();
            });
        });
    },

    cancelTask: function () {
        const $t = $.DLNFrame;
        $.SHT.confirm('warning', $t.data.lang['INF_CANCEL_WARNING'], function () {
            $.SHT.post(modulePath, 'setCancel', { TASK_ID: $('#TASK_ID').val() }, function (result) {
                $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                $t.reload();
                $t.load();
            });
        });
    },

    /** Yeni görev: bugünün tarihi, alanlar açık, iptal kapalı */
    clearForm: function () {
        const $t = $.DLNFrame;
        $($t.form).trigger('reset');
        $('#TASK_ID').val('');
        $($t.form).find(':disabled').prop('disabled', false);
        $('#CUSTOMER_ID').val(null).trigger('change.select2');
        $('#PERSONEL_ID').val(null).trigger('change.select2');
        $('#PLANNED_DATE').val(moment().format('YYYY-MM-DD'));
        $($t.form).clearValidation();
        $('#notEditable').prop('hidden', true);
        $('#cancelledInfo').prop('hidden', true).empty();
        $('#historyCard').prop('hidden', true);
        $('#jqGrid').jqGrid('resetSelection');
        $t.data.selectedId = null;
        $('#btnSave').prop('disabled', false);
        $('#btnCancelTask').prop('disabled', true);
        $('#btnNew').prop('disabled', true);
        $t.loadPersonel(null);
    }
};
