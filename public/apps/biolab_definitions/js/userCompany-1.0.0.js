/**
 * Biolab Yönetim — Kullanıcı şirketleri (mng_user_company)
 * Alanlar: USER_COMPANY_ID, USER_ID, COMPANY_ID, DEFAULT_FL, ACTIVE_FL
 */
const modulePath = 'app=../../../apps/biolab_definitions/php/userCompany-1.0.0@';

$(document).ready(function () {

    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','user/userCompany-2.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','user/userCompany-2.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        clientId: null,
        lang:null,
        selectedIdUserCompany:null
    },
    opt:{
        editFormUserCompany:'#editFormUserCompany'
    },
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonNothingUserCompany();
        $t.setButtonAfterNewUserCompany();

        $t.initUSER_ID();
        $t.initCOMPANY_ID();
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
    initGridListUserCompany: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        $('#jqGridUserCompany').jqg({
            url: proxyPath + modulePath + 'getGridListUserCompany',
            sortname:'mt.userCompanyId',
            sortorder:'desc',
            colModel: [
                { label: $d.lang['USER_COMPANY_ID'], name: 'mt_userCompanyId', index:'mt.userCompanyId', width: 60, fixed:true },
                { label: $d.lang['USER_NAME'], name: 'u_fullName', index:'u.fullName', width: 200, fixed:true },
                { label: $d.lang['USERNAME'], name: 'u_username', index:'u.username', width: 150, fixed:true },
                { label: $d.lang['COMPANY_CODE'], name: 'c_code', index:'c.code', width: 100, fixed:true },
                { label: $d.lang['COMPANY_NAME'], name: 'c_companyName', index:'c.companyName', width: 200, fixed:true },
                { label: $d.lang['DEFAULT_FL'], name: 'mt_defaultFl', index:'mt.defaultFl', width: 90, fixed:true },
                { label: $d.lang['ACTIVE_FL'], name: 'mt_activeFl', index: 'mt.activeFl', width: 75, fixed:true },
            ],
            pager: '#jqGridPagerUserCompany',
            fluidHeight:false,
            postData:{},
            onSelectRow: function (rowid) {
                const $this = $(this);
                const data = $this.getRowData(rowid);
                $d.selectedIdUserCompany = data['mt_userCompanyId'];
                $t.getSelectedIdUserCompany();
            },
        });

        $('#btnGridSearchUserCompany').click($t.clickQGridUserCompany);
        $('#gridSearchFieldUserCompany').keyup($t.clickQGridUserCompany);
    },
    clickQGridUserCompany: function() {
        $('#jqGridUserCompany').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchFieldUserCompany').val()
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
            $t.initGridListUserCompany();

            $t.setButtonNothingUserCompany();
            $t.setButtonAfterNewUserCompany();
        });
        $('#btnNewUserCompany').click($t.clickNewUserCompany);
        $('#btnCancelUserCompany').click($t.clickCancelUserCompany);
        $('#btnDeleteUserCompany').click($t.clickDeleteUserCompany);
        $('#btnSaveUserCompany').click(function () { $t.sendUserCompany('setSaveUserCompany', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddUserCompany').click(function () { $t.sendUserCompany('setAddUserCompany', 'INF_ADD_WARNING_MESSAGE'); });
    },
    getSelectedIdUserCompany: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedIdUserCompany',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedIdUserCompany
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormUserCompany).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFieldsUserCompany(recs[0]);
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
    setFieldsUserCompany: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormUserCompany;

        $(f +' #USER_COMPANY_ID').val(data['mt_userCompanyId']);
        $(f +' #USER_ID').val(data['u_userId']).change();
        $(f +' #COMPANY_ID').val(data['c_companyId']).change();
        $(f +' #DEFAULT_FL').val(data['mt_defaultFl']).change();
        $(f +' #ACTIVE_FL').val(data['mt_activeFl']).change();

        $t.setButtonAfterSelectUserCompany();
    },
    setButtonNothingUserCompany: function () {
        $('#btnNewUserCompany').disable(false);
        $('#btnCancelUserCompany').disable(true);
        $('#btnDeleteUserCompany').disable(true);
        $('#btnSaveUserCompany').disable(true);
    },
    setButtonAfterNewUserCompany: function () {
        $('#btnNewUserCompany').disable(true);
        $('#btnCancelUserCompany').disable(false);
        $('#btnDeleteUserCompany').disable(true);
        $('#btnSaveUserCompany').disable(false);
        $('#ACTIVE_FL').val('Y').change();
        $('#DEFAULT_FL').val('N').change();
    },
    setButtonAfterSelectUserCompany: function () {
        $('#btnNewUserCompany').disable(true);
        $('#btnCancelUserCompany').disable(false);
        $('#btnDeleteUserCompany').disable(false);
        $('#btnSaveUserCompany').disable(false);
    },
    clickNewUserCompany: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormUserCompany();
        $t.setButtonAfterNewUserCompany();
    },
    clickCancelUserCompany: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormUserCompany();
        $t.setButtonAfterNewUserCompany();
    },
    clickDeleteUserCompany: function () {
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
                        $t.post('setDeleteUserCompany');
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },
    // Kaydet (setSaveUserCompany) ve Ekle (setAddUserCompany) aynı formu gönderir; sunucu USER_COMPANY_ID'ye göre karar verir
    sendUserCompany: function (query, warningKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if(!$($t.opt.editFormUserCompany).valid()) {
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
                data: $($t.opt.editFormUserCompany).serialize(),
                beforeSend: function () {
                    $('.frame-block').blk();
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0) {
                        $('#jqGridUserCompany').jqGrid('setGridParam').trigger('reloadGrid');
                        $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                        $t.clearEditFormUserCompany();
                        $t.setButtonAfterNewUserCompany();
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
    clearEditFormUserCompany: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;

        $($o.editFormUserCompany).trigger('reset');
        $($o.editFormUserCompany +' input:hidden').val(null);
        $($o.editFormUserCompany +' select:not(.base)').val(null).change();
        $($o.editFormUserCompany).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $('#jqGridUserCompany').jqGrid('resetSelection');

        $('#ACTIVE_FL').val('Y').change();
        $('#DEFAULT_FL').val('N').change();
    },
};
