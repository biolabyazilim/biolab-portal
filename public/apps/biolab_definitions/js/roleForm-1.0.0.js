/**
 * Biolab Yönetim — Rol ekran yetkileri (mng_role_form)
 * Alanlar: ROLE_FORM_ID, ROLE_ID, FORM_ID, ACTIVE_FL
 */
const modulePath = 'app=../../../apps/biolab_definitions/php/roleForm-1.0.0@';

$(document).ready(function () {

    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','user/roleForm-2.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','user/roleForm-2.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        clientId: null,
        lang:null,
        selectedIdRoleForm:null
    },
    opt:{
        editFormRoleForm:'#editFormRoleForm'
    },
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonNothingRoleForm();
        $t.setButtonAfterNewRoleForm();

        $t.initROLE_ID();
        $t.initFORM_ID();
    },
    initFORM_ID: function () {
        $('#FORM_ID').slb({
            field:{
                value:'formId',
                text:'formText',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getFORM_ID'
            }
        });
    },
    initROLE_ID: function () {
        $('#ROLE_ID').slb({
            field:{
                value:'roleId',
                text:'roleText',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getROLE_ID'
            }
        });
    },
    initGridListRoleForm: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        $('#jqGridRoleForm').jqg({
            url: proxyPath + modulePath + 'getGridListRoleForm',
            sortname:'mt.roleFormId',
            sortorder:'desc',
            colModel: [
                { label: $d.lang['ROLE_FORM_ID'], name: 'mt_roleFormId', index:'mt.roleFormId', width: 60, fixed:true },
                { label: $d.lang['ROLE_NAME'], name: 'r_roleName', index:'r.roleName', width: 200, fixed:true },
                { label: $d.lang['APP_CODE'], name: 'r_appCode', index:'r.appCode', width: 150, fixed:true },
                { label: $d.lang['FORM_NAME'], name: 'f_formName', index:'f.formName', width: 200, fixed:true },
                { label: $d.lang['FORM_PATH'], name: 'f_formPath', index:'f.formPath', width: 350, fixed:true },
                { label: $d.lang['ACTIVE_FL'], name: 'mt_activeFl', index: 'mt.activeFl', width: 75, fixed:true },
            ],
            pager: '#jqGridPagerRoleForm',
            fluidHeight:false,
            postData:{},
            onSelectRow: function (rowid) {
                const $this = $(this);
                const data = $this.getRowData(rowid);
                $d.selectedIdRoleForm = data['mt_roleFormId'];
                $t.getSelectedIdRoleForm();
            },
        });

        $('#btnGridSearchRoleForm').click($t.clickQGridRoleForm);
        $('#gridSearchFieldRoleForm').keyup($t.clickQGridRoleForm);
    },
    clickQGridRoleForm: function() {
        $('#jqGridRoleForm').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchFieldRoleForm').val()
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
            $t.initGridListRoleForm();

            $t.setButtonNothingRoleForm();
            $t.setButtonAfterNewRoleForm();
        });
        $('#btnNewRoleForm').click($t.clickNewRoleForm);
        $('#btnCancelRoleForm').click($t.clickCancelRoleForm);
        $('#btnDeleteRoleForm').click($t.clickDeleteRoleForm);
        $('#btnSaveRoleForm').click(function () { $t.sendRoleForm('setSaveRoleForm', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddRoleForm').click(function () { $t.sendRoleForm('setAddRoleForm', 'INF_ADD_WARNING_MESSAGE'); });
    },
    getSelectedIdRoleForm: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedIdRoleForm',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedIdRoleForm
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormRoleForm).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFieldsRoleForm(recs[0]);
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
    setFieldsRoleForm: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormRoleForm;

        $(f +' #ROLE_FORM_ID').val(data['mt_roleFormId']);
        $(f +' #ROLE_ID').val(data['r_roleId']).change();
        $(f +' #FORM_ID').val(data['f_formId']).change();
        $(f +' #ACTIVE_FL').val(data['mt_activeFl']).change();

        $t.setButtonAfterSelectRoleForm();
    },
    setButtonNothingRoleForm: function () {
        $('#btnNewRoleForm').disable(false);
        $('#btnCancelRoleForm').disable(true);
        $('#btnDeleteRoleForm').disable(true);
        $('#btnSaveRoleForm').disable(true);
    },
    setButtonAfterNewRoleForm: function () {
        $('#btnNewRoleForm').disable(true);
        $('#btnCancelRoleForm').disable(false);
        $('#btnDeleteRoleForm').disable(true);
        $('#btnSaveRoleForm').disable(false);
        $('#ACTIVE_FL').val('Y').change();
    },
    setButtonAfterSelectRoleForm: function () {
        $('#btnNewRoleForm').disable(true);
        $('#btnCancelRoleForm').disable(false);
        $('#btnDeleteRoleForm').disable(false);
        $('#btnSaveRoleForm').disable(false);
    },
    clickNewRoleForm: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormRoleForm();
        $t.setButtonAfterNewRoleForm();
    },
    clickCancelRoleForm: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormRoleForm();
        $t.setButtonAfterNewRoleForm();
    },
    clickDeleteRoleForm: function () {
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
                        $t.post('setDeleteRoleForm');
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },
    // Kaydet (setSaveRoleForm) ve Ekle (setAddRoleForm) aynı formu gönderir; sunucu ROLE_FORM_ID'ye göre karar verir
    sendRoleForm: function (query, warningKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if(!$($t.opt.editFormRoleForm).valid()) {
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
                data: $($t.opt.editFormRoleForm).serialize(),
                beforeSend: function () {
                    $('.frame-block').blk();
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0) {
                        $('#jqGridRoleForm').jqGrid('setGridParam').trigger('reloadGrid');
                        $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                        $t.clearEditFormRoleForm();
                        $t.setButtonAfterNewRoleForm();
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
    clearEditFormRoleForm: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;

        $($o.editFormRoleForm).trigger('reset');
        $($o.editFormRoleForm +' input:hidden').val(null);
        $($o.editFormRoleForm +' select:not(.base)').val(null).change();
        $($o.editFormRoleForm).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $('#jqGridRoleForm').jqGrid('resetSelection');

        $('#ACTIVE_FL').val('Y').change();
    },
};
