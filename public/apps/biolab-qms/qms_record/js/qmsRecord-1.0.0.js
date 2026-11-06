/**
 * Biolab QMS — Form doldur (pilot)
 *
 * - Solda formlar ve seçili formun kayıtları dosya listesi olarak (pcr_document düzeni): satıra tıklamak formu doldurmaya açar;
 *   büyüteç PDF'i pencerede görüntüler, Word/Excel simgesi yüklenen özgün dosyayı, indirme kaydın PDF'ini verir.
 * - Sağda formun onaylı PDF'i (PDF.js). Her sayfanın üstünde şeffaf bir katman vardır; araçlar bu katmana yazar:
 *     Kaydır (varsayılan; tablette parmakla sayfa kayar) · Kalem · Yazı · ✓ · ✗ · Silgi · Geri al · Temizle
 * - İşaretler sayfaya göre 0–1 koordinatla tutulur (ekran boyutu değişse de yeri kaymaz).
 * - Kaydet: işaretli her sayfanın katmanı yüksek çözünürlükte şeffaf PNG olarak çizilir ve işaretlerin kendisiyle birlikte
 *   form alanı olarak gönderilir (PAGES[n], MARKS[i]); sunucu onaylı PDF'in kopyasına basıp kayıt olarak saklar.
 * Sunucu tarafı: php/qmsRecord-1.0.0.php
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-qms/qms_record/php/qmsRecord-1.0.0@';
// Form ve kayıt dosyaları (web kökü dışındaki depodan, yetki kontrolüyle)
const fileApi = '../../../api/qms-file.php';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','qms_record/qmsRecord-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','qms_record/qmsRecord-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        forms:[],          // yürürlükteki formlar (getForms)
        form:null,         // açık form
        recordDoc:null,    // kayıt listesi süzgeci (form); boşsa bütün kayıtlar
        pages:{},          // sayfa no → {el, ink, w, h (css px), ptW, ptH (pt)}
        marks:[],          // {t:'pen'|'text'|'check'|'cross', p, x, y, s, d?, v?}  — koordinat ve boyut sayfa genişliğine göre 0–1
        tool:'pan',
        drawing:null,      // çizilmekte olan kalem işareti
        textInput:null,    // açık yazı kutusu
        renderToken:0,
        searchTimer:null
    },
    opt:{
        color:'#0b1f4d',   // basılı siyahtan ayırt edilsin diye koyu mavi
        size:{             // sayfa genişliğine oran
            pen:  { S:0.0015, M:0.0025, L:0.004 },
            text: { S:0.014,  M:0.018,  L:0.024 },
            mark: { S:0.015,  M:0.022,  L:0.03 }
        },
        exportScale:2.5,   // kayıt PNG'si: PDF noktası başına piksel (A4 ≈ 1490 × 2100)
        maxViewerWidth:1100,
        recentCount:5,     // listenin üstünde gösterilen son doldurulan form sayısı
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
            $t.loadForms();
            $t.loadRecords();
        });

        $('#btnClearFilter').click(function () {
            $d.recordDoc = null;
            $t.loadRecords();
        });
        $('#searchField').on('keyup', function () {
            clearTimeout($d.searchTimer);
            $d.searchTimer = setTimeout($t.loadForms, 300);
        });
        // Satıra ya da kaleme tıklamak formu sağda doldurmak için açar; büyüteç onaylı PDF'i (onay sayfasıyla) görüntüler,
        // indirme özgün Word/Excel dosyasını verir
        $('#formRows').on('click', 'li[data-doc]', function (e) {
            const f = $d.forms[$(this).data('i')];
            const act = $(e.target).closest('[data-act]').data('act');
            if (act === 'view') {
                $t.showPdf(fileApi + '?revision=' + f.revisionId + '&kind=current', $t.code(f) + ' ' + f.docTitle);
                return;
            }
            if (act === 'source') { return; }   // bağlantı kendi indirir
            if (!f.revisionId) {
                $t.warn($d.lang['ERR_PENDING']);
                return;
            }
            if ($d.form && $d.form.revisionId === f.revisionId) { return; }
            $t.confirmLeave(function () { $t.openForm(f); });
        });
        $('#recordRows').on('click', 'li[data-record]', function (e) {
            if ($(e.target).closest('[data-act="download"]').length) { return; }
            const $li = $(this);
            $t.showPdf(fileApi + '?record=' + $li.data('record'), $li.data('title'));
        });
        $('#modalShowPDF').on('hidden.bs.modal', function () { $('#PDFViewerFrame').attr('src', 'about:blank'); });
        $('#tools').on('click', '.btn', function () { $t.setTool($(this).data('tool')); });
        $('#btnUndo').click(function () {
            $t.commitText();
            const m = $d.marks.pop();
            if (m) { $t.redraw(m.p); }
        });
        $('#btnClear').click(function () {
            if (!$d.marks.length) { return; }
            $t.confirm($d.lang['CLEAR_CONFIRM'], function () {
                const pages = $d.marks.map(function (m) { return m.p; });
                $d.marks = [];
                pages.forEach($t.redraw);
            });
        });
        $('#btnSave').click($t.clickSave);
    },

    /* ---------------- Formlar ve kayıtlar ---------------- */

    loadForms: function () {
        const $t = $.DLNFrame;
        $t.request('getForms', { Q: $('#searchField').val() }, function (result) {
            $t.data.forms = result['records'] || [];
            $t.renderForms();
        });
    },

    renderForms: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        const $body = $('#formRows').empty();
        $('#formCount').text($d.forms.length ? '(' + $d.forms.length + ')' : '');
        if (!$d.forms.length) {
            $body.append($('<li class="list-group-item qm-empty">').text(L['FORMS_EMPTY']));
        }
        // Satır: renk şeridi (yürürlükte yeşil, imzada sarı) · dosya ikonu · kod/rev, başlık, özgün dosya adı ve yürürlük · düğmeler.
        // Yürürlüğe girmemiş form soluk ve durumuyla görünür ("İmzada · Hazırlayan bekliyor"); doldurulamaz, görüntülenemez.
        const row = function (f) {
            const i = $d.forms.indexOf(f);
            const pending = !f.revisionId;
            let badge = L['RECORD_COUNT'].replace('%d', f.recordCount);
            if (pending) {
                badge = L['PENDING_' + f.pendingStatus] || f.pendingStatus;
                if (f.waitingRole) {
                    badge += ' · ' + L['WAITING_FOR'].replace('%s', L['ROLE_' + f.waitingRole]);
                }
            }
            const sub = [f.sourceFileName || null,
                         f.effectiveDate ? L['EFFECTIVE'] + ': ' + f.effectiveDate : null].filter(Boolean).join(' · ');
            const $actions = $('<div class="qm-actions">');
            if (!pending) {
                $actions.append($t.iconButton('view', 'fa-search', 'btn-outline-cyan', L['BTN_VIEW']));
                if (f.sourceFileName) {
                    $actions.append($t.iconButton('source', $t.fileIcon(f.sourceFileName) === 'fa-file-excel' ? 'fa-file-excel' : 'fa-file-word',
                        'btn-outline-warning', L['BTN_SOURCE'], fileApi + '?revision=' + f.revisionId + '&kind=source'));
                }
                $actions.append($t.iconButton('fill', 'fa-plus', 'btn-outline-primary', L['BTN_FILL']));
            }
            return $('<li class="list-group-item qm-row">').attr({ 'data-doc': f.documentId, 'data-i': i }).toggleClass('is-pending', pending)
                .toggleClass('is-current', !pending && !!$d.form && $d.form.revisionId === f.revisionId).append(
                $('<div class="list-indicator">').addClass(pending ? 'bg-warning' : 'bg-success'),
                $('<div class="widget-content d-flex">').append(
                    $('<div class="widget-content-wrapper flex-fill d-flex flex-row">').append(
                        $('<div class="qm-icon qm-row-main">').append($('<i class="fas">').addClass($t.fileIcon(f.sourceFileName))),
                        $('<div class="widget-content-left flex-fill qm-row-main">').append(
                            $('<div class="widget-heading">').append(
                                document.createTextNode(($t.code(f) ? $t.code(f) + ' · ' : '') + L['REVISION'] + ' ' + $t.pad(f.revisionNo) + ' '),
                                $('<span class="qm-badge">').toggleClass('qm-badge-wait', pending).text(badge)),
                            $('<div class="widget-title">').text(f.docTitle).attr('title', f.docTitle),
                            sub ? $('<div class="widget-subheading">').text(sub).attr('title', sub) : null),
                        $actions)));
        };
        const head = function (text) {
            return $('<li class="list-group-item qm-group">').append($('<i class="far fa-folder mr-1">'), document.createTextNode(text));
        };
        // En üstte kullanıcının son doldurdukları (en yeni önce); ardından bütün formlar klasör başlıklarıyla
        const recent = $d.forms.filter(function (f) { return f.lastFilled; })
            .sort(function (a, b) { return $t.sortableDate(b.lastFilled).localeCompare($t.sortableDate(a.lastFilled)); })
            .slice(0, $t.opt.recentCount);
        if (recent.length) {
            $body.append(head(L['RECENT_TITLE']));
            recent.forEach(function (f) { $body.append(row(f)); });
        }
        let group = null;
        $d.forms.forEach(function (f) {
            if (f.categoryName !== group) {
                group = f.categoryName;
                $body.append(head(group));
            }
            $body.append(row(f));
        });
    },

    // "dd.MM.yyyy HH:mm" → "yyyyMMddHHmm" (sıralama için)
    sortableDate: function (s) {
        const m = /^(\d{2})\.(\d{2})\.(\d{4}) (\d{2}):(\d{2})$/.exec(s || '');
        return m ? m[3] + m[2] + m[1] + m[4] + m[5] : '';
    },

    // Kayıtlar sayfa açılınca gelir (bütün formlar, en yeni önce); form seçilince yalnızca o formunkiler (✕ ile hepsine dönülür).
    // Kayıtlar yalnızca görüntülenir: donmuş PDF açılır, üzerine yazılamaz.
    loadRecords: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        const doc = $d.recordDoc;
        $('#recordFilter').prop('hidden', !doc).children('span:first').text(doc ? ($t.code(doc) || doc.docTitle) : '');
        $t.request('getRecords', { DOCUMENT_ID: doc ? doc.documentId : '' }, function (result) {
            const rows = result['records'] || [];
            const $body = $('#recordRows').empty();
            $('#recordCount').text(rows.length ? '(' + rows.length + ')' : '');
            if (!rows.length) {
                $body.append($('<li class="list-group-item qm-empty">').text(L[doc ? 'RECORDS_EMPTY' : 'RECORDS_EMPTY_ALL']));
            }
            // Satıra ya da büyütece tıklamak kaydı görüntüler; indirme PDF'i verir
            rows.forEach(function (r) {
                const form = $t.code(r) || r.docTitle;
                const filledAt = moment(r.filledAt, 'YYYY-MM-DD HH:mm:ss').format('DD.MM.YYYY HH:mm');   // sunucu ham tarih döndürür
                const title = form + ' · ' + filledAt + ' · ' + r.filledBy;
                $body.append($('<li class="list-group-item qm-row">').attr({ 'data-record': r.recordId, 'data-title': title }).append(
                    $('<div class="list-indicator bg-info">'),
                    $('<div class="widget-content d-flex">').append(
                        $('<div class="widget-content-wrapper flex-fill d-flex flex-row">').append(
                            $('<div class="qm-icon">').append($('<i class="fas fa-file-signature">')),
                            $('<div class="widget-content-left flex-fill qm-row-main">').append(
                                $('<div class="widget-heading">').text((doc ? '' : form + ' · ') + filledAt),
                                $('<div class="widget-title">').text(r.filledBy + (r.recordTitle ? ' · ' + r.recordTitle : '')),
                                $('<div class="widget-subheading">').text((doc ? '' : r.docTitle + ' · ') + L['REVISION'] + ' ' + $t.pad(r.revisionNo))
                                    .attr('title', r.docTitle)),
                            $('<div class="qm-actions">').append(
                                $t.iconButton('view', 'fa-search', 'btn-outline-cyan', L['BTN_VIEW']),
                                $t.iconButton('download', 'fa-download', 'btn-outline-warning', L['BTN_DOWNLOAD'], fileApi + '?record=' + r.recordId))))));
            });
        });
    },

    openForm: function (f) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L = $d.lang;
        $d.form = f;
        $d.marks = [];
        $d.textInput = null;
        $('#RECORD_TITLE').val('');
        $d.recordDoc = f;
        // Açılan her zaman boş şablondur; kaydedince yeni kayıt oluşur (önceki kayıtlar değişmez)
        $('#formHead').empty().append($('<span class="qm-badge qm-badge-new">').text(L['NEW_RECORD']), document.createTextNode(' '),
            $('<b>').text($t.code(f)), document.createTextNode(' ' + f.docTitle + ' · '
            + L['REVISION'] + ' ' + $t.pad(f.revisionNo) + ' · ' + L['EFFECTIVE'] + ': ' + (f.effectiveDate || '—')));
        $('#formEmpty').prop('hidden', true);
        $('#formArea, #saveBar').prop('hidden', false);
        $t.setTool('pan');
        $t.renderForms();
        $t.loadRecords();
        $t.loadPdf();
    },

    // Onaylı PDF (onay sayfası eklenmemiş hali) sayfa sayfa çizilir; her sayfanın üstüne işaret katmanı konur
    loadPdf: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const token = ++$d.renderToken;
        $d.pages = {};
        const $viewer = $('#viewer').empty().scrollTop(0).append($('<div class="qm-msg">').text($d.lang['LOADING_PDF']));
        const width = Math.min($t.opt.maxViewerWidth, Math.max(300, $viewer.innerWidth() - 20));
        pdfjsLib.getDocument({ url: fileApi + '?revision=' + $d.form.revisionId + '&kind=original', withCredentials: true }).promise.then(function (pdf) {
            $viewer.empty();
            let chain = Promise.resolve();
            for (let p = 1; p <= pdf.numPages; p++) {
                chain = chain.then(function () {
                    if (token !== $d.renderToken) { return null; }
                    return pdf.getPage(p).then(function (page) {
                        const base = page.getViewport({ scale: 1 });
                        const ratio = window.devicePixelRatio || 1;
                        const h = width * base.height / base.width;
                        const vp = page.getViewport({ scale: width / base.width * ratio });
                        const $page = $('<div class="qm-page">').css({ width: width + 'px', height: h + 'px' }).attr('data-page', p);
                        const pdfCanvas = document.createElement('canvas');
                        pdfCanvas.width = vp.width;
                        pdfCanvas.height = vp.height;
                        const ink = document.createElement('canvas');
                        ink.className = 'qm-ink';
                        ink.width = width * ratio;
                        ink.height = h * ratio;
                        ink.getContext('2d').setTransform(ratio, 0, 0, ratio, 0, 0);
                        $page.append(pdfCanvas, ink);
                        $viewer.append($page);
                        $d.pages[p] = { el: $page[0], ink: ink, w: width, h: h, ptW: base.width, ptH: base.height };
                        $t.bindInk(p);
                        return page.render({ canvasContext: pdfCanvas.getContext('2d'), viewport: vp }).promise;
                    });
                });
            }
            return chain;
        }).catch(function () {
            if (token === $d.renderToken) { $viewer.empty().append($('<div class="qm-msg">').text($d.lang['PDF_ERROR'])); }
        });
    },

    /* ---------------- Araçlar ---------------- */

    setTool: function (tool) {
        const $t = $.DLNFrame;
        const L = $t.data.lang;
        $t.commitText();
        $t.data.tool = tool;
        $('#tools .btn').removeClass('active').filter('[data-tool="' + tool + '"]').addClass('active');
        const mode = { pan: 'is-pan', pen: 'is-drawing', text: 'is-text', check: 'is-mark', cross: 'is-mark', eraser: 'is-eraser' }[tool];
        $('#viewer').removeClass('is-pan is-drawing is-text is-mark is-eraser').addClass(mode);
        $('#hint').text(L[{ pan: 'HINT_PAN', pen: 'HINT_PEN', text: 'HINT_TEXT', check: 'HINT_MARK', cross: 'HINT_MARK', eraser: 'HINT_ERASER' }[tool]]);
    },

    sizeOf: function (kind) {
        return $.DLNFrame.opt.size[kind][$('#markSize').val() || 'M'];
    },

    // Katman olayları: sayfaya göre 0–1 koordinat
    bindInk: function (p) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const ink = $d.pages[p].ink;
        const at = function (e) {
            const r = ink.getBoundingClientRect();
            return [Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (e.clientY - r.top) / r.height))];
        };
        ink.addEventListener('pointerdown', function (e) {
            const pt = at(e);
            if ($d.tool === 'pen') {
                e.preventDefault();
                ink.setPointerCapture(e.pointerId);
                $d.drawing = { t: 'pen', p: p, x: pt[0], y: pt[1], s: $t.sizeOf('pen'), d: [pt] };
                $d.marks.push($d.drawing);
                $t.redraw(p);
            } else if ($d.tool === 'text') {
                e.preventDefault();
                $t.commitText();
                $t.openText(p, pt);
            } else if ($d.tool === 'check' || $d.tool === 'cross') {
                $d.marks.push({ t: $d.tool, p: p, x: pt[0], y: pt[1], s: $t.sizeOf('mark') });
                $t.redraw(p);
            } else if ($d.tool === 'eraser') {
                $t.eraseAt(p, pt);
            }
        });
        ink.addEventListener('pointermove', function (e) {
            if (!$d.drawing || $d.drawing.p !== p) { return; }
            e.preventDefault();
            $d.drawing.d.push(at(e));
            $t.redraw(p);
        });
        const end = function () { $d.drawing = null; };
        ink.addEventListener('pointerup', end);
        ink.addEventListener('pointercancel', end);
    },

    // Yazı kutusu tıklanan yerde açılır; Enter ya da başka yere tıklayınca işarete dönüşür, Esc vazgeçer
    openText: function (p, pt) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const pg = $d.pages[p];
        const s = $t.sizeOf('text');
        const fontPx = s * pg.w;
        const $in = $('<input type="text" class="qm-text-input" maxlength="500">').css({
            left: (pt[0] * pg.w) + 'px', top: (pt[1] * pg.h - fontPx * 0.7) + 'px', fontSize: fontPx + 'px', height: (fontPx * 1.4) + 'px'
        });
        $(pg.el).append($in);
        $d.textInput = { el: $in, p: p, x: pt[0], y: pt[1], s: s };
        $in.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); $t.commitText(); }
            if (e.key === 'Escape') { $d.textInput = null; $in.remove(); }
        });
        $in.on('blur', function () { setTimeout($t.commitText, 0); });
        setTimeout(function () { $in.trigger('focus'); }, 0);
    },

    commitText: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const ti = $d.textInput;
        if (!ti) { return; }
        $d.textInput = null;
        const v = $.trim(ti.el.val());
        ti.el.remove();
        if (v) {
            $d.marks.push({ t: 'text', p: ti.p, x: ti.x, y: ti.y, s: ti.s, v: v });
            $t.redraw(ti.p);
        }
    },

    // Tıklanan noktaya en yakın (en son eklenen) işaret silinir
    eraseAt: function (p, pt) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const pg = $d.pages[p];
        const ctx = pg.ink.getContext('2d');
        const px = pt[0] * pg.w;
        const py = pt[1] * pg.h;
        const near = 10;
        for (let i = $d.marks.length - 1; i >= 0; i--) {
            const m = $d.marks[i];
            if (m.p !== p) { continue; }
            let hit = false;
            if (m.t === 'pen') {
                hit = m.d.some(function (q) { return Math.hypot(q[0] * pg.w - px, q[1] * pg.h - py) < near; });
            } else if (m.t === 'text') {
                ctx.font = (m.s * pg.w) + 'px Arial';
                const w = ctx.measureText(m.v).width;
                const h = m.s * pg.w;
                hit = px >= m.x * pg.w - near && px <= m.x * pg.w + w + near && Math.abs(py - m.y * pg.h) <= h / 2 + near;
            } else {
                hit = Math.hypot(m.x * pg.w - px, m.y * pg.h - py) < m.s * pg.w + near;
            }
            if (hit) {
                $d.marks.splice(i, 1);
                $t.redraw(p);
                return;
            }
        }
    },

    redraw: function (p) {
        const $t = $.DLNFrame;
        const pg = $t.data.pages[p];
        if (!pg) { return; }
        const ctx = pg.ink.getContext('2d');
        ctx.clearRect(0, 0, pg.w, pg.h);
        $t.drawMarks(ctx, p, pg.w, pg.h);
    },

    // Bir sayfanın işaretleri, verilen boyuttaki tuvale (ekran ya da kayıt PNG'si) çizilir
    drawMarks: function (ctx, p, W, H) {
        const color = $.DLNFrame.opt.color;
        ctx.strokeStyle = color;
        ctx.fillStyle = color;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        $.DLNFrame.data.marks.forEach(function (m) {
            if (m.p !== p) { return; }
            const cx = m.x * W;
            const cy = m.y * H;
            const s = m.s * W;
            if (m.t === 'pen') {
                ctx.lineWidth = Math.max(1, s);
                ctx.beginPath();
                ctx.moveTo(m.d[0][0] * W, m.d[0][1] * H);
                if (m.d.length === 1) { ctx.lineTo(m.d[0][0] * W + 0.1, m.d[0][1] * H); }
                m.d.forEach(function (q) { ctx.lineTo(q[0] * W, q[1] * H); });
                ctx.stroke();
            } else if (m.t === 'text') {
                ctx.font = s + 'px Arial';
                ctx.textBaseline = 'middle';
                ctx.fillText(m.v, cx, cy);
            } else if (m.t === 'check') {
                ctx.lineWidth = s * 0.15;
                ctx.beginPath();
                ctx.moveTo(cx - s * 0.45, cy);
                ctx.lineTo(cx - s * 0.1, cy + s * 0.4);
                ctx.lineTo(cx + s * 0.5, cy - s * 0.45);
                ctx.stroke();
            } else if (m.t === 'cross') {
                ctx.lineWidth = s * 0.12;
                ctx.beginPath();
                ctx.moveTo(cx - s / 2, cy - s / 2);
                ctx.lineTo(cx + s / 2, cy + s / 2);
                ctx.moveTo(cx + s / 2, cy - s / 2);
                ctx.lineTo(cx - s / 2, cy + s / 2);
                ctx.stroke();
            }
        });
    },

    /* ---------------- Kaydet ---------------- */

    // İşaretler form alanı olarak: "tür|sayfa|x|y|boyut|veri" (kalem: "x,y;x,y…", yazı: metin)
    markText: function (m) {
        const f = function (n) { return Math.round(n * 100000) / 100000; };
        const data = m.t === 'pen' ? m.d.map(function (q) { return f(q[0]) + ',' + f(q[1]); }).join(';') : (m.t === 'text' ? m.v : '');
        return [m.t, m.p, f(m.x), f(m.y), f(m.s), data].join('|');
    },

    clickSave: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.commitText();
        if (!$d.marks.length) {
            $t.warn($d.lang['ERR_EMPTY']);
            return;
        }
        $t.confirm($d.lang['SAVE_CONFIRM'], function () {
            const post = { REVISION_ID: $d.form.revisionId, RECORD_TITLE: $('#RECORD_TITLE').val(), MARKS: $d.marks.map($t.markText),
                           PAGES: {}, PAGE_W: {}, PAGE_H: {} };
            Object.keys($d.pages).forEach(function (p) {
                const pg = $d.pages[p];
                post.PAGE_W[p] = pg.ptW;
                post.PAGE_H[p] = pg.ptH;
                if (!$d.marks.some(function (m) { return String(m.p) === String(p); })) { return; }
                // Kayıt PNG'si ekran boyutundan bağımsız, yüksek çözünürlükte çizilir
                const c = document.createElement('canvas');
                c.width = Math.round(pg.ptW * $t.opt.exportScale);
                c.height = Math.round(pg.ptH * $t.opt.exportScale);
                $t.drawMarks(c.getContext('2d'), parseInt(p, 10), c.width, c.height);
                post.PAGES[p] = c.toDataURL('image/png');
            });
            $t.request('setRecord', post, function (result) {
                $.fn.ntyi({ type: 'success', layout: 'topRight', text: result['resMsg'] });
                $d.marks = [];
                Object.keys($d.pages).forEach($t.redraw);
                $('#RECORD_TITLE').val('');
                $t.loadRecords();
                $t.loadForms();
            });
        });
    },

    /* ---------------- Ortak ---------------- */

    confirmLeave: function (go) {
        const $t = $.DLNFrame;
        $t.commitText();
        if ($t.data.marks.length) {
            $t.confirm($t.data.lang['LEAVE_CONFIRM'], go);
        } else {
            go();
        }
    },

    confirm: function (text, onYes) {
        const L = $.DLNFrame.data.lang;
        $.fn.nty({
            type: 'information',
            text: text,
            buttons: [
                { text: L['BTN_YES_TEXT'], onClick: function ($noty) { $noty.close(); onYes(); } },
                { text: L['BTN_NO_TEXT'] }
            ]
        });
    },

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

    warn: function (text) {
        $.fn.ntyi({ type: 'warning', layout: 'topRight', timeout: 6000, text: $('<span>').text(text).html() });
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

    // Liste satırı düğmesi; href verilirse indirme bağlantısı olur
    iconButton: function (act, icon, style, title, href) {
        const $b = href ? $('<a download>').attr('href', href) : $('<button type="button">');
        return $b.addClass('border-0 btn-transition btn btn-sm ' + style).attr({ 'data-act': act, title: title })
            .append($('<i class="fas">').addClass(icon));
    },

    // Özgün dosyanın uzantısına göre ikon (Word/Excel'den çevrilen form da PDF olarak doldurulur)
    fileIcon: function (name) {
        const ext = String(name || '').split('.').pop().toLowerCase();
        if (/^(doc|docx|rtf|odt)$/.test(ext)) { return 'fa-file-word'; }
        if (/^(xls|xlsx|xlsm|ods|csv)$/.test(ext)) { return 'fa-file-excel'; }
        return 'fa-file-pdf';
    },

    showPdf: function (url, title) {
        $('#modalShowPDFLabel').text(title || '');
        $('#PDFViewerFrame').attr('src', url);
        $('#modalShowPDF').modal('show');
    },

    code: function (f) {
        return (f.docCode || '') + (f.variantName ? ' · ' + f.variantName : '');
    },

    pad: function (n) {
        return n === null || n === undefined ? '—' : String(n).padStart(2, '0');
    }
};
