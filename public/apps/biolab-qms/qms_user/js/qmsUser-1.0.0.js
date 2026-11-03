/**
 * Biolab QMS — Kullanıcılar
 *
 * - Solda kullanıcı formu: kart (kullanıcı adı, ad soyad, unvan, sicil, telefon, e-posta, şifre, aktif),
 *   görev (departman, pozisyon, lokasyon, başlangıç tarihi) ve roller. Hepsi tek Kaydet ile gider.
 *   Seçili kullanıcının görev geçmişi formun altında görünür.
 * - Sağda kullanıcı listesi (departman süzgeci, arama, pasifler).
 * - Pozisyon seçimi departmana göre süzülür: seçilen departmanın pozisyonları + şirket geneli (departmansız) pozisyonlar.
 * - Kullanıcı silinmez, pasife alınır (bekleyen imzası varsa sunucu izin vermez). Kullanıcı adı sonradan değişmez.
 * Sunucu tarafı: php/qmsUser-1.0.0.php
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-qms/qms_user/php/qmsUser-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','qms_user/qmsUser-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','qms_user/qmsUser-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        selectedId:null,     // listede seçili (formda açık) kullanıcının USER_ID'si
        highlightId:null,    // kayıttan sonra listede işaretlenecek kullanıcı
        options:null         // {DEPARTMENT:[…], POSITION:[…], LOCATION:[…], ROLE:[…]} (getOptions)
    },
    opt:{
        editFormUser:'#editFormUser'
    },

    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.setButtonAfterNewUser();
    },

    // Departman / pozisyon / lokasyon / rol listeleri bir kez alınır; seçim kutuları ve rol kutuları bunlardan kurulur
    loadOptions: function () {
        const $t = $.DLNFrame;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getOptions',
            type: 'POST',
            dataType: 'json',
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $t.data.options = result['resObject'];
                    $t.buildSelects();
                    $t.buildRoleBoxes();
                    $t.clearEditForm();
                } else {
                    $t.showError(result ? result['resMsg'] : '');
                }
            },
            error: function (xhr) { $t.showError(xhr); }
        });
    },

    // Seçim kutusunu doldurur: aktifler + (formdaki kayıtta seçili olan) pasifler "(pasif)" ekiyle
    fillSelect: function ($sel, items, selectedId, emptyText) {
        const $d = $.DLNFrame.data;
        const keep = selectedId !== null && selectedId !== undefined ? String(selectedId) : '';
        if ($sel.data('select2')) { $sel.select2('destroy'); }
        $sel.empty().append(new Option(emptyText || $d.lang['SELECT'], '', false, keep === ''));
        items.forEach(function (i) {
            const id = String(i.id);
            if (i.activeFl !== 'Y' && id !== keep) { return; }
            $sel.append(new Option(i.name + (i.activeFl !== 'Y' ? ' (' + $d.lang['STATUS_PASSIVE'] + ')' : ''), id, false, id === keep));
        });
        $sel.select2({ width: '100%', minimumResultsForSearch: 0 });
    },

    buildSelects: function () {
        const $t = $.DLNFrame;
        const o = $t.data.options;
        $t.fillSelect($('#DEPARTMENT_ID'), o.DEPARTMENT, null);
        $t.fillSelect($('#FILTER_DEPARTMENT_ID'), o.DEPARTMENT, null, $t.data.lang['FILTER_ALL']);
        $t.fillPositions(null);
        $t.fillSelect($('#LOCATION_ID'), o.LOCATION, null, $t.data.lang['LOCATION_NONE']);
    },

    // Pozisyonlar: seçili departmanınkiler + şirket geneli (departmansız)
    fillPositions: function (selectedId) {
        const $t = $.DLNFrame;
        const deptId = $('#DEPARTMENT_ID').val();
        const items = $t.data.options.POSITION.filter(function (p) {
            return p.departmentId === null || String(p.departmentId) === String(deptId) || String(p.id) === String(selectedId);
        });
        $t.fillSelect($('#POSITION_ID'), items, selectedId);
    },

    buildRoleBoxes: function () {
        const $box = $('#roleBoxes').empty();
        $.DLNFrame.data.options.ROLE.forEach(function (r) {
            const id = 'ROLE_' + r.code;
            $box.append($('<div class="custom-control custom-checkbox">').append(
                $('<input type="checkbox" class="custom-control-input" name="ROLES[]">').attr({ id: id, value: r.code }),
                $('<label class="custom-control-label">').attr('for', id).text(r.name)));
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
            $t.initGridList();
            $t.setValidation();
            $t.loadOptions();
        });

        $('#btnNewUser').click($t.clickNewUser);
        $('#btnCancelUser').click($t.clickCancelUser);
        $('#btnSaveUser').click(function () { $t.clickSaveUser('setSave', 'INF_SAVE_WARNING_MESSAGE'); });
        $('#btnAddUser').click(function () { $t.clickSaveUser('setAdd', 'INF_ADD_WARNING_MESSAGE'); });

        $('#CB_ACTIVE_FL').on('change', function () { $('#ACTIVE_FL').val(this.checked ? 'Y' : 'N'); });
        $('#DEPARTMENT_ID').on('change', function () { $t.fillPositions($('#POSITION_ID').val()); });

        $('#FILTER_DEPARTMENT_ID').on('change', $t.clickQGrid);
        $('#btnGridSearch').click($t.clickQGrid);
        $('#gridSearchField').keyup($t.clickQGrid);
        $('#SHOW_PASSIVE').change($t.clickQGrid);
    },

    setValidation: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const isNew = function () { return !$('#USER_ID').val(); };
        $($t.opt.editFormUser).validate({
            ignore: '.select2-search__field',
            rules: {
                USERNAME: { required: isNew, maxlength: 50 },
                FULL_NAME: { required: true, maxlength: 100 },
                PASSWORD: { required: isNew, minlength: 10 },
                DEPARTMENT_ID: { required: true },
                POSITION_ID: { required: true },
                ASSIGN_DATE: { required: true }
            },
            messages: {
                USERNAME: $d.lang['ERR_USERNAME'],
                FULL_NAME: $d.lang['ERR_FULL_NAME'],
                PASSWORD: $d.lang['ERR_PASSWORD'].replace('%d', '10'),
                DEPARTMENT_ID: $d.lang['ERR_ASSIGNMENT'],
                POSITION_ID: $d.lang['ERR_ASSIGNMENT'],
                ASSIGN_DATE: $d.lang['ERR_ASSIGN_DATE']
            }
        });
    },

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        function activeFormatter(cellvalue) {
            return cellvalue === 'Y'
                ? '<span class="qm-badge qm-badge-on">' + $d.lang['STATUS_ACTIVE'] + '</span>'
                : '<span class="qm-badge qm-badge-off">' + $d.lang['STATUS_PASSIVE'] + '</span>';
        }
        // "Uzm. Dr. Ayşe Yılmaz"
        function nameFormatter(cellvalue, options, rowObject) {
            return $('<span>').text((rowObject['u_title'] ? rowObject['u_title'] + ' ' : '') + cellvalue).html();
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            sortname:'u.FULL_NAME',
            sortorder:'asc',
            colModel: [
                { label: 'ID',                         name: 'u_userId',         index: 'u.USER_ID',         key: true, hidden: true },
                { label: $d.lang['FULL_NAME'],         name: 'u_fullName',       index: 'u.FULL_NAME',       width: 150, formatter: nameFormatter },
                { label: $d.lang['USERNAME'],          name: 'u_username',       index: 'u.USERNAME',        width: 100 },
                { label: $d.lang['DEPARTMENT_ID'],     name: 'd_departmentName', index: 'd.DEPARTMENT_NAME', width: 120 },
                { label: $d.lang['POSITION_ID'],       name: 'p_positionName',   index: 'p.POSITION_NAME',   width: 130 },
                { label: $d.lang['ROLE_NAMES'],        name: 'roleNames',        index: 'roleNames',         width: 160, sortable: false },
                { label: $d.lang['ACTIVE_FL'],         name: 'u_activeFl',       index: 'u.ACTIVE_FL',       width: 65, formatter: activeFormatter },
            ],
            pager: '#jqGridPager',
            fluidHeight:false,
            height:'500',
            rowNum: 25,
            rowList: [10, 25, 50, 100],
            postData:{ SHOW_PASSIVE: 'N' },
            // Kayıttan sonra kaydedilen satır işaretlenir (forma yeniden yüklenmez: onSelectRow tetiklenmez)
            gridComplete: function () {
                if ($d.highlightId) {
                    $(this).jqGrid('setSelection', $d.highlightId, false);
                    $d.highlightId = null;
                }
            },
            onSelectRow: function (rowid) {
                const data = $(this).getRowData(rowid);
                $d.selectedId = data['u_userId'];
                $t.getSelectedId();
            }
        });
    },

    reloadGrid: function () {
        $('#jqGrid').jqGrid('setGridParam', { datatype: 'json' }).trigger('reloadGrid');
    },

    clickQGrid: function () {
        $('#jqGrid').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    DEPARTMENT_ID: $('#FILTER_DEPARTMENT_ID').val(),
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
                data: { SELECTED_ID: $d.selectedId },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($t.opt.editFormUser).clearValidation();
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
        const o = $t.data.options;
        $('#USER_ID').val(data['USER_ID']);
        $('#USERNAME').val(data['USERNAME']).prop('readonly', true);
        $('#FULL_NAME').val(data['FULL_NAME']);
        $('#TITLE').val(data['TITLE']);
        $('#REGISTRY_NO').val(data['REGISTRY_NO']);
        $('#PHONE').val(data['PHONE']);
        $('#EMAIL').val(data['EMAIL']);
        $('#PASSWORD').val('');
        $('#passwordInfo').text($t.data.lang['PASSWORD_INFO_EDIT']);
        $('#ACTIVE_FL').val(data['ACTIVE_FL']);
        $('#CB_ACTIVE_FL').prop('checked', data['ACTIVE_FL'] === 'Y');

        $t.fillSelect($('#DEPARTMENT_ID'), o.DEPARTMENT, data['DEPARTMENT_ID']);
        $t.fillPositions(data['POSITION_ID']);
        $t.fillSelect($('#LOCATION_ID'), o.LOCATION, data['LOCATION_ID'], $t.data.lang['LOCATION_NONE']);
        $('#ASSIGN_DATE').val(data['ASSIGN_DATE'] || moment().format('YYYY-MM-DD'));
        $('#ASSIGN_REASON').val('');

        $('#roleBoxes input').each(function () {
            this.checked = (data['ROLES'] || []).indexOf(this.value) >= 0;
        });

        // Görev geçmişi
        const $rows = $('#historyRows').empty();
        (data['HISTORY'] || []).forEach(function (h) {
            $rows.append($('<tr>').append(
                $('<td>').text(h.validFrom),
                $('<td>').text(h.validTo || $t.data.lang['HISTORY_CURRENT']),
                $('<td>').text(h.departmentName),
                $('<td>').text(h.positionName),
                $('<td>').text(h.locationName || ''),
                $('<td>').text(h.reason || '')));
        });
        if (!(data['HISTORY'] || []).length) {
            $rows.append($('<tr>').append($('<td colspan="6" class="text-muted">').text($t.data.lang['HISTORY_EMPTY'])));
        }
        $('#historyCard').prop('hidden', false);

        $t.setButtonAfterSelectUser();
    },

    setButtonAfterNewUser: function () {
        $('#btnNewUser').prop('disabled', true);
        $('#btnCancelUser').prop('disabled', false);
        $('#btnSaveUser').prop('disabled', false);
        $('#btnAddToggleUser').prop('disabled', false);
    },

    setButtonAfterSelectUser: function () {
        $('#btnNewUser').prop('disabled', false);
        $('#btnCancelUser').prop('disabled', false);
        $('#btnSaveUser').prop('disabled', false);
        $('#btnAddToggleUser').prop('disabled', false);
    },

    clickNewUser: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        $t.setButtonAfterNewUser();
        $('#USERNAME').trigger('focus');
    },

    clickCancelUser: function () {
        const $t = $.DLNFrame;
        $t.clearEditForm();
        $t.setButtonAfterNewUser();
    },

    // Kaydet (setSave) ve Ekle (setAdd). Kayıttan sonra liste yenilenir ve kaydedilen kullanıcı işaretlenir.
    //   Yeni kayıt → form boşalır.  Güncelleme → kullanıcı formda açık kalır (görev geçmişi yenilenir).
    clickSaveUser: function (query, confirmKey) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (query === 'setAdd') {
            $('#USER_ID').val('');   // Ekle: kullanıcı adı ve şifre yeni kullanıcı için zorunlu
        }
        if(!$($t.opt.editFormUser).valid()) {
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
                        const isNew = !$('#USER_ID').val();
                        $t.post(query, $($t.opt.editFormUser).serialize(), function (result) {
                            const id = result['resObject']['USER_ID'];
                            $d.highlightId = id;
                            if (isNew) {
                                $t.clearEditForm();
                                $t.setButtonAfterNewUser();
                            } else {
                                $d.selectedId = id;
                                $t.getSelectedId();
                            }
                            $t.reloadGrid();
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

    // Formu boşaltır: yeni kullanıcı aktif, görev bugünden başlar, varsayılan rol "Doküman kullanıcısı"
    clearEditForm: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;
        $($o.editFormUser).trigger('reset');
        $('#USER_ID').val(null);
        $('#USERNAME').prop('readonly', false);
        $('#ACTIVE_FL').val('Y');
        $('#CB_ACTIVE_FL').prop('checked', true);
        $('#passwordInfo').text($t.data.lang ? $t.data.lang['PASSWORD_INFO_NEW'] : '');
        if ($t.data.options) {
            $t.buildSelects();
        }
        $('#ASSIGN_DATE').val(moment().format('YYYY-MM-DD'));
        $('#roleBoxes input').prop('checked', false);
        $('#ROLE_QMS_USER').prop('checked', true);
        $('#historyCard').prop('hidden', true);
        $($o.editFormUser).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $($o.editFormUser).clearValidation();
        $('#jqGrid').jqGrid('resetSelection');
        $t.data.selectedId = null;
    }
};
