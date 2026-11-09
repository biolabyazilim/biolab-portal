/**
 * Biolab Yönetim — Kullanıcı tanımlama (mng_user)
 * Alanlar: USER_ID, USERNAME, FULL_NAME, TITLE, EMAIL, PASSWORD, ACTIVE_FL
 * Şifre sunucudan hiç gelmez; güncellemede şifre alanı boş bırakılırsa şifre değişmez.
 */
const modulePath = 'app=../../../apps/biolab_definitions/php/mng_user-1.0.0@';

$(document).ready(function () {

    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','user/user-2.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','user/user-2.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        clientId: null,
        lang:null,
        selectedIdUser:null
    },
    opt:{
        editFormUser:'#editFormUser'
    },
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonNothingUser();
        $t.setButtonAfterNewUser();
    },
    initGridListUser: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        $('#jqGridUser').jqg({
            url: proxyPath + modulePath + 'getGridListUser',
            sortname:'mt.userId',
            sortorder:'desc',
            colModel: [
                { label: $d.lang['USER_ID'], name: 'mt_userId', index:'mt.userId', width: 60, fixed:true },
                { label: $d.lang['USERNAME'], name: 'mt_username', index:'mt.username', width: 150, fixed:true },
                { label: $d.lang['FULL_NAME'], name: 'mt_fullName', index:'mt.fullName', width: 200, fixed:true },
                { label: $d.lang['TITLE'], name: 'mt_title', index:'mt.title', width: 120, fixed:true },
                { label: $d.lang['EMAIL'], name: 'mt_email', index:'mt.email', width: 200, fixed:true },
                { label: $d.lang['ACTIVE_FL'], name: 'mt_activeFl', index: 'mt.activeFl', width: 75, fixed:true },
            ],
            pager: '#jqGridPagerUser',
            fluidHeight:false,
            postData:{},
            onSelectRow: function (rowid) {
                const $this = $(this);
                const data = $this.getRowData(rowid);
                $d.selectedIdUser = data['mt_userId'];
                $t.getSelectedIdUser();
            },
        });

        $('#btnGridSearchUser').click($t.clickQGridUser);
        $('#gridSearchFieldUser').keyup($t.clickQGridUser);
    },
    clickQGridUser: function() {
        $('#jqGridUser').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchFieldUser').val()
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
            $t.initGridListUser();

            $t.setButtonNothingUser();
            $t.setButtonAfterNewUser();
        });
        $('#btnNewUser').click($t.clickNewUser);
        $('#btnCancelUser').click($t.clickCancelUser);
        $('#btnDeleteUser').click($t.clickDeleteUser);
        $('#btnSaveUser').click(function () { $t.sendUser('setSaveUser', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddUser').click(function () { $t.sendUser('setAddUser', 'INF_ADD_WARNING_MESSAGE'); });
    },
    getSelectedIdUser: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedIdUser',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedIdUser
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormUser).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFieldsUser(recs[0]);
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
    setFieldsUser: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormUser;

        $(f +' #USER_ID').val(data['mt_userId']);
        $(f +' #USERNAME').val(data['mt_username']);
        $(f +' #FULL_NAME').val(data['mt_fullName']);
        $(f +' #TITLE').val(data['mt_title']);
        $(f +' #EMAIL').val(data['mt_email']);
        $(f +' #PASSWORD').val('');     // şifre gösterilmez; boş kalırsa değişmez
        $(f +' #ACTIVE_FL').val(data['mt_activeFl']).change();

        $t.setButtonAfterSelectUser();
    },
    setButtonNothingUser: function () {
        $('#btnNewUser').disable(false);
        $('#btnCancelUser').disable(true);
        $('#btnDeleteUser').disable(true);
        $('#btnSaveUser').disable(true);
    },
    setButtonAfterNewUser: function () {
        $('#btnNewUser').disable(true);
        $('#btnCancelUser').disable(false);
        $('#btnDeleteUser').disable(true);
        $('#btnSaveUser').disable(false);
        $('#ACTIVE_FL').val('Y').change();
    },
    setButtonAfterSelectUser: function () {
        $('#btnNewUser').disable(true);
        $('#btnCancelUser').disable(false);
        $('#btnDeleteUser').disable(false);
        $('#btnSaveUser').disable(false);
    },
    clickNewUser: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormUser();
        $t.setButtonAfterNewUser();
    },
    clickCancelUser: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormUser();
        $t.setButtonAfterNewUser();
    },
    clickDeleteUser: function () {
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
                        $t.post('setDeleteUser');
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },
    // Kaydet (setSaveUser) ve Ekle (setAddUser) aynı formu gönderir; sunucu USER_ID'ye göre karar verir
    sendUser: function (query, warningKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if(!$($t.opt.editFormUser).valid()) {
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
                data: $($t.opt.editFormUser).serialize(),
                beforeSend: function () {
                    $('.frame-block').blk();
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0) {
                        $('#jqGridUser').jqGrid('setGridParam').trigger('reloadGrid');
                        $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                        $t.clearEditFormUser();
                        $t.setButtonAfterNewUser();
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
    clearEditFormUser: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;

        $($o.editFormUser).trigger('reset');
        $($o.editFormUser +' input:hidden').val(null);
        $($o.editFormUser).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $('#jqGridUser').jqGrid('resetSelection');

        $('#ACTIVE_FL').val('Y').change();
    },
};
