/**
 * Maliyet — Aylık takip (fire / kayıp / tekrar, Aşama 1)
 *
 * - Üstte dönem (ay) ve maliyet çalışması seçilir; "Yeni dönem" ayı açar (setPeriod). Çalışma değişince setPeriodStudy.
 * - 1. Dosyalar: dört kaynak kartı (opt.sources). Dosya sürükle-bırak ya da tıkla → setUpload (FormData). Numune listeleri
 *   LabAsistan'da seçilen birimle yüklenir (birim başına bir dosya). Aynı kaynak yeniden yüklenirse eskisinin yerini alır.
 * - 2. Rapor: getSummary (özet kutuları, numune ve Netsis birim tabloları), tekrar listesi (jqGrid → getRepeatList),
 *   Netsis malzeme listesi (jqGrid → getNetsisList). Excel: getExcel → /api/pdf.php adresine gidilir.
 * - Seçim kutuları $.fn.slb ile dolar (initX → getX); select id'si alan adıdır. Onaylar $.fn.nty ile.
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-maliyet/monthly/php/monthly-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','monthly/monthly-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','monthly/monthly-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        lang: null,
        periodId: null,
        studyId: null,
        files: [],
        step: 'files',
        view: 'repeat',
        repeatFilter: 'ALL',
        fireCategory: 'ALL',
        summary: null,
        institutions: [],
        gridsReady: false,
        timers: {}
    },
    opt: {
        storeKey: 'mlyt.monthly.period',
        // Kaynak kartları: kod, başlık, kim / nereden, ikon, birim seçilerek mi yüklenir
        sources: [
            { code: 'NETSIS_OUT', title: 'Depodan çıkan malzeme', who: 'Satın alma · Netsis › Depodan çıkan malzeme listesi', icon: 'fas fa-truck-loading', perUnit: false,
              hint: 'Çok aylık dosya da yüklenebilir: yalnız bu ayın satırları alınır.' },
            { code: 'LAB_TEST_COUNT', title: 'Test sayıları (tekrarlı)', who: 'LabAsistan › İstatistik › Test Sayıları', icon: 'fas fa-vials', perUnit: false,
              hint: 'Kayıt Tarihi, Birimin Çalıştığı, "Tekrarları Göster" işaretli.' },
            { code: 'LAB_NOT_ACCEPTED', title: 'Kabul edilmeyen numuneler', who: 'LabAsistan › Hastalar · Kabul Edilmeyen, Red: Hepsi', icon: 'fas fa-ban', perUnit: true,
              hint: 'Önce LabAsistan\'da seçtiğiniz birimi seçin; birim boşsa "Tüm birimler".' },
            { code: 'LAB_REJECTED', title: 'Reddedilen numuneler', who: 'LabAsistan › Hastalar · Kabul: Hepsi, Reddedilen', icon: 'fas fa-times-circle', perUnit: true,
              hint: 'Önce LabAsistan\'da seçtiğiniz birimi seçin; birim boşsa "Tüm birimler".' },
            { code: 'FIRE_REPORT', title: 'Fire / zayi bildirimi', who: 'Şubeler ve PCR · satın alma üzerinden gelen bildirim formu', icon: 'fas fa-trash-alt', perUnit: true,
              unitQuery: 'getFIRE_UNIT', unitPlaceholder: 'Formun birimi (şube ya da PCR)',
              hint: 'Önce formun birimini seçin: tek şube, PCR ya da birleştirilmiş form için "Tüm şubeler". Boş form "bu ay bildirim yok" demektir.' }
        ],
        fireCategories: { FIRE: 'Fire', ZAYI: 'Zayi', SUPPLIER: 'Tedarikçi kaynaklı', OTHER: 'Diğer' },
        typeNames: { CUSTOMER: 'Kurumlar (cari)', OSGB: 'OSGB', BRANCH: 'Şubeler', LAB: 'Kahramanlar lab / kan alma', STORE: 'Ana depo', OTHER: 'Diğer' }
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

        $('#PERIOD_ID').on('change', function () {
            $d.periodId = $(this).val() || null;
            $t.storeSet($d.periodId);
            $t.loadPeriod();
        });
        $('#STUDY_ID').on('change', function () {
            const id = $(this).val() || '';
            if (!$d.periodId || String(id) === String($d.studyId || '')) { return; }
            $t.saveStudy(id);
        });
        $('#btnNewPeriod').on('click', $t.openPeriodModal);
        $('#formPeriod').on('submit', function (e) { e.preventDefault(); $t.savePeriod(); });
        $('#btnExcel').on('click', $t.exportExcel);

        // Adımlar ve rapor sekmeleri
        $('.mt-steps').on('click', '.mt-step', function () { $t.showStep($(this).data('step')); });
        $('#reportTabs').on('click', '.mt-tab', function () { $t.showView($(this).data('view')); });
        $('#repeatFilter').on('click', '.mt-tab', function () {
            $d.repeatFilter = $(this).data('filter');
            $('#repeatFilter .mt-tab').removeClass('active');
            $(this).addClass('active');
            $t.reloadRepeat(true);
        });
        $('#REPEAT_Q').on('input', function () { $t.debounce('repeat', function () { $t.reloadRepeat(true); }); });
        $('#NETSIS_Q').on('input', function () { $t.debounce('netsis', function () { $t.reloadNetsis(true); }); });
        $('#DEPOT_TYPE').on('change', function () { $t.reloadNetsis(true); });
        $('#INST_Q').on('input', function () { $t.debounce('inst', $t.renderInstitutions); });
        $('#fireFilter').on('click', '.mt-tab', function () {
            $d.fireCategory = $(this).data('filter');
            $('#fireFilter .mt-tab').removeClass('active');
            $(this).addClass('active');
            $t.reloadFire(true);
        });
        $('#FIRE_Q').on('input', function () { $t.debounce('fire', function () { $t.reloadFire(true); }); });

        // Kaynak kartları: dosya seçme, sürükle-bırak, silme (kartlar her yüklemede yeniden çizilir → document'e bağlanır)
        const $cards = $('#sourceCards');
        $cards.on('click', '.mt-drop', function (e) {
            if (e.target.tagName !== 'INPUT') { $(this).find('input[type=file]').trigger('click'); }
        });
        $cards.on('change', '.mt-drop input[type=file]', function () {
            if (this.files && this.files[0]) { $t.upload($(this).closest('.mt-src'), this.files[0]); }
            this.value = '';
        });
        $cards.on('dragover dragenter', '.mt-drop', function (e) { e.preventDefault(); $(this).addClass('is-over'); });
        $cards.on('dragleave drop', '.mt-drop', function (e) { e.preventDefault(); $(this).removeClass('is-over'); });
        $cards.on('drop', '.mt-drop', function (e) {
            const files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
            if (files && files[0]) { $t.upload($(this).closest('.mt-src'), files[0]); }
        });
        $cards.on('click', '.btnDeleteFile', function () { $t.deleteFile($(this).data('id'), $(this).data('name')); });
    },

    start: function () {
        const $t = $.DLNFrame;
        $t.renderSources();
        $t.initSTUDY_ID();
        $t.initNEW_STUDY_ID();
        $t.initDEPOT_TYPE();
        $t.initPERIOD_ID(null);
    },

    /* ---------- Seçim kutuları ---------- */

    // selectId: yeni açılan dönem (yoksa son seçilen ya da en yeni)
    initPERIOD_ID: function (selectId) {
        const $t = $.DLNFrame;
        $('#PERIOD_ID').slb({
            field:{ value:'periodId', text:'periodText', disabledFL:'activeFl' },
            select2:{ placeholder:'Dönem yok', allowClear:false, minimumResultsForSearch:-1, width:'100%' },
            ajax:{ url:proxyPath + modulePath + 'getPERIOD_ID' },
            event:{
                afterLoad:function (el) {
                    const $el = $(el);
                    let id = selectId || $t.storeGet();
                    if (!id || !$el.find('option[value="' + id + '"]').length) { id = $el.find('option[value!=""]').first().val() || ''; }
                    $el.val(id).trigger('change');
                    if (!id) { $t.showPeriod(false); }
                }
            }
        });
    },

    initSTUDY_ID: function () {
        $('#STUDY_ID').slb({
            field:{ value:'studyId', text:'studyText', disabledFL:'activeFl' },
            select2:{ placeholder:'Çalışma seçin', allowClear:false, minimumResultsForSearch:-1, width:'100%' },
            ajax:{ url:proxyPath + modulePath + 'getSTUDY_ID' }
        });
    },

    initNEW_STUDY_ID: function () {
        $('#NEW_STUDY_ID').slb({
            field:{ value:'studyId', text:'studyText', disabledFL:'activeFl' },
            select2:{ placeholder:'Çalışma seçin', allowClear:true, minimumResultsForSearch:-1, width:'100%' },
            ajax:{ url:proxyPath + modulePath + 'getSTUDY_ID' },
            event:{ afterLoad:function (el) { $(el).val($(el).find('option[value!=""]').first().val() || '').trigger('change'); } }
        });
    },

    initDEPOT_TYPE: function () {
        $('#DEPOT_TYPE').slb({
            field:{ value:'depotType', text:'depotTypeName', disabledFL:'activeFl' },
            select2:{ placeholder:'Tüm birimler', allowClear:true, minimumResultsForSearch:-1, width:'240px' },
            ajax:{ url:proxyPath + modulePath + 'getDEPOT_TYPE' }
        });
    },

    // Kartlardaki birim kutuları (numune listeleri): LabAsistan'da listeyi alırken seçilen birim. Birim boş bırakıldıysa
    // "Tüm birimler". Kendiliğinden seçilmez: yanlış birimle yüklenmesin diye kullanıcı seçer.
    initUNIT_NAME: function ($sel, source) {
        const s = source || {};
        $sel.slb({
            field:{ value:'unitName', text:'unitText', disabledFL:'activeFl' },
            select2:{ placeholder: s.unitPlaceholder || 'LabAsistan\'da seçtiğiniz birim', allowClear:false, minimumResultsForSearch: s.unitQuery ? 0 : -1, width:'100%' },
            ajax:{ url:proxyPath + modulePath + (s.unitQuery || 'getUNIT_NAME') }
        });
    },

    /* ---------- Dönem ---------- */

    showPeriod: function (has) {
        $('#periodArea').prop('hidden', !has);
        $('#noPeriod').prop('hidden', has);
        $('#btnExcel').prop('disabled', !has);
    },

    loadPeriod: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$d.periodId) { $t.showPeriod(false); return; }
        $t.showPeriod(true);
        $t.loadFiles(function () {
            if ($d.step === 'report') { $t.loadReport(); }
        });
    },

    openPeriodModal: function () {
        // Varsayılan: geçen ay (rapor genelde ayın ilk haftası, önceki ay için hazırlanır)
        $('#PERIOD_YM').val(moment().subtract(1, 'month').format('YYYY-MM')).attr('max', moment().format('YYYY-MM'));
        $('#modalPeriod').modal('show');
    },

    savePeriod: function () {
        const $t = $.DLNFrame;
        const ym = $('#PERIOD_YM').val();
        if (!/^\d{4}-\d{2}$/.test(ym || '')) { $.fn.ntyi({ type: 'error', layout: 'topRight', text: 'Ay seçin.' }); return; }
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setPeriod',
            type: 'POST',
            dataType: 'json',
            data: { PERIOD_YM: ym, STUDY_ID: $('#NEW_STUDY_ID').val() || '' },
            beforeSend: function () { $('#btnSavePeriod').prop('disabled', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $('#modalPeriod').modal('hide');
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    $t.showStep('files');
                    $t.initPERIOD_ID(result['resObject']['periodId']);
                } else {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Dönem açılamadı.' });
                }
            },
            complete: function () { $('#btnSavePeriod').prop('disabled', false); }
        });
    },

    saveStudy: function (studyId) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setPeriodStudy',
            type: 'POST',
            dataType: 'json',
            data: { PERIOD_ID: $d.periodId, STUDY_ID: studyId },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $d.studyId = studyId;
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    if ($d.step === 'report') { $t.loadReport(); }
                } else {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Kaydedilemedi.' });
                }
            }
        });
    },

    /* ---------- 1. Dosyalar ---------- */

    renderSources: function () {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        const html = $t.opt.sources.map(function (s) {
            return '<article class="mt-src" data-source="' + s.code + '">'
                + '<div class="mt-src-head"><div class="mt-src-icon"><i class="' + s.icon + '" aria-hidden="true"></i></div>'
                + '<div><h3 class="mt-src-title">' + esc(s.title) + '</h3><p class="mt-src-who">' + esc(s.who) + '</p></div>'
                + '<div class="mt-src-state js-state"></div></div>'
                + '<div class="mt-src-body"><div class="js-files"></div>'
                + (s.perUnit ? '<div class="mt-unit-row"><div class="mt-unit"><select class="js-unit" aria-label="LabAsistan birimi"></select></div></div>' : '')
                + '</div>'
                + '<label class="mt-drop"><input type="file" accept=".xlsx"><i class="fas fa-cloud-upload-alt" aria-hidden="true"></i> '
                + 'Excel\'i sürükleyin ya da <strong>dosya seçin</strong><div class="mt-src-who">' + esc(s.hint) + '</div></label>'
                + '</article>';
        }).join('');
        $('#sourceCards').html(html);
        $('#sourceCards .js-unit').each(function () {
            const code = $(this).closest('.mt-src').data('source');
            $t.initUNIT_NAME($(this), $t.opt.sources.filter(function (x) { return x.code === code; })[0]);
        });
    },

    loadFiles: function (done) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getFiles',
            type: 'POST',
            dataType: 'json',
            data: { PERIOD_ID: $d.periodId },
            success: function (result) {
                if (!result || result['resCode'] !== 0) {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Dosyalar alınamadı.' });
                    return;
                }
                const obj = result['resObject'];
                $d.files = obj['files'] || [];
                $d.studyId = obj['studyId'] || null;
                $('#STUDY_ID').val($d.studyId ? String($d.studyId) : '').trigger('change.select2');
                $t.renderFiles();
                if (typeof done === 'function') { done(); }
            }
        });
    },

    renderFiles: function () {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        let ready = 0;
        $t.opt.sources.forEach(function (s) {
            const files = $t.data.files.filter(function (f) { return f.sourceCode === s.code; });
            const $card = $('#sourceCards .mt-src[data-source="' + s.code + '"]');
            if (files.length) { ready++; }
            // "ℹ " ile başlayan satırlar bilgi notudur (dönem dışı satır vb.): uyarı sayılmaz, soluk gösterilir
            const warn = files.some(function (f) { return (f.warnings || []).some(function (w) { return !$t.isInfo(w); }); });
            $card.find('.js-state').html(files.length
                ? '<span class="mt-chip ' + (warn ? 'mt-chip-warn' : 'mt-chip-ok') + '"><i class="fas ' + (warn ? 'fa-exclamation-triangle' : 'fa-check') + '"></i> '
                    + (s.perUnit ? files.length + ' birim' : 'Yüklendi') + '</span>'
                : '<span class="mt-chip mt-chip-wait"><i class="far fa-clock"></i> Bekleniyor</span>');
            $card.find('.js-files').html(files.map(function (f) {
                const range = f.dateFrom ? f.dateFrom + (f.dateTo && f.dateTo !== f.dateFrom ? ' – ' + f.dateTo : '') : '';
                return '<div class="mt-file"><i class="far fa-file-excel" style="margin-top:3px;color:#1e6b3a" aria-hidden="true"></i><div class="mt-file-main">'
                    + '<div class="mt-file-name">' + (s.perUnit ? '<span class="mt-type mt-type-BRANCH">' + esc(f.unitName) + '</span> ' : '') + esc(f.fileName) + '</div>'
                    + '<div class="mt-file-meta">' + esc($t.int(f.rowCount)) + ' satır' + (range ? ' · ' + esc(range) : '') + ' · ' + esc(f.cAt || '') + '</div>'
                    + (f.warnings && f.warnings.length ? '<ul class="mt-file-warn">' + f.warnings.map(function (w) {
                        return $t.isInfo(w) ? '<li class="mt-file-info">' + esc(w.replace(/^ℹ\s*/, '')) + '</li>' : '<li>' + esc(w) + '</li>';
                    }).join('') + '</ul>' : '')
                    + '</div><button type="button" class="btn btn-link btn-sm btnDeleteFile" title="Dosyayı sil" data-id="' + f.fileId + '" data-name="' + esc(f.fileName) + '">'
                    + '<i class="far fa-trash-alt"></i></button></div>';
            }).join(''));
        });
        $('.js-file-count').text(ready);
    },

    upload: function ($card, file) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const source = $card.data('source');
        if (!/\.xlsx$/i.test(file.name)) {
            $.fn.ntyi({ type: 'error', layout: 'topRight', text: /\.xlsb?$/i.test(file.name)
                ? 'Eski Excel biçimi: dosyayı Excel\'de açıp Farklı Kaydet → "Excel Çalışma Kitabı (.xlsx)" olarak kaydedin.'
                : 'Yalnız .xlsx dosyası yüklenebilir.' });
            return;
        }
        const s = $t.opt.sources.filter(function (x) { return x.code === source; })[0];
        if (s && s.perUnit && !$card.find('.js-unit').val()) {
            $.fn.ntyi({ type: 'error', layout: 'topRight', text: 'Önce LabAsistan\'da listeyi alırken seçtiğiniz birimi seçin (birim boşsa "Tüm birimler").' });
            return;
        }
        const fd = new FormData();
        fd.append('PERIOD_ID', $d.periodId);
        fd.append('SOURCE_CODE', source);
        fd.append('UNIT_NAME', $card.find('.js-unit').val() || '');
        fd.append('FILE', file, file.name);
        const $drop = $card.find('.mt-drop');
        const label = $drop.html();
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setUpload',
            type: 'POST',
            dataType: 'json',
            data: fd,
            processData: false,
            contentType: false,
            beforeSend: function () { $drop.addClass('is-busy').html('<i class="fas fa-circle-notch fa-spin"></i> ' + $t.esc(file.name) + ' okunuyor…'); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    $t.loadFiles();
                } else {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', timeout: 8000, text: (result && result['resMsg']) || 'Dosya yüklenemedi.' });
                }
            },
            error: function () { $.fn.ntyi({ type: 'error', layout: 'topRight', text: 'Sunucuya ulaşılamadı.' }); },
            complete: function () { $drop.removeClass('is-busy').html(label); }
        });
    },

    deleteFile: function (fileId, name) {
        const $t = $.DLNFrame;
        $.fn.nty({
            type: 'error',
            text: name + ' silinsin mi? Bu dosyadan okunan satırlar rapordan çıkar.',
            buttons: [
                {
                    text: 'Evet, sil',
                    onClick: function ($noty) {
                        $noty.close();
                        $.ajaxq('frame', {
                            url: proxyPath + modulePath + 'setDeleteFile',
                            type: 'POST',
                            dataType: 'json',
                            data: { FILE_ID: fileId },
                            success: function (result) {
                                if (result && result['resCode'] === 0) {
                                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                                    $t.loadFiles();
                                } else {
                                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Silinemedi.' });
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

    /* ---------- 2. Rapor ---------- */

    showStep: function (step) {
        const $t = $.DLNFrame;
        $t.data.step = step;
        $('.mt-steps .mt-step').removeClass('active').filter('[data-step="' + step + '"]').addClass('active');
        $('#stepFiles').prop('hidden', step !== 'files');
        $('#stepReport').prop('hidden', step !== 'report');
        if (step === 'report' && $t.data.periodId) { $t.loadReport(); }
    },

    showView: function (view) {
        const $t = $.DLNFrame;
        $t.data.view = view;
        $('#reportTabs .mt-tab').removeClass('active').filter('[data-view="' + view + '"]').addClass('active');
        $('[data-panel]').each(function () { $(this).prop('hidden', $(this).data('panel') !== view); });
        // jqGrid gizliyken genişliği 0 hesaplar: görünür olunca yeniden boyutlanır
        $(window).trigger('resize');
    },

    loadReport: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$d.gridsReady) { $t.initGridRepeat(); $t.initGridNetsis(); $t.initGridFire(); $d.gridsReady = true; }
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getSummary',
            type: 'POST',
            dataType: 'json',
            data: { PERIOD_ID: $d.periodId },
            success: function (result) {
                if (!result || result['resCode'] !== 0) {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Rapor alınamadı.' });
                    return;
                }
                const obj = result['resObject'];
                $d.summary = obj['summary'];
                $d.institutions = obj['byInstitution'] || [];
                $t.renderKpis(obj['summary']);
                $t.renderSampleTable('#tblArrival', obj['byArrival'] || [], 'Geliş tipi');
                $t.renderSampleTable('#tblUnit', obj['byUnit'] || [], 'Birim');
                $t.renderInstitutions();
                $t.renderDepots(obj['byDepot'] || [], obj['summary']['netsis']);
                $t.renderFireTable('#tblFireUnit', obj['fireByUnit'] || [], 'Birim');
                $t.renderFireTable('#tblFireReason', obj['fireByReason'] || [], 'Sebep');
                $t.renderFireMissing(obj['summary']['fire']['units']);
            }
        });
        $t.reloadRepeat(true);
        $t.reloadNetsis(true);
        $t.reloadFire(true);
    },

    renderKpis: function (s) {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        const r = s.repeat, n = s.samples, ns = s.netsis;
        const check = r.rate + r.price;
        const ext = n.samples ? Math.round(n.external / n.samples * 1000) / 10 : 0;
        const kpi = function (cls, label, value, note) {
            return '<div class="mt-kpi ' + cls + '"><div class="mt-kpi-label">' + esc(label) + '</div><div class="mt-kpi-value">' + value + '</div>'
                + (note ? '<div class="mt-kpi-note">' + esc(note) + '</div>' : '') + '</div>';
        };
        const noStudy = !s.study;
        $('#kpis').html(
            kpi('is-main', 'Tekrar maliyeti (sunulabilir)', noStudy ? '—' : esc($t.money(r.present)) + ' <small>TL</small>',
                noStudy ? 'Maliyet çalışması seçin' : 'Alt sınır · ' + $t.int(r.costedRepeat) + ' tekrarın maliyeti hesaplandı')
            + kpi(check ? 'is-warn' : '', 'Doğrulanacak tekrar maliyeti', noStudy ? '—' : esc($t.money(check)) + ' <small>TL</small>',
                'Oran yüksek ' + $t.money(r.rate) + ' · fiyat yüksek ' + $t.money(r.price) + ' TL')
            + kpi('', 'Red / kabul edilmeyen numune', esc($t.int(n.samples)), $t.int(n.tubes) + ' tüp · ' + $t.int(n.tests) + ' test yapılamadı'
                + (n.approved ? ' · ' + $t.int(n.approved) + ' numune testleri onaylandığı için sayılmadı' : ''))
            + kpi('', 'Dış Lab (kurum) payı', n.samples ? '%' + esc(String(ext).replace('.', ',')) : '—', $t.int(n.external) + ' numune · ' + $t.int(n.externalTubes) + ' tüp')
            + kpi('', 'Depodan çıkış (Netsis)', esc($t.int(ns.lines)) + ' <small>satır</small>', $t.int(ns.docs) + ' fiş · ' + $t.int(ns.items) + ' malzeme kodu')
            + kpi(s.fire.units.missing.length ? 'is-warn' : '', 'Fire / zayi bildirimi', esc($t.int(s.fire.count)) + ' <small>satır</small>',
                'Fire ' + $t.int(s.fire.FIRE) + ' · zayi ' + $t.int(s.fire.ZAYI) + ' · tedarikçi ' + $t.int(s.fire.SUPPLIER)
                + (s.fire.amount ? ' · ' + $t.money(s.fire.amount) + ' TL' : '')
                + (s.fire.units.missing.length ? ' · ' + s.fire.units.missing.length + ' birimden dosya gelmedi' : ''))
        );
    },

    // Numune tablosu: ad, numune (oranlı çubuk), kabul edilmeyen, reddedilen, tüp, test
    renderSampleTable: function (sel, rows, label) {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        if (!rows.length) { $(sel).html('<tbody><tr><td class="mt-grid-msg">Numune listesi yüklenmedi.</td></tr></tbody>'); return; }
        const max = Math.max.apply(null, rows.map(function (r) { return r.samples; })) || 1;
        const tot = rows.reduce(function (a, r) { a.samples += r.samples; a.notAccepted += r.notAccepted; a.rejected += r.rejected; a.tubes += r.tubes; a.tests += r.tests; return a; },
            { samples: 0, notAccepted: 0, rejected: 0, tubes: 0, tests: 0 });
        $(sel).html('<thead><tr><th>' + esc(label) + '</th><th class="num">Numune</th><th class="num">Kabul edilmeyen</th><th class="num">Reddedilen</th>'
            + '<th class="num">Tüp</th><th class="num">Test</th></tr></thead><tbody>'
            + rows.map(function (r) {
                return '<tr><td>' + esc(r.name) + '</td><td class="num mt-bar-cell"><div class="mt-bar-fill" style="width:' + Math.round(r.samples / max * 100) + '%"></div>'
                    + '<span>' + $t.int(r.samples) + '</span></td><td class="num">' + $t.int(r.notAccepted) + '</td><td class="num">' + $t.int(r.rejected)
                    + (r.acceptedThenRejected ? ' <span class="mt-warn-text" title="Kabulden sonra reddedilen">(' + $t.int(r.acceptedThenRejected) + ')</span>' : '')
                    + '</td><td class="num">' + $t.int(r.tubes) + '</td><td class="num">' + $t.int(r.tests) + '</td></tr>';
            }).join('')
            + '</tbody><tfoot><tr><td>Toplam</td><td class="num">' + $t.int(tot.samples) + '</td><td class="num">' + $t.int(tot.notAccepted) + '</td><td class="num">'
            + $t.int(tot.rejected) + '</td><td class="num">' + $t.int(tot.tubes) + '</td><td class="num">' + $t.int(tot.tests) + '</td></tr></tfoot>');
    },

    renderInstitutions: function () {
        const $t = $.DLNFrame;
        const q = $t.norm($('#INST_Q').val());
        const rows = $t.data.institutions.filter(function (r) { return !q || $t.norm(r.name).indexOf(q) >= 0; });
        $t.renderSampleTable('#tblInstitution', rows, 'Kurum (' + rows.length + ')');
    },

    renderDepots: function (rows, ns) {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        $('.js-netsis-range').text(ns.lines ? ns.dateFrom + ' – ' + ns.dateTo : '');
        if (!rows.length) { $('#tblDepot').html('<tbody><tr><td class="mt-grid-msg">Netsis dosyası yüklenmedi.</td></tr></tbody>'); return; }
        const max = Math.max.apply(null, rows.map(function (r) { return r.lines; })) || 1;
        $('#tblDepot').html('<thead><tr><th>Birim (giriş deposu)</th><th>Tür</th><th class="num">Satır</th><th class="num">Fiş</th><th class="num">Malzeme kodu</th></tr></thead><tbody>'
            + rows.map(function (r) {
                return '<tr><td>' + esc(r.name) + (r.customers ? ' <span class="mt-file-meta">· ' + $t.int(r.customers) + ' cari</span>' : '') + '</td>'
                    + '<td><span class="mt-type mt-type-' + esc(r.type) + '">' + esc(r.typeName) + '</span></td>'
                    + '<td class="num mt-bar-cell"><div class="mt-bar-fill" style="width:' + Math.round(r.lines / max * 100) + '%"></div><span>' + $t.int(r.lines) + '</span></td>'
                    + '<td class="num">' + $t.int(r.docs) + '</td><td class="num">' + $t.int(r.items) + '</td></tr>';
            }).join('') + '</tbody>');
    },

    // Fire / zayi özet tablosu: ad, satır (oranlı çubuk), fire, zayi, tedarikçi, PCR boşa giden test, TL
    renderFireTable: function (sel, rows, label) {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        if (!rows.length) { $(sel).html('<tbody><tr><td class="mt-grid-msg">Bildirim yok ya da form yüklenmedi.</td></tr></tbody>'); return; }
        const max = Math.max.apply(null, rows.map(function (r) { return r.count; })) || 1;
        $(sel).html('<thead><tr><th>' + esc(label) + '</th><th class="num">Satır</th><th class="num">Fire</th><th class="num">Zayi</th><th class="num">Tedarikçi</th>'
            + '<th class="num">Boşa giden test</th><th class="num">TL</th></tr></thead><tbody>'
            + rows.map(function (r) {
                return '<tr><td>' + esc(r.name) + '</td><td class="num mt-bar-cell"><div class="mt-bar-fill" style="width:' + Math.round(r.count / max * 100) + '%"></div>'
                    + '<span>' + $t.int(r.count) + '</span></td><td class="num">' + $t.int(r.FIRE) + '</td><td class="num">' + $t.int(r.ZAYI) + '</td><td class="num">'
                    + $t.int(r.SUPPLIER) + '</td><td class="num">' + (r.affectedTests ? $t.int(r.affectedTests) : '') + '</td><td class="num">'
                    + (r.amount ? $t.money(r.amount) : '') + (r.uncosted ? ' <span class="mt-file-meta" title="TL karşılığı hesaplanamayan satır">(' + r.uncosted + ' —)</span>' : '') + '</td></tr>';
            }).join('') + '</tbody>');
    },

    renderFireMissing: function (u) {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        const got = Object.keys(u.received || {});
        $('#fireUnits').html(
            got.map(function (n) { return '<span class="mt-chip mt-chip-ok"><i class="fas fa-check"></i> ' + esc(n) + (u.received[n] ? ' · ' + u.received[n] : ' · bildirim yok') + '</span>'; }).join(' ')
            + (u.missing.length ? ' ' + u.missing.map(function (n) { return '<span class="mt-chip mt-chip-wait"><i class="far fa-clock"></i> ' + esc(n) + '</span>'; }).join(' ') : '')
            + (u.merged ? '<div class="mt-file-meta" style="width:100%">Birleştirilmiş şube formu yüklü: formda satırı olmayan şubeler "gelmedi" görünür; '
                + 'bildirimi olmadığı için mi yoksa formu gelmediği için mi, satın almadan teyit edin.</div>' : ''));
    },

    initGridFire: function () {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        $('#gridFire').jqg({
            url: proxyPath + modulePath + 'getFireList',
            datatype: 'local',
            sortname: 'date',
            sortorder: 'asc',
            colModel: [
                { label: 'ID',          name: 'fireId',    index: 'fireId',    key: true, hidden: true },
                { label: 'Tarih',       name: 'date',      index: 'date',      width: 80 },
                { label: 'Birim',       name: 'unit',      index: 'unit',      width: 130, formatter: function (v) { return esc(v); } },
                { label: 'Malzeme / kit', name: 'material', index: 'material', width: 240, formatter: function (v, o, r) {
                    return esc(v) + (r.runNo ? '<div class="mt-file-meta">Run ' + esc(r.runNo) + (r.affectedTests ? ' · ' + $t.int(r.affectedTests) + ' test boşa gitti' : '')
                        + (r.retested ? (r.retested === 'Y' ? ' · tekrar çalışıldı' : ' · tekrar çalışılmadı') : '') + '</div>' : '')
                        + (r.lotNo ? '<div class="mt-file-meta">Lot / barkod ' + esc(r.lotNo) + '</div>' : '')
                        + (r.warning ? '<div class="mt-warn-text"><i class="fas fa-exclamation-triangle"></i> ' + esc(r.warning) + '</div>' : '')
                        + (r.note ? '<div class="mt-file-meta">' + esc(r.note) + '</div>' : ''); } },
                { label: 'Miktar',      name: 'quantity',  index: 'quantity',  width: 80, align: 'right', formatter: function (v, o, r) { return $t.qty(v) + ' ' + esc(r.measure || ''); } },
                { label: 'Sebep',       name: 'reason',    index: 'reason',    width: 170, formatter: function (v, o, r) {
                    return esc(v) + '<div><span class="mt-type mt-type-' + (r.category === 'ZAYI' ? 'CUSTOMER' : r.category === 'SUPPLIER' ? 'OSGB' : r.category === 'FIRE' ? 'LAB' : 'OTHER') + '">'
                        + esc(r.categoryName) + '</span></div>'; } },
                { label: 'TL',          name: 'amount',    index: 'amount',    width: 90, align: 'right', formatter: function (v, o, r) {
                    return v === null ? '<span class="mt-file-meta" title="' + esc(r.costNote) + '">—</span>' : '<strong>' + $t.money(v) + '</strong>'; } }
            ],
            pager: '#gridFirePager',
            fluidHeight: false,
            height: 'auto',
            rowNum: 50,
            rowList: [50, 100, 200],
            beforeSelectRow: function () { return false; },
            rowattr: function (r) { return r.warning ? { 'class': 'mt-row-warn' } : {}; },
            loadComplete: function (data) {
                if (!data || data['resObject'] === undefined) { return; }
                const c = (data['resObject'] || {})['counts'] || {};
                $('#fireFilter [data-count]').each(function () { $(this).text($t.int(c[$(this).data('count')] || 0)); });
                $('#gridFireMsg').text(Number(data['records']) ? '' : (c.ALL ? 'Bu filtrede satır yok.' : 'Bu ay fire / zayi bildirimi yok ya da form yüklenmedi.'))
                    .prop('hidden', !!Number(data['records']));
            }
        });
    },

    reloadFire: function (resetPage) {
        const $t = $.DLNFrame;
        if (!$t.data.gridsReady) { return; }
        const param = { datatype: 'json', postData: { PERIOD_ID: $t.data.periodId, CATEGORY: $t.data.fireCategory, Q: $.trim($('#FIRE_Q').val()) } };
        if (resetPage) { param.page = 1; }
        $('#gridFire').jqGrid('setGridParam', param).trigger('reloadGrid');
    },

    /* ---------- Listeler (jqGrid) ---------- */

    initGridRepeat: function () {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        $('#gridRepeat').jqg({
            url: proxyPath + modulePath + 'getRepeatList',
            datatype: 'local',
            sortname: 'amount',
            sortorder: 'desc',
            colModel: [
                { label: 'Kod',              name: 'code',      index: 'code',      width: 80,  key: true, formatter: function (v) { return '<span class="mt-mono">' + esc(v) + '</span>'; } },
                { label: 'Test',             name: 'name',      index: 'name',      width: 250, formatter: function (v, o, r) {
                    return esc(v) + (r.group ? '<div class="mt-file-meta">' + esc(r.group) + '</div>' : '')
                        + (r.warning ? '<div class="mt-warn-text"><i class="fas fa-exclamation-triangle"></i> ' + esc(r.warning) + '</div>' : ''); } },
                { label: 'Test sayısı',      name: 'testQty',   index: 'testQty',   width: 90,  align: 'right', formatter: function (v) { return $t.int(v); } },
                { label: 'Tekrar',           name: 'repeatQty', index: 'repeatQty', width: 80,  align: 'right', formatter: function (v) { return $t.int(v); } },
                { label: 'Oran',             name: 'rate',      index: 'rate',      width: 70,  align: 'right', formatter: function (v) { return v === null ? '' : '%' + (Math.round(v * 1000) / 10).toLocaleString('tr-TR'); } },
                { label: 'Birim maliyet',    name: 'unitCost',  index: 'unitCost',  width: 100, align: 'right', formatter: function (v) { return v === null ? '' : $t.money(v); } },
                { label: 'Tekrar maliyeti',  name: 'amount',    index: 'amount',    width: 110, align: 'right', formatter: function (v) { return v === null ? '' : '<strong>' + $t.money(v) + '</strong>'; } },
                { label: 'Maliyet durumu',   name: 'status',    index: 'status',    width: 140, sortable: false, formatter: function (v) { return '<span class="mt-file-meta">' + esc(v) + '</span>'; } },
                { label: 'Kategori',         name: 'category',  index: 'category',  hidden: true }
            ],
            pager: '#gridRepeatPager',
            fluidHeight: false,
            height: 'auto',
            rowNum: 50,
            rowList: [50, 100, 200],
            beforeSelectRow: function () { return false; },
            rowattr: function (r) { return (r.category === 'RATE' || r.category === 'PRICE') ? { 'class': 'mt-row-warn' } : {}; },
            loadComplete: function (data) {
                if (!data || data['resObject'] === undefined) { return; }
                const c = (data['resObject'] || {})['counts'] || {};
                $('#repeatFilter [data-count]').each(function () { $(this).text($t.int(c[$(this).data('count')] || 0)); });
                let msg = '';
                if (data['resCode'] !== 0 && data['resCode'] !== '0') { msg = data['resMsg'] || 'Liste alınamadı.'; }
                else if (!Number(data['records'])) { msg = c.ALL ? 'Bu filtrede test yok.' : 'Test sayıları dosyası yüklenmedi ya da tekrar yok.'; }
                $('#gridRepeatMsg').text(msg).prop('hidden', !msg);
            },
            loadError: function () { $('#gridRepeatMsg').text('Sunucuya ulaşılamadı. Sayfayı yenileyin.').prop('hidden', false); }
        });
    },

    initGridNetsis: function () {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        const qty = function (v) { return v ? $t.qty(v) : '<span class="mt-file-meta">·</span>'; };
        $('#gridNetsis').jqg({
            url: proxyPath + modulePath + 'getNetsisList',
            datatype: 'local',
            sortname: 'lines',
            sortorder: 'desc',
            colModel: [
                { label: 'Stok kodu',  name: 'stockCode', index: 'stockCode', width: 80,  formatter: function (v) { return '<span class="mt-mono">' + esc(v) + '</span>'; } },
                { label: 'Stok adı',   name: 'stockName', index: 'stockName', width: 280, formatter: function (v) { return esc(v); } },
                { label: 'Birim',      name: 'unit',      index: 'unit',      width: 55 },
                { label: 'Kurumlar',   name: 'CUSTOMER',  index: 'CUSTOMER',  width: 90, align: 'right', formatter: qty },
                { label: 'OSGB',       name: 'OSGB',      index: 'OSGB',      width: 80, align: 'right', formatter: qty },
                { label: 'Şubeler',    name: 'BRANCH',    index: 'BRANCH',    width: 80, align: 'right', formatter: qty },
                { label: 'Lab / kan alma', name: 'LAB',   index: 'LAB',       width: 95, align: 'right', formatter: qty },
                { label: 'Diğer',      name: 'OTHER',     index: 'OTHER',     width: 70, align: 'right', formatter: qty },
                { label: 'Toplam',     name: 'total',     index: 'total',     width: 90, align: 'right', formatter: function (v) { return '<strong>' + $t.qty(v) + '</strong>'; } },
                { label: 'Satır',      name: 'lines',     index: 'lines',     width: 60, align: 'right' }
            ],
            pager: '#gridNetsisPager',
            fluidHeight: false,
            height: 'auto',
            rowNum: 50,
            rowList: [50, 100, 200],
            beforeSelectRow: function () { return false; }
        });
    },

    reloadRepeat: function (resetPage) {
        const $t = $.DLNFrame;
        if (!$t.data.gridsReady) { return; }
        const param = { datatype: 'json', postData: { PERIOD_ID: $t.data.periodId, FILTER: $t.data.repeatFilter, Q: $.trim($('#REPEAT_Q').val()) } };
        if (resetPage) { param.page = 1; }
        $('#gridRepeat').jqGrid('setGridParam', param).trigger('reloadGrid');
    },

    reloadNetsis: function (resetPage) {
        const $t = $.DLNFrame;
        if (!$t.data.gridsReady) { return; }
        const param = { datatype: 'json', postData: { PERIOD_ID: $t.data.periodId, DEPOT_TYPE: $('#DEPOT_TYPE').val() || '', Q: $.trim($('#NETSIS_Q').val()) } };
        if (resetPage) { param.page = 1; }
        $('#gridNetsis').jqGrid('setGridParam', param).trigger('reloadGrid');
    },

    /* ---------- Excel ---------- */

    exportExcel: function () {
        const $t = $.DLNFrame;
        const $btn = $('#btnExcel');
        if (!$t.data.periodId) { return; }
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getExcel',
            type: 'POST',
            dataType: 'json',
            data: { PERIOD_ID: $t.data.periodId },
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

    esc: function (v) { return $('<span>').text(v === null || v === undefined ? '' : v).html(); },

    isInfo: function (w) { return /^ℹ/.test(String(w || '')); },

    // Arama için: büyük harf, Türkçe harfler sadeleşir
    norm: function (v) {
        return String(v || '').toLocaleUpperCase('tr-TR').replace(/İ/g, 'I').replace(/Ş/g, 'S').replace(/Ğ/g, 'G')
            .replace(/Ü/g, 'U').replace(/Ö/g, 'O').replace(/Ç/g, 'C').replace(/[^A-Z0-9]/g, '');
    },

    int: function (v) { return Number(v || 0).toLocaleString('tr-TR', { maximumFractionDigits: 0 }); },
    money: function (v) { return Number(v || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    qty: function (v) { return Number(v || 0).toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 3 }); },

    debounce: function (key, fn) {
        const timers = $.DLNFrame.data.timers;
        clearTimeout(timers[key]);
        timers[key] = setTimeout(fn, 300);
    },

    storeGet: function () { try { return window.localStorage.getItem($.DLNFrame.opt.storeKey); } catch (e) { return null; } },
    storeSet: function (v) { try { if (v) { window.localStorage.setItem($.DLNFrame.opt.storeKey, v); } } catch (e) { /* gizli pencere */ } }
};
