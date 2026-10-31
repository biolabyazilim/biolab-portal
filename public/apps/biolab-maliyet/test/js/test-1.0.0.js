/**
 * Maliyet — Test tanımları
 *
 * - Liste jqGrid'dir ($.fn.jqg → getGridList): sayfalama / sıralama sunucuda; sekme (tümü / yöntemi belirsiz / pasif),
 *   hizmet grubu, maliyet yöntemi, birim ve arama postData ile gider (reloadGrid).
 * - Satıra tıklayınca kayıt getSelectedId ile alınır ve form açılır.
 * - Kod, ad, hizmet grubu, birim Tenay'dan gelir: mevcut testte kilitli, yalnızca "Test ekle"de girilir.
 * - Seçim kutuları $.fn.slb ile dolar (initX → getX); select id'si alan adıdır.
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-maliyet/test/php/test-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','test/test-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','test/test-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        lang: null,
        tab: 'all',         // all / undefined / inactive
        searchTimer: null
    },

    init: function () {
        const $t = $.DLNFrame;
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

        $('#tsTabs').on('click', '.ts-tab', function () {
            $d.tab = $(this).data('tab');
            $('#tsTabs .ts-tab').removeClass('active');
            $(this).addClass('active');
            $t.reloadGrid(true);
        });
        $('#F_SERVICE_GROUP_ID, #F_COST_METHOD, #F_LAB_UNIT').on('change', function () { $t.reloadGrid(true); });
        // Arama: yazarken her tuşta değil, durunca (300 ms) sunucuya gider
        $('#SEARCH').on('input', function () {
            clearTimeout($d.searchTimer);
            $d.searchTimer = setTimeout(function () { $t.reloadGrid(true); }, 300);
        });

        $('#btnNewTest').on('click', function () { $t.openTest(null); });
        $('#btnTestSave').on('click', $t.saveTest);
        $('#modalTest input, #modalTest select').on('input change', function () { $('#testMsg').prop('hidden', true); });
        // Form kapanınca seçim kalksın; aynı satıra tekrar tıklanınca form yine açılsın
        $('#modalTest').on('hidden.bs.modal', function () { $('#jqGrid').jqGrid('resetSelection'); });
    },

    start: function () {
        const $t = $.DLNFrame;
        $t.initGridList();
        $t.initF_SERVICE_GROUP_ID();
        $t.initF_COST_METHOD();
        $t.initF_LAB_UNIT();
        $t.initSERVICE_GROUP_ID();
        $t.initLAB_UNIT();
        $t.initCOST_METHOD();
        $t.initSUPPLIER_ID();
        $t.reloadGrid(true);
    },

    /* ---------- Seçim kutuları: filtre ---------- */

    initF_SERVICE_GROUP_ID: function () {
        $('#F_SERVICE_GROUP_ID').slb({
            field:{ value:'serviceGroupId', text:'serviceGroupName', disabledFL:'activeFl' },
            select2:{ placeholder:'Tüm hizmet grupları', allowClear:true },
            ajax:{ url:proxyPath + modulePath + 'getSERVICE_GROUP_ID' }
        });
    },

    initF_COST_METHOD: function () {
        $('#F_COST_METHOD').slb({
            field:{ value:'costMethod', text:'costMethodName', disabledFL:'activeFl' },
            select2:{ placeholder:'Tüm yöntemler', allowClear:true, minimumResultsForSearch:-1 },
            ajax:{ url:proxyPath + modulePath + 'getCOST_METHOD' }
        });
    },

    initF_LAB_UNIT: function () {
        $('#F_LAB_UNIT').slb({
            field:{ value:'labUnit', text:'labUnitName', disabledFL:'activeFl' },
            select2:{ placeholder:'Tüm birimler', allowClear:true, minimumResultsForSearch:-1 },
            ajax:{ url:proxyPath + modulePath + 'getLAB_UNIT' }
        });
    },

    /* ---------- Seçim kutuları: form ---------- */

    initSERVICE_GROUP_ID: function () {
        $('#SERVICE_GROUP_ID').slb({
            field:{ value:'serviceGroupId', text:'serviceGroupName', disabledFL:'activeFl' },
            select2:{ placeholder:'Seçin', allowClear:false },
            ajax:{ url:proxyPath + modulePath + 'getSERVICE_GROUP_ID' }
        });
    },

    initLAB_UNIT: function () {
        $('#LAB_UNIT').slb({
            field:{ value:'labUnit', text:'labUnitName', disabledFL:'activeFl' },
            select2:{ placeholder:'Seçin', allowClear:false, minimumResultsForSearch:-1 },
            ajax:{ url:proxyPath + modulePath + 'getLAB_UNIT' }
        });
    },

    initCOST_METHOD: function () {
        $('#COST_METHOD').slb({
            field:{ value:'costMethod', text:'costMethodName', disabledFL:'activeFl' },
            select2:{ placeholder:'Seçin', allowClear:false, minimumResultsForSearch:-1 },
            ajax:{ url:proxyPath + modulePath + 'getCOST_METHOD' }
        });
    },

    // Tedarikçi: listeden seçilir ya da yeni ad yazılır (tags); kayıtta yeni ad sunucuda tedarikçi olur
    initSUPPLIER_ID: function () {
        $('#SUPPLIER_ID').slb({
            field:{ value:'supplierId', text:'supplierName', disabledFL:'activeFl' },
            select2:{ placeholder:'Seçin ya da yazın', allowClear:true, tags:true },
            ajax:{ url:proxyPath + modulePath + 'getSUPPLIER_ID' }
        });
    },

    /* ---------- Liste ---------- */

    initGridList: function () {
        const $t = $.DLNFrame;
        const esc = function (v) { return $('<span>').text(v === null || v === undefined ? '' : v).html(); };

        function codeFormatter(cellvalue) {
            return '<span class="ts-mono">' + esc(cellvalue) + '</span>';
        }
        // Ad + birleşik testte bileşen sayısı
        function nameFormatter(cellvalue, options, rowObject) {
            const n = Number(rowObject['componentCount']) || 0;
            return esc(cellvalue) + (n ? '<div class="ts-sub"><i class="fas fa-project-diagram"></i> ' + n + ' bileşen</div>' : '');
        }
        function methodFormatter(cellvalue, options, rowObject) {
            return '<span class="ts-badge ts-m-' + esc(cellvalue) + '">' + esc(rowObject['t_costMethodName']) + '</span>';
        }
        // Tedarikçi; yoksa Excel'deki ham "Tedarik" değeri (gri)
        function supplierFormatter(cellvalue, options, rowObject) {
            if (cellvalue) { return esc(cellvalue); }
            return rowObject['t_sourceSupplierText'] ? '<span class="ts-sub">' + esc(rowObject['t_sourceSupplierText']) + '</span>' : '';
        }
        // Patoloji: "2. düzey · 909410"
        function levelFormatter(cellvalue, options, rowObject) {
            const parts = [];
            if (cellvalue) { parts.push(esc(cellvalue) + '. düzey'); }
            if (rowObject['t_sutCode']) { parts.push('<span class="ts-mono">' + esc(rowObject['t_sutCode']) + '</span>'); }
            return parts.join(' · ');
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            datatype: 'local',          // ilk istek start() içindeki reloadGrid ile
            sortname: 't.testName',
            sortorder: 'asc',
            colModel: [
                { label: 'ID',             name: 't_testId',           index: 't.testId',           key: true, hidden: true },
                { label: 'Kod',            name: 't_testCode',         index: 't.testCode',         width: 90,  formatter: codeFormatter },
                { label: 'Test',           name: 't_testName',         index: 't.testName',         width: 320, formatter: nameFormatter },
                { label: 'Hizmet grubu',   name: 'g_serviceGroupName', index: 'g.serviceGroupName', width: 150 },
                { label: 'Birim',          name: 't_labUnitName',      index: 't.labUnit',          width: 80 },
                { label: 'Maliyet yöntemi', name: 't_costMethod',      index: 't.costMethod',       width: 120, formatter: methodFormatter },
                { label: 'Tedarikçi',      name: 's_supplierName',     index: 's.supplierName',     width: 160, formatter: supplierFormatter },
                { label: 'Patoloji',       name: 't_pathologyLevel',   index: 't.pathologyLevel',   width: 120, formatter: levelFormatter },
                { label: 'Sorumlu',        name: 't_responsibleName',  index: 't.responsibleName',  width: 110, sortable: false }
            ],
            pager: '#jqGridPager',
            fluidHeight: false,
            height: 'auto',
            rowNum: 25,
            rowList: [25, 50, 100],
            // Pasif test üstü çizili, yöntemi belirsiz açık sarı
            rowattr: function (rd, cur) {
                if (!cur) { return {}; }
                if (cur['t_activeFl'] === 'N') { return { 'class': 'ts-row-inactive' }; }
                return cur['t_costMethod'] === 'UNDEFINED' ? { 'class': 'ts-row-undefined' } : {};
            },
            onSelectRow: function (rowid) {
                $t.getSelectedId(rowid);
            },
            // Sekme sayıları ve boş liste mesajı
            loadComplete: function (data) {
                if (!data || data['resObject'] === undefined) { return; }   // ilk (boş, local) kurulum
                const c = (data['resObject'] && data['resObject']['counts']) || { all: 0, undefined: 0, inactive: 0 };
                $('[data-count="all"]').text(c.all);
                $('[data-count="undefined"]').text(c.undefined);
                $('[data-count="inactive"]').text(c.inactive);

                let msg = '';
                if (data['resCode'] !== 0 && data['resCode'] !== '0') {
                    msg = data['resMsg'] || 'Liste alınamadı.';
                } else if (!c.all) {
                    msg = 'Henüz test yok. Test listesini Excel aktarımıyla (mlyt-import.bat) yükleyin ya da "Test ekle" ile girin.';
                } else if (!Number(data['records'])) {
                    msg = 'Bu filtrede test yok.';
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
                TAB: $t.data.tab,
                SERVICE_GROUP_ID: $('#F_SERVICE_GROUP_ID').val() || '',
                COST_METHOD: $('#F_COST_METHOD').val() || '',
                LAB_UNIT: $('#F_LAB_UNIT').val() || '',
                Q: $.trim($('#SEARCH').val())
            }
        };
        if (resetPage) { param.page = 1; }
        $('#jqGrid').jqGrid('setGridParam', param).trigger('reloadGrid');
    },

    getSelectedId: function (testId) {
        const $t = $.DLNFrame;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getSelectedId',
            type: 'POST',
            dataType: 'json',
            data: { TEST_ID: testId },
            success: function (result) {
                if (result && result['resCode'] === 0 && result['records']) {
                    $t.openTest(result['records']);
                } else {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Kayıt alınamadı.' });
                }
            }
        });
    },

    /* ---------- Form ---------- */

    openTest: function (t) {
        const isNew = !t;
        $('#modalTestTitle').text(isNew ? 'Yeni test' : t.testCode + ' · ' + t.testName);
        $('#TEST_ID').val(isNew ? '' : t.testId);

        // Tenay alanları: yeni testte girilir, mevcut testte kilitli
        $('#TEST_CODE').val(isNew ? '' : t.testCode).prop('readonly', !isNew);
        $('#TEST_NAME').val(isNew ? '' : t.testName).prop('readonly', !isNew);
        $('#SERVICE_GROUP_ID').val(isNew ? '' : String(t.serviceGroupId)).change().prop('disabled', !isNew);
        $('#LAB_UNIT').val(isNew ? 'BIOLAB' : t.labUnit).change().prop('disabled', !isNew);

        $('#COST_METHOD').val(isNew ? 'UNDEFINED' : t.costMethod).change();
        const $sup = $('#SUPPLIER_ID');
        if (t && t.supplierId && !$sup.find('option[value="' + t.supplierId + '"]').length) {
            $sup.append(new Option(t.supplierName, t.supplierId));
        }
        $sup.val(t && t.supplierId ? String(t.supplierId) : '').change();
        $('#RESPONSIBLE_NAME').val(t ? (t.responsibleName || '') : '');
        $('#PATHOLOGY_LEVEL').val(t && t.pathologyLevel ? t.pathologyLevel : '');
        $('#SUT_CODE').val(t ? (t.sutCode || '') : '');
        $('#SAMPLE_TYPE').val(t ? (t.sampleType || '') : '');
        $('#NOTE').val(t ? (t.note || '') : '');
        $('#ACTIVE').prop('checked', isNew || !!t.active);

        // Bileşenler (birleşik / hesaplamalı test)
        const comps = (t && t.components) || [];
        const $body = $('.js-comp-body').empty();
        comps.forEach(function (c) {
            $body.append($('<tr>').append(
                $('<td class="ts-mono">').text(c.testCode),
                $('<td>').text(c.testName),
                $('<td class="text-right ts-mono">').text(Number(c.quantity).toLocaleString('tr-TR')),
                $('<td class="text-right ts-mono">').text(Number(c.applyRate).toLocaleString('tr-TR'))));
        });
        $('.js-comp-count').text(comps.length);
        $('.js-components').prop('hidden', !comps.length);

        // Excel'den gelen ham değerler (bilgi)
        const src = [];
        if (t && t.sourceSupplierText) { src.push('Tedarik: <b>' + $('<span>').text(t.sourceSupplierText).html() + '</b>'); }
        if (t && t.sourceLevelText)    { src.push('Düzey: <b>' + $('<span>').text(t.sourceLevelText).html() + '</b>'); }
        if (t && t.sourceMaterialText) { src.push('Malzeme notu: <b>' + $('<span>').text(t.sourceMaterialText).html() + '</b>'); }
        $('.js-source').html(src.length ? '<i class="fas fa-file-excel"></i> Excel\'deki değer · ' + src.join(' · ') : '').prop('hidden', !src.length);

        $('#testMsg').prop('hidden', true).text('');
        $('#modalTest').modal('show');
    },

    saveTest: function () {
        const $t = $.DLNFrame;
        const isNew = !$('#TEST_ID').val();
        const msg = function (text) { $('#testMsg').text(text).prop('hidden', false); };

        if (isNew) {
            if (!$.trim($('#TEST_CODE').val())) { msg('Tenay test kodunu girin.'); return; }
            if (!$.trim($('#TEST_NAME').val())) { msg('Test adını girin.'); return; }
            if (!$('#SERVICE_GROUP_ID').val()) { msg('Hizmet grubu seçin.'); return; }
        }
        if (!$('#COST_METHOD').val()) { msg('Maliyet yöntemi seçin.'); return; }
        const sut = $.trim($('#SUT_CODE').val());
        if (sut && !/^\d{6}$/.test(sut)) { msg('SUT kodu 6 haneli olmalı.'); return; }

        // Tedarikçi: listedeki kayıtsa id, yeni yazılmışsa (select2 tags) ad gönderilir
        const supVal = $('#SUPPLIER_ID').val();
        const isNewSupplier = !!supVal && $('#SUPPLIER_ID option:selected').attr('data-select2-tag') === 'true';

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setTest',
            type: 'POST',
            dataType: 'json',
            data: {
                TEST_ID: $('#TEST_ID').val(),
                TEST_CODE: $.trim($('#TEST_CODE').val()),
                TEST_NAME: $.trim($('#TEST_NAME').val()),
                SERVICE_GROUP_ID: $('#SERVICE_GROUP_ID').val(),
                LAB_UNIT: $('#LAB_UNIT').val(),
                COST_METHOD: $('#COST_METHOD').val(),
                SUPPLIER_ID: supVal && !isNewSupplier ? supVal : '',
                SUPPLIER_NAME: isNewSupplier ? supVal : '',
                RESPONSIBLE_NAME: $('#RESPONSIBLE_NAME').val(),
                PATHOLOGY_LEVEL: $('#PATHOLOGY_LEVEL').val(),
                SUT_CODE: sut,
                SAMPLE_TYPE: $('#SAMPLE_TYPE').val(),
                NOTE: $('#NOTE').val(),
                ACTIVE: $('#ACTIVE').is(':checked') ? 'Y' : 'N'
            },
            beforeSend: function () { $('#btnTestSave').prop('disabled', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $('#modalTest').modal('hide');
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    if (isNewSupplier) { $t.initSUPPLIER_ID(); }   // yeni tedarikçi listeye girsin
                    $t.reloadGrid(isNew);
                } else {
                    msg((result && result['resMsg']) || 'Kaydedilemedi.');
                }
            },
            complete: function () { $('#btnTestSave').prop('disabled', false); }
        });
    }
};
