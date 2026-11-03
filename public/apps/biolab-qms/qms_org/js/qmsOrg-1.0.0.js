/**
 * Biolab QMS — Organizasyon tanımları (departman, pozisyon, lokasyon)
 *
 * - Üstteki tür düğmeleri (Departmanlar / Pozisyonlar / Lokasyonlar) listeyi ve formu o türe çevirir (TYPE).
 * - Solda seçili türün listesi (jqGrid; arama ve "pasifleri de göster"), satır seçilince kayıt sağdaki forma dolar.
 * - Pozisyon formunda departman seçimi vardır (boş = şirket geneli pozisyon).
 * - Kaydet / Ekle / Sil D.Sys düzenindedir. Kullanılmış tanım silinmez; sunucu "pasife alın" der.
 * Sunucu tarafı: php/qmsOrg-1.0.0.php (Biolab_QMS veritabanı; her değişiklik denetim kaydına yazılır)
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-qms/qms_org/php/qmsOrg-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','qms_org/qmsOrg-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','qms_org/qmsOrg-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        type:'DEPARTMENT',   // DEPARTMENT / POSITION / LOCATION
        selectedId:null,     // listede seçili (formda açık) kaydın kimliği
        highlightId:null     // kayıttan sonra listede işaretlenecek kayıt
    },
    opt:{
        editFormOrg:'#editFormOrg'
    },

    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonAfterNewOrg();
    },

    // Pozisyon formundaki departman seçimi; pasif departman görünür ama seçilemez
    initDEPARTMENT_ID: function () {
        const $t = $.DLNFrame;
        $('#DEPARTMENT_ID').slb({
            field:{
                value:'departmentId',
                text:'departmentName',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:$t.data.lang['DEPARTMENT_NONE'],
                minimumResultsForSearch:0
            },
            ajax:{
                url:proxyPath + modulePath + 'getDEPARTMENT_ID'
            }
        });
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
            $t.initDEPARTMENT_ID();
            $t.initGridList();
            $t.setValidation();
            $t.applyType();
        });

        $('#typeButtons .qm-type-btn').click(function () {
            $t.changeType($(this).attr('data-type'));
        });
        $('#btnNewOrg').click($t.clickNewOrg);
        $('#btnCancelOrg').click($t.clickCancelOrg);
        $('#btnDeleteOrg').click($t.clickDeleteOrg);
        $('#btnSaveOrg').click(function () { $t.clickSaveOrg('setSave', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddOrg').click(function () { $t.clickSaveOrg('setAdd', 'INF_ADD_WARNING_MESSAGE'); });

        $('#btnGridSearch').click($t.clickQGrid);
        $('#gridSearchField').keyup($t.clickQGrid);
        $('#SHOW_PASSIVE').change($t.clickQGrid);
    },

    setValidation: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $($t.opt.editFormOrg).validate({
            rules: {
                CODE: { required: true, maxlength: 20 },
                NAME: { required: true, maxlength: 150 }
            },
            messages: {
                CODE: $d.lang['ERR_CODE'],
                NAME: $d.lang['ERR_NAME']
            }
        });
    },

    // Tür değişti: form temizlenir, liste o türle yeniden yüklenir
    changeType: function (type) {
        const $t = $.DLNFrame;
        if (type === $t.data.type) {
            return;
        }
        $t.data.type = type;
        $t.clearEditForm();
        $t.setButtonAfterNewOrg();
        $t.applyType();
        $t.clickQGrid();
    },

    // Seçili türe göre düğme, başlık, açıklama ve departman alanı; departman kolonu yalnızca pozisyonda
    applyType: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $('#typeButtons .qm-type-btn').removeClass('active').filter('[data-type="' + $d.type + '"]').addClass('active');
        $('#TYPE').val($d.type);
        $('#formTitle').text($d.lang['FORM_TITLE_' + $d.type]);
        $('#listTitle').text($d.lang['TYPE_' + $d.type]);
        $('#labelNAME').text($d.lang['NAME_' + $d.type]);
        $('#typeInfo').text($d.lang['INFO_' + $d.type]);
        $('#groupDEPARTMENT_ID').prop('hidden', $d.type !== 'POSITION');
        const grid = $('#jqGrid');
        grid.jqGrid('setLabel', 'mt_name', $d.lang['NAME_' + $d.type]);
        grid.jqGrid($d.type === 'POSITION' ? 'showCol' : 'hideCol', 'd_departmentName');
    },

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        function activeFormatter(cellvalue) {
            return cellvalue === 'Y'
                ? '<span class="qm-badge qm-badge-on">' + $d.lang['STATUS_ACTIVE'] + '</span>'
                : '<span class="qm-badge qm-badge-off">' + $d.lang['STATUS_PASSIVE'] + '</span>';
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            sortname:'mt.code',
            sortorder:'asc',
            colModel: [
                { label: 'ID',                         name: 'mt_id',            index: 'mt.id',            key: true, hidden: true },
                { label: $d.lang['CODE'],              name: 'mt_code',          index: 'mt.code',          width: 80 },
                { label: $d.lang['NAME'],              name: 'mt_name',          index: 'mt.name',          width: 220 },
                { label: $d.lang['DEPARTMENT_NAME'],   name: 'd_departmentName', index: 'd.departmentName', width: 160, hidden: true },
                { label: $d.lang['ACTIVE_FL'],         name: 'mt_activeFl',      index: 'mt.activeFl',      width: 70, formatter: activeFormatter },
            ],
            pager: '#jqGridPager',
            fluidHeight:false,
            height:'400',
            rowNum: 25,
            rowList: [10, 25, 50, 100],
            postData:{
                TYPE: $d.type,
                SHOW_PASSIVE: 'N'
            },
            // Kayıttan sonra kaydedilen satır işaretlenir (forma yeniden yüklenmez: onSelectRow tetiklenmez)
            gridComplete: function () {
                if ($d.highlightId) {
                    $(this).jqGrid('setSelection', $d.highlightId, false);
                    $d.highlightId = null;
                }
            },
            onSelectRow: function (rowid) {
                const data = $(this).getRowData(rowid);
                $d.selectedId = data['mt_id'];
                $t.getSelectedId();
            }
        });
    },

    // Kayıttan sonra: arama ve sayfa korunarak liste yenilenir (kaydedilen satır görünür kalsın diye)
    reloadGrid: function () {
        $('#jqGrid').jqGrid('setGridParam', { datatype: 'json' }).trigger('reloadGrid');
    },

    // Listeyi tür, arama ve pasif süzgeciyle yeniden yükler
    clickQGrid: function () {
        const $t = $.DLNFrame;
        $('#jqGrid').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    TYPE: $t.data.type,
                    Q: $('#gridSearchField').val(),
                    SHOW_PASSIVE: $('#SHOW_PASSIVE').is(':checked') ? 'Y' : 'N'
                }
            }
        ).trigger('reloadGrid');
    },

    getSelectedId: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedId',
                type: 'POST',
                dataType: 'json',
                data: {
                    TYPE: $d.type,
                    SELECTED_ID: $d.selectedId
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormOrg).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    if(recs != null && recs.length > 0){
                        $t.setFields(recs[0]);
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

    setFields: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormOrg;
        $(f + ' #RECORD_ID').val(data['RECORD_ID']);
        $(f + ' #CODE').val(data['CODE']);
        $(f + ' #NAME').val(data['NAME']);
        $(f + ' #DEPARTMENT_ID').val(data['DEPARTMENT_ID']).change();
        $(f + ' #ACTIVE_FL').val(data['ACTIVE_FL']).change();
        $t.setButtonAfterSelectOrg();
    },

    setButtonAfterNewOrg: function () {
        $('#btnNewOrg').prop('disabled', true);
        $('#btnCancelOrg').prop('disabled', false);
        $('#btnDeleteOrg').prop('disabled', true);
        $('#btnSaveOrg').prop('disabled', false);
        $('#btnAddToggleOrg').prop('disabled', false);
    },

    setButtonAfterSelectOrg: function () {
        $('#btnNewOrg').prop('disabled', false);
        $('#btnCancelOrg').prop('disabled', false);
        $('#btnDeleteOrg').prop('disabled', false);
        $('#btnSaveOrg').prop('disabled', false);
        $('#btnAddToggleOrg').prop('disabled', false);
    },

    clickNewOrg: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        $t.setButtonAfterNewOrg();
        $('#CODE').trigger('focus');
    },

    clickCancelOrg: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        $t.setButtonAfterNewOrg();
    },

    // Sil: onay alınır; kullanılmış tanımı sunucu silmez, "pasife alın" mesajı döner
    clickDeleteOrg: function () {
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
                        $t.post('setDelete', { TYPE: $d.type, RECORD_ID: $('#RECORD_ID').val() }, function () {
                            $t.clearEditForm();
                            $t.setButtonAfterNewOrg();
                            $t.clickQGrid();
                        });
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },

    // Kaydet (setSave) ve Ekle (setAdd): aynı form, Ekle her zaman yeni kayıt açar.
    // Kayıttan sonra liste yenilenir ve kaydedilen satır işaretlenir (kontrol için).
    //   Yeni kayıt → form boşalır, sıradaki kayıt girilebilir.  Güncelleme → kayıt formda açık kalır.
    clickSaveOrg: function (query, confirmKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if(!$($t.opt.editFormOrg).valid()) {
            return;
        }
        $.fn.nty({
            type: 'information',
            text: $d.lang[confirmKey],
            buttons: [
                {
                    text: $d.lang['BTN_YES_TEXT'],
                    onClick: function($noty){
                        $noty.close();
                        const isNew = query === 'setAdd' || !$('#RECORD_ID').val();
                        $t.post(query, $($t.opt.editFormOrg).serialize(), function (result) {
                            const id = result['resObject']['RECORD_ID'];
                            if (isNew) {
                                $t.clearEditForm();
                                $t.setButtonAfterNewOrg();
                                $('#CODE').trigger('focus');
                            } else {
                                $d.selectedId = id;
                            }
                            $d.highlightId = id;
                            $t.reloadGrid();
                            if ($d.type === 'DEPARTMENT') {
                                $t.initDEPARTMENT_ID();   // pozisyon formundaki departman listesi güncel kalsın
                            }
                        });
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },

    // Kayıt işlemleri için ortak istek: başarıda bildirim + onSuccess, hatada sunucu mesajı
    post: function (query, data, onSuccess) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + query,
                type: 'POST',
                dataType: 'json',
                data: data,
                beforeSend: function () {
                    $('.frame-block').blk();
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0) {
                        $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resMsg']});
                        onSuccess(result);
                    }else{
                        $t.showError($d.lang['INF_ERROR'] + ': ' + (result ? result['resMsg'] : ''));
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

    // Formu boşaltır (tür korunur), listedeki seçimi kaldırır; yeni kayıt aktif başlar
    clearEditForm: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;
        $($o.editFormOrg).trigger('reset');
        $('#RECORD_ID').val(null);
        $('#TYPE').val($t.data.type);
        $('#DEPARTMENT_ID').val(null).change();
        $('#ACTIVE_FL').val('Y').change();
        $($o.editFormOrg).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $($o.editFormOrg).clearValidation();
        $('#jqGrid').jqGrid('resetSelection');
        $t.data.selectedId = null;
    }
};
