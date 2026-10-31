/**
 * HbRapor — Numune kabul
 *
 * - Barkod okuyucu, karakterleri çok hızlı yazıp sonunda Enter gönderir. Tuş aralıklarına bakarak
 *   "okuyucudan okundu" ile "elle yazıldı" ayrılır. Elle girişte barkodun ikinci kez yazılması istenir.
 * - Doğum tarihinden yaş ve algoritma grubu hesaplanır.
 * - Kayıt ve "bugün kabul edilenler" listesi PHP tarafına (php/sampleAcceptance-1.0.0.php) bağlanacak.
 *   Proxy henüz kurulmadığı için kayıt şimdilik yapılmaz; ?demo=1 ile ekran örnek verilerle görülebilir.
 */
const authPath = '../../../api/auth.php';
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/hb-elektroforez/sample_acceptance/php/sampleAcceptance-1.0.0@';
const Moment = moment;

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','sample_acceptance/sampleAcceptance-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','sample_acceptance/sampleAcceptance-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        user: null,
        todayList: [],
        barcodeKeyTimes: [],
        barcodeManual: false,
        lookedUpBarcode: null,   // son sorgulanan barkod (aynı barkod tekrar sorgulanmasın)
       // demo: /[?&]demo=1\b/.test(location.search),
        selectedId:null
    },
    opt: {
        loginPage: '../login.html',
        editFormSA: '#editFormSA',
        // Okuyucuda tuşlar arası süre genelde < 30 ms; bu ortalamanın altı "okuyucu" sayılır
        scannerMaxAvgMs: 35,
        scannerMinLength: 6,
        dateFormat: 'DD.MM.YYYY',
        // Yaşa göre algoritma grubu. Büyükten küçüğe sıralı; ilk uyan grup seçilir.
        // Sınırlar algoritma belgesinden (hplcalgoson01.06): A < 1 yaş, B 1–6 yaş, C ≥ 6 yaş. Sunucuda: HbReportRules::ageGroup
        algorithmGroups: [
            { code: 'C', minMonths: 72, label: 'C (≥ 6 yaş)' },
            { code: 'B', minMonths: 12, label: 'B (1–6 yaş)' },
            { code: 'A', minMonths: 0,  label: 'A (< 1 yaş)' }
        ],
        // Numune red nedenleri. TODO: laboratuvarın kendi listesi netleşince güncellenecek.
        rejectReasons: ['Hemolizli', 'Pıhtılı', 'Yetersiz miktar', 'Yanlış tüp', 'Etiket / barkod hatalı', 'Diğer']
    },

    init: function () {
        const $t = $.DLNFrame;
        moment.locale('tr');
        $t.setEvent();
        $t.setEventBarcode();
        // D.Sys düzeni: dil dosyaları yüklenince 'langLoaded' tetiklenir, grid orada kurulur (setEvent)
      //  $.DLN.LangManager.loadLanguageResource(['general.json', 'sample_acceptance/sampleAcceptance-1.0.0.json']);
        $t.setValidation();
        $t.loadUser();
       // $t.renderTodayList();

        $t.initGENDER_ID();

        // İş listesinden "Yeni kabul aç" ile gelindiyse (?barcode=…): barkod cihazdan geldiği için doğru kabul edilir,
        // elle giriş sayılmaz; sistemde ne olduğu hemen gösterilir ve imleç ad soyad alanına geçer
        const preset = (new URLSearchParams(location.search).get('barcode') || '').replace(/\D+/g, '');
        if (preset) {
            $('#BARCODE').val(preset);
            $t.setBarcodeSource(false);
            $('#barcodeInfo').html('<i class="fas fa-vial"></i> Cihaz sonucundan alındı');
            $t.lookupBarcode(preset);
            $('#PATIENT_NAME').trigger('focus');
        } else {
            $('#BARCODE').trigger('focus');
        }
    },

    initGENDER_ID: function () {
        $('#GENDER_ID').slb({
            field:{
                value:'genderId',
                text:'gender',
                disabledFL:'activeFl'
            },
            select2:{
                placeholder:'',
                minimumResultsForSearch:-1
            },
            ajax:{
                url:proxyPath + modulePath + 'getGENDER_ID'
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
            $t.initGridList();

        });

        $('#btnSave').click($t.clickSave);
        $('#btnReject').click($t.clickReject);
        $('#btnRejectConfirm').click($t.clickRejectConfirm);

    },

    // Barkod okuyucu, elle giriş ve doğum tarihi olayları
    setEventBarcode: function () {
        const $t = $.DLNFrame;

        $('#BARCODE')
            .on('keydown', function (e) {
                if (e.key === 'Enter') {
                    // Okuyucu sonda Enter gönderir: kaynağı belirle, sistemde ne var sorgula, sıradaki alana geç
                    e.preventDefault();
                    if ($.trim(this.value) !== '') {
                        $t.detectBarcodeSource();
                        $t.lookupBarcode($.trim(this.value));
                        $t.focusNextAfterBarcode();
                    }
                    return;
                }
                if (e.key && e.key.length === 1) {
                    $t.data.barcodeKeyTimes.push(performance.now());
                }
            })
            .on('input', function () {
                const clean = this.value.replace(/\D+/g, '');   // sadece rakam
                if (clean !== this.value) { this.value = clean; }
                if (clean === '') { $t.resetBarcodeState(); }
            })
            .on('paste', function () {
                $t.data.barcodeKeyTimes = [];                    // yapıştırma elle giriş sayılır
                setTimeout(function () { $t.setBarcodeSource(true); }, 0);
            })
            .on('blur', function () {
                const v = $.trim(this.value);
                if (v !== '' && v !== $t.data.lookedUpBarcode) {
                    if ($('#barcodeInfo').prop('hidden')) { $t.detectBarcodeSource(); }
                    $t.lookupBarcode(v);
                }
            });

        $('#BARCODE_CONFIRM').on('input', function () {
            this.value = this.value.replace(/\D+/g, '');
        });

        // Doğum tarihi tarih seçicisi (div.date-base): tarih değişince yaş ve algoritma grubu
        $('#BIRTH_DATE').closest('.date-base').on('dp.change', $t.updateAge);
        $('#BIRTH_DATE').on('change blur', $t.updateAge);
    },

    // Barkod okutulunca sistemde ne olduğunu gösterir: daha önce kabul edildi mi, cihazda sonucu var mı
    lookupBarcode: function (barcode) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!barcode || barcode === $d.lookedUpBarcode) { return; }
        $d.lookedUpBarcode = barcode;

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getBarcodeInfo',
            type: 'POST',
            dataType: 'json',
            data: { BARCODE: barcode },
            success: function (result) {
                if (!result || result['resCode'] !== 0 || $.trim($('#BARCODE').val()) !== barcode) { return; }
                $t.renderBarcodeLookup(result['records'] || {});
            }
        });
    },

    renderBarcodeLookup: function (info) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const $box = $('#barcodeLookup').empty().removeClass('is-warn is-info is-new');
        const esc = function (v) { return $('<span>').text(v === null || v === undefined ? '—' : v).html(); };
        const parts = [];

        if (info.acceptance) {
            const a = info.acceptance;
            $box.addClass('is-warn');
            parts.push('<div><i class="fas fa-exclamation-triangle"></i> Bu barkod <strong>'
                + esc(moment(a.acceptedAt, 'YYYY-MM-DD HH:mm:ss').format('DD.MM.YYYY HH:mm'))
                + '</strong> tarihinde kabul edilmiş: ' + esc(a.patientName) + ' · dosya ' + esc(a.fileNo) + '</div>');
            if (a.status === 'REJECTED') {
                parts.push('<div><i class="fas fa-ban"></i> Numune <strong>reddedildi</strong>: ' + esc(a.rejectReason) + '</div>');
            }
            // Kayıt forma yüklenir; aynı barkod ikinci kez kaydedilmez
            $d.selectedId = a.sampleAcceptanceId;
            $t.getSelectedId();
        }

        if (info.runs && info.runs.length) {
            if (!info.acceptance) { $box.addClass('is-info'); }
            parts.push('<div><i class="fas fa-vial"></i> Cihazda ' + info.runs.length + ' sonuç var:</div>');
            info.runs.forEach(function (r) {
                parts.push('<div class="sa-lookup-run">'
                    + esc(moment(r.injectionDate, 'YYYY-MM-DD').format('DD.MM.YYYY')) + ' · enj. ' + esc(r.injectionNo)
                    + ' · A2 <strong>' + esc(r.a2) + '</strong> · F <strong>' + esc(r.f) + '</strong> · A1c ' + esc(r.a1c)
                    + (r.comment ? ' · <em>' + esc(r.comment) + '</em>' : '') + '</div>');
            });
            if (!info.acceptance) {
                parts.push('<div class="sa-lookup-note">Kaydedince bu sonuç numuneye bağlanır.</div>');
            }
        }

        if (!info.acceptance && !(info.runs && info.runs.length)) {
            $box.addClass('is-new');
            parts.push('<div><i class="fas fa-plus-circle"></i> Kayıtlı değil, yeni numune · cihazda sonucu yok</div>');
        }

        $box.html(parts.join('')).prop('hidden', false);
    },

    initGridList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        // Barkod + elle girildiyse "elle" etiketi
        function barcodeFormatter(cellvalue, options, rowObject) {
            const manual = rowObject['mt_barcodeManualFl'] === 'Y'
                ? ' <span class="sa-badge sa-badge-manual">' + $d.lang['MANUAL_ENTRY'] + '</span>' : '';
            return '<span class="sa-mono">' + $('<span>').text(cellvalue || '').html() + '</span>' + manual;
        }
        // Kabul zamanı: "2026-10-19 09:12:05" → "09:12"
        function timeFormatter(cellvalue) {
            return cellvalue ? moment(cellvalue, 'YYYY-MM-DD HH:mm:ss').format('HH:mm') : '';
        }
        // Durum kodu → dil dosyasındaki metin ve rozet
        function statusFormatter(cellvalue) {
            const cls = { WAITING: 'sa-badge-wait', RESULTED: 'sa-badge-info', RERUN: 'sa-badge-info', REJECTED: 'sa-badge-rejected' }[cellvalue] || 'sa-badge-done';
            return '<span class="sa-badge ' + cls + '">' + ($d.lang['STATUS_' + cellvalue] || cellvalue || '') + '</span>';
        }

        $('#jqGrid').jqg({
            url: proxyPath + modulePath + 'getGridList',
            sortname:'mt.sampleAcceptanceId',
            sortorder:'desc',
            colModel: [
                { label: 'ID',                name: 'mt_sampleAcceptanceId', index: 'mt.sampleAcceptanceId', key: true, hidden: true },
                { label: '',                  name: 'mt_barcodeManualFl',    index: 'mt.barcodeManualFl',    hidden: true },
                { label: $d.lang['BARCODE'],  name: 'mt_barcode',            index: 'mt.barcode',            width: 130, formatter: barcodeFormatter },
                { label: $d.lang['PATIENT'],  name: 'mt_patientNameSurname', index: 'mt.patientNameSurname', width: 200 },
                { label: $d.lang['FILE_NO'],  name: 'mt_fileNo',             index: 'mt.fileNo',             width: 100 },
                { label: $d.lang['GENDER'],   name: 'g_gender',              index: 'g.gender',              width: 80 },
                { label: $d.lang['ACCEPT'],   name: 'mt_acceptedAt',         index: 'mt.acceptedAt',         width: 70, formatter: timeFormatter },
                { label: $d.lang['STATUS'],   name: 'mt_status',             index: 'mt.status',             width: 130, formatter: statusFormatter },
            ],
            pager: '#jqGridPager',
            fluidHeight:false,
            height:'143',
            rowNum: 5,
            rowList: [5,10, 20, 30, 40, 50],
            postData:{
              //  PATIENT_ID:$d.clientId
            },
            onSelectRow: function (rowid) {
                const $this = $(this);
                const data = $this.getRowData(rowid);
                $d.selectedId = data['mt_sampleAcceptanceId'];
                $t.getSelectedId();
            },
            gridComplete: function () {
                // const $this = $(this);
                // $this.find(".btnSel").click(function () {
                //     const selectedRow = $this.jqGrid().getRowData($(this).attr("data-id"));
                // });
            },
        });


        $('#btnGridSearch').click($t.clickQGrid);
        $('#gridSearchField').keyup($t.clickQGrid);

        $('#ddmGridSearch > li > a').click(function () {
            $('#ddmGridSearch > li > a').each(function (i, e) {
                if(!$(e).find('i').hasClass('hide')){
                    $(e).find('i').addClass('hide')
                }
            });

            $('#ddmbGridSearch > span:first').html($(this).text());
            $(this).find('i').removeClass('hide');
            $('#gridQueryType').val($(this).attr('data-id'));
            $t.clickQGrid();

        })
    },
    clickQGrid: function() {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $('#jqGrid').jqGrid('setGridParam',
            {
                page: 1,
                datatype: 'json',
                postData:{
                    Q: $('#gridSearchField').val(),
                    T: $('#gridQueryType').val(),
                    //PATIENT_ID:$d.clientId
                }
            }
        ).trigger("reloadGrid");
    },

    getSelectedId: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const $o = $t.opt;
        $.ajaxq('frame',
            {
                url: proxyPath + modulePath + 'getSelectedId',
                type: 'POST',
                dataType: 'json',
                data: {
                //    PATIENT_ID: $d.clientId,
                    SELECTED_ID: $d.selectedId
                },
                beforeSend: function () {
                    $('.frame-block').blk();
                    $($o.editFormSA).clearValidation();
                },
                success: function (result) {
                    const recs = result['records'];
                    let rec = null;
                    if(recs!= null && recs.length > 0){
                        rec = recs[0];
                        $t.setFields(rec);
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

    setFields: function (data) {
        const $t = $.DLNFrame;

        const f = $t.opt.editFormSA;

        $(f + ' #SAMPLE_ACCEPTANCE_ID').val(data['mt_sampleAcceptanceId']);
        $(f + ' #BARCODE_MANUAL_FL').val(data['mt_barcodeManualFl']);
        $(f + ' #BARCODE').val(data['mt_barcode']);
        $(f + ' #PATIENT_NAME').val(data['mt_patientNameSurname']);
        $(f + ' #FILE_NO').val(data['mt_fileNo']);
        $(f + ' #GENDER_ID').val(data['g_genderId']).change();
        $(f + ' #INSTITUTION').val(data['mt_institution']);
        // "1993-03-22 00:00:00" → "22.03.1993"; ardından yaş ve algoritma grubu yeniden hesaplanır
        $(f + ' #BIRTH_DATE').val(data['mt_birthDate']
            ? moment(data['mt_birthDate'], 'YYYY-MM-DD HH:mm:ss').format($t.opt.dateFormat)
            : '');
        $t.updateAge();

        // Red düğmesi: sadece reddedilebilir durumda (sonuç bekleniyor / onay bekliyor / tekrar çalışma)
        $t.data.selectedStatus = data['mt_status'];
        $('#btnReject').prop('hidden', ['WAITING', 'RESULTED', 'RERUN'].indexOf(data['mt_status']) === -1);
    },

    /* ---------- Numune reddi ---------- */

    clickReject: function () {
        const $t = $.DLNFrame;
        const $select = $('#REJECT_REASON_SELECT').empty();
        $t.opt.rejectReasons.forEach(function (r) { $select.append($('<option>').val(r).text(r)); });
        $('#REJECT_REASON_NOTE').val('');
        $('#rejectMsg').prop('hidden', true).text('');
        $('.js-reject-barcode').text($('#BARCODE').val());
        $('#modalReject').modal('show');
    },

    clickRejectConfirm: function () {
        const $t = $.DLNFrame;
        const choice = $('#REJECT_REASON_SELECT').val();
        const note = $.trim($('#REJECT_REASON_NOTE').val());
        if (choice === 'Diğer' && note === '') {
            $('#rejectMsg').text('“Diğer” için açıklama yazın.').prop('hidden', false);
            return;
        }
        const reason = note ? choice + ': ' + note : choice;

        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'setReject',
            type: 'POST',
            dataType: 'json',
            data: { SAMPLE_ACCEPTANCE_ID: $('#SAMPLE_ACCEPTANCE_ID').val(), REJECT_REASON: reason },
            beforeSend: function () { $('#btnRejectConfirm').prop('disabled', true); },
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $('#modalReject').modal('hide');
                    $.fn.ntyi({ type: 'information', layout: 'topRight', text: result['resMsg'] });
                    $t.clickQGrid();
                    $t.clearEditForm();
                } else {
                    $('#rejectMsg').text((result && result['resMsg']) || 'Reddedilemedi.').prop('hidden', false);
                }
            },
            complete: function () { $('#btnRejectConfirm').prop('disabled', false); }
        });
    },

    setValidation: function () {
        const $t = $.DLNFrame;

        $.validator.addMethod('trDate', function (value, el) {
            return this.optional(el) || moment(value, $t.opt.dateFormat, true).isValid();
        }, 'Tarihi gg.aa.yyyy biçiminde yazın.');

        $.validator.addMethod('notFuture', function (value, el) {
            const d = moment(value, $t.opt.dateFormat, true);
            return this.optional(el) || !d.isValid() || !d.isAfter(moment(), 'day');
        }, 'Doğum tarihi bugünden ileri olamaz.');

        $($t.opt.editFormSA).validate({
            ignore: ':hidden',
            rules: {
                BARCODE: { required: true, digits: true, minlength: 6 },
                BARCODE_CONFIRM: { required: true, equalTo: '#BARCODE' },
                PATIENT_NAME: { required: true },
                FILE_NO: { required: true },
                GENDER_ID: { required: true },
                BIRTH_DATE: { required: true, trDate: true, notFuture: true }
            },
            messages: {
                BARCODE: { required: 'Barkodu okutun veya yazın.', digits: 'Barkod yalnızca rakam içerir.', minlength: 'Barkod en az 6 haneli olmalı.' },
                BARCODE_CONFIRM: { required: 'Barkodu tekrar yazın.', equalTo: 'İki barkod eşleşmiyor.' },
                PATIENT_NAME: 'Ad soyad girin.',
                FILE_NO: 'Dosya no girin.',
                GENDER_ID: 'Cinsiyet seçin.',
           //     BIRTH_DATE: { required: 'Doğum tarihi girin.' }
            },
            submitHandler: function () {
                $t.save();
                return false;
            }
        });
    },

    /* ---------- Barkod ---------- */

    detectBarcodeSource: function () {
        const $t = $.DLNFrame;
        const times = $t.data.barcodeKeyTimes;
        const value = $.trim($('#BARCODE').val());
        let manual = true;

        if (times.length >= $t.opt.scannerMinLength && times.length >= value.length) {
            const avg = (times[times.length - 1] - times[0]) / (times.length - 1);
            manual = avg > $t.opt.scannerMaxAvgMs;
        }
        $t.setBarcodeSource(manual);
    },

    setBarcodeSource: function (manual) {
        const $t = $.DLNFrame;
        $t.data.barcodeManual = manual;
        $('#BARCODE_MANUAL_FL').val(manual ? 'Y' : 'N');

        $('#BARCODE').toggleClass('is-scanned', !manual);
        $('#barcodeInfo')
            .prop('hidden', false)
            .toggleClass('is-scanned', !manual)
            .toggleClass('is-manual', manual)
            .html(manual
                ? '<i class="fas fa-keyboard"></i> Elle girildi · barkodu tekrar yazın'
                : '<i class="fas fa-check"></i> Okuyucudan okundu');

        $('#barcodeConfirmGroup').prop('hidden', !manual);
        if (!manual) { $('#BARCODE_CONFIRM').val(''); }
    },

    resetBarcodeState: function () {
        const $t = $.DLNFrame;
        $t.data.barcodeKeyTimes = [];
        $t.data.barcodeManual = false;
        $('#BARCODE_MANUAL_FL').val('N');
        $('#BARCODE').removeClass('is-scanned');
        $('#barcodeInfo').prop('hidden', true).removeClass('is-scanned is-manual').empty();
        $('#barcodeConfirmGroup').prop('hidden', true);
        $('#BARCODE_CONFIRM').val('');
        $t.data.lookedUpBarcode = null;
        $('#barcodeLookup').prop('hidden', true).empty();
    },

    focusNextAfterBarcode: function () {
        const $t = $.DLNFrame;
        $($t.data.barcodeManual ? '#BARCODE_CONFIRM' : '#PATIENT_NAME').trigger('focus');
    },

    /* ---------- Doğum tarihi / yaş ---------- */

    // "25112011" → "25.11.2011"
    formatDateInput: function (value) {
        const digits = value.replace(/\D+/g, '').slice(0, 8);
        if (digits.length <= 2) { return digits; }
        if (digits.length <= 4) { return digits.slice(0, 2) + '.' + digits.slice(2); }
        return digits.slice(0, 2) + '.' + digits.slice(2, 4) + '.' + digits.slice(4);
    },

    updateAge: function () {
        const $t = $.DLNFrame;
        const birth = moment($('#BIRTH_DATE').val(), $t.opt.dateFormat, true);

        if (!birth.isValid() || birth.isAfter(moment(), 'day')) {
            $('#ageInfo').prop('hidden', true);
            return;
        }

        const totalMonths = moment().diff(birth, 'months');
        const years = Math.floor(totalMonths / 12);
        const months = totalMonths % 12;
        const ageText = years > 0 ? years + ' yıl ' + months + ' ay' : months + ' ay';

        const group = $t.opt.algorithmGroups.find(function (g) { return totalMonths >= g.minMonths; });

        $('#ageInfo .js-age').text(ageText);
        $('#ageInfo .js-algorithm').text(group ? group.label : 'tanımlı değil');
        $('#ageInfo').prop('hidden', false);
    },

    /* ---------- Kayıt ---------- */

    getFormData: function () {
        return {
            barcode: $.trim($('#BARCODE').val()),
            manual: $('#BARCODE_MANUAL_FL').val() === 'Y',
            patientName: $.trim($('#PATIENT_NAME').val()),
            fileNo: $.trim($('#FILE_NO').val()),
            genderId: $('#GENDER_ID').val(),
            birthDate: $('#BIRTH_DATE').val(),
            institution: $.trim($('#INSTITUTION').val())
        };
    },


    clickSave: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if($($t.opt.editFormSA).valid()) {
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
                                    data: $($t.opt.editFormSA).serialize()+ '&' + $.param({PATIENT_ID:$d.clientId}),
                                    beforeSend: function () {
                                        $('.frame-block').blk();
                                    },
                                    success: function (result) {

                                        if (result!=null && result['resCode'] === 0) {
                                            $t.clickQGrid();
                                            $.fn.ntyi({ type: 'information',layout: 'topRight',text: result['resCode'] + ' : ' + result['resMsg']});
                                            $t.clearEditForm();
                                       //     $t.setButtonAfterSaveSA();
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

    resetForm: function () {
        const $t = $.DLNFrame;
        const form = $($t.opt.editFormSA);
        form[0].reset();
        form.validate().resetForm();
        form.find('.error').removeClass('error');
        $t.resetBarcodeState();
        $('#ageInfo').prop('hidden', true);
        $('#BARCODE').trigger('focus');
    },

    showFormMsg: function (type, text) {
        $('#formMsg')
            .removeClass('alert-success alert-warning alert-danger')
            .addClass('alert-' + type)
            .text(text)
            .prop('hidden', false);
        clearTimeout($.DLNFrame.data.msgTimer);
        $.DLNFrame.data.msgTimer = setTimeout(function () { $('#formMsg').prop('hidden', true); }, 4000);
    },



    setD10Status: function (ok, text) {
        $('#d10Status')
            .toggleClass('is-error', !ok)
            .prop('hidden', false)
            .find('i').attr('class', ok ? 'far fa-check-circle' : 'fas fa-exclamation-triangle');
        $('#d10Status .sa-status-text').text(text);
    },

    /* ---------- Oturum ---------- */

    loadUser: function () {
        const $t = $.DLNFrame;
        // Demo yalnızca görünüm önizlemesidir; veri ve kayıt yok, oturum aranmaz.
        // Asıl koruma sunucu tarafındadır (PHP oturum kontrolü).
        if ($t.data.demo) { return; }
        $.ajax({ url: authPath + '?action=me', dataType: 'json', cache: false })
            .done(function (res) {
                if (res && res.ok) { $t.data.user = res.user; } else { $t.toLogin(); }
            })
            // Sunucuya ulaşılamadıysa giriş ekranına atma (giriş ↔ ana ekran döngüsü olur); sadece "oturum yok" cevabında dön
            .fail(function () { $('body').prepend('<div class="alert alert-danger m-3">Sunucuya ulaşılamadı. Sayfayı yenileyin.</div>'); });
    },

    toLogin: function () {
        window.top.location.replace(new URL($.DLNFrame.opt.loginPage, location.href).href);
    },

    clearEditForm: function () {
        const $t = $.DLNFrame;
        const $o = $t.opt;

       // $('#fileList').empty();
        $($o.editFormSA).trigger('reset');
        $($o.editFormSA +' input:hidden').val(null);
        $($o.editFormSA +' select').val(null).change();
        $($o.editFormSA).find('.valid').removeClass('valid').closest('.form-group').removeClass('has-error has-success');
        $('#jqGrid').jqGrid('resetSelection');
        $('#BARCODE_MANUAL_FL').val('N');
        $t.resetBarcodeState();
        $('#ageInfo').prop('hidden', true);
        $('#btnReject').prop('hidden', true);
        $t.data.selectedStatus = null;
        $('#BARCODE').trigger('focus');

    },
};
