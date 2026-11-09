/**
 * Biolab Yönetim — Şirket ve şube tanımlama (mng_company)
 * Alanlar: COMPANY_ID, CODE, COMPANY_NAME, TAX_NUMBER, COMPANY_TYPE_ID, PARENT_COMPANY_ID, LANGUAGE_ID, ACTIVE_FL
 */
const modulePath = 'app=../../../apps/biolab_definitions/php/mng_company-1.0.0@';

$(document).ready(function () {

    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','company/company-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','company/company-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        clientId: null,
        lang:null,
        selectedIdCompany:null
    },
    opt:{
        editFormCompany:'#editFormCompany'
    },
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonNothingCompany();
        $t.setButtonAfterNewCompany();

        $t.initCOMPANY_TYPE_ID();
        $t.initPARENT_COMPANY_ID();
        $t.initLANGUAGE_ID();
    },
    initCOMPANY_TYPE_ID: function () {
        $('#COMPANY_TYPE_ID').slb({
            field:{
                value:'companyTypeId',
                text:'companyTypeName',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getCOMPANY_TYPE_ID'
            }
        });
    },
    // Yalnızca Merkez tipindeki şirketler listelenir (sunucu süzer)
    initPARENT_COMPANY_ID: function () {
        $('#PARENT_COMPANY_ID').slb({
            field:{
                value:'companyId',
                text:'companyName',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                allowClear:true,
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getPARENT_COMPANY_ID'
            }
        });
    },
    initLANGUAGE_ID: function () {
        $('#LANGUAGE_ID').slb({
            field:{
                value:'languageId',
                text:'name',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getLANGUAGE_ID'
            }
        });
    },
    initGridListCompany: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        $('#jqGridCompany').jqg({
            url: proxyPath + modulePath + 'getGridListCompany',
            sortname:'mt.companyId',
            sortorder:'desc',
            colModel: [
                { label: $d.lang['COMPANY_ID'], name: 'mt_companyId', index:'mt.companyId', width: 60, fixed:true },
                { label: $d.lang['COMPANY_CODE'], name: 'mt_code', index:'mt.code', width: 100, fixed:true },
                { label: $d.lang['COMPANY_NAME'], name: 'mt_companyName', index:'mt.companyName', width: 200, fixed:true },
                { label: $d.lang['COMPANY_TYPE_NAME'], name: 'ct_companyTypeName', index:'ct.companyTypeName', width: 100, fixed:true },
                { label: $d.lang['PARENT_COMPANY_NAME'], name: 'pat_companyName', index:'pat.companyName', width: 200, fixed:true },
                { label: $d.lang['LANGUAGE'], name: 'l_name', index:'l.name', width: 90, fixed:true },
                { label: $d.lang['TAX_NUMBER'], name: 'mt_taxNumber', index:'mt.taxNumber', width: 120, fixed:true },
                { label: $d.lang['ACTIVE_FL'], name: 'mt_activeFl', index: 'mt.activeFl', width: 75, fixed:true },
            ],
            pager: '#jqGridPagerCompany',
            fluidHeight:false,
            postData:{},
            onSelectRow: function (rowid) {
                const $this = $(this);
                const data = $this.getRowData(rowid);
                $d.selectedIdCompany = data['mt_companyId'];
                $t.getSelectedIdCompany();
            },
        });

        $('#btnGridSearchCompany').click($t.clickQGridCompany);
        $('#gridSearchFieldCompany').keyup($t.clickQGridCompany);
    },
    clickQGridCompany: function() {
        $('#jqGridCompany').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchFieldCompany').val()
                }
            }
        ).trigger("reloadGrid");
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
            $t.initGridListCompany();

            $t.setButtonNothingCompany();
            $t.setButtonAfterNewCompany();
        });
        $('#btnNewCompany').click($t.clickNewCompany);
        $('#btnCancelCompany').click($t.clickCancelCompany);
        $('#btnDeleteCompany').click($t.clickDeleteCompany);
        $('#btnSaveCompany').click(function () { $t.sendCompany('setSaveCompany', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddCompany').click(function () { $t.sendCompany('setAddCompany', 'INF_ADD_WARNING_MESSAGE'); });
    },
    getSelectedIdCompany: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedIdCompany',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedIdCompany
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormCompany).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFieldsCompany(recs[0]);
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
    setFieldsCompany: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormCompany;

        $(f +' #COMPANY_ID').val(data['mt_companyId']);
        $(f +' #CODE').val(data['mt_code']);
        $(f +' #COMPANY_NAME').val(data['mt_companyName']);
        $(f +' #TAX_NUMBER').val(data['mt_taxNumber']);
        $(f +' #COMPANY_TYPE_ID').val(data['ct_companyTypeId']).change();
        $(f +' #PARENT_COMPANY_ID').val(data['pat_companyId']).change();
        $(f +' #LANGUAGE_ID').val(data['l_languageId']).change();
        $(f +' #ACTIVE_FL').val(data['mt_activeFl']).change();

        $t.setButtonAfterSelectCompany();
    },
    setButtonNothingCompany: function () {
        $('#btnNewCompany').disable(false);
        $('#btnCancelCompany').disable(true);
        $('#btnDeleteCompany').disable(true);
        $('#btnSaveCompany').disable(true);
    },
    setButtonAfterNewCompany: function () {
        $('#btnNewCompany').disable(true);
        $('#btnCancelCompany').disable(false);
        $('#btnDeleteCompany').disable(true);
        $('#btnSaveCompany').disable(false);
        $('#ACTIVE_FL').val('Y').change();
    },
    setButtonAfterSelectCompany: function () {
        $('#btnNewCompany').disable(true);
        $('#btnCancelCompany').disable(false);
        $('#btnDeleteCompany').disable(false);
        $('#btnSaveCompany').disable(false);
    },
    clickNewCompany: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormCompany();
        $t.setButtonAfterNewCompany();
    },
    clickCancelCompany: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormCompany();
        $t.setButtonAfterNewCompany();
    },
    clickDeleteCompany: function () {
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
                        $t.post('setDeleteCompany');
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },
    // Kaydet (setSaveCompany) ve Ekle (setAddCompany) aynı formu gönderir; sunucu COMPANY_ID'ye göre karar verir
    sendCompany: function (query, warningKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if(!$($t.opt.editFormCompany).valid()) {
            return;
        }
        $.fn.nty({
            type: 'information',
            text: $d.lang[warningKey],
            buttons: [
                {
                    text: $d.lang['BTN_YES_TEXT'],
                    onClick: function($noty){
                        $noty.close();
                        $t.post(query);
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },
    post: function (query) {
        const $t = $.DLNFrame;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + query,
                type: 'POST',
                dataType: 'json',
                data: $($t.opt.editFormCompany).serialize(),
                beforeSend: function () {
                    $('.frame-block').blk();
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0) {
                        $('#jqGridCompany').jqGrid('setGridParam').trigger('reloadGrid');
                        $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                        $t.clearEditFormCompany();
                        $t.setButtonAfterNewCompany();
                    }else{
                        $t.showError(result ? result['resMsg'] : '');
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
    clearEditFormCompany: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;

        $($o.editFormCompany).trigger('reset');
        $($o.editFormCompany +' input:hidden').val(null);
        $($o.editFormCompany +' select:not(.base)').val(null).change();
        $($o.editFormCompany).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $('#jqGridCompany').jqGrid('resetSelection');

        $('#ACTIVE_FL').val('Y').change();
    },
};
