/**
 * Biolab Yönetim — Rol tanımlama (mng_role)
 * Alanlar: ROLE_ID, ROLE_CODE, ROLE_NAME, ROLE_NAME_EN, APP_CODE, ACTIVE_FL
 */
const modulePath = 'app=../../../apps/biolab_definitions/php/mng_role-1.0.0@';

$(document).ready(function () {

    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','user/role-2.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','user/role-2.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        clientId: null,
        lang:null,
        selectedIdRole:null
    },
    opt:{
        editFormRole:'#editFormRole'
    },
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonNothingRole();
        $t.setButtonAfterNewRole();

        $t.initAPP_CODE();
    },
    // Uygulamalar: menü kökleri (+ rollerde kullanılan kodlar); boş bırakılabilir
    initAPP_CODE: function () {
        $('#APP_CODE').slb({
            field:{
                value:'appCode',
                text:'appText',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                allowClear:true,
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getAPP_CODE'
            }
        });
    },
    initGridListRole: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        $('#jqGridRole').jqg({
            url: proxyPath + modulePath + 'getGridListRole',
            sortname:'mt.roleId',
            sortorder:'desc',
            colModel: [
                { label: $d.lang['ROLE_ID'], name: 'mt_roleId', index:'mt.roleId', width: 60, fixed:true },
                { label: $d.lang['ROLE_CODE'], name: 'mt_roleCode', index:'mt.roleCode', width: 150, fixed:true },
                { label: $d.lang['ROLE_NAME'], name: 'mt_roleName', index:'mt.roleName', width: 200, fixed:true },
                { label: $d.lang['ROLE_NAME_EN'], name: 'mt_roleNameEn', index:'mt.roleNameEn', width: 200, fixed:true },
                { label: $d.lang['APP_CODE'], name: 'mt_appCode', index:'mt.appCode', width: 150, fixed:true },
                { label: $d.lang['FORM_COUNT'], name: 'formCount', width: 80, fixed:true, sortable:false, align:'right' },
                { label: $d.lang['USER_COUNT'], name: 'userCount', width: 90, fixed:true, sortable:false, align:'right' },
                { label: $d.lang['ACTIVE_FL'], name: 'mt_activeFl', index: 'mt.activeFl', width: 75, fixed:true },
            ],
            pager: '#jqGridPagerRole',
            fluidHeight:false,
            postData:{},
            onSelectRow: function (rowid) {
                const $this = $(this);
                const data = $this.getRowData(rowid);
                $d.selectedIdRole = data['mt_roleId'];
                $t.getSelectedIdRole();
            },
        });

        $('#btnGridSearchRole').click($t.clickQGridRole);
        $('#gridSearchFieldRole').keyup($t.clickQGridRole);
    },
    clickQGridRole: function() {
        $('#jqGridRole').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchFieldRole').val()
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
            $t.initGridListRole();

            $t.setButtonNothingRole();
            $t.setButtonAfterNewRole();
        });
        $('#btnNewRole').click($t.clickNewRole);
        $('#btnCancelRole').click($t.clickCancelRole);
        $('#btnDeleteRole').click($t.clickDeleteRole);
        $('#btnSaveRole').click(function () { $t.sendRole('setSaveRole', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddRole').click(function () { $t.sendRole('setAddRole', 'INF_ADD_WARNING_MESSAGE'); });
    },
    getSelectedIdRole: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedIdRole',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedIdRole
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormRole).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFieldsRole(recs[0]);
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
    setFieldsRole: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormRole;

        $(f +' #ROLE_ID').val(data['mt_roleId']);
        $(f +' #ROLE_CODE').val(data['mt_roleCode']);
        $(f +' #ROLE_NAME').val(data['mt_roleName']);
        $(f +' #ROLE_NAME_EN').val(data['mt_roleNameEn']);
        $(f +' #APP_CODE').val(data['mt_appCode']).change();
        $(f +' #ACTIVE_FL').val(data['mt_activeFl']).change();

        $t.setButtonAfterSelectRole();
    },
    setButtonNothingRole: function () {
        $('#btnNewRole').disable(false);
        $('#btnCancelRole').disable(true);
        $('#btnDeleteRole').disable(true);
        $('#btnSaveRole').disable(true);
    },
    setButtonAfterNewRole: function () {
        $('#btnNewRole').disable(true);
        $('#btnCancelRole').disable(false);
        $('#btnDeleteRole').disable(true);
        $('#btnSaveRole').disable(false);
        $('#ACTIVE_FL').val('Y').change();
    },
    setButtonAfterSelectRole: function () {
        $('#btnNewRole').disable(true);
        $('#btnCancelRole').disable(false);
        $('#btnDeleteRole').disable(false);
        $('#btnSaveRole').disable(false);
    },
    clickNewRole: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormRole();
        $t.setButtonAfterNewRole();
    },
    clickCancelRole: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormRole();
        $t.setButtonAfterNewRole();
    },
    clickDeleteRole: function () {
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
                        $t.post('setDeleteRole');
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },
    // Kaydet (setSaveRole) ve Ekle (setAddRole) aynı formu gönderir; sunucu ROLE_ID'ye göre karar verir
    sendRole: function (query, warningKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if(!$($t.opt.editFormRole).valid()) {
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
                data: $($t.opt.editFormRole).serialize(),
                beforeSend: function () {
                    $('.frame-block').blk();
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0) {
                        $('#jqGridRole').jqGrid('setGridParam').trigger('reloadGrid');
                        $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                        $t.clearEditFormRole();
                        $t.setButtonAfterNewRole();
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
    clearEditFormRole: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;

        $($o.editFormRole).trigger('reset');
        $($o.editFormRole +' input:hidden').val(null);
        $($o.editFormRole +' select:not(.base)').val(null).change();
        $($o.editFormRole).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $('#jqGridRole').jqGrid('resetSelection');

        $('#ACTIVE_FL').val('Y').change();
    },
};
