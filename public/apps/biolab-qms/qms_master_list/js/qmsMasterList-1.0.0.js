/**
 * Biolab QMS — Doküman ana listesi (kontrollü doküman listesi)
 *
 * - Solda klasör ağacı (KALİTE DOKÜMANLARI › FORMLAR …) ve doküman sayıları; klasör seçilince liste süzülür.
 * - Sağda dokümanlar: kod, ad, revizyon, durum (yürürlükte / imzada · sırası gelen adım / reddedildi / iptal),
 *   yürürlük ve gözden geçirme tarihi (geciken kırmızı), sahip. Hızlı süzgeçler ve arama ekranda çalışır.
 * - Doküman seçilince altta: PDF önizleme (mühürlü hali, onay sayfasıyla) ve revizyon geçmişi; her revizyonun imza
 *   adımları (imza anındaki ad, unvan, pozisyon, zaman, kayıt hash'i; reddedilmişse gerekçe). Revizyona tıklanınca o
 *   revizyonun PDF'i açılır.
 * Sunucu tarafı: php/qmsMasterList-1.0.0.php · dosyalar: api/qms-file.php
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-qms/qms_master_list/php/qmsMasterList-1.0.0@';
// Doküman dosyaları (web kökü dışındaki depodan, yetki kontrolüyle)
const fileApi = '../../../api/qms-file.php';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','qms_master_list/qmsMasterList-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','qms_master_list/qmsMasterList-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        tree:[],
        categoryId:0,      // 0: bütün klasörler
        docs:[],
        filter:'ALL',
        detail:null,       // seçili doküman (getDetail)
        revisionId:null,   // önizlemedeki revizyon
        renderToken:0
    },
    opt:{
        workerSrc:'../../../lib/plugins/pdfjs-2.6.347/pdf.worker.js'
    },

    init: function () {
        const $t = $.DLNFrame;
        if (window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = $t.opt.workerSrc;
        }
        $t.setEvent();
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
            $t.loadTree();
            $t.loadDocs();
        });

        $('#tree').on('click', 'li', function () {
            $d.categoryId = parseInt($(this).data('id'), 10) || 0;
            $t.renderTree();
            $t.loadDocs();
        });
        $('#SHOW_CANCELLED').on('change', function () { $t.loadTree(); $t.loadDocs(); });
        $('#filters').on('click', '.btn', function () {
            $('#filters .btn').removeClass('active');
            $(this).addClass('active');
            $d.filter = $(this).data('filter');
            $t.renderDocs();
        });
        $('#searchField').on('keyup', function () { $t.renderDocs(); });
        $('#docRows').on('click', 'tr[data-doc]', function () { $t.loadDetail(parseInt($(this).data('doc'), 10)); });
        $('#revisions').on('click', '.qm-rev', function (e) {
            if ($(e.target).closest('a').length) { return; }
            $d.revisionId = parseInt($(this).data('rev'), 10);
            $t.renderRevisions();
            $t.loadPdf();
        });
    },

    showCancelled: function () {
        return $('#SHOW_CANCELLED').is(':checked') ? 'Y' : 'N';
    },

    /* ---------------- Klasörler ---------------- */

    loadTree: function () {
        const $t = $.DLNFrame;
        $t.request('getTree', { SHOW_CANCELLED: $t.showCancelled() }, function (result) {
            $t.data.tree = result['records'] || [];
            $t.renderTree();
        });
    },

    // Kök klasörün sayısı alt klasörlerin toplamıdır; kök seçiliyse bütün dokümanlar listelenir
    renderTree: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const $ul = $('#tree').empty();
        const roots = $d.tree.filter(function (c) { return !c.parentId; });
        roots.forEach(function (root) {
            const kids = $d.tree.filter(function (c) { return String(c.parentId) === String(root.id); });
            const total = kids.reduce(function (n, c) { return n + parseInt(c.docCount, 10); }, parseInt(root.docCount, 10));
            $ul.append($('<li>').attr('data-id', 0).toggleClass('is-current', $d.categoryId === 0)
                .append($('<span>').append($('<i class="fas fa-folder-open mr-1">'), document.createTextNode(root.name)), $('<span class="count">').text(total)));
            kids.forEach(function (c) {
                $ul.append($('<li class="is-child">').attr('data-id', c.id).toggleClass('is-current', $d.categoryId === parseInt(c.id, 10))
                    .append($('<span>').append($('<i class="fas fa-folder mr-1">'), document.createTextNode(c.name)), $('<span class="count">').text(c.docCount)));
            });
        });
    },

    /* ---------------- Dokümanlar ---------------- */

    loadDocs: function () {
        const $t = $.DLNFrame;
        $t.request('getDocs', { CATEGORY_ID: $t.data.categoryId, SHOW_CANCELLED: $t.showCancelled() }, function (result) {
            $t.data.docs = result['records'] || [];
            $t.renderDocs();
        });
    },

    matches: function (d) {
        const f = $.DLNFrame.data.filter;
        const q = $.trim($('#searchField').val()).toLocaleLowerCase('tr');
        if (f === 'EFFECTIVE' && !d.currentRevisionId) { return false; }
        if (f === 'SIGNING' && !d.pendingStatus) { return false; }
        if (f === 'OVERDUE' && d.reviewOverdue !== 'Y') { return false; }
        if (q) {
            const text = [d.docCode, d.variantName, d.docTitle].join(' ').toLocaleLowerCase('tr');
            if (text.indexOf(q) < 0) { return false; }
        }
        return true;
    },

    // Durum: iptal / yürürlükte (+ yeni revizyonu imzada) / imzada · sırası gelen adım / reddedildi
    statusHtml: function (d) {
        const $t = $.DLNFrame;
        const L = $t.data.lang;
        const badge = function (cls, text) { return $('<span class="qm-badge">').addClass(cls).text(text); };
        const waiting = d.waitingRole ? ' · ' + L['WAITING_FOR'].replace('%s', L['ROLE_' + d.waitingRole]) : '';
        const out = $('<span>');
        if (d.docStatus === 'CANCELLED') {
            return out.append(badge('qm-badge-err', L['ST_CANCELLED']));
        }
        if (d.currentRevisionId) {
            out.append(badge('qm-badge-ok', L['ST_EFFECTIVE']));
            if (d.pendingStatus) {
                out.append(' ', badge(d.pendingStatus === 'DRAFT' ? 'qm-badge-wait' : 'qm-badge-sign',
                    L['ST_NEW_REV'].replace('%s', $t.pad(d.pendingRevisionNo)) + (d.pendingStatus === 'DRAFT' ? ' · ' + L['ST_DRAFT'] : waiting)));
            }
        } else if (d.pendingStatus === 'DRAFT') {
            out.append(badge('qm-badge-wait', L['ST_DRAFT']));
        } else if (d.pendingStatus) {
            out.append(badge('qm-badge-sign', L['ST_SIGNING'] + waiting));
        }
        if (d.fillableFl === 'Y') {
            out.append(' ', badge('', L['FILLABLE'] + (parseInt(d.recordCount, 10) ? ' · ' + L['RECORDS'].replace('%d', d.recordCount) : '')));
        }
        return out;
    },

    renderDocs: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        const $body = $('#docRows').empty();
        const rows = $d.docs.filter($t.matches);
        let group = null;
        rows.forEach(function (d) {
            // Bütün klasörler listelenirken klasör başlıkları
            if ($d.categoryId === 0 && d.categoryName !== group) {
                group = d.categoryName;
                $body.append($('<tr class="group">').append($('<td colspan="7">').text(group)));
            }
            const rev = d.currentRevisionId ? d.currentRevisionNo : d.pendingRevisionNo;
            $body.append($('<tr>').attr('data-doc', d.documentId).toggleClass('is-current', !!$d.detail && $d.detail.documentId === d.documentId).append(
                $('<td class="code">').text((d.docCode || '—') + (d.variantName ? ' · ' + d.variantName : '')),
                $('<td>').text(d.docTitle),
                $('<td class="nowrap">').text($t.pad(rev)),
                $('<td>').append($t.statusHtml(d)),
                $('<td class="nowrap">').text(d.effectiveDate || '—'),
                $('<td class="nowrap">').append(document.createTextNode(d.nextReviewDate || '—'),
                    d.reviewOverdue === 'Y' ? $('<span class="qm-badge qm-badge-err ml-1">').text(L['OVERDUE']) : ''),
                $('<td>').text(d.ownerName)
            ));
        });
        if (!rows.length) {
            $body.append($('<tr>').append($('<td colspan="7" class="text-muted text-center p-3">').text(L['EMPTY'])));
        }
        $('#count').text(L['COUNT'].replace('%d', rows.length));
    },

    /* ---------------- Ayrıntı ---------------- */

    loadDetail: function (documentId) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.request('getDetail', { DOCUMENT_ID: documentId }, function (result) {
            $d.detail = result['resObject'];
            const revs = $d.detail.revisions || [];
            const current = revs.filter(function (r) { return String(r.revisionId) === String($d.detail.currentRevisionId); })[0];
            $d.revisionId = current ? parseInt(current.revisionId, 10) : (revs[0] ? parseInt(revs[0].revisionId, 10) : null);
            $t.renderDetail();
            $t.renderDocs();
            $t.loadPdf();
            $('#detailCard')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    },

    renderDetail: function () {
        const $t = $.DLNFrame;
        const doc = $t.data.detail;
        const L = $t.data.lang;
        $('#detailHead').empty().append(
            $('<div>').append($('<b>').text((doc.docCode || '') + (doc.variantName ? ' · ' + doc.variantName : '')), document.createTextNode(' ' + doc.docTitle)),
            $('<div class="meta">').text([L['TYPE'] + ': ' + doc.docTypeName, L['FOLDER'] + ': ' + doc.categoryName, L['OWNER'] + ': ' + doc.ownerName,
                L['FIRST_PUBLISH'] + ': ' + (doc.firstPublishDate || '—')].join(' · ')
                + (doc.fillableFl === 'Y' ? ' · ' + L['FILLABLE'] + ' · ' + L['RECORDS'].replace('%d', doc.recordCount) : '')));
        $('#detailCard').prop('hidden', false);
        $t.renderRevisions();
    },

    // Revizyon kartları; her kartta imza adımları (son tur önce), dosya bağlantıları ve özetler
    renderRevisions: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        const $box = $('#revisions').empty();
        ($d.detail.revisions || []).forEach(function (r) {
            const cls = { EFFECTIVE: 'qm-badge-ok', SIGNING: 'qm-badge-sign', DRAFT: 'qm-badge-wait', CANCELLED: 'qm-badge-err' }[r.revStatus] || '';
            const $card = $('<div class="qm-rev">').attr('data-rev', r.revisionId).toggleClass('is-current', parseInt(r.revisionId, 10) === $d.revisionId);
            $card.append($('<div class="title">').append(document.createTextNode('Rev ' + $t.pad(r.revisionNo) + ' '),
                $('<span class="qm-badge">').addClass(cls).text(L['ST_' + r.revStatus] || r.revStatus)));
            $card.append($('<div class="meta">').text(L['UPLOADED'].replace('%s', r.uploadedAt).replace('%s', r.uploadedBy)));
            $card.append($('<div>').text(L['CHANGE_REASON'] + ': ' + r.changeReason));
            const dates = [];
            if (r.effectiveDate) { dates.push(L['COL_EFFECTIVE'] + ': ' + r.effectiveDate); }
            if (r.nextReviewDate && r.revStatus === 'EFFECTIVE') { dates.push(L['REVIEW_ON'].replace('%s', r.nextReviewDate)); }
            if (r.obsoleteDate) { dates.push(L['OBSOLETE_ON'].replace('%s', r.obsoleteDate)); }
            if (r.legacyFl === 'Y') { dates.push(L['LEGACY']); }
            if (dates.length) { $card.append($('<div class="meta">').text(dates.join(' · '))); }

            // İmza adımları: tur tur (reddedilip yeniden gönderilen revizyonda eski tur da görünür)
            let round = null;
            const $steps = $('<ul class="qm-steps">');
            (r.steps || []).forEach(function (s) {
                if (parseInt(r.signRound, 10) > 1 && s.signRound !== round) {
                    round = s.signRound;
                    $steps.append($('<li class="meta">').text(L['ROUND'].replace('%d', s.signRound)));
                }
                const $li = $('<li>').append($('<span class="role">').text(L['ROLE_' + s.signRole]));
                if (s.stepStatus === 'SIGNED') {
                    $li.append(document.createTextNode(((s.signerTitle ? s.signerTitle + ' ' : '') + s.signerName) + ' · ' + s.signerPosition),
                        $('<div class="meta">').text(L['STEP_SIGNED'] + ' ' + s.signedAt + ' · ' + (L['AUTH_' + s.authMethod] || s.authMethod)),
                        $('<div class="hash">').text(L['RECORD_HASH'] + ': ' + (s.recordHash || '')));
                } else {
                    $li.append(document.createTextNode(s.userName + ' · '), $('<span class="meta">').text(L['STEP_' + s.stepStatus] + (s.completedAt ? ' ' + s.completedAt : '')));
                    if (s.rejectNote) {
                        $li.append($('<div class="text-danger">').text(L['REJECTED_NOTE'].replace('%s', s.rejectNote)));
                    }
                }
                $steps.append($li);
            });
            $card.append($steps);

            const $links = $('<div class="mt-1">').append($('<a target="_blank" rel="noopener">').attr('href', fileApi + '?revision=' + r.revisionId)
                .append($('<i class="fas fa-external-link-alt">'), document.createTextNode(' ' + L['OPEN_PDF'])),
                document.createTextNode(' · '), $('<a download>').attr('href', fileApi + '?revision=' + r.revisionId + '&download=1')
                .append($('<i class="fas fa-download">'), document.createTextNode(' ' + L[r.signedHash ? 'DOWNLOAD_SIGNED' : 'DOWNLOAD_PDF'])));
            if (r.certOnly) {
                $links.append(document.createTextNode(' · '), $('<a download>').attr('href', fileApi + '?revision=' + r.revisionId + '&kind=cert&download=1')
                    .append($('<i class="fas fa-file-signature">'), document.createTextNode(' ' + L['DOWNLOAD_CERT'])));
            }
            if (r.hasSource) {
                $links.append(document.createTextNode(' · '), $('<a>').attr('href', fileApi + '?revision=' + r.revisionId + '&kind=source')
                    .append($('<i class="fas fa-file-download">'), document.createTextNode(' ' + L['DOWNLOAD_SOURCE'])));
            }
            $card.append($links);
            $card.append($('<div class="qm-hash mt-1">').text(L['PDF_HASH'] + ': ' + (r.pdfHash || '—')));
            if (r.signedHash) { $card.append($('<div class="qm-hash">').text(L['SIGNED_HASH'] + ': ' + r.signedHash)); }
            $box.append($card);
        });
    },

    // Seçili revizyonun PDF'i (mühürlüyse onay sayfasıyla; birleştirilemediyse onay sayfası ayrı) sayfa sayfa çizilir
    loadPdf: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        const rev = ($d.detail.revisions || []).filter(function (r) { return parseInt(r.revisionId, 10) === $d.revisionId; })[0];
        const token = ++$d.renderToken;
        const $viewer = $('#viewer').empty().scrollTop(0);
        if (!rev) { return; }
        $viewer.append($('<div class="qm-msg">').text(L['LOADING_PDF']));
        const urls = [fileApi + '?revision=' + rev.revisionId];
        if (rev.certOnly) { urls.push(fileApi + '?revision=' + rev.revisionId + '&kind=cert'); }
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
        chain.catch(function () {
            if (token === $d.renderToken) { $viewer.empty().append($('<div class="qm-msg">').text(L['PDF_ERROR'])); }
        });
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

    showError: function (text) {
        const $d = $.DLNFrame.data;
        $.fn.ntyp({
            type: 'error',
            text: text,
            modal: true,
            buttons: [{ addClass: 'btn btn-sm btn-danger', text: $d.lang['BTN_OK_TEXT'] }]
        });
    },

    pad: function (n) {
        return n === null || n === undefined ? '—' : String(n).padStart(2, '0');
    }
};
