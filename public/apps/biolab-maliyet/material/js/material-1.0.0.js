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
        billableCategoryId: null, // "Bedelli malzeme" kategorisinin id'si: seçilince fiyat yerine sabit bedel girilir
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
        $('#COPY_FROM_STUDY_ID').on('change', $t.fillFromCopy);
        // Tedarikçi kurları: satır ekle / sil (kayıt "Kaydet" ile, çalışmayla birlikte)
        $('#btnAddSupplierRate').on('click', function () { $t.addSupplierRateRow(null); });
        $('#supplierRates').on('click', '.btnSupplierRateRemove', function () { $(this).closest('.mt-srate').remove(); });

        $('#mtTabs').on('click', '.mt-tab', function () {
            $d.tab = $(this).data('tab');
            $('#mtTabs .mt-tab').removeClass('active');
            $(this).addClass('active');
            $t.reloadGrid(true);
        });
        $('#F_CATEGORY_ID, #F_SUPPLIER_ID, #F_CURRENCY').on('change', function () { $t.reloadGrid(true); });
        // Arama: yazarken her tuşta değil, durunca (300 ms) sunucuya gider
        $('#SEARCH').on('input', function () {
            clearTimeout($d.searchTimer);
            $d.searchTimer = setTimeout(function () { $t.reloadGrid(true); }, 300);
        });

        $('#btnNewMaterial').on('click', function () { $t.openMaterial(null); });
        $('#btnExcelList').on('click', $t.exportList);
        // Form kapanınca seçim kalksın; aynı satıra tekrar tıklanınca form yine açılsın
        $('#modalMaterial').on('hidden.bs.modal', function () { $('#jqGrid').jqGrid('resetSelection'); });
        $('#btnMaterialSave').on('click', $t.saveMaterial);
        $('#btnMaterialDelete').on('click', $t.deleteMaterial);
        $('#PACKAGE_QTY, #PACKAGE_PRICE, #PATIENT_QTY, #BILLABLE_PRICE').on('input', $t.preview);
        $('#CURRENCY, #USAGE_UNIT, #SUPPLIER_ID').on('change', $t.preview);
        $('#CATEGORY_ID').on('change', function () { $t.applyKitMode(true); });
        $('#btnTestLink').on('click', $t.linkTest);
        $('#materialTests').on('click', '.btnTestUnlink', function () { $t.unlinkTest($(this).data('id'), $(this).data('code')); });
        $('#TEST_SEARCH').on('input', $t.filterTests);
        $('#modalMaterial input, #modalMaterial select').on('input change', function () { $('#materialMsg').prop('hidden', true); });
        $('#modalStudy').on('input change', 'input, select', function () { $('#studyMsg').prop('hidden', true); });
    },

    start: function () {
        const $t = $.DLNFrame;

        $t.initGridList();      // boş kurulur; ilk yükleme çalışma seçilince (STUDY_ID change)
        $t.initSTUDY_ID(null);
        $t.initF_CATEGORY_ID();
        $t.initF_SUPPLIER_ID();
        $t.initF_CURRENCY();
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

    // Liste filtresi: boş = tüm tedarikçiler
    initF_SUPPLIER_ID: function () {
        $('#F_SUPPLIER_ID').slb({
            field:{
                value:'supplierId',
                text:'supplierName',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'Tüm tedarikçiler',
                allowClear:true,
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getSUPPLIER_ID'
            },
            event:{
                // Listenin başına "tedarikçisi olmayanlar" (-1): sunucuda tedarikçi boş olanlar süzülür
                afterLoad:function (el) {
                    $(el).find('option[value=""]').after(new Option('— Tedarikçisi olmayanlar —', '-1'));
                }
            }
        });
    },

    // Liste filtresi: ambalaj fiyatının para birimi (TRY / USD / EUR / GBP); boş = hepsi
    initF_CURRENCY: function () {
        $('#F_CURRENCY').slb({
            field:{
                value:'currency',
                text:'currency',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'Tüm para birimleri',
                allowClear:true,
                minimumResultsForSearch:-1
            },
            ajax:{
                url:proxyPath + modulePath + 'getCURRENCY'
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
        // Tedarikçi sözleşme kurları (ör. NUCLEUS EUR 44,70): o tedarikçinin malzemelerinde genel kurun yerine geçer
        (s.supplierRates || []).forEach(function (r) {
            $info.append($('<span class="mt-rate mt-rate-supplier">').text(r.supplierName + ' ' + r.currency + ' ' + $t.fmt(r.rate, 4))
                .attr('title', 'Tedarikçi kuru: ' + r.supplierName + ' malzemeleri bu kurla hesaplanır'));
        });
        $info.append('<span class="mt-sep"></span>', $('<span>').text(s.workDaysPerMonth + ' iş günü / ay'));

        if (s.editable) {
            // Kur eksikse düğme dikkat çeksin: döviz fiyatlı malzemelerin birim maliyeti kursuz hesaplanamaz
            $info.append($('<button type="button" class="btn btn-sm mt-study-edit btnStudyEdit">')
                .addClass(missing ? 'btn-warning' : 'btn-outline-secondary')
                .append('<i class="fas fa-pen"></i> ', missing ? 'Kurları gir' : 'Kurları düzenle'));
        }
    },

    // Yeni çalışma: fiyatların kopyalanacağı çalışma. Varsayılan en son çalışma (liste kur tarihine göre yeniden eskiye)
    initCOPY_FROM_STUDY_ID: function () {
        const $t = $.DLNFrame;
        $('#COPY_FROM_STUDY_ID').slb({
            field:{
                value:'studyId',
                text:'studyText',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'Kopyalama (boş başla)',
                allowClear:true,
                minimumResultsForSearch:-1
            },
            ajax:{
                url:proxyPath + modulePath + 'getSTUDY_ID'
            },
            event:{
                afterLoad:function (el) {
                    $(el).val($(el).find('option[value!=""]').first().val() || '').change();
                }
            }
        });
    },

    // Kopyalanacak çalışma seçilince kurları, TL katsayısı ve tedarikçi kurları forma dolar (uzman değiştirebilir)
    fillFromCopy: function () {
        const $t = $.DLNFrame;
        const id = $('#COPY_FROM_STUDY_ID').val();
        if (!id || $('#EDIT_STUDY_ID').val()) {
            $('.js-copy-hint').text('Boş bırakılırsa liste fiyatsız başlar.');
            return;
        }
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getStudyInfo',
            type: 'POST',
            dataType: 'json',
            data: { STUDY_ID: id },
            success: function (result) {
                const s = result && result['resCode'] === 0 ? result['records'] : null;
                if (!s) { return; }
                ['USD', 'EUR', 'GBP'].forEach(function (cur) {
                    const r = s.rates && s.rates[cur];
                    $('#RATE_' + cur).val(r ? $t.fmt(r, 4) : '');
                });
                $('#TRY_FACTOR').val($t.fmt(s.tryFactor, 4));
                $('#WORK_DAYS_PER_MONTH').val(s.workDaysPerMonth);
                $('#supplierRates').empty();
                (s.supplierRates || []).forEach(function (r) { $t.addSupplierRateRow(r); });
                $('.js-copy-hint').text(s.priceCount + ' malzeme fiyatı ve kurlar "' + s.studyName + '" çalışmasından aktarılacak; eski çalışma değişmez. '
                    + 'Kurları ve değişen fiyatları yeni çalışmada güncelleyin.');
            }
        });
    },

    openStudy: function (s) {
        $('#modalStudyTitle').text(s ? 'Çalışmayı düzenle' : 'Yeni maliyet çalışması');
        // Fiyat kopyalama yalnız yeni çalışmada
        $('.js-copy-only').prop('hidden', !!s);
        if (!s) { $.DLNFrame.initCOPY_FROM_STUDY_ID(); }
        $('#EDIT_STUDY_ID').val(s ? s.studyId : '');
        $('#STUDY_NAME').val(s ? s.studyName : '');
        $('#RATE_DATE').val(s ? s.rateDate : moment().format('DD.MM.YYYY'));
        $('#WORK_DAYS_PER_MONTH').val(s ? s.workDaysPerMonth : 26);
        $('#TRY_FACTOR').val($.DLNFrame.fmt(s ? s.tryFactor : 1, 4));
        ['USD', 'EUR', 'GBP'].forEach(function (cur) {
            const r = s && s.rates && s.rates[cur];
            $('#RATE_' + cur).val(r ? $.DLNFrame.fmt(r, 4) : '');
        });
        $('#supplierRates').empty();
        ((s && s.supplierRates) || []).forEach(function (r) { $.DLNFrame.addSupplierRateRow(r); });
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

        // Tedarikçi kurları: boş satır atlanır; eksik / hatalı satırda kayıt yapılmaz
        const supplierRates = [];
        let srError = '';
        $('#supplierRates .mt-srate').each(function () {
            const $r = $(this);
            const sup = $r.find('.js-sr-supplier').val();
            const rateRaw = $.trim($r.find('.js-sr-rate').val());
            if (!sup && !rateRaw) { return; }
            const rate = $t.parse(rateRaw);
            if (!sup) { srError = 'Tedarikçi kurunda tedarikçi seçin.'; return false; }
            if (rate === null || rate <= 0) { srError = 'Tedarikçi kuru sıfırdan büyük bir sayı olmalı.'; return false; }
            supplierRates.push({ supplierId: Number(sup), currency: $r.find('.js-sr-currency').val(), rate: rate });
        });
        if (srError) { $('#studyMsg').text(srError).prop('hidden', false); return; }

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
                RATE_GBP: $('#RATE_GBP').val(),
                SUPPLIER_RATES: JSON.stringify(supplierRates),
                COPY_FROM_STUDY_ID: $('#EDIT_STUDY_ID').val() ? '' : ($('#COPY_FROM_STUDY_ID').val() || '')
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

    // Tedarikçi kuru satırı: tedarikçi listesi malzeme formundaki tedarikçi seçiminden (getSUPPLIER_ID) kopyalanır
    addSupplierRateRow: function (r) {
        const $t = $.DLNFrame;
        const $sup = $('<select class="form-control form-control-sm js-sr-supplier">').append(new Option('Tedarikçi seçin', ''));
        $('#SUPPLIER_ID option').each(function () {
            if (this.value && $(this).attr('data-select2-tag') !== 'true') { $sup.append(new Option($(this).text(), this.value)); }
        });
        if (r && r.supplierId && !$sup.find('option[value="' + r.supplierId + '"]').length) {
            $sup.append(new Option(r.supplierName, r.supplierId));   // pasif tedarikçi de görünsün
        }
        const $cur = $('<select class="form-control form-control-sm js-sr-currency">');
        ['EUR', 'USD', 'GBP'].forEach(function (c) { $cur.append(new Option(c, c)); });

        $sup.val(r ? String(r.supplierId) : '');
        $cur.val(r ? r.currency : 'EUR');
        $('#supplierRates').append($('<div class="form-row mt-srate">').append(
            $('<div class="form-group col-6">').append($sup),
            $('<div class="form-group col-2">').append($cur),
            $('<div class="form-group col-3">').append($('<input type="text" class="form-control form-control-sm mt-mono js-sr-rate" placeholder="44,7">')
                .val(r ? $t.fmt(r.rate, 4) : '')),
            $('<div class="form-group col-1 text-right">').append('<button type="button" class="btn btn-link btn-sm text-danger btnSupplierRateRemove" title="Satırı kaldır"><i class="fas fa-times"></i></button>')
        ));
    },

    /* ---------- Malzeme listesi ---------- */

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const esc = function (v) { return $('<span>').text(v === null || v === undefined ? '' : v).html(); };

        // Malzeme adı + kısa kod rozeti (TK, LM…) + katalog no + varsa not
        function nameFormatter(cellvalue, options, rowObject) {
            const code = rowObject['m_shortCode'] ? ' <span class="mt-badge mt-badge-info mt-mono" title="Kısa kod">' + esc(rowObject['m_shortCode']) + '</span>' : '';
            const sub = [rowObject['m_catalogNo'] ? 'Katalog ' + esc(rowObject['m_catalogNo']) : '', rowObject['m_note'] ? esc(rowObject['m_note']) : '']
                .filter(Boolean).join(' · ');
            return esc(cellvalue) + code + (sub ? '<div class="mt-sub">' + sub + '</div>' : '');
        }
        function categoryFormatter(cellvalue) {
            return '<span class="mt-badge mt-badge-info">' + esc(cellvalue) + '</span>';
        }
        function supplierFormatter(cellvalue) {
            return cellvalue ? esc(cellvalue) : '<span class="mt-sub">—</span>';
        }
        // Ambalaj: "960 adet" + açıklama (kitte "96 test / kit" + "70 hasta"); fiyat yoksa rozet
        function packageFormatter(cellvalue, options, rowObject) {
            if (rowObject['isBillable'] === 'Y') {
                return rowObject['hasPrice'] === 'Y' ? '<span class="mt-badge mt-badge-info">Sabit bedel</span>' : '<span class="mt-badge mt-badge-warn">Bedel yok</span>';
            }
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
            if (rowObject['isBillable'] === 'Y') {
                return rowObject['hasPrice'] === 'Y' ? '<span class="mt-mono">' + $t.fmt(rowObject['m_billablePrice'], 2) + ' TL</span>' : '';
            }
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
                $d.billableCategoryId = obj['billableCategoryId'] ? String(obj['billableCategoryId']) : null;
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
                SUPPLIER_ID: $('#F_SUPPLIER_ID').val() || '',
                CURRENCY: $('#F_CURRENCY').val() || '',
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
        $('#SHORT_CODE').val(m ? (m.shortCode || '') : '');
        $('#CATALOG_NO').val(m ? (m.catalogNo || '') : '');

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
        $('#BILLABLE_PRICE').val(m && m.billablePrice !== null && m.billablePrice !== undefined ? $t.fmt(m.billablePrice, 4) : '').prop('disabled', !editable);
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
        $('#TEST_SEARCH').val('');   // başka malzemeden kalan arama temizlenir
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
    // Kategori bedelli malzeme mi: fiyat bölümü yerine sabit bedel alanı
    isBillable: function () {
        const id = $.DLNFrame.data.billableCategoryId;
        return !!id && $('#CATEGORY_ID').val() === id;
    },
    applyKitMode: function (fromUser) {
        const $t = $.DLNFrame;
        const kit = $t.isKit();
        const billable = $t.isBillable();
        $('#modalMaterial .js-kit-only').prop('hidden', !kit);
        $('.js-billable-only').prop('hidden', !billable);
        $('.js-price-section').prop('hidden', billable);
        $('.js-billable-unit').text($('#USAGE_UNIT option:selected').text() || 'adet');
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
            // data-search: arama için kod + ad (Türkçe küçük harf)
            return '<tr class="js-test-row" data-search="' + esc((r.testCode + ' ' + r.testName).toLocaleLowerCase('tr-TR')) + '"><td class="mt-mono">' + esc(r.testCode) + '</td><td>' + esc(r.testName)
                + (r.activeFl === 'N' ? ' <span class="mt-badge mt-badge-wait">pasif</span>' : '') + '</td>'
                + '<td class="mt-num mt-mono">' + $t.qty(r.quantity) + '</td><td>' + esc(r.consumptionName) + '</td>'
                + '<td class="text-right"><button type="button" class="btn btn-link btn-sm btnTestUnlink" data-id="' + r.recipeItemId
                + '" data-code="' + esc(r.testCode) + '" title="Bağlantıyı kaldır"><i class="fas fa-unlink"></i></button></td></tr>';
        }).join(''));
        $t.filterTests();   // bağla / kaldır sonrası arama korunur
    },

    // Bağlı testlerde arama: yalnız ekrandaki satırları süzer (kod ya da ad içinde geçen)
    filterTests: function () {
        const q = $.trim($('#TEST_SEARCH').val()).toLocaleLowerCase('tr-TR');
        const $rows = $('#materialTests .js-test-row');
        let shown = 0;
        $rows.each(function () {
            const ok = !q || $(this).attr('data-search').indexOf(q) >= 0;
            $(this).toggle(ok);
            if (ok) { shown++; }
        });
        $('#materialTests .js-test-nomatch').remove();
        if ($rows.length && !shown) {
            $('#materialTests').append('<tr class="mt-empty js-test-nomatch"><td colspan="5">Aramaya uyan bağlı test yok.</td></tr>');
        }
        // Sayı: arama varsa "3 / 23"
        $('.js-test-count').text(q && $rows.length ? shown + ' / ' + $rows.length : $rows.length);
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
        // TL'nin kuru çalışmanın öngörü katsayısı; tedarikçinin bu para birimi için sözleşme kuru varsa o
        const supId = $('#SUPPLIER_ID').val();
        const supRate = s && supId ? (s.supplierRates || []).filter(function (r) { return String(r.supplierId) === String(supId) && r.currency === cur; })[0] : null;
        const rate = cur === 'TRY' ? (s ? Number(s.tryFactor) || 1 : 1)
            : supRate ? Number(supRate.rate)
            : (s && s.rates && s.rates[cur] ? Number(s.rates[cur]) : null);
        const kit = $t.isKit();
        const patients = $t.parse($('#PATIENT_QTY').val());
        const divisor = patients !== null && patients > 0 ? patients : qty;

        $('.js-qty-hint').text(kit ? 'bir kitte kaç ' + unit + ' var' : 'ambalajda kaç ' + unit + ' var');
        $('.js-billable-unit').text(unit || 'adet');
        let value = '—', formula = '';
        const billablePrice = $t.parse($('#BILLABLE_PRICE').val());
        if ($t.isBillable()) {
            // Bedelli malzeme: birim maliyet = sabit bedel (çalışmadan bağımsız)
            if (billablePrice === null) {
                formula = 'Bedeli girin.';
            } else {
                value = $t.fmt(billablePrice, 4) + ' TL / ' + unit;
                formula = 'sabit bedel, her çalışmada aynı';
            }
        } else if (!s) {
            formula = 'Fiyat için bir maliyet çalışması seçin.';
        } else if (qty === null || price === null) {
            formula = 'Ambalaj içeriği ve fiyatı girin.';
        } else if (qty <= 0) {
            formula = 'Ambalaj içeriği sıfırdan büyük olmalı.';
        } else if (rate === null) {
            formula = 'Bu çalışmada ' + cur + ' kuru yok; önce kurları düzenleyin.';
        } else {
            value = $t.fmt(price * rate / divisor, 4) + ' TL / ' + (divisor === qty ? unit : 'hasta');
            formula = '= ' + $t.fmt(price, 2) + ' ' + cur + (rate === 1 ? '' : ' × ' + $t.fmt(rate, 4) + (supRate ? ' (tedarikçi kuru)' : '')) + ' ÷ '
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
        const billable = $t.isBillable();
        const billableRaw = $.trim($('#BILLABLE_PRICE').val());
        if (billable && billableRaw && ($t.parse(billableRaw) === null || $t.parse(billableRaw) < 0)) { msg('Bedeli sayı olarak girin.'); return; }
        if (!billable && (qtyRaw || priceRaw)) {
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
                SHORT_CODE: $.trim($('#SHORT_CODE').val()),
                CATALOG_NO: $.trim($('#CATALOG_NO').val()),
                BILLABLE_PRICE: billable ? billableRaw : '',
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

    // Excel çıktısı: ekrandaki filtrelerle; sunucu dosyayı hazırlar, indirme adresi (/api/pdf.php?t=…) döner
    exportList: function () {
        const $btn = $('#btnExcelList');
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getExcelList',
            type: 'POST',
            dataType: 'json',
            data: {
                STUDY_ID: $('#STUDY_ID').val() || '',
                TAB: $.DLNFrame.data.tab,
                CATEGORY_ID: $('#F_CATEGORY_ID').val() || '',
                SUPPLIER_ID: $('#F_SUPPLIER_ID').val() || '',
                CURRENCY: $('#F_CURRENCY').val() || '',
                Q: $.trim($('#SEARCH').val())
            },
            beforeSend: function () { $btn.prop('disabled', true).find('i').attr('class', 'fas fa-circle-notch fa-spin'); },
            success: function (result) {
                if (result && result['resCode'] === 0 && result['resObject'] && result['resObject']['url']) {
                    window.location.href = result['resObject']['url'];   // ek olarak gelir; sayfa değişmez
                } else {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Excel hazırlanamadı.' });
                }
            },
            complete: function () { $btn.prop('disabled', false).find('i').attr('class', 'fas fa-file-excel'); }
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
