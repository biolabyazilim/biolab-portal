/**
 * Created by Nur.
 * User: nur
 * Date: 06.11.2026
 * Time: 09:10:19
 */


const modulePath = 'app=../../../apps/biolab_definitions/php/menu-1.0.0@';
$(document).ready(function () {

    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','user/menu-2.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','user/menu-2.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        clientId: null,
        lang:null,
        selectedIdMenu:null
    },
    opt:{
        editFormMenu:'#editFormMenu'
    },
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonNothingMenu();
        $t.setButtonAfterNewMenu();

        $t.initPARENT_MENU_ID();
        $t.initFORM_ID();
    },

    initPARENT_MENU_ID: function () {
        $('#PARENT_MENU_ID').slb({
            field:{
                value:'menuId',
                text:'menuName',
                disabledFL:'activeFL'
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getPARENT_MENU_ID'
            }
        });

    },
    initFORM_ID: function () {
        $('#FORM_ID').slb({
            field:{
                value:'formId',
                text:'formName',
                disabledFL:'activeFL'
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
    initGridListMenu: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // function actSelFormatter (cellvalue, options, rowObject){
        //     return '<button type="button" class="btn btn-sm btn-xs-jqg btn-xs bg-purple btn-flat btnSel" data-id="'+options["rowId"]+'">'+$d.lang['BTN_SELECT_TEXT']+'</button>'
        // }
        $('#jqGridMenu').jqg({
            url: proxyPath + modulePath + 'getGridListMenu',
            sortname:'mt.menuId',
            sortorder:'desc',
            colModel: [
                { label: $d.lang['MENU_ID'], name: 'mt_menuId', index:'mt.menuId' ,width: 60, fixed:true },
                { label: $d.lang['PARENT_MENU_ID'], name: 'pat_menuId',  index:'pat.menuId', width: 75, hidden:true },
                { label: $d.lang['PARENT_MENU_NAME'], name: 'pat_menuName', index:'pat.menuName', width: 175, fixed:true },
                { label: $d.lang['FORM_ID'], name: 'f_formId', index:'f.formId' ,width: 75, hidden:true },
                { label: $d.lang['MENU_NAME'], name: 'mt_menuName',  index:'mt.menuName', width: 175, fixed:true },
                { label: $d.lang['FORM_NAME'], name: 'f_formName', index:'f.formName', width: 175, fixed:true },
             //   { label: $d.lang['MENU_NAME_EN'], name: 'mt_menuNameEn',  index:'mt.menuNameEn',width: 175, fixed:true },
                { label: $d.lang['MENU_NAME_COMMENT'], name: 'mt_menuNameComment', index:'mt.menuNameComment', width: 175, fixed:true },
                { label: $d.lang['MENU_ORDER'], name: 'mt_menuOrder',  index:'mt.menuOrder',width: 75, fixed:true },
                { label: $d.lang['MENU_GROUP'], name: 'mt_menuGroup', index:'mt.menuGroup', width: 75, fixed:true },
            //    { label: $d.lang['MENU_IN_HEAD_FL'], name: 'mt_menuInHeadFl', index:'mt.menuInHeadFl', width: 75, fixed:true },
                /*
                { label: $d.lang['C_BY'], name: 'mt_cBy', index: 'mt.cBy', width: 80, fixed:true },
                { label: $d.lang['C_AT'], name: 'mt_cAt', index: 'mt.cAt', width: 130, fixed:true },
                { label: $d.lang['U_BY'], name: 'mt_uBy', index: 'mt.uBy', width: 75, fixed:true },
                { label: $d.lang['U_AT'], name: 'mt_uAt', index: 'mt.uAt', width: 130, fixed:true },
                { label: $d.lang['D_BY'], name: 'mt_dBy', index: 'mt.dBy', width: 75, fixed:true },
                { label: $d.lang['D_AT'], name: 'mt_dAt', index: 'mt.dAt', width: 75, fixed:true },

                 */
                { label: $d.lang['ACTIVE_FL'], name: 'mt_activeFl', index: 'mt.activeFl', width: 75, fixed:true },
                { label: $d.lang['RECORD_FL'], name: 'mt_recordFl', index: 'mt.recordFl', width: 60, fixed:true },

            ],
            pager: '#jqGridPagerMenu',
            fluidHeight:false,
            postData:{},
            onSelectRow: function (rowid) {
                const $this = $(this);
                const data = $this.getRowData(rowid);
                $d.selectedIdMenu = data['mt_menuId'];
                $t.getSelectedIdMenu();
            },
            gridComplete: function () {
                // const $this = $(this);
                // $this.find(".btnSel").click(function () {
                //     const selectedRow = $this.jqGrid().getRowData($(this).attr("data-id"));
                // });
            },
        });


        $('#btnGridSearchMenu').click($t.clickQGridMenu);
        $('#gridSearchFieldMenu').keyup($t.clickQGridMenu);

        $('#ddmGridSearchMenu > li > a').click(function () {
            $('#ddmGridSearchMenu > li > a').each(function (i, e) {
                if(!$(e).find('i').hasClass('hide')){
                    $(e).find('i').addClass('hide')
                }
            });

            $('#ddmGridSearchMenu > span:first').html($(this).text());
            $(this).find('i').removeClass('hide');
            $('#gridQueryTypeMenu').val($(this).attr('data-id'));
            $t.clickQGridMenu();

        })
    },
    clickQGridMenu: function() {
        $('#jqGridMenu').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchFieldMenu').val(),
                    T: $('#gridQueryTypeMenu').val()
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
            $t.initGridListMenu();

            $t.setButtonNothingMenu();
            $t.setButtonAfterNewMenu();
        });
        $('#btnNewMenu').click($t.clickNewMenu);
        $('#btnCancelMenu').click($t.clickCancelMenu);
        $('#btnDeleteMenu').click($t.clickDeleteMenu);
        $('#btnSaveMenu').click($t.clickSaveMenu);
        $('#btnAddMenu').click($t.clickAddMenu);

    },
    getSelectedIdMenu: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // const $o = $t.opt;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedIdMenu',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedIdMenu
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormMenu).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    let rec = null;
                    if(recs!= null && recs.length > 0){
                        rec = recs[0];
                        $t.setFieldsMenu(rec);
                    }
                },
                complete: function () {
                    $('.frame-block').ublk();
                },
                error: function (xhr) {
                    $('.frame-block').ublk();
                    $.fn.ntyp({
                        type: 'error',
                        text: xhr,
                        modal: true,
                        buttons: [
                            {
                                addClass: 'btn btn-sm btn-danger',
                                text: $d.lang['BTN_OK_TEXT'],
                            }
                        ]
                    })
                }
            }
        );
    },
    setFieldsMenu: function (data) {
        const $t = $.DLNFrame;

        $($t.opt.editFormMenu +' #MENU_ID').val(data['mt_menuId']);
        $($t.opt.editFormMenu +' #PARENT_MENU_ID').val(data['pat_menuId']).change();
        $($t.opt.editFormMenu +' #FORM_ID').val(data['f_formId']).change();
        $($t.opt.editFormMenu +' #MENU_NAME').val(data['mt_menuName']);
        $($t.opt.editFormMenu +' #MENU_NAME_EN').val(data['mt_menuNameEn']);
        $($t.opt.editFormMenu +' #MENU_NAME_COMMENT').val(data['mt_menuNameComment']);
        $($t.opt.editFormMenu +' #MENU_ORDER').val(data['mt_menuOrder']);
        $($t.opt.editFormMenu +' #MENU_GROUP').val(data['mt_menuGroup']);
        $($t.opt.editFormMenu +' #MENU_IN_HEAD_FL').val(data['mt_menuInHeadFl']);

        $($t.opt.editFormMenu +' #C_BY').val(data['mt_cBy']);
        $($t.opt.editFormMenu +' #C_AT').val($.DLN.fn.dfv(data['mt_cAt'])).change();
        $($t.opt.editFormMenu +' #U_BY').val(data['mt_uBy']);
        $($t.opt.editFormMenu +' #U_AT').val($.DLN.fn.dfv(data['mt_uAt'])).change();
        $($t.opt.editFormMenu +' #D_BY').val(data['mt_dBy']);
        $($t.opt.editFormMenu +' #D_AT').val($.DLN.fn.dfv(data['mt_dAt'])).change();
        $($t.opt.editFormMenu +' #ACTIVE_FL').val(data['mt_activeFl']).change();
        $($t.opt.editFormMenu +' #RECORD_FL').val(data['mt_recordFl']).change();

        $t.setButtonAfterSelectMenu();
    },
    setButtonNothingMenu: function () {
        $('#btnNewMenu').disable(false);
        $('#btnCancelMenu').disable(true);
        $('#btnDeleteMenu').disable(true);
        $('#btnSaveMenu').disable(true);
    },
    setButtonAfterNewMenu: function () {
        $('#btnNewMenu').disable(true);
        $('#btnCancelMenu').disable(false);
        $('#btnDeleteMenu').disable(true);
        $('#btnSaveMenu').disable(false);
        $('#ACTIVE_FL').val('Y').change();
        $('#RECORD_FL').val('1').change();
    },
    setButtonAfterCancelMenu: function () {
        $('#btnNewMenu').disable(false);
        $('#btnCancelMenu').disable(true);
        $('#btnDeleteMenu').disable(true);
        $('#btnSaveMenu').disable(true);
        const $t = $.DLNFrame;
        $t.setButtonAfterNewMenu();
    },
    setButtonAfterSaveMenu: function () {
        $('#btnNewMenu').disable(false);
        $('#btnCancelMenu').disable(true);
        $('#btnDeleteMenu').disable(true);
        $('#btnSaveMenu').disable(true);
        const $t = $.DLNFrame;
        $t.setButtonAfterNewMenu();
    },
    setButtonAfterDeleteMenu: function () {
        $('#btnNewMenu').disable(false);
        $('#btnCancelMenu').disable(true);
        $('#btnDeleteMenu').disable(true);
        $('#btnSaveMenu').disable(true);
        const $t = $.DLNFrame;
        $t.setButtonAfterNewMenu();
    },
    setButtonAfterSelectMenu: function () {
        $('#btnNewMenu').disable(true);
        $('#btnCancelMenu').disable(false);
        $('#btnDeleteMenu').disable(false);
        $('#btnSaveMenu').disable(false);
    },
    clickNewMenu: function () {

        const $t = $.DLNFrame;
        $t.clearEditFormMenu();
        $t.setButtonAfterNewMenu();

        $('#jqGridMenu').jqGrid('resetSelection');
    },
    clickCancelMenu: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormMenu();

        $t.setButtonAfterCancelMenu();
        // $d.gridList.getGlobalId.rows().deselect();
    },
    clickDeleteMenu: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // const $o = $t.opt;
        $.fn.nty({
            type:'error',
            text: $d.lang['INF_DELETE_WARNING_MESSAGE'],
            buttons:[
                {
                    text:$d.lang['BTN_YES_TEXT'],
                    onClick: function($noty){
                        $noty.close();
                        $.ajaxq('frame',
                            {
                                url: proxyPath + modulePath + 'setDeleteMenu',
                                type: 'POST',
                                dataType: 'json',
                                data: $($t.opt.editFormMenu).serialize(),
                                beforeSend: function () {
                                    $('.frame-block').blk();
                                },
                                success: function (result) {
                                    if (result != null && result['resCode'] === 0) {
                                        $('#jqGridMenu').jqGrid('setGridParam').trigger('reloadGrid');
                                        $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                        $t.clearEditFormMenu();
                                        $t.setButtonAfterDeleteMenu();
                                    }else{
                                        $.fn.ntyp({
                                            type: 'error',
                                            text: $d.lang['INF_ERROR']+': #' + result['resCode'] + ' : ' + result['resMsg'],
                                            modal: true,
                                            buttons: [
                                                {
                                                    addClass: 'btn btn-sm btn-danger',
                                                    text: $d.lang['BTN_OK_TEXT'],
                                                }
                                            ]
                                        })
                                    }
                                },
                                complete: function () {
                                    $('.frame-block').ublk();
                                },
                                error: function (xhr) {
                                    $('.frame-block').ublk();
                                    $.fn.ntyp({
                                        type: 'error',
                                        text: xhr,
                                        modal: true,
                                        buttons: [
                                            {
                                                addClass: 'btn btn-sm btn-danger',
                                                text: $d.lang['BTN_OK_TEXT'],
                                            }
                                        ]
                                    })
                                }
                            }
                        );


                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });

    },
    clickSaveMenu: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // const $o = $t.opt;
        if($($t.opt.editFormMenu).valid()) {
            $.fn.nty({
                type: 'information',
                text: $d.lang['INF_SAVE_WARNING_MESSAGE'],
                buttons: [
                    {
                        text: $d.lang['BTN_YES_TEXT'],
                        onClick: function($noty){
                            $noty.close();
                            $.ajaxq('frame',
                                {
                                    url: proxyPath + modulePath + 'setSaveMenu',
                                    type: 'POST',
                                    dataType: 'json',
                                    data: $($t.opt.editFormMenu).serialize(),
                                    beforeSend: function () {
                                        $('.frame-block').blk();
                                    },
                                    success: function (result) {
                                        if (result != null && result['resCode'] === 0) {
                                            $('#jqGridMenu').jqGrid('setGridParam').trigger('reloadGrid');
                                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                            $t.clearEditFormMenu();
                                            $t.setButtonAfterSaveMenu();
                                        }else{

                                            $.fn.ntyp({
                                                type: 'error',
                                                text: $d.lang['INF_ERROR']+': #' + result['resCode'] + ' : ' + result['resMsg'],
                                                modal: true,
                                                buttons: [
                                                    {
                                                        addClass: 'btn btn-sm btn-danger',
                                                        text: $d.lang['BTN_OK_TEXT'],
                                                    }
                                                ]
                                            })
                                        }
                                    },
                                    complete: function () {
                                        $('.frame-block').ublk();
                                    },
                                    error: function (xhr) {
                                        $('.frame-block').ublk();
                                        $.fn.ntyp({
                                            type: 'error',
                                            text: xhr,
                                            modal: true,
                                            buttons: [
                                                {
                                                    addClass: 'btn btn-sm btn-danger',
                                                    text: $d.lang['BTN_OK_TEXT'],
                                                }
                                            ]
                                        })
                                    }
                                }
                            );

                        }
                    },
                    {
                        text: $d.lang['BTN_NO_TEXT'],
                    }
                ]
            });
        }
    },
    clickAddMenu: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // const $o = $t.opt;
        if($($t.opt.editFormMenu).valid()) {
            $.fn.nty({
                type: 'information',
                text: $d.lang['INF_ADD_WARNING_MESSAGE'],
                buttons: [
                    {
                        text: $d.lang['BTN_YES_TEXT'],
                        onClick: function ($noty) {
                            $noty.close();
                            $.ajaxq('frame',
                                {
                                    url: proxyPath + modulePath + 'setAddMenu',
                                    type: 'POST',
                                    dataType: 'json',
                                    data: $($t.opt.editFormMenu).serialize(),
                                    beforeSend: function () {
                                        $('.frame-block').blk();
                                    },
                                    success: function (result) {

                                        if (result != null && result['resCode'] === 0) {
                                            $('#jqGridMenu').jqGrid('setGridParam').trigger('reloadGrid');
                                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                            $t.clearEditFormMenu();
                                            $t.setButtonAfterSaveMenu();

                                          //  if (window.parent && window.parent.ReloadMenu) { window.parent.ReloadMenu(); }


                                        }else{
                                            $.fn.ntyp({
                                                type: 'error',
                                                text: $d.lang['INF_ERROR']+': #' + result['resCode'] + ' : ' + result['resMsg'],
                                                modal: true,
                                                buttons: [
                                                    {
                                                        addClass: 'btn btn-sm btn-danger',
                                                        text: $d.lang['BTN_OK_TEXT'],
                                                    }
                                                ]
                                            })
                                        }
                                    },
                                    complete: function () {
                                        $('.frame-block').ublk();
                                    },
                                    error: function (xhr) {
                                        $('.frame-block').ublk();
                                        $.fn.ntyp({
                                            type: 'error',
                                            text: xhr,
                                            modal: true,
                                            buttons: [
                                                {
                                                    addClass: 'btn btn-sm btn-danger',
                                                    text: $d.lang['BTN_OK_TEXT'],
                                                }
                                            ]
                                        })
                                    }
                                }
                            );

                        }
                    },
                    {
                        text: $d.lang['BTN_NO_TEXT'],
                    }
                ]
            });
        }
    },
    clearEditFormMenu: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;
        $($o.editFormMenu).trigger('reset');
        $($o.editFormMenu +' input:hidden').val(null);
        $($o.editFormMenu +' select').val(null).change();
        $($o.editFormMenu).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $('#jqGridMenu').jqGrid('resetSelection');

        $('#ACTIVE_FL').val('Y').change();
        $('#RECORD_FL').val('1').change();
    }
};
