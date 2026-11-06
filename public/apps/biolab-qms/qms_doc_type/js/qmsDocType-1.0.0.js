/**
 * Biolab QMS — Doküman tipleri
 *
 * - Solda tip formu: klasör, ad, kod öneki, süreler ve onay kutuları (okudum onayı, dış kaynaklı, aktif).
 *   Kod kalıbı sorulmaz; sunucu önekten üretir (TL → TL + 1–4 hane). Yeni kayıtta ad klasörden dolar.
 *   Altında varsayılan dağıtım; tek Kaydet ile tek transaction'da gider (DISTRIBUTION[i][type], DISTRIBUTION[i][id] form alanları).
 *   İmzacılar tipte tanımlanmaz: her dokümanın imzacıları yüklemede seçilir (dokümana göre değişir).
 * - Sağda tip listesi (klasör süzgeci, arama, pasifler).
 * - Kayıttan sonra liste yenilenir ve kaydedilen tip işaretlenir; yeni kayıtta form boşalır.
 * Sunucu tarafı: php/qmsDocType-1.0.0.php
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-qms/qms_doc_type/php/qmsDocType-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','qms_doc_type/qmsDocType-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','qms_doc_type/qmsDocType-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        selectedId:null,     // listede seçili (formda açık) tipin DOC_TYPE_ID'si
        highlightId:null,    // kayıttan sonra listede işaretlenecek tip
        subjects:null        // {POSITION:[…], DEPARTMENT:[…], LOCATION:[…], USER:[…]} (getSubjects; dağıtım seçimleri)
    },
    opt:{
        editFormDocType:'#editFormDocType',
        subjectTypes:['ALL','OWNER_DEPT','DEPARTMENT','POSITION','LOCATION','USER'],
        noSubjectId:['ALL','OWNER_DEPT']
    },

    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonAfterNewDocType();
    },

    // Klasör seçimleri: form (zorunlu) ve liste süzgeci (boş = bütün klasörler)
    initCATEGORY_ID: function () {
        const $d = $.DLNFrame.data;
        const field = { value:'categoryId', text:'categoryPath', disabledFL:'activeFl' };
        $('#CATEGORY_ID').slb({
            field: field,
            select2:{ placeholder:'', minimumResultsForSearch:0 },
            ajax:{ url:proxyPath + modulePath + 'getCATEGORY_ID' }
        });
        $('#FILTER_CATEGORY_ID').slb({
            field: field,
            select2:{ placeholder:$d.lang['FILTER_ALL'], minimumResultsForSearch:0 },
            ajax:{ url:proxyPath + modulePath + 'getCATEGORY_ID' }
        });
    },

    // Pozisyon, departman, lokasyon, kişi listeleri bir kez alınır; dağıtım seçimleri bunlardan kurulur
    loadSubjects: function (onLoaded) {
        const $t = $.DLNFrame;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getSubjects',
            type: 'POST',
            dataType: 'json',
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $t.data.subjects = result['resObject'];
                    if (onLoaded) { onLoaded(); }
                } else {
                    $t.showError(result ? result['resMsg'] : '');
                }
            },
            error: function (xhr) { $t.showError(xhr); }
        });
    },

    setEvent: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        const body = $('body');
        body.off('.lang');
        body.off('langLoaded.lang');
        body.on('langLoaded.lang', function () {
            $d.lang = getLangDatas();

            $.DLN.LangManager.setLanguageTemplate($(document));
            $.DLN.plugin.init();
            $t.initCATEGORY_ID();
            $t.initGridList();
            $t.setValidation();
            $t.loadSubjects($t.resetDistribution);
        });

        $('#btnNewDocType').click($t.clickNewDocType);
        $('#btnCancelDocType').click($t.clickCancelDocType);
        $('#btnDeleteDocType').click($t.clickDeleteDocType);
        $('#btnSaveDocType').click(function () { $t.clickSaveDocType('setSave', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddDocType').click(function () { $t.clickSaveDocType('setAdd', 'INF_ADD_WARNING_MESSAGE'); });
        $('#btnDistAdd').click(function () { $t.addDistRow('DEPARTMENT', null); });

        // Onay kutuları gizli Y/N alanlarını doldurur (sunucuya gizli alan gider)
        $('.qm-flag').on('change', function () {
            $($(this).attr('data-target')).val(this.checked ? 'Y' : 'N');
        });
        $('#CB_EXTERNAL_FL').on('change', $t.applyExternal);

        // Yeni kayıtta klasör seçilince ad boşsa klasör adıyla dolar (ör. TALİMATLAR); istenirse değiştirilir
        $('#CATEGORY_ID').on('change', function () {
            if ($('#DOC_TYPE_ID').val() || $.trim($('#DOC_TYPE_NAME').val()) || !$(this).val()) { return; }
            const path = $(this).find('option:selected').text();
            $('#DOC_TYPE_NAME').val(path.split(' › ').pop());
        });

        $('#FILTER_CATEGORY_ID').on('change', $t.clickQGrid);
        $('#btnGridSearch').click($t.clickQGrid);
        $('#gridSearchField').keyup($t.clickQGrid);
        $('#SHOW_PASSIVE').change($t.clickQGrid);
    },

    setValidation: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $($t.opt.editFormDocType).validate({
            ignore: '.select2-search__field',
            rules: {
                CATEGORY_ID: { required: true },
                CODE_PREFIX: { required: true, maxlength: 20 },
                DOC_TYPE_NAME: { required: true, maxlength: 150 },
                REVIEW_MONTHS: { required: true, min: 1, max: 120 },
                RETENTION_YEARS: { required: true, min: 1, max: 100 }
            },
            messages: {
                CATEGORY_ID: $d.lang['ERR_CATEGORY_ID'],
                CODE_PREFIX: $d.lang['ERR_CODE_PREFIX'],
                DOC_TYPE_NAME: $d.lang['ERR_DOC_TYPE_NAME'],
                REVIEW_MONTHS: $d.lang['ERR_PERIOD'],
                RETENTION_YEARS: $d.lang['ERR_PERIOD']
            }
        });
    },

    // Dış kaynaklı seçilince açıklama görünür
    applyExternal: function () {
        $('#externalInfo').prop('hidden', $('#EXTERNAL_FL').val() !== 'Y');
    },

    // Yeni tip için varsayılan dağıtım: dokümanın sahibi departman
    resetDistribution: function () {
        const $t = $.DLNFrame;
        $('#distRows').empty();
        $t.addDistRow('OWNER_DEPT', null);
    },

    // Evet/hayır alanı: gizli alan (Y/N) ve onay kutusu birlikte ayarlanır
    setFlag: function (field, value) {
        $('#' + field).val(value);
        $('#CB_' + field).prop('checked', value === 'Y');
    },

    // Dağıtım satırı: tür seçimi + (gerekiyorsa) hedef seçimi + kaldır düğmesi
    addDistRow: function (type, id) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const $row = $('<div class="qm-dist-row">');
        const $type = $('<select class="form-control form-control-sm qm-dist-type">');
        $t.opt.subjectTypes.forEach(function (st) {
            $type.append(new Option($d.lang['SUBJECT_' + st], st, false, st === type));
        });
        const $subject = $('<select class="form-control form-control-sm qm-dist-subject">');
        const $remove = $('<button type="button" class="btn btn-sm btn-outline-danger" title="Kaldır"><i class="fas fa-times"></i></button>');

        function fillSubjects(selectedId) {
            const st = $type.val();
            const needsId = $t.opt.noSubjectId.indexOf(st) < 0;
            $subject.empty().prop('hidden', !needsId);
            if (!needsId) { return; }
            $subject.append(new Option($d.lang['SUBJECT_SELECT'], '', false, !selectedId));
            ($d.subjects[st] || []).forEach(function (s) {
                const sel = String(s.id) === String(selectedId);
                if (s.activeFl !== 'Y' && !sel) { return; }
                $subject.append(new Option(s.name + (s.activeFl !== 'Y' ? ' (' + $d.lang['STATUS_PASSIVE'] + ')' : ''), s.id, false, sel));
            });
        }
        $type.on('change', function () { fillSubjects(null); });
        $remove.on('click', function () { $row.remove(); $t.refreshDistEmpty(); });

        $row.append($type, $subject, $remove);
        $('#distRows').append($row);
        fillSubjects(id);
        $t.refreshDistEmpty();
    },

    refreshDistEmpty: function () {
        $('#distEmpty').prop('hidden', $('#distRows .qm-dist-row').length > 0);
    },

    // Formdaki dağıtım → sunucuya giden JSON
    collectDistribution: function () {
        const $t = $.DLNFrame;
        const dists = [];
        $('#distRows .qm-dist-row').each(function () {
            const type = $(this).find('.qm-dist-type').val();
            const needsId = $t.opt.noSubjectId.indexOf(type) < 0;
            const id = $(this).find('.qm-dist-subject').val();
            dists.push({ type: type, id: needsId ? (id ? Number(id) : '') : null });
        });
        return dists;
    },

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        function activeFormatter(cellvalue) {
            return cellvalue === 'Y'
                ? '<span class="qm-badge qm-badge-on">' + $d.lang['STATUS_ACTIVE'] + '</span>'
                : '<span class="qm-badge qm-badge-off">' + $d.lang['STATUS_PASSIVE'] + '</span>';
        }
        function esignFormatter(cellvalue) {
            return cellvalue === 'Y' ? '<span class="qm-badge qm-badge-on">' + $d.lang['ESIGN_BADGE'] + '</span>' : '<span class="text-muted small">' + $d.lang['WET_BADGE'] + '</span>';
        }
        function externalFormatter(cellvalue) {
            return cellvalue === 'Y' ? '<span class="qm-badge qm-badge-ext">' + $d.lang['EXTERNAL_BADGE'] + '</span>' : '';
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            sortname:'c.SORT_NO',
            sortorder:'asc',
            colModel: [
                { label: 'ID',                        name: 'mt_docTypeId',    index: 'mt.DOC_TYPE_ID',   key: true, hidden: true },
                { label: $d.lang['CATEGORY_NAME'],    name: 'categoryPath',    index: 'c.SORT_NO',        width: 130 },
                { label: $d.lang['CODE_PREFIX'],      name: 'mt_codePrefix',   index: 'mt.CODE_PREFIX',   width: 70 },
                { label: $d.lang['DOC_TYPE_NAME'],    name: 'mt_docTypeName',  index: 'mt.DOC_TYPE_NAME', width: 160 },
                { label: $d.lang['EXTERNAL_FL'],      name: 'mt_externalFl',   index: 'mt_externalFl',    width: 100, sortable: false, formatter: externalFormatter },
                { label: $d.lang['ESIGN_COL'],        name: 'mt_esignFl',      index: 'mt_esignFl',       width: 90, sortable: false, formatter: esignFormatter },
                { label: $d.lang['DOC_COUNT'],        name: 'docCount',        index: 'docCount',         width: 65, align: 'right', sortable: false },
                { label: $d.lang['ACTIVE_FL'],        name: 'mt_activeFl',     index: 'mt.ACTIVE_FL',     width: 65, formatter: activeFormatter },
            ],
            pager: '#jqGridPager',
            fluidHeight:false,
            height:'500',
            rowNum: 25,
            rowList: [10, 25, 50, 100],
            postData:{ SHOW_PASSIVE: 'N' },
            // Kayıttan sonra kaydedilen satır işaretlenir (forma yeniden yüklenmez: onSelectRow tetiklenmez)
            gridComplete: function () {
                if ($d.highlightId) {
                    $(this).jqGrid('setSelection', $d.highlightId, false);
                    $d.highlightId = null;
                }
            },
            onSelectRow: function (rowid) {
                const data = $(this).getRowData(rowid);
                $d.selectedId = data['mt_docTypeId'];
                $t.getSelectedId();
            }
        });
    },

    reloadGrid: function () {
        $('#jqGrid').jqGrid('setGridParam', { datatype: 'json' }).trigger('reloadGrid');
    },

    clickQGrid: function () {
        $('#jqGrid').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    CATEGORY_ID: $('#FILTER_CATEGORY_ID').val(),
                    Q: $('#gridSearchField').val(),
                    SHOW_PASSIVE: $('#SHOW_PASSIVE').is(':checked') ? 'Y' : 'N'
                }
            }
        ).trigger('reloadGrid');
    },

    getSelectedId: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedId',
                type: 'POST',
                dataType: 'json',
                data: { SELECTED_ID: $d.selectedId },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormDocType).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFields(recs[0]);
                    }
                },
                complete: function () {
                    $('.frame-block').ublk();
                },
                error: function (xhr) {
                    $('.frame-block').ublk();
                    $t.showError(xhr);
                }
            }
        );
    },

    setFields: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormDocType;
        $(f + ' #DOC_TYPE_ID').val(data['DOC_TYPE_ID']);
        $(f + ' #CATEGORY_ID').val(data['CATEGORY_ID']).change();
        $(f + ' #CODE_PREFIX').val(data['CODE_PREFIX']);
        $(f + ' #DOC_TYPE_NAME').val(data['DOC_TYPE_NAME']);
        $(f + ' #REVIEW_MONTHS').val(data['REVIEW_MONTHS']);
        $(f + ' #RETENTION_YEARS').val(data['RETENTION_YEARS']);
        $t.setFlag('READ_ACK_FL', data['READ_ACK_FL']);
        $t.setFlag('EXTERNAL_FL', data['EXTERNAL_FL']);
        $t.setFlag('FILLABLE_FL', data['FILLABLE_FL']);
        $t.setFlag('ESIGN_FL', data['ESIGN_FL']);
        $t.setFlag('ACTIVE_FL', data['ACTIVE_FL']);
        $('#externalInfo').prop('hidden', data['EXTERNAL_FL'] !== 'Y');

        $('#distRows').empty();
        (data['DISTRIBUTION'] || []).forEach(function (d) { $t.addDistRow(d.type, d.id); });
        $t.refreshDistEmpty();

        $t.setButtonAfterSelectDocType();
    },

    setButtonAfterNewDocType: function () {
        $('#btnNewDocType').prop('disabled', true);
        $('#btnCancelDocType').prop('disabled', false);
        $('#btnDeleteDocType').prop('disabled', true);
        $('#btnSaveDocType').prop('disabled', false);
        $('#btnAddToggleDocType').prop('disabled', false);
    },

    setButtonAfterSelectDocType: function () {
        $('#btnNewDocType').prop('disabled', false);
        $('#btnCancelDocType').prop('disabled', false);
        $('#btnDeleteDocType').prop('disabled', false);
        $('#btnSaveDocType').prop('disabled', false);
        $('#btnAddToggleDocType').prop('disabled', false);
    },

    // Yeni: form temizlenir; listede bir klasör süzülmüşse yeni tipin klasörü o olarak gelir
    clickNewDocType: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        if ($('#FILTER_CATEGORY_ID').val()) {
            $('#CATEGORY_ID').val($('#FILTER_CATEGORY_ID').val()).change();
        }
        $t.setButtonAfterNewDocType();
        $('#CODE_PREFIX').trigger('focus');
    },

    clickCancelDocType: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        $t.setButtonAfterNewDocType();
    },

    // Sil: dokümanı olan tipi sunucu silmez, "pasife alın" der
    clickDeleteDocType: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.fn.nty({
            type:'error',
            text: $d.lang['INF_DELETE_WARNING_MESSAGE'],
            buttons:[
                {
                    text:$d.lang['BTN_YES_TEXT'],
                    onClick: function($noty){
                        $noty.close();
                        $t.post('setDelete', { DOC_TYPE_ID: $('#DOC_TYPE_ID').val() }, function () {
                            $t.clearEditForm();
                            $t.setButtonAfterNewDocType();
                            $t.clickQGrid();
                        });
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },

    // Kaydet (setSave) ve Ekle (setAdd). Kayıttan sonra liste yenilenir ve kaydedilen tip işaretlenir.
    //   Yeni kayıt → form boşalır.  Güncelleme → tip formda açık kalır.
    clickSaveDocType: function (query, confirmKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if(!$($t.opt.editFormDocType).valid()) {
            return;
        }
        $.fn.nty({
            type: 'information',
            text: $d.lang[confirmKey],
            buttons: [
                {
                    text: $d.lang['BTN_YES_TEXT'],
                    onClick: function($noty){
                        $noty.close();
                        const isNew = query === 'setAdd' || !$('#DOC_TYPE_ID').val();
                        const data = $($t.opt.editFormDocType).serialize() + '&' + $.param({
                            DISTRIBUTION: $t.collectDistribution()
                        });
                        $t.post(query, data, function (result) {
                            const id = result['resObject']['DOC_TYPE_ID'];
                            if (isNew) {
                                $t.clearEditForm();
                                $t.setButtonAfterNewDocType();
                            } else {
                                $d.selectedId = id;
                            }
                            $d.highlightId = id;
                            $t.reloadGrid();
                        });
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },

    // Kayıt işlemleri için ortak istek: başarıda bildirim + onSuccess, hatada sunucu mesajı
    post: function (query, data, onSuccess) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + query,
                type: 'POST',
                dataType: 'json',
                data: data,
                beforeSend: function () {
                    $('.frame-block').blk();
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0) {
                        $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resMsg']});
                        onSuccess(result);
                    }else{
                        $t.showError($d.lang['INF_ERROR'] + ': ' + (result ? result['resMsg'] : ''));
                    }
                },
                complete: function () {
                    $('.frame-block').ublk();
                },
                error: function (xhr) {
                    $('.frame-block').ublk();
                    $t.showError(xhr);
                }
            }
        );
    },

    showError: function (text) {
        const $d = $.DLNFrame.data;
        $.fn.ntyp({
            type: 'error',
            text: text,
            modal: true,
            buttons: [
                {
                    addClass: 'btn btn-sm btn-danger',
                    text: $d.lang['BTN_OK_TEXT'],
                }
            ]
        });
    },

    // Formu boşaltır, listedeki seçimi kaldırır; dağıtım varsayılana döner
    clearEditForm: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;
        $($o.editFormDocType).trigger('reset');
        $('#DOC_TYPE_ID').val(null);
        $('#CATEGORY_ID').val(null).change();
        $t.setFlag('READ_ACK_FL', 'Y');
        $t.setFlag('EXTERNAL_FL', 'N');
        $t.setFlag('FILLABLE_FL', 'N');
        $t.setFlag('ESIGN_FL', 'N');
        $t.setFlag('ACTIVE_FL', 'Y');
        $('#externalInfo').prop('hidden', true);
        $('#REVIEW_MONTHS').val(24);
        $('#RETENTION_YEARS').val(10);
        $($o.editFormDocType).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $($o.editFormDocType).clearValidation();
        $('#jqGrid').jqGrid('resetSelection');
        $t.data.selectedId = null;
        if ($t.data.subjects) {
            $t.resetDistribution();
        }
    }
};
