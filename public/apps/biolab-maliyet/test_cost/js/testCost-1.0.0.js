/**
 * Maliyet — Test maliyetleri
 *
 * - Üstte maliyet çalışması seçilir; maliyet o çalışmanın fiyat ve kurlarıyla hesaplanır (sunucuda, CostCalculator).
 * - Liste jqGrid'dir ($.fn.jqg → getGridList): durum sekmesi, birim (açılışta Patoloji), hizmet grubu, yöntem ve arama
 *   postData ile gider (reloadGrid). Maliyete göre sıralanabilir.
 * - Satıra tıklayınca getSelectedId ile ayrıntı açılır: reçeteli testte reçete düzenlenir (setRecipe),
 *   hesaplamalı testte bileşenler ve maliyetleri görünür.
 * - "Patoloji Excel": PATOLOJİ sayfası düzeninde, canlı formüllü Excel (getExcelPathology → /api/pdf.php indirmesi).
 * - Seçim kutuları $.fn.slb ile dolar (initX → getX); select id'si alan adıdır.
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-maliyet/test_cost/php/testCost-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','test_cost/testCost-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','test_cost/testCost-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        lang: null,
        tab: 'all',
        searchTimer: null,
        study: null,        // getGridList → resObject.study
        detail: null,       // açık testin ayrıntısı
        lines: []           // düzenlenen reçete: { materialId, materialName, unitName, quantity, unitCost }
    },
    opt: {
        storeKey: 'mlyt.material.studyId',   // malzeme ekranıyla aynı: son seçilen çalışma
        defaultLabUnit: 'PATHOLOGY'          // açılışta patoloji testleri (ilk maliyetlendirilen grup)
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

        $('#STUDY_ID').on('change', function () {
            const id = Number($(this).val()) || null;
            if (id) { $t.storeSet(id); }
            $t.reloadGrid(true);
        });
        $('#tcTabs').on('click', '.tc-tab', function () {
            $d.tab = $(this).data('tab');
            $('#tcTabs .tc-tab').removeClass('active');
            $(this).addClass('active');
            $t.reloadGrid(true);
        });
        $('#F_LAB_UNIT, #F_SERVICE_GROUP_ID, #F_COST_METHOD').on('change', function () { $t.reloadGrid(true); });
        $('#F_LAB_UNIT').on('change', $t.togglePathologyExcel);
        $('#SEARCH').on('input', function () {
            clearTimeout($d.searchTimer);
            $d.searchTimer = setTimeout(function () { $t.reloadGrid(true); }, 300);
        });

        // Reçete düzenleme
        $('.js-recipe-body').on('input', '.tc-qty', function () {
            const i = Number($(this).closest('tr').data('i'));
            $d.lines[i].quantity = $(this).val();
            $t.renderRecipeTotals();
        });
        $('.js-recipe-body').on('click', '.btnRemoveLine', function () {
            $d.lines.splice(Number($(this).closest('tr').data('i')), 1);
            $t.renderRecipe();
        });
        $('#btnAddLine').on('click', $t.addLine);
        $('#ADD_QTY').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $t.addLine(); } });
        $('#btnRecipeSave').on('click', $t.saveRecipe);
        $('#btnExcelPathology').on('click', $t.exportPathology);
        $('#modalCost').on('hidden.bs.modal', function () { $('#jqGrid').jqGrid('resetSelection'); });
    },

    start: function () {
        const $t = $.DLNFrame;
        $t.initGridList();
        $t.initF_LAB_UNIT();
        $t.initF_SERVICE_GROUP_ID();
        $t.initF_COST_METHOD();
        $t.initMATERIAL_ID();
        $t.initSTUDY_ID();      // seçilince (change) liste yüklenir
    },

    /* ---------- Seçim kutuları ---------- */

    initSTUDY_ID: function () {
        const $t = $.DLNFrame;
        $('#STUDY_ID').slb({
            field:{ value:'studyId', text:'studyText', disabledFL:'activeFl' },
            select2:{ placeholder:'Henüz çalışma yok', allowClear:false, minimumResultsForSearch:-1 },
            ajax:{ url:proxyPath + modulePath + 'getSTUDY_ID' },
            event:{
                afterLoad:function (el) {
                    const $el = $(el);
                    let id = $t.storeGet();
                    if (!id || !$el.find('option[value="' + id + '"]').length) { id = $el.find('option[value!=""]').first().val() || ''; }
                    $el.val(id).change();
                }
            }
        });
    },

    initF_LAB_UNIT: function () {
        const $t = $.DLNFrame;
        $('#F_LAB_UNIT').slb({
            field:{ value:'labUnit', text:'labUnitName', disabledFL:'activeFl' },
            select2:{ placeholder:'Tüm birimler', allowClear:true, minimumResultsForSearch:-1 },
            ajax:{ url:proxyPath + modulePath + 'getLAB_UNIT' },
            event:{
                // Açılışta patoloji; değer change tetiklemeden verilir (ilk liste çalışma seçilince gelir)
                afterLoad:function (el) { $(el).val($t.opt.defaultLabUnit).trigger('change.select2'); $t.togglePathologyExcel(); }
            }
        });
    },

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

    initMATERIAL_ID: function () {
        $('#MATERIAL_ID').slb({
            field:{ value:'materialId', text:'materialText', disabledFL:'activeFl' },
            select2:{ placeholder:'Eklenecek malzemeyi seçin', allowClear:true },
            ajax:{ url:proxyPath + modulePath + 'getMATERIAL_ID' }
        });
    },

    /* ---------- Liste ---------- */

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const esc = $t.esc;

        function codeFormatter(cellvalue) { return '<span class="tc-mono">' + esc(cellvalue) + '</span>'; }
        // Ad + patolojide Excel notu
        function nameFormatter(cellvalue, options, r) {
            return esc(cellvalue) + (r['t_sourceMaterialText'] ? '<div class="tc-sub">' + esc(r['t_sourceMaterialText']) + '</div>' : '');
        }
        function methodFormatter(cellvalue, options, r) { return esc(r['t_costMethodName']); }
        function costFormatter(cellvalue) {
            return cellvalue === null || cellvalue === undefined ? '<span class="tc-sub">—</span>' : '<strong class="tc-mono">' + $t.fmt(cellvalue, 2) + '</strong>';
        }
        // Durum + ilk eksik (fare üstünde hepsi)
        function statusFormatter(cellvalue, options, r) {
            const miss = r['missing'] || [];
            const more = r['missingCount'] > miss.length ? ' (+' + (r['missingCount'] - miss.length) + ')' : '';
            return '<span class="tc-badge tc-s-' + esc(cellvalue) + '" title="' + esc(miss.join('\n')) + '">' + esc(r['statusText']) + '</span>'
                + (miss.length && cellvalue !== 'OK' ? '<div class="tc-sub">' + esc(miss[0]) + more + '</div>' : '');
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            datatype: 'local',          // ilk istek çalışma seçilince
            sortname: 't.testName',
            sortorder: 'asc',
            colModel: [
                { label: 'ID',            name: 't_testId',           index: 't.testId',           key: true, hidden: true },
                { label: 'Kod',           name: 't_testCode',         index: 't.testCode',         width: 80,  formatter: codeFormatter },
                { label: 'Test',          name: 't_testName',         index: 't.testName',         width: 340, formatter: nameFormatter },
                { label: 'Hizmet grubu',  name: 'g_serviceGroupName', index: 'g.serviceGroupName', width: 140 },
                { label: 'Yöntem',        name: 't_costMethod',       index: 't.costMethod',       width: 100, formatter: methodFormatter },
                { label: 'Maliyet (TL)',  name: 'cost',               index: 'cost',               width: 100, align: 'right', formatter: costFormatter },
                { label: 'Durum',         name: 'status',             index: 'status',             width: 200, formatter: statusFormatter }
            ],
            pager: '#jqGridPager',
            fluidHeight: false,
            height: 'auto',
            rowNum: 25,
            rowList: [25, 50, 100],
            onSelectRow: function (rowid) { $t.getSelectedId(rowid); },
            loadComplete: function (data) {
                if (!data || data['resObject'] === undefined) { return; }   // ilk (boş, local) kurulum
                const obj = data['resObject'] || {};
                $d.study = obj['study'] || null;
                const c = obj['counts'] || {};
                $('#tcTabs [data-count]').each(function () { $(this).text(c[$(this).data('count')] || 0); });
                $('.js-total').text(obj['total'] !== undefined ? $t.fmt(obj['total'], 2) : '—');

                let msg = '';
                if (data['resCode'] !== 0 && data['resCode'] !== '0') {
                    msg = data['resMsg'] || 'Liste alınamadı.';
                } else if (!$d.study) {
                    msg = 'Maliyet hesaplamak için önce Malzemeler ekranında bir maliyet çalışması açın ve fiyatları girin.';
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

    reloadGrid: function (resetPage) {
        const $t = $.DLNFrame;
        const param = {
            datatype: 'json',
            postData: {
                STUDY_ID: $('#STUDY_ID').val() || '',
                TAB: $t.data.tab,
                LAB_UNIT: $('#F_LAB_UNIT').val() || '',
                SERVICE_GROUP_ID: $('#F_SERVICE_GROUP_ID').val() || '',
                COST_METHOD: $('#F_COST_METHOD').val() || '',
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
            data: { TEST_ID: testId, STUDY_ID: $('#STUDY_ID').val() || '' },
            success: function (result) {
                if (result && result['resCode'] === 0 && result['records']) {
                    $t.openDetail(result['records']);
                    $('#modalCost').modal('show');
                } else {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Kayıt alınamadı.' });
                }
            }
        });
    },

    /* ---------- Ayrıntı ---------- */

    openDetail: function (d) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $d.detail = d;

        $('#TEST_ID').val(d.testId);
        $('#modalCostTitle').text(d.testCode + ' · ' + d.testName);
        $('.js-cost').text(d.cost === null ? '—' : $t.fmt(d.cost, 2));
        $('.js-status').attr('class', 'tc-badge js-status tc-s-' + d.status).text(d.statusText);
        $('.js-meta').text([d.serviceGroupName, d.costMethodName, d.pathologyLevel ? d.pathologyLevel + '. düzey' : null,
            d.studyName ? 'Çalışma: ' + d.studyName : 'Çalışma seçilmedi'].filter(Boolean).join(' · '));

        const $miss = $('.js-missing').empty();
        (d.missing || []).forEach(function (m) { $miss.append($('<li>').text(m)); });
        $miss.prop('hidden', !(d.missing || []).length || d.status === 'OK');

        // Reçete (düzenlenebilir) ya da bileşenler
        $('.js-recipe').prop('hidden', !d.recipeEditable);
        $('.js-components').prop('hidden', d.recipeEditable);
        $('#btnRecipeSave').prop('hidden', !d.recipeEditable);

        if (d.recipeEditable) {
            $d.lines = (d.lines || []).filter(function (l) { return l.type === 'MATERIAL'; }).map(function (l) {
                return { materialId: l.materialId, materialName: l.materialName, unitName: l.unitName,
                         quantity: $t.qty(l.quantity), unitCost: l.unitCost, reason: l.reason };
            });
            $t.renderRecipe();
            $('#MATERIAL_ID').val('').change();
            $('#ADD_QTY').val('1');
        } else {
            const $body = $('.js-comp-body').empty();
            (d.lines || []).forEach(function (l) {
                $body.append($('<tr>').append(
                    $('<td class="tc-mono">').text(l.testCode),
                    $('<td>').text(l.testName),
                    $('<td class="tc-num">').text($t.qty(l.quantity)),
                    $('<td class="tc-num">').text($t.fmt(l.applyRate, 2)),
                    $('<td class="tc-num">').text(l.unitCost === null ? '—' : $t.fmt(l.unitCost, 2)),
                    $('<td class="tc-num">').text(l.amount === null ? '—' : $t.fmt(l.amount, 2)),
                    $('<td>').append($('<span class="tc-badge">').addClass('tc-s-' + l.status).text($t.statusLabel(l.status)))));
            });
            if (!(d.lines || []).length) {
                $body.append('<tr><td colspan="7" class="tc-sub">Bileşen tanımlı değil.</td></tr>');
            }
        }

        $('.js-source').html(d.sourceMaterialText ? '<i class="fas fa-file-excel"></i> Excel\'deki malzeme notu: <b>' + $t.esc(d.sourceMaterialText) + '</b>' : '')
            .prop('hidden', !d.sourceMaterialText);
        $('#costMsg').prop('hidden', true).text('');
    },

    renderRecipe: function () {
        const $t = $.DLNFrame;
        const $body = $('.js-recipe-body').empty();
        $t.data.lines.forEach(function (l, i) {
            $body.append($('<tr>').attr('data-i', i).append(
                $('<td>').text(l.materialName),
                $('<td class="text-right">').append($('<input type="text" class="form-control form-control-sm tc-mono tc-qty" inputmode="decimal">').val(l.quantity)),
                $('<td>').text(l.unitName || ''),
                $('<td class="tc-num">').html(l.unitCost === null || l.unitCost === undefined
                    ? '<span class="tc-sub" title="' + $t.esc(l.reason || '') + '">' + (l.isNew ? 'kaydedince' : $t.esc(l.reason || '—')) + '</span>'
                    : $t.esc($t.fmt(l.unitCost, 4))),
                $('<td class="tc-num js-amount">'),
                $('<td class="text-right">').append('<button type="button" class="btn btn-sm btn-outline-danger btnRemoveLine" title="Çıkar"><i class="fas fa-times"></i></button>')));
        });
        if (!$t.data.lines.length) {
            $body.append('<tr><td colspan="6" class="tc-sub">Reçete boş. Aşağıdan malzeme ekleyin.</td></tr>');
        }
        $t.renderRecipeTotals();
    },

    // Miktar değiştikçe satır tutarı ve toplam (fiyatı bilinen satırlar)
    renderRecipeTotals: function () {
        const $t = $.DLNFrame;
        let total = 0, partial = false;
        $('.js-recipe-body tr[data-i]').each(function () {
            const l = $t.data.lines[Number($(this).data('i'))];
            const q = $t.parse(l.quantity);
            const amount = (q !== null && l.unitCost !== null && l.unitCost !== undefined) ? q * l.unitCost : null;
            if (amount === null) { partial = true; } else { total += amount; }
            $(this).find('.js-amount').text(amount === null ? '—' : $t.fmt(amount, 2));
        });
        $('.js-recipe-total').text($t.fmt(total, 2) + (partial ? ' (eksik)' : ''));
    },

    addLine: function () {
        const $t = $.DLNFrame;
        const mid = Number($('#MATERIAL_ID').val());
        const q = $t.parse($('#ADD_QTY').val());
        if (!mid) { $('#costMsg').text('Eklenecek malzemeyi seçin.').prop('hidden', false); return; }
        if (q === null || q <= 0) { $('#costMsg').text('Miktar sıfırdan büyük olmalı.').prop('hidden', false); return; }
        if ($t.data.lines.some(function (l) { return l.materialId === mid; })) {
            $('#costMsg').text('Bu malzeme reçetede zaten var; miktarını değiştirin.').prop('hidden', false); return;
        }
        // "DOKU TAKİP KASETİ (adet)" → ad ve birim
        const text = $('#MATERIAL_ID option:selected').text();
        const m = text.match(/^(.*)\s\(([^()]*)\)$/);
        $t.data.lines.push({ materialId: mid, materialName: m ? m[1] : text, unitName: m ? m[2] : '',
                             quantity: $('#ADD_QTY').val(), unitCost: null, isNew: true });
        $('#costMsg').prop('hidden', true);
        $('#MATERIAL_ID').val('').change();
        $('#ADD_QTY').val('1');
        $t.renderRecipe();
    },

    saveRecipe: function () {
        const $t = $.DLNFrame;
        const items = $t.data.lines.map(function (l) { return { materialId: l.materialId, quantity: l.quantity }; });
        for (let i = 0; i < items.length; i++) {
            const q = $t.parse(items[i].quantity);
            if (q === null || q <= 0) { $('#costMsg').text((i + 1) + '. satır: miktar sıfırdan büyük olmalı.').prop('hidden', false); return; }
        }
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setRecipe',
            type: 'POST',
            dataType: 'json',
            data: { TEST_ID: $('#TEST_ID').val(), STUDY_ID: $('#STUDY_ID').val() || '', ITEMS: JSON.stringify(items) },
            beforeSend: function () { $('#btnRecipeSave').prop('disabled', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    $t.openDetail(result['resObject']);   // yeniden hesaplanmış hali
                    $t.reloadGrid(false);
                } else {
                    $('#costMsg').text((result && result['resMsg']) || 'Kaydedilemedi.').prop('hidden', false);
                }
            },
            complete: function () { $('#btnRecipeSave').prop('disabled', false); }
        });
    },

    // "Patoloji Excel" yalnız birim filtresi Patoloji iken görünür (Biolab / tüm birimlerde anlamı yok)
    togglePathologyExcel: function () {
        $('#btnExcelPathology').prop('hidden', $('#F_LAB_UNIT').val() !== 'PATHOLOGY');
    },

    // Patoloji maliyet Excel'i: sunucu dosyayı hazırlar, indirme adresi (/api/pdf.php?t=…) döner
    exportPathology: function () {
        const $btn = $('#btnExcelPathology');
        if (!$('#STUDY_ID').val()) {
            $.fn.ntyi({ type: 'error', layout: 'topRight', text: 'Excel için bir maliyet çalışması seçin.' });
            return;
        }
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getExcelPathology',
            type: 'POST',
            dataType: 'json',
            data: { STUDY_ID: $('#STUDY_ID').val() },
            beforeSend: function () { $btn.prop('disabled', true).find('i').attr('class', 'fas fa-circle-notch fa-spin'); },
            success: function (result) {
                if (result && result['resCode'] === 0 && result['resObject'] && result['resObject']['url']) {
                    window.location.href = result['resObject']['url'];   // ek olarak gelir; sayfa değişmez
                } else {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Excel hazırlanamadı.' });
                }
            },
            error: function () { $.fn.ntyi({ type: 'error', layout: 'topRight', text: 'Sunucuya ulaşılamadı.' }); },
            complete: function () { $btn.prop('disabled', false).find('i').attr('class', 'fas fa-file-excel'); }
        });
    },

    /* ---------- Yardımcılar ---------- */

    statusLabel: function (s) {
        return { OK: 'Hesaplandı', MISSING_PRICE: 'Fiyatı eksik', NO_RECIPE: 'Reçete / kit yok', COMPONENT_MISSING: 'Bileşeni eksik',
                 NEEDS_VOLUME: 'Hacim gerekli', CYCLE: 'Bileşen döngüsü' }[s] || s;
    },

    esc: function (v) { return $('<span>').text(v === null || v === undefined ? '' : v).html(); },

    // "1.234,56" / "0,5" / "2" → sayı ; boş/geçersiz → null
    parse: function (v) {
        v = $.trim(String(v === null || v === undefined ? '' : v)).replace(/\s/g, '');
        if (!v) { return null; }
        if (v.indexOf(',') >= 0) { v = v.replace(/\./g, '').replace(',', '.'); }
        const n = Number(v);
        return isFinite(n) ? n : null;
    },

    fmt: function (v, maxDigits) {
        const n = Number(v);
        if (v === null || v === undefined || !isFinite(n)) { return ''; }
        return n.toLocaleString('tr-TR', { minimumFractionDigits: Math.min(2, maxDigits), maximumFractionDigits: maxDigits });
    },

    // Adet / miktar (reçete miktarı, bileşen adedi): tam sayıysa ondalıksız (2), değilse en çok 4 hane (0,5)
    qty: function (v) {
        const n = Number(v);
        if (v === null || v === undefined || !isFinite(n)) { return ''; }
        return n.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 4 });
    },

    storeGet: function () {
        try { return localStorage.getItem($.DLNFrame.opt.storeKey); } catch (e) { return null; }
    },
    storeSet: function (v) {
        try { localStorage.setItem($.DLNFrame.opt.storeKey, String(v)); } catch (e) { /* gizli pencere vb. */ }
    }
};
