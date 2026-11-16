/**
 * Saha Takip — Firmalar
 *
 * - Solda firma listesi (jqGrid; arama: ad, adres, il/ilçe, İSG-KATİP no). "Açık görev": bitmemiş görev sayısı.
 * - Satır seçilince form dolar; firmanın konumu sağdaki küçük haritada iğneyle gösterilir.
 * - Konum: haritaya tıkla ya da iğneyi sürükle; ya da "enlem, boylam" yapıştır (Google Haritalar'dan kopyalanan).
 * - Kaydet / Ekle / Sil. Bitmemiş görevi olan firma silinemez.
 * Sunucu tarafı: php/firma-1.0.0.php · Ortak yardımcılar: ../common/sht-common.js ($.SHT)
 */
const modulePath = 'app=../../../apps/biolab-saha-takip/firma/php/firma-1.0.0@';
const langFiles = ['general.json', 'sht_firma/firma-1.0.0.json'];
const DEFAULT_CENTER = [38.42, 27.14];   // İzmir (konum yokken)

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
        selectedId:null,
        map:null,
        pin:null
    },
    form:'#editFormFirma',

    init: function () {
        const $t = $.DLNFrame;
        $('body').off('langLoaded.lang').on('langLoaded.lang', function () {
            $t.data.lang = getLangDatas();
            $.DLN.LangManager.setLanguageTemplate($(document));
            $.DLN.plugin.init();
            $t.initMap();
            $t.initGridList();
            $t.setValidation();
            $t.setButtons(false);
        });

        $('#btnNew, #btnCancel').click(function () { $t.clearForm(); $t.setButtons(false); });
        $('#btnSave').click(function () { $t.save('setSave', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAdd').click(function () { $t.save('setAdd', 'INF_ADD_WARNING_MESSAGE'); });
        $('#btnDelete').click($t.remove);
        $('#btnGridSearch').click($t.reload);
        $('#gridSearchField').keyup($t.reload);
        $('#btnClearLocation').click(function () { $t.setLocation(null); });

        // Adres / yer arama: yazdıkça (400 ms bekleyip) öneri; Enter → ilk öneri; Esc → kapat
        let timer = null;
        $('#placeSearch').on('input', function () {
            clearTimeout(timer);
            const q = $(this).val().trim();
            if (q.length < 3) { $('#placeResults').prop('hidden', true); return; }
            timer = setTimeout(function () { $t.searchPlace(q); }, 400);
        }).on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); $('#placeResults .st-res').first().trigger('click'); }
            if (e.key === 'Escape') { $('#placeResults').prop('hidden', true); }
        });
        // Formdaki adres + il/ilçe ile ara
        $('#btnFromAddress').click(function () {
            const q = [$('#ADDRESS').val(), $('#CITY').val()].join(' ').trim();
            if (q) { $('#placeSearch').val(q); $t.searchPlace(q); }
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.st-search').length) { $('#placeResults').prop('hidden', true); }
        });
        // Yapıştırılan / yazılan koordinat
        $('#COORD').on('change', function () {
            const c = $.SHT.coord($(this).val());
            if (c) { $t.setLocation(c, true, true); } else if ($(this).val().trim() === '') { $t.setLocation(null); }
            else { $.fn.ntyi({ type: 'error', layout: 'topRight', text: $t.data.lang['ERR_LOCATION'] }); }
        });
    },

    // ---------- Konum haritası ----------

    initMap: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $d.map = L.map('pickMap').setView(DEFAULT_CENTER, 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo($d.map);
        $d.map.on('click', function (e) { $t.setLocation([e.latlng.lat, e.latlng.lng], false, true); });
    },

    /**
     * Konumu forma ve haritaya yazar. null → temizler. center: haritayı oraya götür.
     * fromUser: kullanıcı yeni yer seçti (haritaya tıkladı / iğneyi sürükledi / koordinat yapıştırdı) →
     *           o noktanın adresi bulunup Adres ve İl/ilçe kutularına yazılır. Kayıtlı firma açılırken false.
     */
    setLocation: function (c, center, fromUser) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!c) {
            $('#LAT, #LON, #COORD').val('');
            if ($d.pin) { $d.map.removeLayer($d.pin); $d.pin = null; }
            return;
        }
        const lat = Math.round(c[0] * 1e6) / 1e6;
        const lon = Math.round(c[1] * 1e6) / 1e6;
        $('#LAT').val(lat);
        $('#LON').val(lon);
        $('#COORD').val(lat + ', ' + lon);
        if ($d.pin) { $d.pin.setLatLng([lat, lon]); }
        else {
            $d.pin = L.marker([lat, lon], { draggable: true }).addTo($d.map);
            $d.pin.on('dragend', function () {
                const p = $d.pin.getLatLng();
                $.DLNFrame.setLocation([p.lat, p.lng], false, true);
            });
        }
        if (center) { $d.map.setView([lat, lon], Math.max($d.map.getZoom(), 16)); }
        if (fromUser) { $t.reverseFill(lat, lon); }
    },

    /**
     * Koordinattan adres (Photon reverse) → Adres ve İl/ilçe kutularını günceller.
     * Arka arkaya tıklanırsa yalnız son seçimin cevabı yazılır (eski cevaplar yok sayılır).
     */
    reverseFill: function (lat, lon) {
        const $t = $.DLNFrame;
        const seq = ($t.data.reverseSeq = ($t.data.reverseSeq || 0) + 1);
        $('#ADDRESS, #CITY').addClass('st-loading');
        $.ajax({
            url: 'https://photon.komoot.io/reverse',
            data: { lat: lat, lon: lon, limit: 1 },
            dataType: 'json',
            timeout: 8000
        }).done(function (res) {
            if (seq !== $t.data.reverseSeq) { return; }
            const f = ((res && res.features) || [])[0];
            if (!f) { return; }
            $t.fillAddress($t.describe(f.properties || {}));
        }).always(function () {
            if (seq === $t.data.reverseSeq) { $('#ADDRESS, #CITY').removeClass('st-loading'); }
        });
    },

    /** Bulunan adresi kutulara yazar; değişen kutu kısa süre vurgulanır (kullanıcı fark etsin) */
    fillAddress: function (d) {
        [['#ADDRESS', d.address], ['#CITY', d.city]].forEach(function (x) {
            if (!x[1] || $(x[0]).val() === x[1]) { return; }
            $(x[0]).val(x[1]).addClass('st-changed');
            setTimeout(function () { $(x[0]).removeClass('st-changed'); }, 1500);
        });
    },

    // ---------- Adres / yer arama ----------
    // Photon (OpenStreetMap verisi, komoot.io): ücretsiz, anahtar gerekmez, yazdıkça arama için yapılmış.
    // Adres, sokak, mahalle, semt iyi bulunur; İŞYERİ ADLARI çoğunlukla bulunmaz (OSM'de eksik).
    // Firma adıyla arama için Google Places API (anahtar + faturalı hesap) gerekir.
    // Sonuçlar Türkiye ile sınırlı ve haritanın o anki merkezine yakın olanlar önce gelir.

    /**
     * Türkçe adres yazımını arama servisinin anladığı biçime çevirir:
     *   "174 Sk. No:3" → "174 sokak 3", "Cd." → "caddesi", "Mah." → "mahallesi", "Bulv." → "bulvarı"
     * ("no:3" kelime sanılıp yanlış sonuca gidiyordu.)
     */
    normalizeQuery: function (q) {
        return String(q)
            .replace(/\bno\s*[:.]?\s*(\d+)/gi, ' $1')
            .replace(/\bsk\b\.?/gi, 'sokak')
            .replace(/\bsok\b\.?/gi, 'sokak')
            .replace(/\bcd\b\.?/gi, 'caddesi')
            .replace(/\bcad\b\.?/gi, 'caddesi')
            .replace(/\bmah\b\.?/gi, 'mahallesi')
            .replace(/\bblv\b\.?|\bbulv\b\.?/gi, 'bulvarı')
            .replace(/[\/,]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    },

    searchPlace: function (q) {
        const $t = $.DLNFrame;
        const c = $t.data.map.getCenter();
        $t.data.searchHasNumber = /\d+\s*$|\bno\s*[:.]?\s*\d+/i.test(q);   // kapı no yazıldı mı
        $.ajax({
            url: 'https://photon.komoot.io/api/',
            data: { q: $t.normalizeQuery(q), limit: 6, lat: c.lat.toFixed(4), lon: c.lng.toFixed(4), bbox: '25.6,35.8,44.9,42.2' },
            dataType: 'json',
            timeout: 8000
        }).done(function (res) {
            $t.renderResults((res && res.features) || []);
        }).fail(function () {
            $('#placeResults').prop('hidden', false).html('<div class="st-empty">' + $t.data.lang['SEARCH_ERROR'] + '</div>');
        });
    },

    /** Photon sonucundan okunur satırlar: başlık + alt satır, ve formu doldurmak için adres / il-ilçe */
    describe: function (p) {
        // Sonuç bir sokak/caddenin kendisiyse adı "name"de gelir (street alanı boş)
        const streetName = p.street || ((p.osm_key === 'highway' || p.type === 'street') ? p.name : null);
        const street = [streetName, p.housenumber].filter(Boolean).join(' No:');
        const area = p.district || p.locality || '';
        const city = [p.city || p.county, p.state].filter(Boolean).filter(function (v, i, a) { return a.indexOf(v) === i; }).join(' / ');
        const title = p.name || street || area || city;
        return {
            title: title,
            sub: [p.name ? street : '', area !== title ? area : '', city].filter(Boolean).join(', '),
            address: [street, area].filter(Boolean).join(', '),
            city: city
        };
    },

    renderResults: function (features) {
        const $t = $.DLNFrame;
        const $box = $('#placeResults').empty().prop('hidden', false);
        if (!features.length) {
            $box.html('<div class="st-empty">' + $t.data.lang['SEARCH_EMPTY'] + '</div>');
            return;
        }
        features.forEach(function (f) {
            const p = f.properties || {};
            const d = $t.describe(p);
            const ll = f.geometry.coordinates;   // [boylam, enlem]
            // Kapı no arandı ama sonuç sokak düzeyinde: iğne sokağın ortasına düşer, kullanıcı sürükleyip düzeltmeli
            const noNumber = $t.data.searchHasNumber && !p.housenumber && (p.type === 'street' || p.osm_key === 'highway');
            $('<div class="st-res">')
                .append($('<span>').text(d.title), $('<small>').text(d.sub + (noNumber ? ' · ' + $t.data.lang['SEARCH_NO_NUMBER'] : '')))
                .on('click', function () {
                    $t.setLocation([ll[1], ll[0]], true);
                    $t.data.map.setZoom(17);
                    // Seçilen yerin adresi kutulara yazılır (yeni yer seçildi → adres de güncellenir)
                    $t.data.reverseSeq = ($t.data.reverseSeq || 0) + 1;   // süren eski adres sorgusu yazmasın
                    $t.fillAddress(d);
                    $box.prop('hidden', true);
                })
                .appendTo($box);
        });
    },

    // ---------- Liste ----------

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            sortname:'mt.customerName',
            sortorder:'asc',
            colModel: [
                { label: 'ID',                     name: 'mt_customerId',   index: 'mt.customerId',   key: true, hidden: true },
                { label: $d.lang['CUSTOMER_NAME'], name: 'mt_customerName', index: 'mt.customerName', width: 200 },
                { label: $d.lang['CITY'],          name: 'mt_city',         index: 'mt.city',         width: 100 },
                { label: $d.lang['ISG_KATIP_NO'],  name: 'mt_isgKatipNo',   index: 'mt.isgKatipNo',   width: 90 },
                { label: $d.lang['HAS_LOCATION'],  name: 'mt_lat',          index: 'mt.lat',          width: 60, align: 'center', sortable: false,
                    formatter: function (v) { return v ? '<i class="fas fa-map-marker-alt text-success"></i>' : '<span class="st-badge st-badge-warn">' + $d.lang['NO_LOCATION'] + '</span>'; } },
                { label: $d.lang['OPEN_TASKS'],    name: 'openTasks',       index: 'openTasks',       width: 70, align: 'right', sortable: false },
                { label: $d.lang['ACTIVE_FL'],     name: 'mt_activeFl',     index: 'mt.activeFl',     width: 60,
                    formatter: function (v) { return '<span class="st-badge ' + (v === 'Y' ? 'st-badge-on' : 'st-badge-off') + '">' + (v === 'Y' ? $d.lang['FL_YES_TEXT'] : $d.lang['FL_NO_TEXT']) + '</span>'; } },
            ],
            pager: '#jqGridPager',
            fluidHeight:false,
            height:'470',
            rowNum: 25,
            rowList: [25, 50, 100],
            onSelectRow: function (rowid) {
                $d.selectedId = $(this).getRowData(rowid)['mt_customerId'];
                $t.load();
            }
        });
    },

    reload: function () {
        $('#jqGrid').jqGrid('setGridParam', { page: 1, datatype: 'json', postData: { Q: $('#gridSearchField').val() } }).trigger('reloadGrid');
    },

    // ---------- Form ----------

    setValidation: function () {
        const $d = $.DLNFrame.data;
        $($.DLNFrame.form).validate({
            rules: { CUSTOMER_NAME: { required: true, maxlength: 200 } },
            messages: { CUSTOMER_NAME: $d.lang['ERR_CUSTOMER_NAME'] }
        });
    },

    load: function () {
        const $t = $.DLNFrame;
        $($t.form).clearValidation();
        $.SHT.post(modulePath, 'getSelectedId', { SELECTED_ID: $t.data.selectedId }, function (result) {
            const r = (result['records'] || [])[0];
            if (!r) { return; }
            const f = $t.form;
            ['CUSTOMER_ID', 'CUSTOMER_NAME', 'ADDRESS', 'CITY', 'CONTACT_NAME', 'PHONE', 'ISG_KATIP_NO', 'NOTE'].forEach(function (k) {
                const key = 'mt_' + k.toLowerCase().replace(/_([a-z])/g, function (m, c) { return c.toUpperCase(); });
                $(f + ' #' + k).val(r[key]);
            });
            $(f + ' #ACTIVE_FL').val(r['mt_activeFl']).change();
            $t.setLocation(r['mt_lat'] ? [parseFloat(r['mt_lat']), parseFloat(r['mt_lon'])] : null, true);
            $t.setButtons(true);
        });
    },

    /** Kayıtlı firma seçiliyken Sil açık, Ekle (kopyala) açık; yeni kayıtta Sil kapalı */
    setButtons: function (selected) {
        $('#btnNew').prop('disabled', !selected);
        $('#btnDelete').prop('disabled', !selected);
    },

    save: function (action, warningKey) {
        const $t = $.DLNFrame;
        if (!$($t.form).valid()) { return; }
        $.SHT.confirm('information', $t.data.lang[warningKey], function () {
            $.SHT.post(modulePath, action, $($t.form).serialize(), function (result) {
                $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                $t.reload();
                $t.data.selectedId = result['resObject']['CUSTOMER_ID'];
                $t.load();
            });
        });
    },

    remove: function () {
        const $t = $.DLNFrame;
        $.SHT.confirm('error', $t.data.lang['INF_DELETE_WARNING_MESSAGE'], function () {
            $.SHT.post(modulePath, 'setDelete', { CUSTOMER_ID: $('#CUSTOMER_ID').val() }, function (result) {
                $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                $t.clearForm();
                $t.setButtons(false);
                $t.reload();
            });
        });
    },

    clearForm: function () {
        const $t = $.DLNFrame;
        $($t.form).trigger('reset');
        $($t.form + ' input:hidden').val(null);
        $($t.form).clearValidation();
        $('#ACTIVE_FL').val('Y').change();
        $('#jqGrid').jqGrid('resetSelection');
        $t.data.selectedId = null;
        $t.setLocation(null);
    }
};
