/**
 * Biolab Yönetim — Kullanıcı rolleri (mng_user_role)
 * Alanlar: USER_ROLE_ID, USER_ID, COMPANY_ID, ROLE_ID, ACTIVE_FL
 */
const modulePath = 'app=../../../apps/biolab_definitions/php/userRole-1.0.0@';

$(document).ready(function () {

    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','user/userRole-2.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','user/userRole-2.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        clientId: null,
        lang:null,
        selectedIdUserRole:null
    },
    opt:{
        editFormUserRole:'#editFormUserRole'
    },
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonNothingUserRole();
        $t.setButtonAfterNewUserRole();

        $t.initUSER_ID();
        $t.initCOMPANY_ID();
        $t.initROLE_ID();
    },
    initUSER_ID: function () {
        $('#USER_ID').slb({
            field:{
                value:'userId',
                text:'userText',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getUSER_ID'
            }
        });
    },
    initCOMPANY_ID: function () {
        $('#COMPANY_ID').slb({
            field:{
                value:'companyId',
                text:'companyName',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getCOMPANY_ID'
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
    initGridListUserRole: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        $('#jqGridUserRole').jqg({
            url: proxyPath + modulePath + 'getGridListUserRole',
            sortname:'mt.userRoleId',
            sortorder:'desc',
            colModel: [
                { label: $d.lang['USER_ROLE_ID'], name: 'mt_userRoleId', index:'mt.userRoleId', width: 60, fixed:true },
                { label: $d.lang['USER_NAME'], name: 'u_fullName', index:'u.fullName', width: 200, fixed:true },
                { label: $d.lang['USERNAME'], name: 'u_username', index:'u.username', width: 150, fixed:true },
                { label: $d.lang['COMPANY_NAME'], name: 'c_companyName', index:'c.companyName', width: 150, fixed:true },
                { label: $d.lang['ROLE_NAME'], name: 'r_roleName', index:'r.roleName', width: 200, fixed:true },
                { label: $d.lang['APP_CODE'], name: 'r_appCode', index:'r.appCode', width: 150, fixed:true },
                { label: $d.lang['ACTIVE_FL'], name: 'mt_activeFl', index: 'mt.activeFl', width: 75, fixed:true },
            ],
            pager: '#jqGridPagerUserRole',
            fluidHeight:false,
            postData:{},
            onSelectRow: function (rowid) {
                const $this = $(this);
                const data = $this.getRowData(rowid);
                $d.selectedIdUserRole = data['mt_userRoleId'];
                $t.getSelectedIdUserRole();
            },
        });

        $('#btnGridSearchUserRole').click($t.clickQGridUserRole);
        $('#gridSearchFieldUserRole').keyup($t.clickQGridUserRole);
    },
    clickQGridUserRole: function() {
        $('#jqGridUserRole').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchFieldUserRole').val()
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
            $t.initGridListUserRole();

            $t.setButtonNothingUserRole();
            $t.setButtonAfterNewUserRole();
        });
        $('#btnNewUserRole').click($t.clickNewUserRole);
        $('#btnCancelUserRole').click($t.clickCancelUserRole);
        $('#btnDeleteUserRole').click($t.clickDeleteUserRole);
        $('#btnSaveUserRole').click(function () { $t.sendUserRole('setSaveUserRole', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddUserRole').click(function () { $t.sendUserRole('setAddUserRole', 'INF_ADD_WARNING_MESSAGE'); });
    },
    getSelectedIdUserRole: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedIdUserRole',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedIdUserRole
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormUserRole).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFieldsUserRole(recs[0]);
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
    setFieldsUserRole: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormUserRole;

        $(f +' #USER_ROLE_ID').val(data['mt_userRoleId']);
        $(f +' #USER_ID').val(data['u_userId']).change();
        $(f +' #COMPANY_ID').val(data['c_companyId']).change();
        $(f +' #ROLE_ID').val(data['r_roleId']).change();
        $(f +' #ACTIVE_FL').val(data['mt_activeFl']).change();

        $t.setButtonAfterSelectUserRole();
    },
    setButtonNothingUserRole: function () {
        $('#btnNewUserRole').disable(false);
        $('#btnCancelUserRole').disable(true);
        $('#btnDeleteUserRole').disable(true);
        $('#btnSaveUserRole').disable(true);
    },
    setButtonAfterNewUserRole: function () {
        $('#btnNewUserRole').disable(true);
        $('#btnCancelUserRole').disable(false);
        $('#btnDeleteUserRole').disable(true);
        $('#btnSaveUserRole').disable(false);
        $('#ACTIVE_FL').val('Y').change();
    },
    setButtonAfterSelectUserRole: function () {
        $('#btnNewUserRole').disable(true);
        $('#btnCancelUserRole').disable(false);
        $('#btnDeleteUserRole').disable(false);
        $('#btnSaveUserRole').disable(false);
    },
    clickNewUserRole: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormUserRole();
        $t.setButtonAfterNewUserRole();
    },
    clickCancelUserRole: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormUserRole();
        $t.setButtonAfterNewUserRole();
    },
    clickDeleteUserRole: function () {
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
                        $t.post('setDeleteUserRole');
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },
    // Kaydet (setSaveUserRole) ve Ekle (setAddUserRole) aynı formu gönderir; sunucu USER_ROLE_ID'ye göre karar verir
    sendUserRole: function (query, warningKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if(!$($t.opt.editFormUserRole).valid()) {
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
                data: $($t.opt.editFormUserRole).serialize(),
                beforeSend: function () {
                    $('.frame-block').blk();
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0) {
                        $('#jqGridUserRole').jqGrid('setGridParam').trigger('reloadGrid');
                        $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                        $t.clearEditFormUserRole();
                        $t.setButtonAfterNewUserRole();
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
    clearEditFormUserRole: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;

        $($o.editFormUserRole).trigger('reset');
        $($o.editFormUserRole +' input:hidden').val(null);
        $($o.editFormUserRole +' select:not(.base)').val(null).change();
        $($o.editFormUserRole).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $('#jqGridUserRole').jqGrid('resetSelection');

        $('#ACTIVE_FL').val('Y').change();
    },
};
