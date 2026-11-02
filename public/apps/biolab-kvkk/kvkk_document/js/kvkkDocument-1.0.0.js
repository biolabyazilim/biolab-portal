/**
 * KVKK Yönetimi — Belgeler
 *
 * - Solda belge listesi (jqGrid). Üstteki "Başlık" seçimi ve arama kutusu listeyi süzer (getGridList).
 * - Satır seçilince belge sağdaki forma dolar (getSelectedId), altında PDF versiyonları listelenir (getFileGridList).
 * - Kaydet: belge bilgisi + seçildiyse PDF dosyası birlikte gönderilir (setSave).
 *   Yeni belgede PDF zorunludur; kayıtlı belgede seçilen PDF yeni versiyon olarak eklenir, eskisi arşivde kalır.
 * - Versiyon listesindeki "Aç" düğmesi PDF'i yeni sekmede açar (getFilePdf → /api/pdf.php).
 * - Sil: belge silinmez, pasife alınır ve yayından kalkar (setDelete).
 * Sunucu tarafı: php/kvkkDocument-1.0.0.php
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-kvkk/kvkk_document/php/kvkkDocument-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','kvkk_document/kvkkDocument-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','kvkk_document/kvkkDocument-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        selectedId:null    // listede seçili belgenin DOCUMENT_ID'si
    },
    opt:{
        editFormDocument:'#editFormDocument'
    },

    // Ekran açılışı: olaylar ve düğmelerin ilk durumu. Seçim kutuları ve listeler dil dosyası yüklenince kurulur.
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonAfterNewDocument();
    },

    // Liste süzgeci: başlık seçilince yalnızca o başlığın belgeleri listelenir (boş = bütün başlıklar)
    initFILTER_NODE_ID: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $('#FILTER_NODE_ID').slb({
            field:{
                value:'nodeId',
                text:'nodePath',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:$d.lang['FILTER_ALL'],
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getNODE_ID'
            }
        });
    },

    // Formdaki başlık seçimi: belge hangi başlığın altında yayınlanacak
    initNODE_ID: function () {
        $('#NODE_ID').slb({
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
                url:proxyPath + modulePath + 'getNODE_ID'
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
            $t.initFILTER_NODE_ID();
            $t.initNODE_ID();
            $t.initGridList();
            $t.initGridListFile();
            $t.setValidation();
        });

        $('#btnNewDocument').click($t.clickNewDocument);
        $('#btnCancelDocument').click($t.clickCancelDocument);
        $('#btnDeleteDocument').click($t.clickDeleteDocument);
        $('#btnSaveDocument').click($t.clickSaveDocument);
        $('#btnAddDocument').click($t.clickAddDocument);

        $('#FILTER_NODE_ID').on('change', $t.clickQGrid);
        $('#btnGridSearch').click($t.clickQGrid);
        $('#gridSearchField').keyup($t.clickQGrid);
    },

    // Form kuralları; PDF yalnızca yeni belgede zorunlu
    setValidation: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $($t.opt.editFormDocument).validate({
            rules: {
                NODE_ID: { required: true },
                DOCUMENT_NAME: { required: true, maxlength: 200 },
                DOCUMENT_FILE: {
                    required: function () { return $('#DOCUMENT_ID').val() === ''; }
                }
            },
            messages: {
                NODE_ID: $d.lang['ERR_NODE_ID'],
                DOCUMENT_NAME: $d.lang['ERR_DOCUMENT_NAME'],
                DOCUMENT_FILE: $d.lang['ERR_DOCUMENT_FILE']
            }
        });
    },

    // Belge listesi (jqGrid)
    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // "2026-10-31 09:12:05" → "31.10.2026 09:12"
        function dateTimeFormatter(cellvalue) {
            return cellvalue ? moment(cellvalue, 'YYYY-MM-DD HH:mm:ss').format('DD.MM.YYYY HH:mm') : '';
        }
        // Yayın durumu rozeti; dosyası hiç yüklenmemiş belge "Dosya yok" görünür
        function publishFormatter(cellvalue, options, rowObject) {
            if (!rowObject['lastVersionNo']) {
                return '<span class="kv-badge kv-badge-warn">' + $d.lang['NO_FILE'] + '</span>';
            }
            return cellvalue === 'Y'
                ? '<span class="kv-badge kv-badge-on">' + $d.lang['STATUS_PUBLISHED'] + '</span>'
                : '<span class="kv-badge kv-badge-off">' + $d.lang['STATUS_DRAFT'] + '</span>';
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            sortname:'n.nodeName',
            sortorder:'asc',
            colModel: [
                { label: 'ID',                      name: 'mt_documentId',   index: 'mt.documentId',   key: true, hidden: true },
                { label: $d.lang['NODE_PATH'],      name: 'nodePath',        index: 'n.nodeName',      width: 180 },
                { label: $d.lang['DOCUMENT_NAME'],  name: 'mt_documentName', index: 'mt.documentName', width: 220 },
                { label: $d.lang['SORT_NO'],        name: 'mt_sortNo',       index: 'mt.sortNo',       width: 50, align: 'right' },
                { label: $d.lang['LAST_VERSION'],   name: 'lastVersionNo',   index: 'lastVersionNo',   width: 70, align: 'right', sortable: false },
                { label: $d.lang['LAST_UPLOAD'],    name: 'lastUploadAt',    index: 'lastUploadAt',    width: 110, formatter: dateTimeFormatter, sortable: false },
                { label: $d.lang['PUBLISH_FL'],     name: 'mt_publishFl',    index: 'mt.publishFl',    width: 80, formatter: publishFormatter },
            ],
            pager: '#jqGridPager',
            fluidHeight:false,
            height:'400',
            rowNum: 25,
            rowList: [10, 20, 50, 100],
            onSelectRow: function (rowid) {
                const data = $(this).getRowData(rowid);
                $d.selectedId = data['mt_documentId'];
                $t.getSelectedId();
            }
        });
    },

    // Listeyi süzgeçle yeniden yükler (başlık seçimi + arama kutusu)
    clickQGrid: function () {
        $('#jqGrid').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchField').val(),
                    NODE_ID: $('#FILTER_NODE_ID').val()
                }
            }
        ).trigger('reloadGrid');
    },

    // Seçili belgenin PDF versiyonları (jqGrid); en yeni versiyon üstte
    initGridListFile: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // 245760 → "240 KB"
        function sizeFormatter(cellvalue) {
            return cellvalue ? Math.ceil(cellvalue / 1024) + ' KB' : '';
        }
        function dateTimeFormatter(cellvalue) {
            return cellvalue ? moment(cellvalue, 'YYYY-MM-DD HH:mm:ss').format('DD.MM.YYYY HH:mm') : '';
        }
        // Her satırda PDF'i açan düğme; tıklama gridComplete'te bağlanır
        function openFormatter(cellvalue, options, rowObject) {
            return '<button type="button" class="btn btn-sm btn-primary btnOpenPdf" data-id="' + rowObject['f_documentFileId'] + '">'
                + '<i class="far fa-file-pdf"></i> ' + $d.lang['BTN_OPEN_PDF'] + '</button>';
        }

        $('#jqGridFile').jqg({
            url: proxyPath + modulePath + 'getFileGridList',
            sortname:'f.versionNo',
            sortorder:'desc',
            colModel: [
                { label: 'ID',                    name: 'f_documentFileId', index: 'f.documentFileId', key: true, hidden: true },
                { label: $d.lang['VERSION_NO'],   name: 'f_versionNo',      index: 'f.versionNo',      width: 60, align: 'right' },
                { label: $d.lang['FILE_NAME'],    name: 'f_fileName',       index: 'f.fileName',       width: 170 },
                { label: $d.lang['FILE_SIZE'],    name: 'f_fileSize',       index: 'f.fileSize',       width: 70, align: 'right', formatter: sizeFormatter },
                { label: $d.lang['UPLOADED_BY'],  name: 'u_fullName',       index: 'u.fullName',       width: 120 },
                { label: $d.lang['UPLOADED_AT'],  name: 'f_cAt',            index: 'f.cAt',            width: 110, formatter: dateTimeFormatter },
                // Etiket boş ('') verilirse jqGrid kolon adını ("open") yazar; bu yüzden tek boşluk
                { label: ' ',                     name: 'open',             index: 'open',             width: 70, sortable: false, formatter: openFormatter },
            ],
            pager: '#jqGridFilePager',
            fluidHeight:false,
            height:'143',
            rowNum: 5,
            rowList: [5, 10, 20],
            postData:{
                DOCUMENT_ID: ''
            },
            gridComplete: function () {
                $(this).find('.btnOpenPdf').click(function (e) {
                    e.stopPropagation();
                    $t.clickOpenPdf($(this).attr('data-id'));
                });
            }
        });
    },

    // Versiyon listesini seçili belgeyle yeniden yükler (belge seçili değilse liste boşalır)
    reloadGridListFile: function () {
        const $t = $.DLNFrame;
        $('#jqGridFile').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    DOCUMENT_ID: $t.data.selectedId || ''
                }
            }
        ).trigger('reloadGrid');
    },

    // PDF'i yeni sekmede açar. Sekme tıklama anında açılır (tarayıcı açılır pencereyi engellemesin), adres gelince yüklenir.
    clickOpenPdf: function (documentFileId) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const win = window.open('', '_blank');
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getFilePdf',
                type: 'POST',
                dataType: 'json',
                data: {
                    DOCUMENT_FILE_ID: documentFileId
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0 && result['resObject']) {
                        if (win) { win.location.href = result['resObject']['url']; }
                    }else{
                        if (win) { win.close(); }
                        $.fn.ntyi({ type: 'error',layout: 'topRight',text: result['resMsg']});
                    }
                },
                error: function (xhr) {
                    if (win) { win.close(); }
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

    // Seçili belgenin bilgilerini sunucudan alıp forma yazar, versiyon listesini yeniler
    getSelectedId: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedId',
                type: 'POST',
                dataType: 'json',
                data: {
                    SELECTED_ID: $d.selectedId
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormDocument).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFields(recs[0]);
                    }
                    $t.reloadGridListFile();
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

    // getSelectedId cevabını forma yazar (mt_ = belge, n_ = başlık). Dosya kutusu her seçimde boşalır.
    setFields: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormDocument;

        $(f + ' #DOCUMENT_ID').val(data['mt_documentId']);
        $(f + ' #NODE_ID').val(data['n_nodeId']).change();
        $(f + ' #DOCUMENT_NAME').val(data['mt_documentName']);
        $(f + ' #SORT_NO').val(data['mt_sortNo']);
        $(f + ' #PUBLISH_FL').val(data['mt_publishFl']).change();
        $(f + ' #DOCUMENT_FILE').val('');

        $t.setButtonAfterSelectDocument();
    },

    // Yeni kayıt durumu: form boş, Kaydet / Ekle ve İptal açık
    setButtonAfterNewDocument: function () {
        $('#btnNewDocument').prop('disabled', true);
        $('#btnCancelDocument').prop('disabled', false);
        $('#btnDeleteDocument').prop('disabled', true);
        $('#btnSaveDocument').prop('disabled', false);
        $('#btnAddToggleDocument').prop('disabled', false);
    },

    // Listeden belge seçildi: güncelleme, silme ve (formdaki bilgilerle) yeni ekleme açık
    setButtonAfterSelectDocument: function () {
        $('#btnNewDocument').prop('disabled', false);
        $('#btnCancelDocument').prop('disabled', false);
        $('#btnDeleteDocument').prop('disabled', false);
        $('#btnSaveDocument').prop('disabled', false);
        $('#btnAddToggleDocument').prop('disabled', false);
    },

    // Yeni: form temizlenir. Listede bir başlık süzülmüşse yeni belgenin başlığı o olarak gelir
    clickNewDocument: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        if ($('#FILTER_NODE_ID').val()) {
            $('#NODE_ID').val($('#FILTER_NODE_ID').val()).change();
        }
        $t.setButtonAfterNewDocument();
        $('#DOCUMENT_NAME').trigger('focus');
    },

    // İptal: seçim ve form temizlenir, yeni kayıt durumuna dönülür
    clickCancelDocument: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        $t.setButtonAfterNewDocument();
    },

    // Sil: onay alınır, belge pasife alınır ve yayından kalkar (setDelete)
    clickDeleteDocument: function () {
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
                                data: { DOCUMENT_ID: $('#DOCUMENT_ID').val() },
                                beforeSend: function () {
                                    $('.frame-block').blk();
                                },
                                success: function (result) {
                                    if (result != null && result['resCode'] === 0) {
                                        $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                        $t.clearEditForm();
                                        $t.setButtonAfterNewDocument();
                                        $t.clickQGrid();
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

    // Kaydet: belge bilgisi ve (seçildiyse) PDF tek istekte gider.
    // Dosya içerdiği için form serialize() yerine FormData ile gönderilir (processData / contentType kapalı).
    // Kayıttan sonra aynı belge seçili kalır; yeni versiyon listede hemen görünür.
    clickSaveDocument: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if($($t.opt.editFormDocument).valid()) {
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
                                    data: new FormData($($t.opt.editFormDocument)[0]),
                                    processData: false,
                                    contentType: false,
                                    beforeSend: function () {
                                        $('.frame-block').blk();
                                    },
                                    success: function (result) {
                                        if (result != null && result['resCode'] === 0) {
                                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                            $t.clickQGrid();
                                            $d.selectedId = result['resObject']['DOCUMENT_ID'];
                                            $t.getSelectedId();
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

    // Ekle: formdaki bilgilerle her zaman YENİ belge eklenir (seçili belge değişmez) (setAdd).
    // Yeni belgede PDF zorunlu; form doğrulaması seçili belgede dosyayı istemediği için burada ayrıca bakılır.
    clickAddDocument: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if ($('#DOCUMENT_FILE').val() === '') {
            $.fn.ntyi({ type: 'error',layout: 'topRight',text: $d.lang['ERR_DOCUMENT_FILE']});
            return;
        }
        if($($t.opt.editFormDocument).valid()) {
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
                                    data: new FormData($($t.opt.editFormDocument)[0]),
                                    processData: false,
                                    contentType: false,
                                    beforeSend: function () {
                                        $('.frame-block').blk();
                                    },
                                    success: function (result) {
                                        if (result != null && result['resCode'] === 0) {
                                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                            $t.clickQGrid();
                                            $d.selectedId = result['resObject']['DOCUMENT_ID'];
                                            $t.getSelectedId();
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

    // Formu boşaltır, listedeki seçimi ve versiyon listesini temizler; yayın "Hayır", sıra 0 olarak başlar
    clearEditForm: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;
        $($o.editFormDocument).trigger('reset');
        $($o.editFormDocument +' input:hidden').val(null);
        $($o.editFormDocument +' select').val(null).change();
        $($o.editFormDocument).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $($o.editFormDocument).clearValidation();
        $('#jqGrid').jqGrid('resetSelection');
        $t.data.selectedId = null;
        $t.reloadGridListFile();

        $('#SORT_NO').val(0);
        $('#PUBLISH_FL').val('N').change();
    }
};
