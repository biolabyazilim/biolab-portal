/**
 * Created by Nur.
 * User: nur
 * Date: 06.11.2026
 * Time: 11:33:27
 */
const modulePath = 'app=../../../apps/biolab_definitions/php/mng_form-1.0.0@';
$(document).ready(function () {

    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','user/form-2.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','user/form-2.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        clientId: null,
        lang:null,
        selectedIdForm:null
    },
    opt:{
        editFormForm:'#editFormForm'
    },
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonNothingForm();
        $t.setButtonAfterNewForm();
        $t.initFORM_PATH();
    },
    initFORM_PATH: function () {
        $('#FORM_PATH').slb({
            field:{
                value:'formPath',
                text:'formText',
                disabledFL:null
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getFORM_PATH'
            }
        });

    },
    initGridListForm: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        $('#jqGridForm').jqg({
            url: proxyPath + modulePath + 'getGridListForm',
            sortname: 'mt.formId',
            sortorder: 'desc',
            colModel: [
                { label: $d.lang['FORM_ID'],name: 'mt_formId', index:'mt.formId',  width: 75, fixed:true },
                { label: $d.lang['FORM_CODE'], name: 'mt_formCode', index:'mt.formCode',width: 120, fixed:true },
                { label: $d.lang['FORM_NAME'], name: 'mt_formName', index:'mt.formName',width: 150, fixed:true },
                { label: $d.lang['FORM_NAME_EN'],  name: 'mt_formNameEn', index:'mt.formNameEn', width: 150, fixed:true },
                { label: $d.lang['FORM_PATH'],  name: 'mt_formPath', index:'mt.formPath', width: 300, fixed:true },
                //{ label: $d.lang['FORM_TYPE'],  name: 'mt_formType', index:'mt.formType', width: 75, fixed:true },
              //  { label: $d.lang['FORM_COMMENT'], name: 'mt_formComment', index:'mt.formComment', width: 300, fixed:true },
              //  { label: $d.lang['FORM_TIME_STAMP'],  name: 'mt_formTimeStamp', index:'mt.formTimeStamp', width: 120, fixed:true },
              //  { label: $d.lang['FORM_NAME_UNIQUE'], name: 'mt_formNameUnique', index:'mt.formNameUnique', width: 120, fixed:true },
               // { label: $d.lang['FORM_VISIBLE'], name: 'mt_formVisible', index:'mt.formVisible', width: 75, fixed:true },
                { label: $d.lang['C_BY'], name: 'mt_cBy', index: 'mt.cBy', width: 80, fixed:true },
                { label: $d.lang['C_AT'], name: 'mt_cAt', index: 'mt.cAt', width: 130, fixed:true },
                { label: $d.lang['U_BY'], name: 'mt_uBy', index: 'mt.uBy', width: 75, fixed:true },
                { label: $d.lang['U_AT'], name: 'mt_uAt', index: 'mt.uAt', width: 130, fixed:true },
                { label: $d.lang['D_BY'], name: 'mt_dBy', index: 'mt.dBy', width: 75, fixed:true },
                { label: $d.lang['D_AT'], name: 'mt_dAt', index: 'mt.dAt', width: 75, fixed:true },
                { label: $d.lang['ACTIVE_FL'], name: 'mt_activeFl', index: 'mt.activeFl', width: 75, fixed:true },
                { label: $d.lang['RECORD_FL'], name: 'mt_recordFl', index: 'mt.recordFl', width: 60, fixed:true },
            ],
            pager: '#jqGridPagerForm',
            fluidHeight:false,
            postData:{},
            onSelectRow: function (rowid) {
                const $this = $(this);
                const data = $this.getRowData(rowid);
                $d.selectedIdForm = data['mt_formId'];
                $t.getSelectedIdForm();
            },
            gridComplete: function () {
                // const $this = $(this);
                // $this.find(".btnSel").click(function () {
                //     const selectedRow = $this.jqGrid().getRowData($(this).attr("data-id"));
                // });
            },
        });


        $('#btnGridSearchForm').click($t.clickQGridForm);
        $('#gridSearchFieldForm').keyup($t.clickQGridForm);

        $('#ddmGridSearchForm > li > a').click(function () {
            $('#ddmGridSearchForm > li > a').each(function (i, e) {
                if(!$(e).find('i').hasClass('hide')){
                    $(e).find('i').addClass('hide')
                }
            });

            $('#ddmbGridSearchForm > span:first').html($(this).text());
            $(this).find('i').removeClass('hide');
            $('#gridQueryTypeForm').val($(this).attr('data-id'));
            $t.clickQGridForm();

        })
    },
    clickQGridForm: function() {
        $('#jqGridForm').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchFieldForm').val(),
                    T: $('#gridQueryTypeForm').val()
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
            $t.initGridListForm();

            $t.setButtonNothingForm();
            $t.setButtonAfterNewForm();
        });
        $('#btnNewForm').click($t.clickNewForm);
        $('#btnCancelForm').click($t.clickCancelForm);
        $('#btnDeleteForm').click($t.clickDeleteForm);
        $('#btnSaveForm').click($t.clickSaveForm);
        $('#btnAddForm').click($t.clickAddForm);

    },
    getSelectedIdForm: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // const $o = $t.opt;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedIdForm',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedIdForm
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormForm).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    let rec = null;
                    if(recs!= null && recs.length > 0){
                        rec = recs[0];
                        $t.setFieldsForm(rec);
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
    setFieldsForm: function (data) {
        const $t = $.DLNFrame;

        $($t.opt.editFormForm +' #FORM_ID').val(data['mt_formId']);
        $($t.opt.editFormForm +' #FORM_CODE').val(data['mt_formCode']);
        $($t.opt.editFormForm +' #FORM_NAME').val(data['mt_formName']);
        $($t.opt.editFormForm +' #FORM_NAME_EN').val(data['mt_formNameEn']);
        $($t.opt.editFormForm +' #FORM_PATH').val(data['mt_formPath']).change();
        $($t.opt.editFormForm +' #FORM_TYPE').val(data['mt_formType']).change();
        $($t.opt.editFormForm +' #FORM_COMMENT').val(data['mt_formComment']);
        $($t.opt.editFormForm +' #FORM_TIME_STAMP').val(data['mt_formTimeStamp']);
        $($t.opt.editFormForm +' #FORM_NAME_UNIQUE').val(data['mt_formNameUnique']);
        $($t.opt.editFormForm +' #FORM_VISIBLE').val(data['mt_formVisible']).change();
        $($t.opt.editFormForm +' #C_BY').val(data['mt_cBy']);
        $($t.opt.editFormForm +' #C_AT').val($.DLN.fn.dfv(data['mt_cAt'])).change();
        $($t.opt.editFormForm +' #U_BY').val(data['mt_uBy']);
        $($t.opt.editFormForm +' #U_AT').val($.DLN.fn.dfv(data['mt_uAt'])).change();
        $($t.opt.editFormForm +' #D_BY').val(data['mt_dBy']);
        $($t.opt.editFormForm +' #D_AT').val($.DLN.fn.dfv(data['mt_dAt'])).change();
        $($t.opt.editFormForm +' #ACTIVE_FL').val(data['mt_activeFl']).change();
        $($t.opt.editFormForm +' #RECORD_FL').val(data['mt_recordFl']).change();

        $t.setButtonAfterSelectForm();
    },
    setButtonNothingForm: function () {
        $('#btnNewForm').disable(false);
        $('#btnCancelForm').disable(true);
        $('#btnDeleteForm').disable(true);
        $('#btnSaveForm').disable(true);
    },
    setButtonAfterNewForm: function () {
        $('#btnNewForm').disable(true);
        $('#btnCancelForm').disable(false);
        $('#btnDeleteForm').disable(true);
        $('#btnSaveForm').disable(false);
        $('#ACTIVE_FL').val('Y').change();
    },
    setButtonAfterCancelForm: function () {
        $('#btnNewForm').disable(false);
        $('#btnCancelForm').disable(true);
        $('#btnDeleteForm').disable(true);
        $('#btnSaveForm').disable(true);
        const $t = $.DLNFrame;
        $t.setButtonAfterNewForm();
    },
    setButtonAfterSaveForm: function () {
        $('#btnNewForm').disable(false);
        $('#btnCancelForm').disable(true);
        $('#btnDeleteForm').disable(true);
        $('#btnSaveForm').disable(true);
        const $t = $.DLNFrame;
        $t.setButtonAfterNewForm();
    },
    setButtonAfterDeleteForm: function () {
        $('#btnNewForm').disable(false);
        $('#btnCancelForm').disable(true);
        $('#btnDeleteForm').disable(true);
        $('#btnSaveForm').disable(true);
        const $t = $.DLNFrame;
        $t.setButtonAfterNewForm();
    },
    setButtonAfterSelectForm: function () {
        $('#btnNewForm').disable(true);
        $('#btnCancelForm').disable(false);
        $('#btnDeleteForm').disable(false);
        $('#btnSaveForm').disable(false);
    },
    clickNewForm: function () {

        const $t = $.DLNFrame;
        $t.clearEditFormForm();
        $t.setButtonAfterNewForm();

        $('#jqGridForm').jqGrid('resetSelection');
    },
    clickCancelForm: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormForm();

        $t.setButtonAfterCancelForm();
        // $d.gridList.getGlobalId.rows().deselect();
    },
    clickDeleteForm: function () {
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
                                url: proxyPath + modulePath + 'setDeleteForm',
                                type: 'POST',
                                dataType: 'json',
                                data: $($t.opt.editFormForm).serialize(),
                                beforeSend: function () {
                                    $('.frame-block').blk();
                                },
                                success: function (result) {
                                    if (result != null && result['resCode'] === 0) {
                                        $('#jqGridForm').jqGrid('setGridParam').trigger('reloadGrid');
                                        $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                        $t.clearEditFormForm();
                                        $t.setButtonAfterDeleteForm();
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
    clickSaveForm: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // const $o = $t.opt;
        if($($t.opt.editFormForm).valid()) {
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
                                    url: proxyPath + modulePath + 'setSaveForm',
                                    type: 'POST',
                                    dataType: 'json',
                                    data: $($t.opt.editFormForm).serialize(),
                                    beforeSend: function () {
                                        $('.frame-block').blk();
                                    },
                                    success: function (result) {
                                        if (result != null && result['resCode'] === 0) {
                                            $('#jqGridForm').jqGrid('setGridParam').trigger('reloadGrid');
                                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                            $t.clearEditFormForm();
                                            $t.setButtonAfterSaveForm();
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
    clickAddForm: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // const $o = $t.opt;
        if($($t.opt.editFormForm).valid()) {
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
                                    url: proxyPath + modulePath + 'setAddForm',
                                    type: 'POST',
                                    dataType: 'json',
                                    data: $($t.opt.editFormForm).serialize(),
                                    beforeSend: function () {
                                        $('.frame-block').blk();
                                    },
                                    success: function (result) {

                                        if (result != null && result['resCode'] === 0) {
                                            $('#jqGridForm').jqGrid('setGridParam').trigger('reloadGrid');
                                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                            $t.clearEditFormForm();
                                            $t.setButtonAfterSaveForm();
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
    clearEditFormForm: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;
        $($o.editFormForm).trigger('reset');
        $($o.editFormForm +' input:hidden').val(null);
        $($o.editFormForm +' select').val(null).change();
        $($o.editFormForm).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $('#jqGridForm').jqGrid('resetSelection');

        $('#ACTIVE_FL').val('Y').change();
        $('#RECORD_FL').val('1').change();
    }
};
