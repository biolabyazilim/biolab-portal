/**
 * Maliyet — Kit eşleştirme
 *
 * - Liste jqGrid'dir ($.fn.jqg → getGridList): güven sekmesi (yüksek / orta / öneri yok / kit bağlı), yöntem ve arama
 *   postData ile gider (reloadGrid). Öneri sunucuda hesaplanır (Core\Mlyt\KitMatcher).
 * - Satırda önerilen kit seçili gelir; kutudan başka öneri ya da bütün kitlerden biri seçilebilir, miktar değiştirilebilir.
 * - Satırın kutusu işaretlenip "Seçilenleri bağla" → setLink: testin reçetesine hasta başı kit satırı. Yöntemi belirsiz
 *   test Kit olur. "Kit bağlı" sekmesinde bağlantı kaldırılabilir (setUnlink).
 * - Seçim yalnız açık sayfadaki satırlar içindir; sekme / filtre / sayfa değişince temizlenir.
 * - Seçim kutuları $.fn.slb ile dolar (initX → getX); select id'si alan adıdır.
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-maliyet/kit_match/php/kitMatch-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','kit_match/kitMatch-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','kit_match/kitMatch-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        lang: null,
        tab: 'HIGH',
        searchTimer: null,
        kits: []            // bütün kitler (getGridList → resObject.kits); satırdaki "Tüm kitler" grubu
    },
    opt: {
        confidenceText: { HIGH: 'Yüksek', MEDIUM: 'Orta', NONE: 'Öneri yok', LINKED: 'Kit bağlı' }
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

        $('#kmTabs').on('click', '.km-tab', function () {
            $d.tab = $(this).data('tab');
            $('#kmTabs .km-tab').removeClass('active');
            $(this).addClass('active');
            $t.reloadGrid(true);
        });
        $('#F_COST_METHOD').on('change', function () { $t.reloadGrid(true); });
        $('#SEARCH').on('input', function () {
            clearTimeout($d.searchTimer);
            $d.searchTimer = setTimeout(function () { $t.reloadGrid(true); }, 300);
        });

        // Satır içi: seçim kutusu, kit değiştirilince satır kendiliğinden seçilir
        $('#jqGrid').on('change', '.km-chk', function () { $t.markRow(this); $t.renderSelected(); });
        $('#jqGrid').on('change', '.km-kit', function () {
            const $chk = $(this).closest('tr').find('.km-chk');
            $chk.prop('checked', !!$(this).val());
            $t.markRow($chk[0]);
            $t.renderSelected();
        });
        $('#jqGrid').on('click', '.btnUnlink', function () { $t.unlink($(this).data('test'), $(this).data('material'), $(this).data('code')); });

        // Tümünü seç / seçimi kaldır: başlıktaki kutu (grid başlığı #jqGrid dışında, document'e bağlanır)
        $(document).on('change', '.km-chk-all', function () { $t.selectPage(this.checked); });
        $('#btnLink').on('click', $t.link);
    },

    start: function () {
        const $t = $.DLNFrame;
        $t.initGridList();
        $t.initF_COST_METHOD();
        $t.reloadGrid(true);
    },

    /* ---------- Seçim kutuları ---------- */

    initF_COST_METHOD: function () {
        $('#F_COST_METHOD').slb({
            field:{ value:'costMethod', text:'costMethodName', disabledFL:'activeFl' },
            select2:{ placeholder:'Tüm yöntemler', allowClear:true, minimumResultsForSearch:-1 },
            ajax:{ url:proxyPath + modulePath + 'getCOST_METHOD' }
        });
    },

    /* ---------- Liste ---------- */

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const esc = $t.esc;

        // Bağlı sekmesinde kutu yok
        function checkFormatter(cellvalue, options, r) {
            return r['confidence'] === 'LINKED' ? '' : '<input type="checkbox" class="km-chk" aria-label="Seç">';
        }
        function codeFormatter(cellvalue) { return '<span class="km-mono">' + esc(cellvalue) + '</span>'; }
        // Test adı + tedarikçisi
        function nameFormatter(cellvalue, options, r) {
            return esc(cellvalue) + (r['s_supplierName'] ? '<div class="km-sub">' + esc(r['s_supplierName']) + '</div>' : '');
        }
        function methodFormatter(cellvalue, options, r) { return esc(r['t_costMethodName']); }
        // Kit: öneriler (ilki seçili) + bütün kitler; bağlı sekmesinde bağlı kitler ve kaldır düğmesi
        function kitFormatter(cellvalue, options, r) {
            if (r['confidence'] === 'LINKED') {
                return (r['linked'] || []).map(function (k) {
                    return '<div class="km-linked"><span>' + esc(k.materialName) + (k.quantity !== 1 ? ' × ' + esc($t.qty(k.quantity)) : '') + '</span>'
                        + '<button type="button" class="btn btn-link btn-sm btnUnlink" title="Bağlantıyı kaldır" data-test="' + r['t_testId']
                        + '" data-material="' + k.materialId + '" data-code="' + esc(r['t_testCode']) + '"><i class="fas fa-unlink"></i></button></div>';
                }).join('');
            }
            const sug = r['suggestions'] || [];
            const ids = sug.map(function (s) { return s.materialId; });
            let html = '<select class="form-control km-kit"><option value="">' + (sug.length ? '— kit seçin —' : '— öneri yok, kit seçin —') + '</option>';
            if (sug.length) {
                html += '<optgroup label="Öneriler">' + sug.map(function (s, i) {
                    return '<option value="' + s.materialId + '"' + (i === 0 ? ' selected' : '') + '>' + esc(s.materialName)
                        + ' (%' + Math.round(s.match * 100) + (s.linkedCount ? ', ' + s.linkedCount + ' teste bağlı' : '') + ')</option>';
                }).join('') + '</optgroup>';
            }
            html += '<optgroup label="Tüm kitler">' + $d.kits.filter(function (k) { return ids.indexOf(k.materialId) < 0; }).map(function (k) {
                return '<option value="' + k.materialId + '">' + esc(k.materialName) + '</option>';
            }).join('') + '</optgroup></select>';
            return html;
        }
        function matchFormatter(cellvalue, options, r) {
            const pct = cellvalue === null || cellvalue === undefined ? '' : ' %' + Math.round(cellvalue * 100);
            return '<span class="km-badge km-c-' + esc(r['confidence']) + '">' + esc($t.opt.confidenceText[r['confidence']] || r['confidence']) + pct + '</span>';
        }
        function qtyFormatter(cellvalue, options, r) {
            return r['confidence'] === 'LINKED' ? '' : '<input type="text" class="form-control km-mono km-qty" inputmode="decimal" value="1" title="Hasta başı kaç test">';
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            datatype: 'local',          // ilk istek start() → reloadGrid
            sortname: 't.testCode',
            sortorder: 'asc',
            colModel: [
                { label: 'ID',            name: 't_testId',         index: 't.testId',       key: true, hidden: true },
                { label: '<input type="checkbox" class="km-chk-all" title="Sayfadakileri seç / seçimi kaldır" aria-label="Tümünü seç">', name: 'chk',              index: 'chk',            width: 34,  align: 'center', sortable: false, formatter: checkFormatter },
                { label: 'Kod',           name: 't_testCode',       index: 't.testCode',     width: 80,  formatter: codeFormatter },
                { label: 'Test',          name: 't_testName',       index: 't.testName',     width: 280, formatter: nameFormatter },
                { label: 'Yöntem',        name: 't_costMethod',     index: 't.costMethod',   width: 90,  formatter: methodFormatter },
                { label: 'Kit',           name: 'suggestions',      index: 'suggestions',    width: 340, sortable: false, formatter: kitFormatter },
                { label: 'Uyum',          name: 'score',            index: 'score',          width: 90,  formatter: matchFormatter },
                { label: 'Hasta başı',    name: 'qty',              index: 'qty',            width: 80,  align: 'right', sortable: false, formatter: qtyFormatter }
            ],
            pager: '#jqGridPager',
            fluidHeight: false,
            height: 'auto',
            rowNum: 25,
            rowList: [25, 50, 100],
            // Satıra tıklamak seçmez: seçim kutuyla (satırdaki kit / miktar kutularına tıklarken satır seçilmesin)
            beforeSelectRow: function () { return false; },
            // Kit listesi satırlar çizilmeden önce alınır: kitFormatter "Tüm kitler" grubunu $d.kits'ten doldurur
            // (loadComplete satırlar çizildikten sonra çalışır, orada alınırsa ilk açılışta grup boş kalır)
            beforeProcessing: function (data) {
                if (data && data['resObject'] && data['resObject']['kits']) { $d.kits = data['resObject']['kits']; }
            },
            loadComplete: function (data) {
                if (!data || data['resObject'] === undefined) { return; }   // ilk (boş, local) kurulum
                const obj = data['resObject'] || {};
                const c = obj['counts'] || {};
                $('#kmTabs [data-count]').each(function () { $(this).text(c[$(this).data('count')] || 0); });
                $('.km-chk-all').prop('hidden', $d.tab === 'LINKED');   // kit bağlı sekmesinde seçim yok
                $t.renderSelected();

                // Satırdaki kit kutusu: aramalı (select2). Liste her yüklendiğinde satırlar yeniden çizildiği için burada kurulur.
                $('#jqGrid .km-kit').select2({
                    width: '100%',
                    minimumResultsForSearch: 0,   // arama kutusu her zaman görünsün
                    dropdownAutoWidth: true       // uzun kit adları kesilmesin
                });

                let msg = '';
                if (data['resCode'] !== 0 && data['resCode'] !== '0') {
                    msg = data['resMsg'] || 'Liste alınamadı.';
                } else if (!$d.kits.length) {
                    msg = 'Henüz kit yok. Malzemeler ekranında "Kit" kategorisinde malzeme açın ya da fiyat listesini aktarın.';
                } else if (!Number(data['records'])) {
                    msg = 'Bu sekmede test yok.';
                }
                $('#gridMsg').text(msg).prop('hidden', !msg);
            },
            loadError: function () {
                $('#gridMsg').text('Sunucuya ulaşılamadı. Sayfayı yenileyin.').prop('hidden', false);
            }
        });

        // jqGrid başlıktaki tıklamayı (sıralama için) yakalayıp iptal ediyor; kutunun tıklaması başlığa ulaşmasın,
        // yoksa kutu işaretlenmez. Başlık grid kurulunca bir kez çizilir, bağlama burada bir kez yapılır.
        $('.km-chk-all').on('click', function (e) { e.stopPropagation(); });
    },

    // Filtreler postData ile gider; resetPage: filtre değiştiyse 1. sayfaya döner, bağlama sonrası sayfada kalır
    reloadGrid: function (resetPage) {
        const $t = $.DLNFrame;
        const param = {
            datatype: 'json',
            postData: {
                TAB: $t.data.tab,
                COST_METHOD: $('#F_COST_METHOD').val() || '',
                Q: $.trim($('#SEARCH').val())
            }
        };
        if (resetPage) { param.page = 1; }
        $('#jqGrid').jqGrid('setGridParam', param).trigger('reloadGrid');
    },

    /* ---------- Seçim ---------- */

    markRow: function (chk) {
        $(chk).closest('tr').toggleClass('km-row-checked', chk.checked);
    },

    // Seçilebilir satırlar: kutusu olan ve kiti seçili olanlar (öneri yok satırında kit seçilmemişse seçilemez)
    selectableRows: function () {
        return $('#jqGrid tr.jqgrow').filter(function () { return $(this).find('.km-chk').length && $(this).find('.km-kit').val(); });
    },

    allSelected: function () {
        const $rows = $.DLNFrame.selectableRows();
        return $rows.length > 0 && $rows.find('.km-chk:not(:checked)').length === 0;
    },

    // on = true: sayfadaki seçilebilir satırların hepsi işaretlenir; false: sayfadaki bütün seçim kalkar
    selectPage: function (on) {
        const $t = $.DLNFrame;
        const $chks = on ? $t.selectableRows().find('.km-chk') : $('#jqGrid .km-chk');
        $chks.each(function () {
            this.checked = on;
            $t.markRow(this);
        });
        $t.renderSelected();
    },

    // Seçili sayısı, bağla düğmesi ve başlıktaki tümünü seç kutusu (hepsi / bir kısmı / hiçbiri) seçime göre
    renderSelected: function () {
        const $t = $.DLNFrame;
        const n = $('#jqGrid .km-chk:checked').length;
        const all = $t.allSelected();
        $('.js-selected').text(n);
        $('#btnLink').prop('disabled', !n);
        $('.km-chk-all').prop({ checked: all, indeterminate: n > 0 && !all });
    },

    /* ---------- Bağlama ---------- */

    link: function () {
        const $t = $.DLNFrame;
        const items = [];
        let error = '';
        $('#jqGrid tr.jqgrow').each(function () {
            if (!$(this).find('.km-chk').is(':checked')) { return; }
            const code = $(this).find('.km-mono').first().text();
            const materialId = $(this).find('.km-kit').val();
            const qty = $t.parse($(this).find('.km-qty').val());
            if (!materialId) { error = error || code + ': kit seçin.'; return; }
            if (qty === null || qty <= 0) { error = error || code + ': miktar sıfırdan büyük olmalı.'; return; }
            items.push({ testId: this.id, materialId: materialId, quantity: $(this).find('.km-qty').val() });
        });
        if (error) { $.fn.ntyi({ type: 'error', layout: 'topRight', text: error }); return; }
        if (!items.length) { return; }

        $.fn.nty({
            type: 'information',
            text: items.length + ' test seçilen kitlere bağlansın mı? Testlerin reçetesine hasta başı kit satırı yazılır.',
            buttons: [
                {
                    text: 'Evet, bağla',
                    onClick: function ($noty) {
                        $noty.close();
                        $.ajaxq('frame', {
                            url: proxyPath + modulePath + 'setLink',
                            type: 'POST',
                            dataType: 'json',
                            data: { ITEMS: JSON.stringify(items) },
                            beforeSend: function () { $('#btnLink').prop('disabled', true); },
                            success: function (result) {
                                if (result && result['resCode'] === 0) {
                                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                                    $t.reloadGrid(false);   // bağlananlar "Kit bağlı" sekmesine geçer
                                } else {
                                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Bağlanamadı.' });
                                    $t.renderSelected();
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

    unlink: function (testId, materialId, testCode) {
        const $t = $.DLNFrame;
        $.fn.nty({
            type: 'error',
            text: testCode + ' testinin kit bağlantısı kaldırılsın mı? Kit bu testin reçetesinden çıkar.',
            buttons: [
                {
                    text: 'Evet, kaldır',
                    onClick: function ($noty) {
                        $noty.close();
                        $.ajaxq('frame', {
                            url: proxyPath + modulePath + 'setUnlink',
                            type: 'POST',
                            dataType: 'json',
                            data: { TEST_ID: testId, MATERIAL_ID: materialId },
                            success: function (result) {
                                if (result && result['resCode'] === 0) {
                                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                                    $t.reloadGrid(false);
                                } else {
                                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Kaldırılamadı.' });
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

    esc: function (v) { return $('<span>').text(v === null || v === undefined ? '' : v).html(); },

    // "1.234,56" / "0,5" / "2" → sayı ; boş/geçersiz → null
    parse: function (v) {
        v = $.trim(String(v === null || v === undefined ? '' : v)).replace(/\s/g, '');
        if (!v) { return null; }
        if (v.indexOf(',') >= 0) { v = v.replace(/\./g, '').replace(',', '.'); }
        const n = Number(v);
        return isFinite(n) ? n : null;
    },

    // Adet / miktar: tam sayıysa ondalıksız (1), değilse en çok 3 hane (0,5)
    qty: function (v) {
        const n = Number(v);
        if (!isFinite(n)) { return ''; }
        return n.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
    }
};
