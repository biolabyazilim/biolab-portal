/**
 * Saha Takip — Personeller
 *
 * - Solda saha personeli listesi (jqGrid). Arama kutusu kod / ad / kullanıcı adına göre süzer (getGridList).
 *   "Telefon oturumu": açık mobil oturum sayısı, "Son bağlantı": telefonun portala son isteği.
 * - Satır seçilince personel sağdaki forma dolar (getSelectedId).
 * - Kaydet / Ekle: portal kullanıcısı + personel kodu (Traccar Identifier) kaydedilir (setSave / setAdd).
 *   Sunucu, kullanıcıya "Saha personeli" (SHT_PERSONEL) rolünü yoksa otomatik verir.
 * - Telefon oturumlarını kapat: personel telefonda yeniden giriş yapmak zorunda kalır (setRevokeTokens).
 * - Sil: personel pasife alınır, telefon oturumları kapanır (setDelete).
 * Sunucu tarafı: php/personel-1.0.0.php
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-saha-takip/personel/php/personel-1.0.0@';
const langFiles = ['general.json', 'sht_personel/personel-1.0.0.json'];

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(langFiles);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(langFiles);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        selectedId:null    // listede seçili personelin PERSONEL_ID'si
    },
    opt:{
        editFormPersonel:'#editFormPersonel'
    },

    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonAfterNew();
    },

    // Portal kullanıcısı seçimi (bu şirkete bağlı kullanıcılar)
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
            $t.initUSER_ID();
            $t.initGridList();
            $t.setValidation();
        });

        $('#btnNewPersonel').click($t.clickNew);
        $('#btnCancelPersonel').click($t.clickCancel);
        $('#btnDeletePersonel').click($t.clickDelete);
        $('#btnSavePersonel').click(function () { $t.clickSave('setSave', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddPersonel').click(function () { $t.clickSave('setAdd', 'INF_ADD_WARNING_MESSAGE'); });
        $('#btnRevokeTokens').click($t.clickRevokeTokens);

        $('#btnGridSearch').click($t.clickQGrid);
        $('#gridSearchField').keyup($t.clickQGrid);
        // Personel kodu her zaman büyük harf (sunucu da büyütür)
        $('#PERSONEL_CODE').on('input', function () {
            const pos = this.selectionStart;
            this.value = this.value.toLocaleUpperCase('tr-TR').replace(/[^A-Z0-9]/g, '');
            this.setSelectionRange(pos, pos);
        });
    },

    setValidation: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.validator.addMethod('personelCode', function (value) {
            return /^[A-Z0-9]{1,20}$/.test(value);
        });
        $($t.opt.editFormPersonel).validate({
            rules: {
                USER_ID: { required: true },
                PERSONEL_CODE: { required: true, personelCode: true },
                TRACCAR_DEVICE_ID: { digits: true }
            },
            messages: {
                USER_ID: $d.lang['ERR_USER_ID'],
                PERSONEL_CODE: $d.lang['ERR_PERSONEL_CODE'],
                TRACCAR_DEVICE_ID: $d.lang['ERR_TRACCAR_DEVICE_ID']
            }
        });
    },

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        function dateTimeFormatter(cellvalue) {
            return cellvalue ? moment(cellvalue, 'YYYY-MM-DD HH:mm:ss').format('DD.MM.YYYY HH:mm') : '';
        }
        function activeFormatter(cellvalue) {
            return cellvalue === 'Y'
                ? '<span class="st-badge st-badge-on">' + $d.lang['STATUS_ACTIVE'] + '</span>'
                : '<span class="st-badge st-badge-off">' + $d.lang['STATUS_PASSIVE'] + '</span>';
        }
        function tokenFormatter(cellvalue) {
            const n = parseInt(cellvalue, 10) || 0;
            return n > 0
                ? '<span class="st-badge st-badge-on"><i class="fas fa-mobile-alt"></i> ' + n + '</span>'
                : '<span class="st-badge st-badge-off">0</span>';
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            sortname:'mt.personelCode',
            sortorder:'asc',
            colModel: [
                { label: 'ID',                         name: 'mt_personelId',      index: 'mt.personelId',   key: true, hidden: true },
                { label: $d.lang['PERSONEL_CODE'],     name: 'mt_personelCode',    index: 'mt.personelCode', width: 80 },
                { label: $d.lang['FULL_NAME'],         name: 'u_fullName',         index: 'u.fullName',      width: 160 },
                { label: $d.lang['USERNAME'],          name: 'u_username',         index: 'u.username',      width: 100 },
                { label: $d.lang['JOB_TYPE'],          name: 'mt_jobType',         index: 'mt.jobType',      width: 120 },
                { label: $d.lang['ACTIVE_TOKENS'],     name: 'activeTokens',       index: 'activeTokens',    width: 80, align: 'center', formatter: tokenFormatter, sortable: false },
                { label: $d.lang['LAST_SEEN'],         name: 'lastSeenAt',         index: 'lastSeenAt',      width: 110, formatter: dateTimeFormatter, sortable: false },
                { label: $d.lang['ACTIVE_FL'],         name: 'mt_activeFl',        index: 'mt.activeFl',     width: 70, formatter: activeFormatter },
            ],
            pager: '#jqGridPager',
            fluidHeight:false,
            height:'400',
            rowNum: 25,
            rowList: [10, 25, 50, 100],
            onSelectRow: function (rowid) {
                const data = $(this).getRowData(rowid);
                $d.selectedId = data['mt_personelId'];
                $t.getSelectedId();
            }
        });
    },

    clickQGrid: function () {
        $('#jqGrid').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchField').val()
                }
            }
        ).trigger('reloadGrid');
    },

    // Sunucu hatası penceresi (ortak)
    showError: function (text) {
        const $d = $.DLNFrame.data;
        $.fn.ntyp({
            type: 'error',
            text: text,
            modal: true,
            buttons: [ { addClass: 'btn btn-sm btn-danger', text: $d.lang['BTN_OK_TEXT'] } ]
        });
    },

    // Ortak istek: ekran kilitlenir, resCode 0 ise onOk çalışır, değilse hata penceresi açılır
    post: function (action, data, onOk) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + action,
                type: 'POST',
                dataType: 'json',
                data: data,
                beforeSend: function () {
                    $('.frame-block').blk();
                },
                success: function (result) {
                    if (result != null && result['resCode'] === 0) {
                        onOk(result);
                    }else{
                        $t.showError($d.lang['INF_ERROR'] + ': #' + (result && result['resCode']) + ' : ' + (result && result['resMsg']));
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

    // Onay penceresi; Evet'e basılınca onYes çalışır
    confirm: function (type, text, onYes) {
        const $d = $.DLNFrame.data;
        $.fn.nty({
            type: type,
            text: text,
            buttons: [
                { text: $d.lang['BTN_YES_TEXT'], onClick: function ($noty) { $noty.close(); onYes(); } },
                { text: $d.lang['BTN_NO_TEXT'] }
            ]
        });
    },

    getSelectedId: function () {
        const $t = $.DLNFrame;
        $($t.opt.editFormPersonel).clearValidation();
        $t.post('getSelectedId', { SELECTED_ID: $t.data.selectedId }, function (result) {
            const recs = result['records'];
            if(recs != null && recs.length > 0){
                $t.setFields(recs[0]);
            }
        });
    },

    // getSelectedId cevabını forma yazar (mt_ = personel, u_ = kullanıcı)
    setFields: function (data) {
        const $t = $.DLNFrame;
        const f = $t.opt.editFormPersonel;

        $(f + ' #PERSONEL_ID').val(data['mt_personelId']);
        $(f + ' #USER_ID').val(data['u_userId']).change();
        $(f + ' #PERSONEL_CODE').val(data['mt_personelCode']);
        $(f + ' #JOB_TYPE').val(data['mt_jobType']);
        $(f + ' #PHONE').val(data['mt_phone']);
        $(f + ' #TRACCAR_DEVICE_ID').val(data['mt_traccarDeviceId']);
        $(f + ' #ACTIVE_FL').val(data['mt_activeFl']).change();

        $t.setButtonAfterSelect();
    },

    // Yeni kayıt durumu: kullanıcı seçilebilir, sil / oturum kapat kapalı
    setButtonAfterNew: function () {
        $('#USER_ID').prop('disabled', false);
        $('#btnNewPersonel').prop('disabled', true);
        $('#btnCancelPersonel').prop('disabled', false);
        $('#btnDeletePersonel').prop('disabled', true);
        $('#btnRevokeTokens').prop('disabled', true);
        $('#btnSavePersonel').prop('disabled', false);
        $('#btnAddTogglePersonel').prop('disabled', false);
    },

    // Kayıtlı personel seçildi: kullanıcısı değiştirilemez (başka kullanıcı için Yeni)
    setButtonAfterSelect: function () {
        $('#USER_ID').prop('disabled', true);
        $('#btnNewPersonel').prop('disabled', false);
        $('#btnCancelPersonel').prop('disabled', false);
        $('#btnDeletePersonel').prop('disabled', false);
        $('#btnRevokeTokens').prop('disabled', false);
        $('#btnSavePersonel').prop('disabled', false);
        $('#btnAddTogglePersonel').prop('disabled', true);
    },

    clickNew: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        $t.setButtonAfterNew();
    },

    clickCancel: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        $t.setButtonAfterNew();
    },

    // Kaydet (setSave) / Ekle (setAdd). Kullanıcı kutusu kapalıyken de değeri gitsin diye form açılıp okunur.
    clickSave: function (action, warningKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const $form = $($t.opt.editFormPersonel);
        if (!$form.valid()) { return; }
        $t.confirm('information', $d.lang[warningKey], function () {
            const $user = $('#USER_ID');
            const wasDisabled = $user.prop('disabled');
            $user.prop('disabled', false);
            const data = $form.serialize();
            $user.prop('disabled', wasDisabled);

            $t.post(action, data, function (result) {
                $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                $t.clickQGrid();
                $d.selectedId = result['resObject']['PERSONEL_ID'];
                $t.getSelectedId();
            });
        });
    },

    clickDelete: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.confirm('error', $d.lang['INF_DELETE_WARNING_MESSAGE'], function () {
            $t.post('setDelete', { PERSONEL_ID: $('#PERSONEL_ID').val() }, function (result) {
                $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                $t.clearEditForm();
                $t.setButtonAfterNew();
                $t.clickQGrid();
            });
        });
    },

    clickRevokeTokens: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.confirm('warning', $d.lang['INF_REVOKE_WARNING_MESSAGE'], function () {
            $t.post('setRevokeTokens', { PERSONEL_ID: $('#PERSONEL_ID').val() }, function (result) {
                $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                $t.clickQGrid();
            });
        });
    },

    // Formu boşaltır ve listedeki seçimi kaldırır; aktif "Evet" olarak başlar
    clearEditForm: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;
        $($o.editFormPersonel).trigger('reset');
        $($o.editFormPersonel + ' input:hidden').val(null);
        $($o.editFormPersonel + ' select').val(null).change();
        $($o.editFormPersonel).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $($o.editFormPersonel).clearValidation();
        $('#jqGrid').jqGrid('resetSelection');
        $t.data.selectedId = null;
        $('#ACTIVE_FL').val('Y').change();
    }
};
