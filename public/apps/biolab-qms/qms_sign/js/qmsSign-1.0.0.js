/**
 * Biolab QMS — İmzalarım
 *
 * - Solda oturumdaki kullanıcının sırası gelmiş imzaları. Satır seçilince belge sağda açılır (PDF.js; telefonda da çalışır).
 *   Belge api/qms-file.php'den gelir; sunucu "belge açıldı" kaydını orada yazar.
 * - Belge sonuna kadar kaydırılmadan İmzala açılmaz. Sonuna kadar okunan belgeler listede işaretlenip tek imzayla imzalanabilir.
 * - İmza alanı: fare, parmak ya da kalem (pointer olayları). Çizgiler noktalarıyla (x,y,t) gönderilir; boş imza kabul edilmez.
 * - İmza ve red için giriş şifresi yeniden sorulur. Mesajlar noty ile gösterilir.
 * Sunucu tarafı: php/qmsSign-1.0.0.php
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-qms/qms_sign/php/qmsSign-1.0.0@';
// Belge dosyası (web kökü dışındaki depodan, yetki kontrolüyle)
const fileApi = '../../../api/qms-file.php';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','qms_sign/qmsSign-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','qms_sign/qmsSign-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        tab:'PENDING',     // PENDING: imzamı bekleyenler · SIGNED: imzaladıklarım (salt okunur)
        list:[],           // imzamı bekleyenler (getList)
        signed:[],         // imzaladıklarım (getSigned)
        current:null,      // açık belge (getDetail / getSignedDetail; readOnly: imzaladığı belge)
        read:{},           // bu oturumda sonuna kadar okunan adımlar: {stepId: true}
        checked:{},        // toplu imza için işaretlenenler
        strokes:[],        // imza çizgileri: [[[x,y,t],…],…]
        drawing:null,      // çizilmekte olan çizgi
        startedAt:0,
        renderToken:0,     // belge değişince eski çizim yarıda bırakılır
        renderDone:false   // bütün sayfalar çizildi mi (çizilmeden sona kaydırmak okundu sayılmaz)
    },
    opt:{
        minPoints:10,
        workerSrc:'../../../lib/plugins/pdfjs-2.6.347/pdf.worker.js'
    },

    init: function () {
        const $t = $.DLNFrame;
        if (window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = $t.opt.workerSrc;
        }
        $t.setEvent();
        $t.initPad();
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
            $t.loadList();
        });

        $('#btnRefresh').click(function () { $d.tab === 'SIGNED' ? $t.loadSigned() : $t.loadList(); });
        $('#tabs').on('click', '.btn', function () {
            $('#tabs .btn').removeClass('active');
            $(this).addClass('active');
            $d.tab = $(this).data('tab');
            $t.closeDetail();
            $d.tab === 'SIGNED' ? $t.loadSigned() : $t.loadList();
        });
        $('#listRows').on('click', 'tr[data-step]', function (e) {
            if ($(e.target).is('input')) { return; }
            $t.openStep(parseInt($(this).data('step'), 10));
        });
        $('#listRows').on('click', 'tr[data-revision]', function (e) {
            if ($(e.target).closest('a').length) { return; }   // indirme bağlantısı belgeyi açmaz
            $t.openSigned(parseInt($(this).data('revision'), 10));
        });
        $('#listRows').on('change', '.qm-sel', function () {
            $d.checked[$(this).data('step')] = this.checked;
            $t.updateSignButton();
        });
        $('#viewer').on('scroll', function () { $t.checkRead(); });

        $('#btnClearPad').click($t.clearPad);
        $('#btnSign').click($t.clickSign);
        $('#btnReject').click(function () {
            $('#rejectBox, #btnRejectConfirm').prop('hidden', false);
            $('#btnReject').prop('hidden', true);
            $('#REJECT_NOTE').trigger('focus');
        });
        $('#btnRejectConfirm').click($t.clickReject);
    },

    loadList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.request('getList', {}, function (result) {
            $d.list = result['records'] || [];
            const ids = $d.list.map(function (r) { return String(r.stepId); });
            Object.keys($d.checked).forEach(function (k) { if (ids.indexOf(k) < 0) { delete $d.checked[k]; } });
            $('#pendingCount').text($d.list.length ? '(' + $d.list.length + ')' : '');
            if ($d.tab !== 'PENDING') { return; }
            $t.renderList();
            if ($d.current && !$d.current.readOnly && ids.indexOf(String($d.current.stepId)) < 0) {
                $t.closeDetail();
            }
        });
    },

    loadSigned: function () {
        const $t = $.DLNFrame;
        $t.request('getSigned', {}, function (result) {
            $t.data.signed = result['records'] || [];
            $t.renderList();
        });
    },

    renderList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        if ($d.tab === 'SIGNED') {
            $t.renderSigned();
            return;
        }
        $('#listInfo').text(L['LIST_INFO']);
        const $body = $('#listRows').empty();
        if (!$d.list.length) {
            $body.append($('<tr>').append($('<td class="text-muted">').text(L['LIST_EMPTY'])));
        }
        $d.list.forEach(function (r) {
            const read = !!$d.read[r.stepId];
            const code = (r.docCode || '') + (r.variantName ? ' · ' + r.variantName : '');
            $body.append($('<tr>').attr('data-step', r.stepId).toggleClass('is-current', !!$d.current && $d.current.stepId === r.stepId).append(
                $('<td style="width:24px">').append($('<input type="checkbox" class="qm-sel">').attr('data-step', r.stepId)
                    .prop('disabled', !read).prop('checked', read && !!$d.checked[r.stepId]).attr('title', read ? '' : L['ERR_NOT_READ'])),
                $('<td>').append(
                    $('<div class="code">').text(code + ' · ' + L['REVISION'] + ' ' + $t.pad(r.revisionNo)),
                    $('<div class="title">').text(r.docTitle)),
                $('<td style="white-space:nowrap">').append(
                    $('<div>').append($('<span class="qm-badge qm-badge-wait">').text(L['ROLE_' + r.signRole])),
                    $('<div class="text-muted small mt-1">').text(r.activatedAt || ''),
                    $('<div class="small mt-1">').append(read
                        ? $('<span class="qm-badge qm-badge-ok">').text(L['VIEWED'])
                        : $('<span class="qm-badge qm-badge-new">').text(r.viewed === 'Y' ? L['VIEWED'] : L['NOT_VIEWED'])))
            ));
        });
        $t.updateSignButton();
    },

    // İmzaladıklarım: belge, adımım, imza zamanı, belgenin bugünkü durumu (imzadaysa sıranın kimde olduğu)
    renderSigned: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        $('#listInfo').text(L['SIGNED_INFO']);
        const $body = $('#listRows').empty();
        if (!$d.signed.length) {
            $body.append($('<tr>').append($('<td class="text-muted">').text(L['SIGNED_EMPTY'])));
        }
        $d.signed.forEach(function (r) {
            const code = (r.docCode || '') + (r.variantName ? ' · ' + r.variantName : '');
            let status = L['REV_' + r.revStatus] || r.revStatus;
            if (r.revStatus === 'SIGNING' && r.waitingRole) {
                status += ' · ' + L['WAITING_FOR'].replace('%s', L['ROLE_' + r.waitingRole]);
            }
            $body.append($('<tr>').attr('data-revision', r.revisionId)
                .toggleClass('is-current', !!$d.current && $d.current.readOnly && $d.current.revisionId === r.revisionId).append(
                $('<td>').append(
                    $('<div class="code">').text(code + ' · ' + L['REVISION'] + ' ' + $t.pad(r.revisionNo)),
                    $('<div class="title">').text(r.docTitle),
                    $('<div class="small mt-1">').append($('<span class="qm-badge">')
                        .addClass(r.revStatus === 'EFFECTIVE' ? 'qm-badge-ok' : (r.revStatus === 'SIGNING' ? 'qm-badge-done' : 'qm-badge-new')).text(status))),
                $('<td style="white-space:nowrap">').append(
                    $('<div>').append($('<span class="qm-badge qm-badge-new">').text(L['ROLE_' + r.signRole])),
                    $('<div class="text-muted small mt-1">').text(L['SIGNED_AT'] + ': ' + r.signedAt),
                    $('<div class="mt-1">').append($t.downloadLinks(r)))
            ));
        });
        $('#signCard').prop('hidden', true);
    },

    // İmzalı PDF'i indirme bağlantıları (r: revisionId, certOnly). Onay sayfası belgeyle birleştirilemediyse ayrı dosya da verilir.
    downloadLinks: function (r) {
        const L = $.DLNFrame.data.lang;
        const url = fileApi + '?revision=' + r.revisionId + '&download=1';
        const $box = $('<span>').append($('<a class="btn btn-sm btn-outline-primary py-0">').attr({ href: url, download: '' })
            .append($('<i class="fas fa-download">'), document.createTextNode(' ' + L['BTN_DOWNLOAD'])));
        if (r.certOnly) {
            $box.append(document.createTextNode(' '), $('<a class="btn btn-sm btn-outline-secondary py-0">').attr({ href: url + '&kind=cert', download: '' })
                .append($('<i class="fas fa-file-signature">'), document.createTextNode(' ' + L['BTN_DOWNLOAD_CERT'])));
        }
        return $box;
    },

    // İmzaladığı belge salt okunur açılır (imza alanı yok, okundu takibi yok)
    openSigned: function (revisionId) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.request('getSignedDetail', { REVISION_ID: revisionId }, function (result) {
            $d.current = result['resObject'];
            $t.renderDetail();
            $t.renderList();
            $t.loadPdf();
        });
    },

    openStep: function (stepId) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.request('getDetail', { SIGN_STEP_ID: stepId }, function (result) {
            $d.current = result['resObject'];
            $t.renderDetail();
            $t.renderList();
            $t.loadPdf();
        });
    },

    closeDetail: function () {
        const $d = $.DLNFrame.data;
        $d.current = null;
        $d.renderToken++;
        $('#detail, #signCard').prop('hidden', true);
        $('#detailEmpty').prop('hidden', false);
        $('#viewer').empty();
    },

    renderDetail: function () {
        const $t = $.DLNFrame;
        const c = $t.data.current;
        const L = $t.data.lang;
        const code = (c.docCode || '') + (c.variantName ? ' · ' + c.variantName : '');
        $('#detailHead').empty().append(
            $('<div>').append($('<b>').text(code), document.createTextNode(' ' + c.docTitle)),
            $('<div class="meta">').text(L['REVISION'] + ' ' + $t.pad(c.revisionNo) + ' · ' + L['TYPE'] + ': ' + c.docTypeName + ' · '
                + L['OWNER'] + ': ' + c.ownerName + ' · ' + L['UPLOADED_BY'] + ': ' + c.uploadedBy),
            $('<div class="meta">').text(L['CHANGE_REASON'] + ': ' + c.changeReason));
        // İmzaladığı belge (salt okunur) indirilebilir; imza bekleyen belgede indirme yok, önce okunup imzalanır
        if (c.readOnly) {
            $('#detailHead').append($('<div class="mt-1">').append($t.downloadLinks(c)));
        }
        const $flow = $('#detailFlow').empty();
        c.flow.forEach(function (f) {
            const you = f.status === 'ACTIVE' && f.role === c.signRole;
            const state = f.status === 'SIGNED' ? L['STEP_SIGNED'] + (f.signedAt ? ' · ' + f.signedAt : '')
                        : (f.status === 'ACTIVE' ? (you ? L['STEP_YOU'] : L['STEP_ACTIVE']) : L['STEP_WAITING']);
            $flow.append($('<div class="qm-step">').toggleClass('is-signed', f.status === 'SIGNED').toggleClass('is-active', f.status === 'ACTIVE')
                .append($('<b>').text(L['ROLE_' + f.role]), document.createTextNode(f.name), $('<div class="text-muted small">').text(state)));
        });
        $('#detailEmpty').prop('hidden', true);
        $('#detail').prop('hidden', false);
        $('#signCard').prop('hidden', !!c.readOnly);
        $('#rejectBox, #btnRejectConfirm').prop('hidden', true);
        $('#btnReject').prop('hidden', false);
        $('#REJECT_NOTE').val('');
        $t.resizePad();
        $t.updateReadState();
    },

    // Belge (ve birleştirilemediyse ayrı onay sayfası) sayfa sayfa çizilir; çizim bitince sonuna kadar okundu mu bakılır
    loadPdf: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        const c = $d.current;
        const token = ++$d.renderToken;
        $d.renderDone = false;
        const $viewer = $('#viewer').empty().scrollTop(0).append($('<div class="qm-msg">').text(L['LOADING_PDF']));
        const urls = [fileApi + '?revision=' + c.revisionId + '&kind=current'];
        if (c.certOnly) {
            urls.push(fileApi + '?revision=' + c.revisionId + '&kind=cert');
        }
        const width = Math.max(300, $viewer.innerWidth() - 20);
        const renderDoc = function (url, label) {
            return pdfjsLib.getDocument({ url: url, withCredentials: true }).promise.then(function (pdf) {
                let chain = Promise.resolve();
                if (label) {
                    chain = chain.then(function () { if (token === $d.renderToken) { $viewer.append($('<div class="qm-sep">').text(label)); } });
                }
                for (let p = 1; p <= pdf.numPages; p++) {
                    chain = chain.then(function () {
                        if (token !== $d.renderToken) { return null; }
                        return pdf.getPage(p).then(function (page) {
                            const base = page.getViewport({ scale: 1 });
                            const ratio = window.devicePixelRatio || 1;
                            const vp = page.getViewport({ scale: width / base.width * ratio });
                            const canvas = document.createElement('canvas');
                            canvas.width = vp.width;
                            canvas.height = vp.height;
                            canvas.style.width = (vp.width / ratio) + 'px';
                            $viewer.append(canvas);
                            return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
                        });
                    });
                }
                return chain;
            });
        };
        let chain = Promise.resolve().then(function () { $viewer.find('.qm-msg').remove(); });
        urls.forEach(function (u, i) { chain = chain.then(function () { return renderDoc(u, i > 0 ? L['CERT_SEPARATE'] : null); }); });
        chain.then(function () {
            if (token === $d.renderToken) {
                $d.renderDone = true;
                $t.checkRead();
            }
        }).catch(function () {
            if (token === $d.renderToken) { $viewer.empty().append($('<div class="qm-msg">').text(L['PDF_ERROR'])); }
        });
    },

    // Belgenin sonuna gelindiyse okundu sayılır (kısa belgede çizim biter bitmez)
    checkRead: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const v = document.getElementById('viewer');
        if (!$d.current || $d.current.readOnly || $d.read[$d.current.stepId] || !$d.renderDone) { return; }
        if (v.scrollTop + v.clientHeight >= v.scrollHeight - 20) {
            $d.read[$d.current.stepId] = true;
            $t.updateReadState();
            $t.renderList();
        }
    },

    updateReadState: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if ($d.current && $d.current.readOnly) {
            $('#readState').empty().append($('<span class="text-muted">').append($('<i class="fas fa-lock">'), document.createTextNode(' ' + $d.lang['READ_ONLY'])));
            return;
        }
        const read = $d.current && $d.read[$d.current.stepId];
        $('#readState').empty().append(read
            ? $('<span class="text-success">').append($('<i class="fas fa-check-circle">'), document.createTextNode(' ' + $d.lang['READ_DONE']))
            : $('<span class="text-muted">').append($('<i class="fas fa-arrow-down">'), document.createTextNode(' ' + $d.lang['READ_TO_END'])));
        $t.updateSignButton();
    },

    // Seçili belgeler varsa onlar, yoksa açık belge imzalanır
    signTargets: function () {
        const $d = $.DLNFrame.data;
        const picked = Object.keys($d.checked).filter(function (k) { return $d.checked[k] && $d.read[k]; }).map(Number);
        if (picked.length) { return picked; }
        return $d.current && $d.read[$d.current.stepId] ? [$d.current.stepId] : [];
    },

    updateSignButton: function () {
        const $t = $.DLNFrame;
        const L = $t.data.lang;
        if (!L) { return; }
        const n = $t.signTargets().length;
        const picked = Object.keys($t.data.checked).filter(function (k) { return $t.data.checked[k]; }).length;
        $('#btnSignText').text(picked > 0 ? L['BTN_SIGN_SELECTED'].replace('%d', n) : L['BTN_SIGN']);
        $('#btnSign').prop('disabled', n === 0);
        const readOnly = $t.data.tab === 'SIGNED' || ($t.data.current && $t.data.current.readOnly);
        $('#signCard').prop('hidden', readOnly || (!$t.data.current && picked === 0));
    },

    /* ---------------- İmza alanı ---------------- */

    initPad: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const canvas = document.getElementById('pad');
        const pos = function (e) {
            const r = canvas.getBoundingClientRect();
            return [Math.round((e.clientX - r.left) * 10) / 10, Math.round((e.clientY - r.top) * 10) / 10, Math.round(performance.now() - $d.startedAt)];
        };
        canvas.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            canvas.setPointerCapture(e.pointerId);
            if (!$d.strokes.length) { $d.startedAt = performance.now(); }
            $d.drawing = [pos(e)];
            $d.strokes.push($d.drawing);
            $t.drawPad();
        });
        canvas.addEventListener('pointermove', function (e) {
            if (!$d.drawing) { return; }
            e.preventDefault();
            $d.drawing.push(pos(e));
            $t.drawPad();
        });
        const end = function () { $d.drawing = null; };
        canvas.addEventListener('pointerup', end);
        canvas.addEventListener('pointercancel', end);
        $(window).on('resize', function () { $t.resizePad(); });
    },

    // Ekran yoğunluğuna göre çözünürlük; boyut değişince çizgiler yeniden çizilir
    resizePad: function () {
        const canvas = document.getElementById('pad');
        const ratio = window.devicePixelRatio || 1;
        const w = canvas.clientWidth;
        const h = canvas.clientHeight;
        if (!w) { return; }
        canvas.width = w * ratio;
        canvas.height = h * ratio;
        canvas.getContext('2d').setTransform(ratio, 0, 0, ratio, 0, 0);
        $.DLNFrame.drawPad();
    },

    drawPad: function () {
        const canvas = document.getElementById('pad');
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.lineWidth = 2.2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0b1f4d';
        $.DLNFrame.data.strokes.forEach(function (s) {
            ctx.beginPath();
            ctx.moveTo(s[0][0], s[0][1]);
            if (s.length === 1) { ctx.lineTo(s[0][0] + 0.1, s[0][1]); }
            s.forEach(function (p) { ctx.lineTo(p[0], p[1]); });
            ctx.stroke();
        });
    },

    clearPad: function () {
        $.DLNFrame.data.strokes = [];
        $.DLNFrame.drawPad();
    },

    // "x,y,t;x,y,t|…" — form alanı olarak gider (JSON değil)
    strokeText: function () {
        return $.DLNFrame.data.strokes.map(function (s) { return s.map(function (p) { return p.join(','); }).join(';'); }).join('|');
    },

    pointCount: function () {
        return $.DLNFrame.data.strokes.reduce(function (n, s) { return n + s.length; }, 0);
    },

    /* ---------------- İmza ve red ---------------- */

    clickSign: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        const ids = $t.signTargets();
        if (!ids.length) {
            $t.warn(L['ERR_NOT_READ']);
            return;
        }
        if ($t.pointCount() < $t.opt.minPoints) {
            $t.warn(L['ERR_SIGNATURE']);
            return;
        }
        if (!$('#PASSWORD').val()) {
            $t.warn(L['ERR_PASSWORD']);
            $('#PASSWORD').trigger('focus');
            return;
        }
        $.fn.nty({
            type: 'information',
            text: L['SIGN_CONFIRM'].replace('%d', ids.length),
            buttons: [
                {
                    text: L['BTN_YES_TEXT'],
                    onClick: function ($noty) {
                        $noty.close();
                        $t.post('setSign', {
                            SIGN_STEP_IDS: ids,
                            PASSWORD: $('#PASSWORD').val(),
                            SIGNATURE: document.getElementById('pad').toDataURL('image/png'),
                            STROKES: $t.strokeText()
                        }, function (result) {
                            (result['resObject']['FAILED'] || []).forEach(function (f) { $t.warn(f.message); });
                            $t.afterAction();
                        });
                    }
                },
                {
                    text: L['BTN_NO_TEXT'],
                }
            ]
        });
    },

    clickReject: function () {
        const $t = $.DLNFrame;
        const L = $t.data.lang;
        const note = $.trim($('#REJECT_NOTE').val());
        if (note.length < 5) {
            $t.warn(L['ERR_REJECT_NOTE']);
            $('#REJECT_NOTE').trigger('focus');
            return;
        }
        if (!$('#PASSWORD').val()) {
            $t.warn(L['ERR_PASSWORD']);
            $('#PASSWORD').trigger('focus');
            return;
        }
        $.fn.nty({
            type: 'error',
            text: L['REJECT_CONFIRM'],
            buttons: [
                {
                    text: L['BTN_YES_TEXT'],
                    onClick: function ($noty) {
                        $noty.close();
                        $t.post('setReject', { SIGN_STEP_ID: $t.data.current.stepId, PASSWORD: $('#PASSWORD').val(), REJECT_NOTE: note }, $t.afterAction);
                    }
                },
                {
                    text: L['BTN_NO_TEXT'],
                }
            ]
        });
    },

    // İmza ya da red sonrası: şifre ve imza temizlenir, liste yenilenir
    afterAction: function () {
        const $t = $.DLNFrame;
        $('#PASSWORD').val('');
        $t.clearPad();
        $t.data.checked = {};
        $t.closeDetail();
        $t.loadList();
    },

    /* ---------------- Ortak ---------------- */

    request: function (query, data, onSuccess) {
        const $t = $.DLNFrame;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + query,
            type: 'POST',
            dataType: 'json',
            data: data,
            beforeSend: function () { $('.frame-block').blk(); },
            success: function (result) {
                if (result != null && result['resCode'] === 0) {
                    onSuccess(result);
                } else {
                    $t.showError(result ? result['resMsg'] : '');
                }
            },
            complete: function () { $('.frame-block').ublk(); },
            error: function (xhr) {
                $('.frame-block').ublk();
                $t.showError(xhr);
            }
        });
    },

    // Kayıt işlemleri: başarıda köşe bildirimi + onSuccess, hatada sunucu mesajı (şifre alanı temizlenir)
    post: function (query, data, onSuccess) {
        const $t = $.DLNFrame;
        $t.request(query, data, function (result) {
            $.fn.ntyi({ type: 'success', layout: 'topRight', text: result['resMsg'] });
            onSuccess(result);
        });
    },

    warn: function (text) {
        $.fn.ntyi({ type: 'warning', layout: 'topRight', timeout: 6000, text: $('<span>').text(text).html() });
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

    pad: function (n) {
        return n === null || n === undefined ? '—' : String(n).padStart(2, '0');
    }
};
