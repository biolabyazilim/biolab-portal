/**
 * Maliyet — Malzeme kataloğu ve fiyatlar
 *
 * - Üstte maliyet çalışması seçilir; fiyatlar ve kurlar o çalışmaya aittir.
 * - Liste jqGrid'dir ($.fn.jqg → getGridList): sayfalama ve sıralama sunucuda; sekme (tümü / fiyatı girilmemiş /
 *   fiyatı girilmiş), kategori, arama ve seçili çalışma postData ile gider (reloadGrid).
 * - Satıra tıklayınca kayıt getSelectedId ile alınır ve form açılır; fiyat alanları seçili çalışmanın fiyatını yazar.
 * - Birim maliyet (TL) = ambalaj fiyatı × kur ÷ (hasta sayısı girilmişse o, yoksa ambalaj içeriği); TL kuru = öngörü katsayısı.
 *   Formda yazarken önizlenir, kayıtta sunucu hesaplar.
 * - Kit: "Kit" kategorisindeki malzeme. Kategori kit seçilince etiketler değişir (kitteki test sayısı, kit fiyatı) ve "kitten çıkan hasta sayısı" etiketi gelir.
 * - Kullanıldığı testler: malzemenin bağlı olduğu reçete satırları. Teste bağla / kaldır hemen kaydedilir
 *   (setMaterialTest / setMaterialTestDelete); test maliyeti ekranı aynı satırları görür.
 * - Onaylanmış çalışma salt okunurdur (düğmeler kapanır, sunucu da reddeder).
 * - Seçim kutuları $.fn.slb ile dolar (initX → getX); select id'si alan adıdır.
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-maliyet/material/php/material-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','material/material-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','material/material-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        lang: null,
        study: null,        // seçili çalışma (kurlar dahil); getGridList → resObject.study
        tab: 'all',         // all / missing / priced
        kitCategoryId: null,// "Kit" kategorisinin id'si (getGridList → resObject.kitCategoryId)
        searchTimer: null
    },
    opt: {
        storeKey: 'mlyt.material.studyId',   // son seçilen çalışma (kişisel kolaylık)
        statusText: { DRAFT: 'Taslak', REVIEW: 'İncelemede', APPROVED: 'Onaylandı', ARCHIVED: 'Arşiv' }
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
            $t.start();
        });

        $('#STUDY_ID').on('change', function () {
            const id = Number($(this).val()) || null;
            if (id) { $t.storeSet(id); }
            $t.reloadGrid(true);
        });
        $('#btnNewStudy').on('click', function () { $t.openStudy(null); });
        $('#studyBar').on('click', '.btnStudyEdit', function () { $t.openStudy($d.study); });
        $('#btnStudySave').on('click', $t.saveStudy);

        $('#mtTabs').on('click', '.mt-tab', function () {
            $d.tab = $(this).data('tab');
            $('#mtTabs .mt-tab').removeClass('active');
            $(this).addClass('active');
            $t.reloadGrid(true);
        });
        $('#F_CATEGORY_ID').on('change', function () { $t.reloadGrid(true); });
        // Arama: yazarken her tuşta değil, durunca (300 ms) sunucuya gider
        $('#SEARCH').on('input', function () {
            clearTimeout($d.searchTimer);
            $d.searchTimer = setTimeout(function () { $t.reloadGrid(true); }, 300);
        });

        $('#btnNewMaterial').on('click', function () { $t.openMaterial(null); });
        // Form kapanınca seçim kalksın; aynı satıra tekrar tıklanınca form yine açılsın
        $('#modalMaterial').on('hidden.bs.modal', function () { $('#jqGrid').jqGrid('resetSelection'); });
        $('#btnMaterialSave').on('click', $t.saveMaterial);
        $('#btnMaterialDelete').on('click', $t.deleteMaterial);
        $('#PACKAGE_QTY, #PACKAGE_PRICE, #PATIENT_QTY').on('input', $t.preview);
        $('#CURRENCY, #USAGE_UNIT').on('change', $t.preview);
        $('#CATEGORY_ID').on('change', function () { $t.applyKitMode(true); });
        $('#btnTestLink').on('click', $t.linkTest);
        $('#materialTests').on('click', '.btnTestUnlink', function () { $t.unlinkTest($(this).data('id'), $(this).data('code')); });
        $('#modalMaterial input, #modalMaterial select').on('input change', function () { $('#materialMsg').prop('hidden', true); });
        $('#modalStudy input').on('input', function () { $('#studyMsg').prop('hidden', true); });
    },

    start: function () {
        const $t = $.DLNFrame;

        $t.initGridList();      // boş kurulur; ilk yükleme çalışma seçilince (STUDY_ID change)
        $t.initSTUDY_ID(null);
        $t.initF_CATEGORY_ID();
        $t.initCATEGORY_ID();
        $t.initSUPPLIER_ID();
        $t.initUSAGE_UNIT();
        $t.initCURRENCY();
        $t.initTEST_ID();
    },

    // Çalışma: yüklenince istenen (yoksa son seçilen, o da yoksa ilk) çalışma seçilir; change → liste
    initSTUDY_ID: function (selectId) {
        const $t = $.DLNFrame;
        $('#STUDY_ID').slb({
            field:{
                value:'studyId',
                text:'studyText',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'Henüz çalışma yok',
                allowClear:false,
                minimumResultsForSearch:-1
            },
            ajax:{
                url:proxyPath + modulePath + 'getSTUDY_ID'
            },
            event:{
                afterLoad:function (el) {
                    const $el = $(el);
                    const has = function (id) { return id && $el.find('option[value="' + id + '"]').length > 0; };
                    let id = selectId || $t.storeGet();
                    if (!has(id)) { id = $el.find('option[value!=""]').first().val() || ''; }
                    $el.val(id).change();
                }
            }
        });
    },

    // Liste filtresi: boş = tüm kategoriler
    initF_CATEGORY_ID: function () {
        $('#F_CATEGORY_ID').slb({
            field:{
                value:'categoryId',
                text:'categoryName',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'Tüm kategoriler',
                allowClear:true,
                minimumResultsForSearch:-1
            },
            ajax:{
                url:proxyPath + modulePath + 'getCATEGORY_ID'
            }
        });
    },

    initCATEGORY_ID: function () {
        $('#CATEGORY_ID').slb({
            field:{
                value:'categoryId',
                text:'categoryName',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'Seçin',
                allowClear:false,
                minimumResultsForSearch:-1
            },
            ajax:{
                url:proxyPath + modulePath + 'getCATEGORY_ID'
            }
        });
    },

    // Tedarikçi: listeden seçilir ya da yeni ad yazılır (tags); kayıtta yeni ad sunucuda tedarikçi olur
    initSUPPLIER_ID: function () {
        $('#SUPPLIER_ID').slb({
            field:{
                value:'supplierId',
                text:'supplierName',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'Seçin ya da yazın',
                allowClear:true,
                tags:true
            },
            ajax:{
                url:proxyPath + modulePath + 'getSUPPLIER_ID'
            }
        });
    },

    initUSAGE_UNIT: function () {
        $('#USAGE_UNIT').slb({
            field:{
                value:'usageUnit',
                text:'usageUnitName',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                allowClear:false,
                minimumResultsForSearch:-1
            },
            ajax:{
                url:proxyPath + modulePath + 'getUSAGE_UNIT'
            }
        });
    },

    initCURRENCY: function () {
        $('#CURRENCY').slb({
            field:{
                value:'currency',
                text:'currency',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                allowClear:false,
                minimumResultsForSearch:-1
            },
            ajax:{
                url:proxyPath + modulePath + 'getCURRENCY'
            }
        });
    },

    // Malzemenin (kitin) bağlanacağı test: kod ya da adla aranır
    initTEST_ID: function () {
        $('#TEST_ID').slb({
            field:{
                value:'testId',
                text:'testText',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'Test kodu ya da adı yazın',
                allowClear:true
            },
            ajax:{
                url:proxyPath + modulePath + 'getTEST_ID'
            }
        });
    },

    /* ---------- Çalışma ---------- */


    //kur degisikligi otomatik hesap
    renderStudyBar: function () {
        const $t = $.DLNFrame;
        const s = $t.data.study;
        // Çubuk (#studyBar) seçim kutusunu da taşır; yalnız durum/kur alanı (#studyInfo) yeniden çizilir
        const $bar = $('#studyBar').removeClass('is-locked is-empty');
        const $info = $('#studyInfo').empty();

        if (!s) {
            $bar.addClass('is-empty');
            $info.append('<i class="fas fa-info-circle"></i>',
                $('<span>').text('Fiyat girmek için önce "Yeni çalışma" ile bir maliyet çalışması açın.'));
            $('#btnNewMaterial').prop('disabled', false);
            return;
        }
        if (!s.editable) { $bar.addClass('is-locked'); }

        // Seçim kutusu adı zaten gösteriyor: burada durum, kur tarihi, kurlar, iş günü
        $info.append(
            $('<span class="mt-badge">').addClass(s.editable ? 'mt-badge-warn' : 'mt-badge-wait')
                .append($('<i class="fas">').addClass(s.editable ? 'fa-pen' : 'fa-lock'), ' ', $t.opt.statusText[s.status] || s.status),
            '<span class="mt-sep"></span>',
            $('<span>').text('Kur tarihi ' + s.rateDate));

        let missing = 0;
        ['USD', 'EUR', 'GBP'].forEach(function (cur) {
            const r = s.rates && s.rates[cur];
            if (!r) { missing++; }
            $info.append($('<span class="mt-rate">').toggleClass('is-missing', !r).text(cur + ' ' + (r ? $t.fmt(r, 4) : '—')));
        });
        // TL öngörü katsayısı yalnız 1'den farklıysa gösterilir (TL fiyatlar bununla çarpılıyor)
        if (Number(s.tryFactor) !== 1) {
            $info.append($('<span class="mt-rate">').text('TL ×' + $t.fmt(s.tryFactor, 4)).attr('title', 'TL öngörü katsayısı'));
        }
        $info.append('<span class="mt-sep"></span>', $('<span>').text(s.workDaysPerMonth + ' iş günü / ay'));

        if (s.editable) {
            // Kur eksikse düğme dikkat çeksin: döviz fiyatlı malzemelerin birim maliyeti kursuz hesaplanamaz
            $info.append($('<button type="button" class="btn btn-sm mt-study-edit btnStudyEdit">')
                .addClass(missing ? 'btn-warning' : 'btn-outline-secondary')
                .append('<i class="fas fa-pen"></i> ', missing ? 'Kurları gir' : 'Kurları düzenle'));
        }
    },

    openStudy: function (s) {
        $('#modalStudyTitle').text(s ? 'Çalışmayı düzenle' : 'Yeni maliyet çalışması');
        $('#EDIT_STUDY_ID').val(s ? s.studyId : '');
        $('#STUDY_NAME').val(s ? s.studyName : '');
        $('#RATE_DATE').val(s ? s.rateDate : moment().format('DD.MM.YYYY'));
        $('#WORK_DAYS_PER_MONTH').val(s ? s.workDaysPerMonth : 26);
        $('#TRY_FACTOR').val($.DLNFrame.fmt(s ? s.tryFactor : 1, 4));
        ['USD', 'EUR', 'GBP'].forEach(function (cur) {
            const r = s && s.rates && s.rates[cur];
            $('#RATE_' + cur).val(r ? $.DLNFrame.fmt(r, 4) : '');
        });
        $('#studyMsg').prop('hidden', true).text('');
        $('#modalStudy').modal('show');
    },

    saveStudy: function () {
        const $t = $.DLNFrame;
        const name = $.trim($('#STUDY_NAME').val());
        if (!name) { $('#studyMsg').text('Çalışma adını girin.').prop('hidden', false); return; }
        if (!moment($('#RATE_DATE').val(), 'DD.MM.YYYY', true).isValid()) {
            $('#studyMsg').text('Kur tarihini gg.aa.yyyy biçiminde girin.').prop('hidden', false); return;
        }

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setStudy',
            type: 'POST',
            dataType: 'json',
            data: {
                STUDY_ID: $('#EDIT_STUDY_ID').val(),
                STUDY_NAME: name,
                RATE_DATE: $('#RATE_DATE').val(),
                WORK_DAYS_PER_MONTH: $('#WORK_DAYS_PER_MONTH').val(),
                TRY_FACTOR: $('#TRY_FACTOR').val(),
                RATE_USD: $('#RATE_USD').val(),
                RATE_EUR: $('#RATE_EUR').val(),
                RATE_GBP: $('#RATE_GBP').val()
            },
            beforeSend: function () { $('#btnStudySave').prop('disabled', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $('#modalStudy').modal('hide');
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    // Liste yeniden dolar ve kaydedilen çalışma seçilir (ad / durum değişmiş olabilir)
                    $t.initSTUDY_ID(result['resObject'] && String(result['resObject']['studyId']));
                } else {
                    $('#studyMsg').text((result && result['resMsg']) || 'Kaydedilemedi.').prop('hidden', false);
                }
            },
            complete: function () { $('#btnStudySave').prop('disabled', false); }
        });
    },

    /* ---------- Malzeme listesi ---------- */

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const esc = function (v) { return $('<span>').text(v === null || v === undefined ? '' : v).html(); };

        // Malzeme adı + varsa not
        function nameFormatter(cellvalue, options, rowObject) {
            return esc(cellvalue) + (rowObject['m_note'] ? '<div class="mt-sub">' + esc(rowObject['m_note']) + '</div>' : '');
        }
        function categoryFormatter(cellvalue) {
            return '<span class="mt-badge mt-badge-info">' + esc(cellvalue) + '</span>';
        }
        function supplierFormatter(cellvalue) {
            return cellvalue ? esc(cellvalue) : '<span class="mt-sub">—</span>';
        }
        // Ambalaj: "960 adet" + açıklama (kitte "96 test / kit" + "70 hasta"); fiyat yoksa rozet
        function packageFormatter(cellvalue, options, rowObject) {
            if (rowObject['hasPrice'] !== 'Y') { return '<span class="mt-badge mt-badge-warn">Fiyat yok</span>'; }
            const isKit = rowObject['isKit'] === 'Y';
            const patients = Number(rowObject['p_patientQty']) || 0;
            return '<span class="mt-mono">' + $t.qty(cellvalue) + '</span> ' + esc(rowObject['m_usageUnitName']) + (isKit ? ' / kit' : '')
                + (patients ? '<div class="mt-sub">' + $t.qty(patients) + ' hastaya yeter</div>' : '')
                + (rowObject['p_packageDesc'] ? '<div class="mt-sub">' + esc(rowObject['p_packageDesc']) + '</div>' : '');
        }
        // Bağlı test sayısı; teste bağlanmamış kit uyarıyla
        function usedFormatter(cellvalue, options, rowObject) {
            const n = Number(cellvalue) || 0;
            if (n) { return '<span class="mt-mono">' + n + '</span>'; }
            return rowObject['isKit'] === 'Y' ? '<span class="mt-badge mt-badge-warn" title="Bu kit hiçbir teste bağlı değil">Bağlı değil</span>' : '<span class="mt-sub">—</span>';
        }
        function priceFormatter(cellvalue, options, rowObject) {
            return rowObject['hasPrice'] === 'Y' ? '<span class="mt-mono">' + $t.fmt(cellvalue, 2) + ' ' + esc(rowObject['p_currency']) + '</span>' : '';
        }
        // Birim maliyet: kur yoksa uyarı, fiyat yoksa boş
        function unitCostFormatter(cellvalue, options, rowObject) {
            if (rowObject['rateMissing'] === 'Y') {
                return '<span class="mt-badge mt-badge-danger" title="Bu çalışmada ' + esc(rowObject['p_currency']) + ' kuru girilmemiş">Kur yok</span>';
            }
            if (cellvalue === null || cellvalue === undefined || cellvalue === '') { return ''; }
            return '<strong class="mt-mono">' + $t.fmt(cellvalue, 4) + '</strong><div class="mt-sub">/ ' + esc(rowObject['m_usageUnitName']) + '</div>';
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            datatype: 'local',          // ilk istek reloadGrid ile (çalışma seçilince)
            sortname: 'm.materialName',
            sortorder: 'asc',
            colModel: [
                { label: 'ID',                 name: 'm_materialId',   index: 'm.materialId',   key: true, hidden: true },
                { label: 'Malzeme',            name: 'm_materialName', index: 'm.materialName', width: 260, formatter: nameFormatter },
                { label: 'Kategori',           name: 'c_categoryName', index: 'c.sortNo',       width: 120, formatter: categoryFormatter },
                { label: 'Tedarikçi',          name: 's_supplierName', index: 's.supplierName', width: 140, formatter: supplierFormatter },
                { label: 'Ambalaj',            name: 'p_packageQty',   index: 'p.packageQty',   width: 140, formatter: packageFormatter },
                { label: 'Ambalaj fiyatı',     name: 'p_packagePrice', index: 'p.packagePrice', width: 110, align: 'right', formatter: priceFormatter },
                { label: 'Birim maliyet (TL)', name: 'unitCostTry',    index: 'unitCostTry',    width: 120, align: 'right', formatter: unitCostFormatter },
                { label: 'Kullanılan test',    name: 'usedCount',      index: 'usedCount',      width: 100, align: 'center', sortable: false, formatter: usedFormatter }
            ],
            pager: '#jqGridPager',
            fluidHeight: false,
            height: 'auto',
            rowNum: 25,
            rowList: [25, 50, 100],
            // Fiyatı girilmemiş satır açık sarı
            rowattr: function (rd, cur) {
                return (cur && cur['hasPrice'] === 'N') ? { 'class': 'mt-row-missing' } : {};
            },
            onSelectRow: function (rowid) {
                $t.getSelectedId(rowid);
            },
            // Yanıtla gelen çalışma bilgisi ve sekme sayıları
            loadComplete: function (data) {
                if (!data || data['resObject'] === undefined) { return; }   // ilk (boş, local) kurulum
                const obj = data['resObject'] || {};
                $d.study = obj['study'] || null;
                $d.kitCategoryId = obj['kitCategoryId'] ? String(obj['kitCategoryId']) : null;
                $t.renderStudyBar();
                const c = obj['counts'] || { all: 0, missing: 0, priced: 0 };
                $('[data-count="all"]').text(c.all);
                $('[data-count="missing"]').text(c.missing);
                $('[data-count="priced"]').text(c.priced);

                let msg = '';
                if (data['resCode'] !== 0 && data['resCode'] !== '0') {
                    msg = data['resMsg'] || 'Liste alınamadı.';
                } else if (!c.all) {
                    msg = 'Henüz malzeme yok. "Malzeme ekle" ile başlayın ya da Excel listesini içe aktarın.';
                } else if (!Number(data['records'])) {
                    msg = 'Bu filtrede malzeme yok.';
                }
                $('#gridMsg').text(msg).prop('hidden', !msg);
            },
            loadError: function () {
                $('#gridMsg').text('Sunucuya ulaşılamadı. Sayfayı yenileyin.').prop('hidden', false);
            }
        });
    },

    // Filtreler postData ile gider; resetPage: filtre değiştiyse 1. sayfaya döner, kayıt sonrası sayfada kalır
    reloadGrid: function (resetPage) {
        const $t = $.DLNFrame;
        const param = {
            datatype: 'json',
            postData: {
                STUDY_ID: $('#STUDY_ID').val() || '',
                TAB: $t.data.tab,
                CATEGORY_ID: $('#F_CATEGORY_ID').val() || '',
                Q: $.trim($('#SEARCH').val())
            }
        };
        if (resetPage) { param.page = 1; }
        $('#jqGrid').jqGrid('setGridParam', param).trigger('reloadGrid');
    },

    // Seçilen satırın kaydı (malzeme + seçili çalışmadaki fiyatı) → form
    getSelectedId: function (materialId) {
        const $t = $.DLNFrame;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getSelectedId',
            type: 'POST',
            dataType: 'json',
            data: { MATERIAL_ID: materialId,
                    STUDY_ID: $('#STUDY_ID').val() || ''
            },
            success: function (result) {
                if (result && result['resCode'] === 0 && result['records']) {
                    $t.openMaterial(result['records']);
                } else {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Kayıt alınamadı.' });
                }
            }
        });
    },

    /* ---------- Malzeme formu ---------- */

    openMaterial: function (m) {
        const $t = $.DLNFrame;
        const s = $t.data.study;
        const editable = !s || s.editable;

        $('#modalMaterialTitle').text(m ? 'Malzemeyi düzenle' : 'Yeni malzeme');
        $('#MATERIAL_ID').val(m ? m.materialId : '');
        $('#MATERIAL_NAME').val(m ? m.materialName : '');
        $('#CATEGORY_ID').val(m ? String(m.categoryId) : '').change();   // yeni malzemede kullanıcı seçer
        $('#USAGE_UNIT').val(m ? m.usageUnit : 'PIECE').change();
        $('#NOTE').val(m ? (m.note || '') : '');

        // Pasif / listede olmayan tedarikçi de formda görünsün
        const $sup = $('#SUPPLIER_ID');
        if (m && m.supplierId && !$sup.find('option[value="' + m.supplierId + '"]').length) {
            $sup.append(new Option(m.supplierName, m.supplierId));
        }
        $sup.val(m && m.supplierId ? String(m.supplierId) : '').change();

        $('.js-price-study').text(s ? s.studyName : 'çalışma seçilmedi');
        $('#PACKAGE_QTY').val(m && m.hasPrice ? $t.qty(m.packageQty) : '');
        $('#PACKAGE_PRICE').val(m && m.hasPrice ? $t.fmt(m.packagePrice, 2) : '');
        $('#CURRENCY').val(m && m.hasPrice ? m.currency : 'TRY').change();
        $('#PACKAGE_DESC').val(m && m.hasPrice ? (m.packageDesc || '') : '');
        $('#VAT_INCLUDED').prop('checked', !!(m && m.vatIncluded));
        $('#PATIENT_QTY').val(m && m.hasPrice && m.patientQty ? $t.qty(m.patientQty) : '');
        $('#PACKAGE_QTY, #PACKAGE_PRICE, #CURRENCY, #PACKAGE_DESC, #PATIENT_QTY, #VAT_INCLUDED').prop('disabled', !s || !editable);
        // Excel'den gelen fiyat notu (ör. "MUTLAKA ÖNGÖRÜLÜ ÇALIŞ", "ALGEN") bilgi olarak
        $('.js-price-note').text(m && m.priceNote ? 'Excel notu: ' + m.priceNote : '').prop('hidden', !(m && m.priceNote));
        $('#btnMaterialSave').prop('disabled', !editable);
        $('#btnMaterialDelete').prop('hidden', !m);

        // Kullanıldığı testler: yeni malzemede önce kayıt gerekir
        $('.js-tests-new').prop('hidden', !!m);
        $('.js-tests-edit').prop('hidden', !m);
        $('#TEST_ID').val('').change();
        $('#TEST_QUANTITY').val('1');
        $t.renderTests(m ? (m.tests || []) : []);

        $('#materialMsg').prop('hidden', true).text('');
        $t.applyKitMode(false);
        $t.preview();
        $('#modalMaterial').modal('show');
    },

    // Kategori kit mi: etiketler ve açıklama buna göre; yeni kitte kullanım birimi "test" olur
    isKit: function () {
        const kitId = $.DLNFrame.data.kitCategoryId;
        return !!kitId && $('#CATEGORY_ID').val() === kitId;
    },
    applyKitMode: function (fromUser) {
        const $t = $.DLNFrame;
        const kit = $t.isKit();
        $('#modalMaterial .js-kit-only').prop('hidden', !kit);
        $('.js-qty-label').text(kit ? 'Kitteki test sayısı' : 'Ambalaj içeriği');
        $('.js-price-label').text(kit ? 'Kit fiyatı' : 'Ambalaj fiyatı');
        $('.js-patient-label').text(kit ? 'Kitten çıkan hasta sayısı' : 'Kaç hastaya yeter');
        $('#PATIENT_QTY').attr('placeholder', kit ? '70' : '—');
        $('#PACKAGE_DESC').attr('placeholder', kit ? '1 kit = 2 x 50 test' : '1 kutu = 10 paket x 96');
        if (kit && fromUser && !$('#MATERIAL_ID').val()) { $('#USAGE_UNIT').val('TEST').change(); }
        $t.preview();
    },

    renderTests: function (tests) {
        const $t = $.DLNFrame;
        const esc = function (v) { return $('<span>').text(v === null || v === undefined ? '' : v).html(); };
        $('.js-test-count').text(tests.length);
        $('.js-test-qty-label').text('Hasta başı (' + ($('#USAGE_UNIT option:selected').text() || 'birim') + ')');
        if (!tests.length) {
            $('#materialTests').html('<tr class="mt-empty font-weight-bold"><td colspan="5">' + ($t.isKit() ? 'Bu kit henüz hiçbir teste bağlı değil.' : 'Bu malzeme hiçbir testin reçetesinde yok.') + '</td></tr>');
            return;
        }
        $('#materialTests').html(tests.map(function (r) {
            return '<tr><td class="mt-mono">' + esc(r.testCode) + '</td><td>' + esc(r.testName)
                + (r.activeFl === 'N' ? ' <span class="mt-badge mt-badge-wait">pasif</span>' : '') + '</td>'
                + '<td class="mt-num mt-mono">' + $t.qty(r.quantity) + '</td><td>' + esc(r.consumptionName) + '</td>'
                + '<td class="text-right"><button type="button" class="btn btn-link btn-sm btnTestUnlink" data-id="' + r.recipeItemId
                + '" data-code="' + esc(r.testCode) + '" title="Bağlantıyı kaldır"><i class="fas fa-unlink"></i></button></td></tr>';
        }).join(''));
    },

    // Seçili testi bağlar (hemen kaydedilir); aynı test zaten bağlıysa miktarı güncellenir
    linkTest: function () {
        const $t = $.DLNFrame;
        const msg = function (text) { $('#materialMsg').text(text).prop('hidden', false); };
        const testId = $('#TEST_ID').val();
        const qty = $t.parse($('#TEST_QUANTITY').val());
        if (!testId) { msg('Bağlanacak testi seçin.'); return; }
        if (qty === null || qty <= 0) { msg('Miktar sıfırdan büyük olmalı.'); return; }

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setMaterialTest',
            type: 'POST',
            dataType: 'json',
            data: { MATERIAL_ID: $('#MATERIAL_ID').val(), TEST_ID: testId, QUANTITY: $('#TEST_QUANTITY').val() },
            beforeSend: function () { $('#btnTestLink').prop('disabled', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    $t.renderTests((result['resObject'] && result['resObject']['tests']) || []);
                    $('#TEST_ID').val('').change();
                    $('#TEST_QUANTITY').val('1');
                    $t.reloadGrid(false);   // listede "Testler" sayısı güncellensin
                } else {
                    msg((result && result['resMsg']) || 'Bağlanamadı.');
                }
            },
            complete: function () { $('#btnTestLink').prop('disabled', false); }
        });
    },

    unlinkTest: function (recipeItemId, testCode) {
        const $t = $.DLNFrame;
        $.fn.nty({
            type: 'error',
            text: testCode + ' bağlantısı kaldırılsın mı? Malzeme bu testin reçetesinden çıkar.',
            buttons: [
                {
                    text: 'Evet, kaldır',
                    onClick: function ($noty) {
                        $noty.close();
                        $.ajaxq('frame', {
                            url: proxyPath + modulePath + 'setMaterialTestDelete',
                            type: 'POST',
                            dataType: 'json',
                            data: { MATERIAL_ID: $('#MATERIAL_ID').val(), RECIPE_ITEM_ID: recipeItemId },
                            success: function (result) {
                                if (result && result['resCode'] === 0) {
                                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                                    $t.renderTests((result['resObject'] && result['resObject']['tests']) || []);
                                    $t.reloadGrid(false);
                                } else {
                                    $('#materialMsg').text((result && result['resMsg']) || 'Kaldırılamadı.').prop('hidden', false);
                                }
                            }
                        });
                    }
                },
                {
                    addClass: 'btn btn-danger',
                    text: 'Vazgeç',
                    onClick: function ($noty) { $noty.close(); }
                }
            ]
        });
    },

    // Birim maliyet önizlemesi (yalnızca gösterim; kaydı sunucu hesaplar)
    preview: function () {
        const $t = $.DLNFrame;
        const s = $t.data.study;
        const unit = $('#USAGE_UNIT option:selected').text() || '';
        const qty = $t.parse($('#PACKAGE_QTY').val());
        const price = $t.parse($('#PACKAGE_PRICE').val());
        const cur = $('#CURRENCY').val();
        // TL'nin kuru çalışmanın öngörü katsayısı
        const rate = cur === 'TRY' ? (s ? Number(s.tryFactor) || 1 : 1) : (s && s.rates && s.rates[cur] ? Number(s.rates[cur]) : null);
        const kit = $t.isKit();
        const patients = $t.parse($('#PATIENT_QTY').val());
        const divisor = patients !== null && patients > 0 ? patients : qty;

        $('.js-qty-hint').text(kit ? 'bir kitte kaç ' + unit + ' var' : 'ambalajda kaç ' + unit + ' var');
        let value = '—', formula = '';
        if (!s) {
            formula = 'Fiyat için bir maliyet çalışması seçin.';
        } else if (qty === null || price === null) {
            formula = 'Ambalaj içeriği ve fiyatı girin.';
        } else if (qty <= 0) {
            formula = 'Ambalaj içeriği sıfırdan büyük olmalı.';
        } else if (rate === null) {
            formula = 'Bu çalışmada ' + cur + ' kuru yok; önce kurları düzenleyin.';
        } else {
            value = $t.fmt(price * rate / divisor, 4) + ' TL / ' + (divisor === qty ? unit : 'hasta');
            formula = '= ' + $t.fmt(price, 2) + ' ' + cur + (rate === 1 ? '' : ' × ' + $t.fmt(rate, 4)) + ' ÷ '
                + $t.qty(divisor) + (divisor === qty ? '' : ' hasta');
        }
        $('.js-preview-value').text(value);
        $('.js-preview-formula').text(formula);
    },

    saveMaterial: function () {
        const $t = $.DLNFrame;
        const s = $t.data.study;
        const name = $.trim($('#MATERIAL_NAME').val());
        const qtyRaw = $.trim($('#PACKAGE_QTY').val());
        const priceRaw = $.trim($('#PACKAGE_PRICE').val());
        const msg = function (text) { $('#materialMsg').text(text).prop('hidden', false); };

        if (!name) { msg('Malzeme adını girin.'); return; }
        if (!$('#CATEGORY_ID').val()) { msg('Kategori seçin.'); return; }
        const kit = $t.isKit();
        if (qtyRaw || priceRaw) {
            if ($t.parse(qtyRaw) === null || $t.parse(qtyRaw) <= 0) { msg((kit ? 'Kitteki test sayısı' : 'Ambalaj içeriği') + ' sıfırdan büyük bir sayı olmalı.'); return; }
            if ($t.parse(priceRaw) === null || $t.parse(priceRaw) < 0) { msg((kit ? 'Kit fiyatını' : 'Ambalaj fiyatını') + ' sayı olarak girin.'); return; }
        }
        const patientRaw = $.trim($('#PATIENT_QTY').val());
        if (patientRaw && ($t.parse(patientRaw) === null || $t.parse(patientRaw) <= 0)) { msg('Hasta sayısı sıfırdan büyük bir sayı olmalı.'); return; }

        // Tedarikçi: listedeki kayıtsa id, yeni yazılmışsa (select2 tags) ad gönderilir
        const $supOpt = $('#SUPPLIER_ID option:selected');
        const supVal = $('#SUPPLIER_ID').val();
        const isNewSupplier = !!supVal && $supOpt.attr('data-select2-tag') === 'true';

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setMaterial',
            type: 'POST',
            dataType: 'json',
            data: {
                STUDY_ID: s ? s.studyId : '',
                MATERIAL_ID: $('#MATERIAL_ID').val(),
                MATERIAL_NAME: name,
                CATEGORY_ID: $('#CATEGORY_ID').val(),
                USAGE_UNIT: $('#USAGE_UNIT').val(),
                SUPPLIER_ID: supVal && !isNewSupplier ? supVal : '',
                SUPPLIER_NAME: isNewSupplier ? supVal : '',
                NOTE: $('#NOTE').val(),
                PATIENT_QTY: patientRaw,
                PACKAGE_QTY: qtyRaw,
                PACKAGE_PRICE: priceRaw,
                CURRENCY: $('#CURRENCY').val(),
                PACKAGE_DESC: $('#PACKAGE_DESC').val(),
                VAT_INCLUDED: $('#VAT_INCLUDED').is(':checked') ? 'Y' : 'N'
            },
            beforeSend: function () { $('#btnMaterialSave').prop('disabled', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    const newId = !$('#MATERIAL_ID').val() && result['resObject'] ? result['resObject']['materialId'] : null;
                    if (newId) {
                        // Yeni malzeme: pencere açık kalır ve kayıtla yeniden dolar; "Kullanıldığı testler" hemen kullanılabilir
                        $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] + '. Şimdi testlere bağlayabilirsiniz.' });
                        $t.getSelectedId(newId);
                    } else {
                        $('#modalMaterial').modal('hide');
                        $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    }
                    if (isNewSupplier) { $t.initSUPPLIER_ID(); }   // yeni tedarikçi listeye girsin
                    $t.reloadGrid(false);
                } else {
                    msg((result && result['resMsg']) || 'Kaydedilemedi.');
                }
            },
            complete: function () { $('#btnMaterialSave').prop('disabled', false); }
        });
    },
    deleteMaterial: function () {
        const $t = $.DLNFrame;
        const id = $('#MATERIAL_ID').val();
        if (!id) { return; }

        $.fn.nty({
            type: 'error',
            text: '"' + $('#MATERIAL_NAME').val() + '" silinsin mi? Malzeme listeden kalkar.',
            buttons: [
                {
                    text: 'Evet, sil',
                    onClick: function ($noty) {
                        $noty.close();
                        $.ajaxq('frame', {
                            url: proxyPath + modulePath + 'setMaterialDelete',
                            type: 'POST',
                            dataType: 'json',
                            data: { MATERIAL_ID: id },
                            success: function (result) {
                                if (result && result['resCode'] === 0) {
                                    $('#modalMaterial').modal('hide');
                                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                                    $t.reloadGrid(false);
                                } else {
                                    $('#materialMsg').text((result && result['resMsg']) || 'Silinemedi.').prop('hidden', false);
                                }
                            }
                        });
                    }
                },
                {
                    addClass: 'btn btn-danger',
                    text: 'Vazgeç',
                    onClick: function ($noty) { $noty.close(); }
                }
            ]
        });
    },

    /* ---------- Yardımcılar ---------- */

    // "1.234,56" / "1234,56" / "1234.56" → 1234.56 ; boş/geçersiz → null
    parse: function (v) {
        v = $.trim(String(v || '')).replace(/\s/g, '');
        if (!v) { return null; }
        if (v.indexOf(',') >= 0) { v = v.replace(/\./g, '').replace(',', '.'); }
        const n = Number(v);
        return isFinite(n) ? n : null;
    },

    // Türkçe sayı biçimi; sondaki gereksiz sıfırlar atılır (en az 2 hane kalır)
    fmt: function (v, maxDigits) {
        const n = Number(v);
        if (!isFinite(n)) { return ''; }
        return n.toLocaleString('tr-TR', { minimumFractionDigits: Math.min(2, maxDigits), maximumFractionDigits: maxDigits });
    },

    // Adet / miktar (ambalaj içeriği, hasta sayısı, reçete miktarı): tam sayıysa ondalıksız (1, 96), değilse en çok 3 hane (0,5)
    qty: function (v) {
        const n = Number(v);
        if (!isFinite(n)) { return ''; }
        return n.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
    },

    storeGet: function () {
        try { return localStorage.getItem($.DLNFrame.opt.storeKey); } catch (e) { return null; }
    },
    storeSet: function (v) {
        try { localStorage.setItem($.DLNFrame.opt.storeKey, String(v)); } catch (e) { /* gizli pencere vb. */ }
    }
};
