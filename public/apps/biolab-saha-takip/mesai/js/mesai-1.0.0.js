/**
 * Saha Takip — Mesailer (salt okunur)
 *
 * - Süzgeç: tarih aralığı (varsayılan son 7 gün), personel, durum (açık / kapalı) → getGridList
 * - Liste: personel, başlangıç, bitiş, süre (açık mesaide şu ana kadar), durum.
 *   "Kapatılmadan yeni mesai başladı" gibi otomatik kapanışlar sarı rozetle gösterilir.
 * - "Rota" düğmesi: Canlı harita ekranını o personelin o günkü rotasıyla açar.
 * - Liste 30 sn'de bir kendiliğinden yenilenir (sayfa / sıralama korunur, sekme görünmüyorsa yenilenmez).
 * Sunucu tarafı: php/mesai-1.0.0.php
 */
const modulePath = 'app=../../../apps/biolab-saha-takip/mesai/php/mesai-1.0.0@';
const langFiles = ['general.json', 'sht_mesai/mesai-1.0.0.json'];
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
        lang:null
    },

    init: function () {
        const $t = $.DLNFrame;
        $('#DATE_FROM').val(moment().subtract(6, 'days').format('YYYY-MM-DD'));
        $('#DATE_TO').val(moment().format('YYYY-MM-DD'));

        $('body').off('langLoaded.lang').on('langLoaded.lang', function () {
            $t.data.lang = getLangDatas();
            $.DLN.LangManager.setLanguageTemplate($(document));
            $.DLN.plugin.init();
            $t.initFILTER_PERSONEL_ID();
            $t.initGridList();
        });

        $('#btnGridSearch').click($t.clickQGrid);
        $('#DATE_FROM, #DATE_TO, #FILTER_STATUS, #FILTER_PERSONEL_ID').on('change', $t.clickQGrid);

        // Otomatik yenileme: telefonda başlatılan / bitirilen mesai en geç 30 sn'de listeye düşer.
        // Sayfa ve sıralama korunur; sekme arka plandayken (görünmüyorken) istek atılmaz.
        setInterval($t.autoRefresh, REFRESH_MS);
    },

    autoRefresh: function () {
        if (document.hidden || !$.DLNFrame.data.lang) { return; }
        $('#jqGrid').jqGrid('setGridParam', {
            datatype: 'json',
            postData: $.DLNFrame.postData()
        }).trigger('reloadGrid', [{ current: true }]);   // current: seçili satır ve sayfa kalsın
        $('#lastRefresh').text(moment().format('HH:mm:ss'));
    },

    initFILTER_PERSONEL_ID: function () {
        const $d = $.DLNFrame.data;
        $('#FILTER_PERSONEL_ID').slb({
            field:{
                value:'personelId',
                text:'personelText',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:$d.lang['FILTER_ALL_PERSONEL'],
                allowClear:true,
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getPERSONEL_ID'
            }
        });
    },

    // 135 → "2 sa 15 dk"
    duration: function (minutes) {
        const L_ = $.DLNFrame.data.lang;
        const m = parseInt(minutes, 10) || 0;
        const h = Math.floor(m / 60);
        return (h > 0 ? h + ' ' + L_['HOUR_SHORT'] + ' ' : '') + (m % 60) + ' ' + L_['MIN_SHORT'];
    },

    postData: function () {
        return {
            DATE_FROM: $('#DATE_FROM').val(),
            DATE_TO: $('#DATE_TO').val(),
            PERSONEL_ID: $('#FILTER_PERSONEL_ID').val() || '',
            STATUS: $('#FILTER_STATUS').val() || 'ALL'
        };
    },

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        function dt(v) { return v ? moment(v, 'YYYY-MM-DD HH:mm:ss').format('DD.MM.YYYY HH:mm') : ''; }
        function endFormatter(v, o, r) {
            if (!v) { return ''; }
            // Bitiş başlangıçla aynı günse sadece saat
            return moment(v, 'YYYY-MM-DD HH:mm:ss').isSame(moment(r.startedAt, 'YYYY-MM-DD HH:mm:ss'), 'day')
                ? moment(v, 'YYYY-MM-DD HH:mm:ss').format('HH:mm') : dt(v);
        }
        function stateFormatter(v, o, r) {
            if (r.isOpen === 'Y') { return '<span class="st-badge st-badge-on">' + $d.lang['BADGE_OPEN'] + '</span>'; }
            if (r.endReason === 'REPLACED') { return '<span class="st-badge st-badge-warn" title="' + $d.lang['REASON_REPLACED'] + '">' + $d.lang['REASON_REPLACED'] + '</span>'; }
            if (r.endReason === 'AUTO') { return '<span class="st-badge st-badge-warn">' + $d.lang['REASON_AUTO'] + '</span>'; }
            return '<span class="st-badge st-badge-off">' + $d.lang['BADGE_CLOSED'] + '</span>';
        }
        function routeFormatter(v, o, r) {
            return '<button type="button" class="btn btn-sm btn-outline-primary btnRoute" data-p="' + r.personelId + '" data-d="' + r.day + '">'
                + '<i class="fas fa-route"></i> ' + $d.lang['BTN_ROUTE'] + '</button>';
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            sortname:'mt.startedAt',
            sortorder:'desc',
            postData: $t.postData(),
            colModel: [
                { label: 'ID',                     name: 'shiftId',      index: 'mt.shiftId',     key: true, hidden: true },
                { label: $d.lang['PERSONEL_CODE'], name: 'personelCode', index: 'p.personelCode', width: 70 },
                { label: $d.lang['FULL_NAME'],     name: 'fullName',     index: 'u.fullName',     width: 170 },
                { label: $d.lang['STARTED_AT'],    name: 'startedAt',    index: 'mt.startedAt',   width: 120, formatter: dt },
                { label: $d.lang['ENDED_AT'],      name: 'endedAt',      index: 'mt.endedAt',     width: 110, formatter: endFormatter },
                { label: $d.lang['DURATION'],      name: 'minutes',      index: 'minutes',        width: 90, align: 'right', sortable: false,
                    formatter: function (v) { return $t.duration(v); } },
                { label: $d.lang['STATE'],         name: 'isOpen',       index: 'isOpen',         width: 150, sortable: false, formatter: stateFormatter },
                { label: ' ',                      name: 'route',        index: 'route',          width: 80, sortable: false, formatter: routeFormatter },
                { name: 'minutesRaw', hidden: true },
                { name: 'personelId', hidden: true },
                { name: 'day', hidden: true },
                { name: 'endReason', hidden: true }
            ],
            pager: '#jqGridPager',
            fluidHeight:false,
            height:'450',
            rowNum: 50,
            rowList: [25, 50, 100, 200],
            gridComplete: function () {
                $(this).find('.btnRoute').click(function (e) {
                    e.stopPropagation();
                    $t.openRoute($(this).attr('data-p'), $(this).attr('data-d'));
                });
                // Bu sayfadaki mesai sayısı ve toplam süre (gizli minutesRaw sütunundan; görünen sütun biçimlenmiş metin)
                const rows = $(this).jqGrid('getRowData');
                const sum = rows.reduce(function (s, r) { return s + (parseInt(r.minutesRaw, 10) || 0); }, 0);
                $('#pageTotal').text(rows.length ? $d.lang['TOTAL'] + ': ' + rows.length + ' · ' + $t.duration(sum) : '');
            }
        });
    },

    clickQGrid: function () {
        $('#jqGrid').jqGrid('setGridParam', {
            page: 1,
            datatype: 'json',
            postData: $.DLNFrame.postData()
        }).trigger('reloadGrid');
    },

    // Canlı harita ekranını rota modunda açar (kabuktaki çerçevede; menüde harita işaretlenir)
    openRoute: function (personelId, day) {
        const url = './harita/harita-1.0.0.html?personel=' + encodeURIComponent(personelId) + '&date=' + encodeURIComponent(day);
        if (window.parent && window.parent !== window && typeof window.parent.hbOpenPage === 'function') {
            window.parent.hbOpenPage(url);
        } else {
            window.location.href = '../harita/harita-1.0.0.html?personel=' + encodeURIComponent(personelId) + '&date=' + encodeURIComponent(day);
        }
    }
};
