/**
 * KVKK Yönetimi — Menü ağacı
 *
 * - Solda başlık ağacı (lib.js $.fn.treeView; D.Sys menuWithTree ekranıyla aynı kullanım).
 *   Kök başlıklar açılışta, alt başlıklar "+" ikonuna tıklanınca sunucudan gelir (getNodeTree).
 * - Başlığa tıklanınca bilgileri sağdaki forma dolar (getSelectedId); Kaydet ile güncellenir (setSave).
 * - Başlık sürüklenip başka bir başlığın içine bırakılırsa üst başlığı değişir (setChangeTreePosition).
 * - Sil: başlık silinmez, pasife alınır (RECORD_FL = 0). Altında başlık ya da belge varsa sunucu izin vermez.
 * Sunucu tarafı: php/kvkkNode-1.0.0.php
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-kvkk/kvkk_node/php/kvkkNode-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','kvkk_node/kvkkNode-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','kvkk_node/kvkkNode-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        selectedIdNode:null,   // ağaçta seçili başlığın NODE_ID'si
        treeView:null
    },
    opt:{
        editFormNode:'#editFormNode'
    },

    // Ekran açılışı: olaylar, düğmelerin ilk durumu, üst başlık seçim kutusu ve ağaç
    init: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.setEvent();
        $t.setButtonAfterNewNode();
        $t.initPARENT_NODE_ID();
        $t.initTreeView();

        const treeViewWellAjax = $('#treeViewWellAjax');

        // Başlık sürüklenip başka bir başlığın içine bırakıldı: yeni üst başlık kaydedilir.
        // d = taşınan başlık, n = yeni üst başlık (ana seviyeye bırakıldıysa boş)
        treeViewWellAjax.on('moved', function (e, d, n) {
            $.ajaxq('frame',
                {
                    url: proxyPath + modulePath + 'setChangeTreePosition',
                    type: 'POST',
                    dataType: 'json',
                    data: {NODE_ID: $(d).data('id'), PARENT_NODE_ID: $(n).data('id')},
                    beforeSend: function () {
                        $('.frame-block').blk();
                    },
                    success: function (result) {
                        if (result != null && result['resCode'] === 0) {
                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resMsg']});
                            $t.initPARENT_NODE_ID();
                        }else{
                            // Taşıma kabul edilmediyse ağaç veritabanındaki hâline döner
                            $.fn.ntyi({ type: 'error',layout: 'topRight',text: result['resMsg']});
                            $t.initTreeView();
                        }
                    },
                    complete: function () {
                        $('.frame-block').ublk();
                    }
                }
            );
        });

        // Ağaçta bir başlığa tıklandı: bilgileri forma yüklenir
        treeViewWellAjax.on('onClickItem', function (e, el) {
            $d.selectedIdNode = $(el).data('id');
            $t.getSelectedIdNode();
        });
    },

    // Ağacı kurar (ya da kayıttan sonra baştan yükler). Kök başlıklar hemen gelir.
    // treeView yüklenen kutuya "loaded" sınıfı koyar ve bir daha sunucuya gitmez; yeniden yüklemek için sınıf ve liste temizlenir.
    initTreeView: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const treeViewWellAjax = $('#treeViewWellAjax');
        treeViewWellAjax.removeClass('loaded').find('> ul').empty();
        $d.treeView = treeViewWellAjax.treeView({
            ajax:{
                url:proxyPath + modulePath + 'getNodeTree',
                data:null
            },
            field:{
                ID:'nodeId',
                PARENT_ID:'parentNodeId',
                TEXT:'nodeName',
                ACTIVE_FL:'activeFl',
                RECORD_FL:'recordFl'
            }
        });
    },

    // Üst başlık seçim kutusu: bütün başlıklar "Ana › Alt" yoluyla listelenir
    initPARENT_NODE_ID: function () {
        $('#PARENT_NODE_ID').slb({
            field:{
                value:'nodeId',
                text:'nodePath',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getPARENT_NODE_ID'
            }
        });
    },

    // Düğme olayları ve dil dosyası yüklenince yapılacaklar (D.Sys düzeni)
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
            $t.setValidation();
        });
        $('#btnNewNode').click($t.clickNewNode);
        $('#btnCancelNode').click($t.clickCancelNode);
        $('#btnDeleteNode').click($t.clickDeleteNode);
        $('#btnSaveNode').click($t.clickSaveNode);
        $('#btnAddNode').click($t.clickAddNode);
    },

    // Form kuralları; mesajlar dil dosyasından
    setValidation: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $($t.opt.editFormNode).validate({
            rules: {
                NODE_NAME: { required: true, maxlength: 200 }
            },
            messages: {
                NODE_NAME: $d.lang['ERR_NODE_NAME']
            }
        });
    },

    // Seçili başlığın bilgilerini sunucudan alıp forma yazar
    getSelectedIdNode: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedId',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedIdNode
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormNode).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFieldsNode(recs[0]);
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

    // getSelectedId cevabını forma yazar (mt_ = başlık, pat_ = üst başlık)
    setFieldsNode: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormNode;

        $(f + ' #NODE_ID').val(data['mt_nodeId']);
        $(f + ' #PARENT_NODE_ID').val(data['pat_nodeId']).change();
        $(f + ' #NODE_NAME').val(data['mt_nodeName']);
        $(f + ' #NODE_DESC').val(data['mt_nodeDesc']);
        $(f + ' #SORT_NO').val(data['mt_sortNo']);
        $(f + ' #ACTIVE_FL').val(data['mt_activeFl']).change();

        $t.setButtonAfterSelectNode();
    },

    // Yeni kayıt durumu: form boş, Kaydet / Ekle ve İptal açık
    setButtonAfterNewNode: function () {
        $('#btnNewNode').prop('disabled', true);
        $('#btnCancelNode').prop('disabled', false);
        $('#btnDeleteNode').prop('disabled', true);
        $('#btnSaveNode').prop('disabled', false);
        $('#btnAddToggleNode').prop('disabled', false);
    },

    // Ağaçtan başlık seçildi: güncelleme, silme ve (formdaki bilgilerle) yeni ekleme açık
    setButtonAfterSelectNode: function () {
        $('#btnNewNode').prop('disabled', false);
        $('#btnCancelNode').prop('disabled', false);
        $('#btnDeleteNode').prop('disabled', false);
        $('#btnSaveNode').prop('disabled', false);
        $('#btnAddToggleNode').prop('disabled', false);
    },

    // Yeni: form temizlenir. Ağaçta bir başlık seçiliyse yeni başlık onun altına eklenir (üst başlık hazır gelir)
    clickNewNode: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const parentId = $d.selectedIdNode;
        $t.clearEditFormNode();
        if (parentId) {
            $('#PARENT_NODE_ID').val(parentId).change();
        }
        $t.setButtonAfterNewNode();
        $('#NODE_NAME').trigger('focus');
    },

    // İptal: seçim ve form temizlenir, yeni kayıt durumuna dönülür
    clickCancelNode: function () {
        const $t = $.DLNFrame;
        $t.clearEditFormNode();
        $t.setButtonAfterNewNode();
    },

    // Sil: onay alınır, başlık pasife alınır (setDelete)
    clickDeleteNode: function () {
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
                        $.ajaxq('frame',
                            {
                                url: proxyPath + modulePath + 'setDelete',
                                type: 'POST',
                                dataType: 'json',
                                data: $($t.opt.editFormNode).serialize(),
                                beforeSend: function () {
                                    $('.frame-block').blk();
                                },
                                success: function (result) {
                                    if (result != null && result['resCode'] === 0) {
                                        $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                        $t.clearEditFormNode();
                                        $t.setButtonAfterNewNode();
                                        $t.initTreeView();
                                        $t.initPARENT_NODE_ID();
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

    // Kaydet: NODE_ID boşsa yeni başlık eklenir, doluysa güncellenir (setSave). Sonra ağaç yeniden yüklenir.
    clickSaveNode: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if($($t.opt.editFormNode).valid()) {
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
                                    url: proxyPath + modulePath + 'setSave',
                                    type: 'POST',
                                    dataType: 'json',
                                    data: $($t.opt.editFormNode).serialize(),
                                    beforeSend: function () {
                                        $('.frame-block').blk();
                                    },
                                    success: function (result) {
                                        if (result != null && result['resCode'] === 0) {
                                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                            $t.clearEditFormNode();
                                            $t.setButtonAfterNewNode();
                                            $t.initTreeView();
                                            $t.initPARENT_NODE_ID();
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

    // Ekle: formdaki bilgilerle her zaman YENİ başlık eklenir (seçili başlık değişmez) (setAdd)
    clickAddNode: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if($($t.opt.editFormNode).valid()) {
            $.fn.nty({
                type: 'information',
                text: $d.lang['INF_ADD_WARNING_MESSAGE'],
                buttons: [
                    {
                        text: $d.lang['BTN_YES_TEXT'],
                        onClick: function($noty){
                            $noty.close();
                            $.ajaxq('frame',
                                {
                                    url: proxyPath + modulePath + 'setAdd',
                                    type: 'POST',
                                    dataType: 'json',
                                    data: $($t.opt.editFormNode).serialize(),
                                    beforeSend: function () {
                                        $('.frame-block').blk();
                                    },
                                    success: function (result) {
                                        if (result != null && result['resCode'] === 0) {
                                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                            $t.clearEditFormNode();
                                            $t.setButtonAfterNewNode();
                                            $t.initTreeView();
                                            $t.initPARENT_NODE_ID();
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

    // Formu boşaltır, ağaçtaki seçimi kaldırır; aktiflik "Evet", sıra 0 olarak başlar
    clearEditFormNode: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;
        $($o.editFormNode).trigger('reset');
        $($o.editFormNode +' input:hidden').val(null);
        $($o.editFormNode +' select').val(null).change();
        $($o.editFormNode).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $($o.editFormNode).clearValidation();
        $('#treeViewWellAjax').find('.selected-el').removeClass('selected-el');
        $t.data.selectedIdNode = null;

        $('#SORT_NO').val(0);
        $('#ACTIVE_FL').val('Y').change();
    }
};
