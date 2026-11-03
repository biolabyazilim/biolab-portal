/**
 * Biolab QMS — Doküman yükleme (toplu)
 *
 * - Solda dosya alanı: klasör ya da dosyalar sürüklenir / seçilir. Klasör yapısı (FORMLAR/FR06- …/dosya.pdf) korunur:
 *   sunucu kod, ad, varyant ve revizyonu yol ve dosya adından okur (parseFiles).
 * - Altında imza ve sahiplik: sahip departman, Hazırlayan, Kontrol eden, Onaylayan, değişiklik nedeni.
 *   Tablodan işaretlenen satırlara "Seçili satırlara uygula" ile verilir (boş bırakılan alan satırda değişmez).
 * - Sağda kontrol tablosu: kod / ad / varyant / revizyon / tip düzeltilebilir, fazla dosya çıkarılabilir.
 *   Düzeltmeden sonra "Yeniden kontrol et" (checkRows). Hatasız ve imzacısı tam satırlar "Hazır" olur.
 * - "Hazır satırları yükle": satırlar sırayla, her biri ayrı istekte yüklenir (setUpload); biri hata verse de diğerleri sürer.
 * Sunucu tarafı: php/qmsDocument-1.0.0.php
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-qms/qms_document/php/qmsDocument-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','qms_document/qmsDocument-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','qms_document/qmsDocument-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        options:null,      // {DOC_TYPE:[…], DEPARTMENT:[…], USER:[…]} (getOptions)
        files:[],          // {file: File, path: 'FORMLAR/…/x.pdf'}; sıra numarası sunucuya giden dosya numarasıdır
        removed:{},        // listeden çıkarılan dosya numaraları
        used:{},           // yüklenmiş satırların dosya numaraları (yeniden ayrıştırılmaz)
        rows:[],           // kontrol tablosu satırları (sunucudan); yüklenen satırda done = true
        filter:'ALL',
        uploading:false
    },
    opt:{
        maxFiles:900,      // parseFiles: her dosya bir form alanı; PHP max_input_vars = 1000
        checkChunk:40,     // checkRows: satır başına ~13 alan → 40 satır ≈ 520 alan
        notifyRows:5       // kontrol özeti bildiriminde listelenen hatalı satır sayısı
    },

    init: function () {
        const $t = $.DLNFrame;
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
            $('#CHANGE_REASON').val($d.lang['DEFAULT_REASON']);
            $t.loadOptions();
            $t.loadUploaded();
            $t.render();
        });

        // Sürükle-bırak: klasör bırakılınca içindeki bütün dosyalar yollarıyla alınır
        const $drop = $('#dropZone');
        $drop.on('dragover dragenter', function (e) { e.preventDefault(); $drop.addClass('is-over'); });
        $drop.on('dragleave dragend', function () { $drop.removeClass('is-over'); });
        $drop.on('drop', function (e) {
            e.preventDefault();
            $drop.removeClass('is-over');
            $t.readDrop(e.originalEvent.dataTransfer);
        });
        $('#btnPickFiles').click(function () { $('#pickFiles').val('').trigger('click'); });
        $('#btnPickFolder').click(function () { $('#pickFolder').val('').trigger('click'); });
        $('#pickFiles, #pickFolder').on('change', function () {
            $t.addFiles(Array.prototype.map.call(this.files, function (f) { return { file: f, path: f.webkitRelativePath || f.name }; }));
        });

        $('#btnApply').click($t.clickApply);
        $('#btnCheck').click(function () { $t.checkRows(); });
        $('#btnUpload').click($t.clickUpload);
        $('#btnClear').click($t.clickClear);
        $('#checkAll').on('change', function () {
            $('#rows .qm-sel:enabled').prop('checked', this.checked);
        });
        $('#filters').on('click', '.btn', function () {
            $('#filters .btn').removeClass('active');
            $(this).addClass('active');
            $d.filter = $(this).data('filter');
            $t.render();
        });

        // Tablodaki düzeltmeler satıra yazılır; "Yeniden kontrol et" ile sunucu doğrular
        $('#rows').on('change', '.qm-edit', function () {
            const row = $d.rows[$(this).closest('tr').data('i')];
            row[$(this).data('field')] = $(this).val();
            row.dirty = true;
            $(this).closest('tr').find('.qm-status').html($t.statusHtml(row));
        });
        $('#rows').on('click', '.qm-why', function () {
            $t.showRowMessages($d.rows[$(this).closest('tr').data('i')]);
        });
        $('#rows').on('click', '.qm-x', function () {
            $d.removed[$(this).data('file')] = true;
            $t.parseFiles();
        });
    },

    loadOptions: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $.ajaxq('frame', {
            url: proxyPath + modulePath + 'getOptions',
            type: 'POST',
            dataType: 'json',
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $d.options = result['resObject'];
                    $t.fillSelect($('#OWNER_DEPARTMENT_ID'), $d.options.DEPARTMENT.map(function (o) { return { id: o.id, text: o.name }; }));
                    const users = $d.options.USER.map(function (u) { return { id: u.id, text: u.name + ' — ' + u.positionName }; });
                    ['#PREPARED_ID', '#CHECKED_ID', '#APPROVED_ID'].forEach(function (s) { $t.fillSelect($(s), users); });
                    $t.render();
                } else {
                    $t.showError(result ? result['resMsg'] : '');
                }
            },
            error: function (xhr) { $t.showError(xhr); }
        });
    },

    fillSelect: function ($sel, items) {
        $sel.empty().append(new Option($.DLNFrame.data.lang['SELECT'], '', true, true));
        items.forEach(function (i) { $sel.append(new Option(i.text, i.id, false, false)); });
        $sel.select2({ width: '100%', minimumResultsForSearch: 0 });
    },

    // Bırakılan öğeler: klasörler içine girilerek okunur (readEntries her çağrıda bir parça döner)
    readDrop: function (dt) {
        const $t = $.DLNFrame;
        const entries = Array.prototype.map.call(dt.items || [], function (it) { return it.webkitGetAsEntry ? it.webkitGetAsEntry() : null; })
            .filter(function (e) { return e !== null; });
        if (!entries.length) {
            $t.addFiles(Array.prototype.map.call(dt.files, function (f) { return { file: f, path: f.name }; }));
            return;
        }
        const out = [];
        const walk = function (entry) {
            return new Promise(function (resolve) {
                if (entry.isFile) {
                    entry.file(function (f) { out.push({ file: f, path: entry.fullPath.replace(/^\/+/, '') }); resolve(); }, function () { resolve(); });
                } else if (entry.isDirectory) {
                    const reader = entry.createReader();
                    const all = [];
                    const read = function () {
                        reader.readEntries(function (batch) {
                            if (batch.length) {
                                Array.prototype.push.apply(all, batch);
                                read();
                            } else {
                                Promise.all(all.map(walk)).then(resolve);
                            }
                        }, function () { resolve(); });
                    };
                    read();
                } else {
                    resolve();
                }
            });
        };
        $('.frame-block').blk();
        Promise.all(entries.map(walk)).then(function () {
            $('.frame-block').ublk();
            $t.addFiles(out);
        });
    },

    // Aynı yol + boyuttaki dosya ikinci kez eklenmez
    addFiles: function (list) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const seen = {};
        $d.files.forEach(function (f) { seen[f.path + '|' + f.file.size] = true; });
        list.forEach(function (f) {
            if (!seen[f.path + '|' + f.file.size] && !/(^|\/)(\.|~\$|Thumbs\.db$)/i.test(f.path)) {
                seen[f.path + '|' + f.file.size] = true;
                $d.files.push(f);
            }
        });
        $t.parseFiles();
    },

    // Yüklenmemiş ve çıkarılmamış dosyalar sunucuda satırlara ayrılır; tabloda verilen imzacı/sahip korunur
    parseFiles: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const done = $d.rows.filter(function (r) { return r.done; });
        const used = $d.used;
        const paths = {};
        let count = 0;
        $d.files.forEach(function (f, i) {
            if (!used[i] && !$d.removed[i]) { paths[i] = f.path; count++; }
        });
        if (count === 0) {
            $d.rows = done;
            $t.render();
            return;
        }
        // Her yol ayrı form alanı (PATHS[n]); PHP max_input_vars (1000) aşılırsa fazlası sessizce düşer
        if (count > $t.opt.maxFiles) {
            $t.showError($d.lang['ERR_TOO_MANY'].replace('%d', $t.opt.maxFiles));
            return;
        }
        const keep = {};
        $d.rows.forEach(function (r) { if (!r.done) { keep[$t.rowKey(r)] = r; } });
        $t.request('parseFiles', { PATHS: paths }, function (result) {
            const rows = (result['records'] || []).map(function (r) {
                const old = keep[$t.rowKey(r)];
                if (old) {
                    ['ownerDepartmentId', 'preparedId', 'checkedId', 'approvedId'].forEach(function (k) { r[k] = r[k] || old[k]; });
                }
                return r;
            });
            $d.rows = done.concat(rows);
            // Korunan imzacılar sunucuda da kontrol edilsin
            if (Object.keys(keep).length) {
                $t.checkRows();
            } else {
                $t.render();
                $t.notifyProblems();
            }
        });
    },

    // Satırın sunucuya giden alanları (ROWS[i][…] ve setUpload)
    rowPost: function (r) {
        return {
            DOC_TYPE_ID: r.docTypeId,
            DOC_CODE: r.docCode,
            VARIANT_NAME: r.variantName,
            DOC_TITLE: r.docTitle,
            REVISION_NO: r.revisionNo,
            OWNER_DEPARTMENT_ID: r.ownerDepartmentId,
            PREPARED_ID: r.preparedId,
            CHECKED_ID: r.checkedId,
            APPROVED_ID: r.approvedId,
            FILE_ERRORS: r.fileErrors || [],
            FILE_WARNINGS: r.fileWarnings || []
        };
    },

    // Düzeltilen (yüklenmemiş) satırlar yeniden kontrol edilir. max_input_vars için parça parça gider;
    // sunucunun döndürdüğü alanlar satırın üzerine yazılır (dosya bilgileri ekranda kalır).
    checkRows: function (onDone) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const open = $d.rows.filter(function (r) { return !r.done; });
        if (!open.length) {
            $t.render();
            return;
        }
        const chunks = [];
        for (let i = 0; i < open.length; i += $t.opt.checkChunk) {
            chunks.push(open.slice(i, i + $t.opt.checkChunk));
        }
        let left = chunks.length;
        chunks.forEach(function (chunk) {
            $t.request('checkRows', { ROWS: chunk.map($t.rowPost) }, function (result) {
                (result['records'] || []).forEach(function (rec, i) {
                    ['docTypeId', 'docTypeName', 'external', 'docCode', 'variantName', 'docTitle', 'revisionNo', 'documentId', 'currentRevisionNo',
                     'ownerDepartmentId', 'preparedId', 'checkedId', 'approvedId', 'errors', 'warnings']
                        .forEach(function (k) { chunk[i][k] = rec[k]; });
                    chunk[i].dirty = false;
                });
                if (--left === 0) {
                    $t.markDuplicates(open);
                    $t.render();
                    if (onDone) {
                        onDone();
                    } else {
                        $t.notifyProblems();
                    }
                }
            });
        });
    },

    // Parçalar ayrı kontrol edildiği için aynı kod + varyantın tablo genelinde tekrarı burada işaretlenir
    markDuplicates: function (rows) {
        const $t = $.DLNFrame;
        const msg = $t.data.lang['ERR_DUPLICATE_ROW'];
        const count = {};
        // Yüklenmiş satırlar sayılmaz: onlar için sunucu "imzada revizyonu var" hatasını verir
        $t.data.rows.forEach(function (r) { if (!r.done) { const k = $t.rowKey(r); count[k] = (count[k] || 0) + 1; } });
        rows.forEach(function (r) {
            if (count[$t.rowKey(r)] > 1 && r.errors.indexOf(msg) < 0) {
                r.errors.push(msg);
            }
        });
    },

    rowKey: function (r) {
        return (r.docCode || '#' + (r.docTitle || '').toLocaleLowerCase('tr')) + '|' + (r.variantName || '').toLocaleLowerCase('tr');
    },

    // Satırın durumu: DONE (yüklendi), ERROR (hata), MISSING (imzacı/sahip eksik), READY (yüklenebilir)
    rowState: function (r) {
        if (r.done) { return 'DONE'; }
        if (r.errors && r.errors.length) { return 'ERROR'; }
        const need = r.external ? ['approvedId'] : ['preparedId', 'checkedId', 'approvedId'];
        const missing = need.some(function (k) { return !r[k]; }) || (!r.documentId && !r.ownerDepartmentId);
        return missing ? 'MISSING' : 'READY';
    },

    statusHtml: function (r) {
        const $t = $.DLNFrame;
        const L = $t.data.lang;
        if (r.uploading) {
            return '<span class="qm-badge qm-badge-wait">' + L['STATUS_UPLOADING'] + '</span>';
        }
        const state = $t.rowState(r);
        const badge = {
            DONE: '<span class="qm-badge qm-badge-done">' + L['STATUS_DONE'] + '</span>',
            ERROR: '<span class="qm-badge qm-badge-err">' + L['FILTER_ERRORS'] + '</span>',
            MISSING: '<span class="qm-badge qm-badge-wait">' + L['STATUS_MISSING'] + '</span>',
            READY: '<span class="qm-badge qm-badge-ok">' + L['STATUS_READY'] + '</span>'
        }[state];
        const kind = r.documentId
            ? ' <span class="qm-badge qm-badge-new">' + $t.esc(L['NEW_REVISION'].replace('%s', $t.pad(r.currentRevisionNo)).replace('%s', $t.pad(r.revisionNo))) + '</span>'
            : ' <span class="qm-badge qm-badge-new">' + L['NEW_DOC'] + '</span>';
        // Mesajlar tabloya yazılmaz: simgeye tıklanınca noty ile gösterilir
        const count = (r.errors || []).length + (r.warnings || []).length;
        const why = count
            ? ' <a href="javascript:" class="qm-why ' + ((r.errors || []).length ? 'is-err' : 'is-warn') + '" title="' + $t.esc(L['ROW_DETAILS']) + '">'
              + '<i class="fas fa-exclamation-circle"></i> ' + count + '</a>'
            : '';
        const dirty = r.dirty ? ' <span class="qm-badge qm-badge-wait">' + L['STATUS_DIRTY'] + '</span>' : '';
        if (state === 'DONE') {
            return badge + $t.revStatusHtml(r);
        }
        return badge + kind + dirty + why;
    },

    // Yüklenen revizyonun durumu: imzadaysa sırası gelen adım ("Hazırlayan bekliyor"); yükleyen ve zaman başlıkta
    revStatusHtml: function (r) {
        const $t = $.DLNFrame;
        const L = $t.data.lang;
        if (!r.revStatus) {
            return '';
        }
        const roleLabel = { PREPARED: L['PREPARED_ID'], CHECKED: L['CHECKED_ID'], APPROVED: L['APPROVED_ID'] };
        let text = L['REV_' + r.revStatus] || r.revStatus;
        if (r.revStatus === 'SIGNING' && r.waitingRole) {
            text += ' · ' + L['WAITING_FOR'].replace('%s', roleLabel[r.waitingRole]);
        }
        const title = r.uploadedAt ? L['UPLOADED_INFO'].replace('%s', r.uploadedAt).replace('%s', r.uploadedBy || '') : '';
        return ' <span class="qm-badge qm-badge-new" title="' + $t.esc(title) + '">' + $t.esc(text) + '</span>';
    },

    // Son yüklenen revizyonlar sunucudan gelir ("Yüklendi" süzgeci); sayfa yenilense de görünür
    loadUploaded: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.request('getUploaded', {}, function (result) {
            $d.rows = $d.rows.filter(function (r) { return !r.done; }).concat(result['records'] || []);
            $t.render();
        });
    },

    // Satırın hata ve uyarıları (durum simgesine tıklanınca)
    showRowMessages: function (r) {
        const $t = $.DLNFrame;
        const L = $t.data.lang;
        let text = '<b>' + $t.esc($t.rowLabel(r)) + '</b>';
        (r.errors || []).forEach(function (e) { text += '<br>• ' + $t.esc(e); });
        (r.warnings || []).forEach(function (w) { text += '<br>• <i>' + $t.esc(w) + '</i>'; });
        $.fn.ntyp({
            type: (r.errors || []).length ? 'error' : 'warning',
            text: text,
            modal: true,
            buttons: [{ addClass: 'btn btn-sm btn-primary', text: L['BTN_OK_TEXT'] }]
        });
    },

    // Kontrol bittiğinde hatalı satırların özeti (sağ üstte; ilk birkaç satır ve ilk hatası)
    notifyProblems: function () {
        const $t = $.DLNFrame;
        const L = $t.data.lang;
        const bad = $t.data.rows.filter(function (r) { return !r.done && (r.errors || []).length; });
        if (!bad.length) {
            return;
        }
        let text = '<b>' + $t.esc(L['NOTIFY_ERRORS'].replace('%d', bad.length)) + '</b>';
        bad.slice(0, $t.opt.notifyRows).forEach(function (r) {
            text += '<br>• ' + $t.esc($t.rowLabel(r)) + ': ' + $t.esc(r.errors[0]);
        });
        if (bad.length > $t.opt.notifyRows) {
            text += '<br>' + $t.esc(L['NOTIFY_MORE'].replace('%d', bad.length - $t.opt.notifyRows));
        }
        text += '<br><small>' + $t.esc(L['NOTIFY_HINT']) + '</small>';
        $.fn.ntyi({ type: 'warning', layout: 'topRight', timeout: 8000, text: text });
    },

    rowLabel: function (r) {
        return (r.docCode || r.docTitle || '') + (r.variantName ? ' · ' + r.variantName : '');
    },

    render: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$d.lang) { return; }
        const L = $d.lang;
        const o = $d.options || { DOC_TYPE: [], DEPARTMENT: [], USER: [] };
        const userName = {};
        o.USER.forEach(function (u) { userName[u.id] = u.name; });
        const deptName = {};
        o.DEPARTMENT.forEach(function (x) { deptName[x.id] = x.name; });

        const $body = $('#rows').empty();
        const counts = { ALL: 0, READY: 0, ERROR: 0, DONE: 0 };
        $d.rows.forEach(function (r, i) {
            const state = $t.rowState(r);
            counts.ALL++;
            if (counts[state] !== undefined) { counts[state]++; }
            if ($d.filter !== 'ALL' && $d.filter !== state) { return; }

            const lock = r.done || $d.uploading;
            const input = function (field, cls) {
                return $('<input type="text" class="form-control qm-edit">').addClass(cls || '').attr('data-field', field)
                    .val(r[field] === null || r[field] === undefined ? '' : r[field]).prop('disabled', lock);
            };
            const $type = $('<select class="form-control qm-edit c-type" data-field="docTypeId">').prop('disabled', lock)
                .append(new Option(L['TYPE_SELECT'], '', false, !r.docTypeId));
            o.DOC_TYPE.forEach(function (t) { $type.append(new Option(t.name, t.id, false, String(t.id) === String(r.docTypeId))); });

            const $files = $('<ul class="qm-files">');
            (r.fileIndexes || []).forEach(function (fi, k) {
                const $li = $('<li>').attr('title', ($d.files[fi] || {}).path || r.fileNames[k]);
                if (!lock) {
                    $li.append($('<i class="fas fa-times qm-x">').attr({ 'data-file': fi, title: L['REMOVE_FILE'] }));
                }
                $files.append($li.append(document.createTextNode(r.fileNames[k])));
            });

            const $signers = $('<div class="qm-signers">');
            const roles = r.external ? [['approvedId', 'APPROVED_ID']] : [['preparedId', 'PREPARED_ID'], ['checkedId', 'CHECKED_ID'], ['approvedId', 'APPROVED_ID']];
            roles.forEach(function (x) {
                $signers.append($('<div>').append($('<span>').text(L[x[1]] + ': '), document.createTextNode(r[x[0]] ? ((r.signerNames || {})[x[0]] || userName[r[x[0]]] || '#' + r[x[0]]) : '—')));
            });

            $body.append($('<tr>').attr('data-i', i).toggleClass('is-error', state === 'ERROR').toggleClass('is-done', state === 'DONE').append(
                $('<td>').append($('<input type="checkbox" class="qm-sel">').prop('disabled', lock)),
                $('<td class="qm-status">').html($t.statusHtml(r)),
                $('<td>').append(input('docCode', 'c-code')),
                $('<td>').append(input('docTitle', 'c-title')),
                $('<td>').append(input('variantName', 'c-variant')),
                $('<td>').append(input('revisionNo', 'c-rev')),
                $('<td>').append($type),
                $('<td>').append($files),
                $('<td>').text(r.ownerName || (r.documentId ? '—' : (deptName[r.ownerDepartmentId] || '—'))),
                $('<td>').append($signers)
            ));
        });
        if (!$d.rows.length) {
            $body.append($('<tr>').append($('<td colspan="10" class="text-muted text-center p-3">').text(L['EMPTY'])));
        }
        $('#checkAll').prop('checked', false);
        $('#summary').text(L['SUMMARY'].replace('%d', counts.ALL).replace('%d', counts.READY).replace('%d', counts.ERROR).replace('%d', counts.DONE));
        $('#btnUpload, #btnCheck, #btnApply, #btnClear').prop('disabled', $d.uploading);
    },

    // İşaretli satırlara panelde seçilenler verilir (boş alan satırda değişmez; dış kaynaklıda yalnızca onaylayan)
    clickApply: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const v = {
            ownerDepartmentId: $('#OWNER_DEPARTMENT_ID').val(),
            preparedId: $('#PREPARED_ID').val(),
            checkedId: $('#CHECKED_ID').val(),
            approvedId: $('#APPROVED_ID').val()
        };
        const picked = $('#rows .qm-sel:checked').map(function () { return $d.rows[$(this).closest('tr').data('i')]; }).get();
        if (!picked.length) {
            $.fn.ntyi({ type: 'warning', layout: 'topRight', text: $d.lang['SETTINGS_INFO'] });
            return;
        }
        picked.forEach(function (r) {
            Object.keys(v).forEach(function (k) {
                if (!v[k] || (r.external && (k === 'preparedId' || k === 'checkedId'))) { return; }
                r[k] = parseInt(v[k], 10);
            });
        });
        $t.checkRows();
    },

    clickUpload: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if ($d.rows.some(function (r) { return r.dirty; })) {
            $t.checkRows($t.clickUpload);
            return;
        }
        const ready = $d.rows.filter(function (r) { return $t.rowState(r) === 'READY'; });
        if (!ready.length) {
            $.fn.ntyi({ type: 'warning', layout: 'topRight', text: $d.lang['SUMMARY'].replace('%d', $d.rows.length).replace('%d', 0)
                .replace('%d', $d.rows.filter(function (r) { return $t.rowState(r) === 'ERROR'; }).length).replace('%d', $d.rows.filter(function (r) { return r.done; }).length) });
            return;
        }
        if (!$.trim($('#CHANGE_REASON').val())) {
            $t.showError($d.lang['ERR_REASON']);
            return;
        }
        $.fn.nty({
            type: 'information',
            text: $d.lang['UPLOAD_CONFIRM'].replace('%d', ready.length),
            buttons: [
                {
                    text: $d.lang['BTN_YES_TEXT'],
                    onClick: function ($noty) {
                        $noty.close();
                        $t.uploadAll(ready);
                    }
                },
                {
                    text: $d.lang['BTN_NO_TEXT'],
                }
            ]
        });
    },

    // Satırlar sırayla yüklenir; hata veren satır tabloda hatasıyla kalır
    uploadAll: function (queue) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const reason = $.trim($('#CHANGE_REASON').val());
        let ok = 0;
        let failed = 0;
        $d.uploading = true;
        $t.render();
        const next = function () {
            const r = queue.shift();
            if (!r) {
                $d.uploading = false;
                $t.loadUploaded();
                $t.notifyProblems();
                $.fn.ntyp({ type: failed ? 'warning' : 'information', text: $d.lang['UPLOAD_FINISHED'].replace('%d', ok).replace('%d', failed), layout: 'center', timeout: false,
                            buttons: [{ addClass: 'btn btn-sm btn-primary', text: $d.lang['BTN_OK_TEXT'] }] });
                return;
            }
            r.uploading = true;
            $t.render();
            const fd = new FormData();
            // Satır alanları düz form alanı olarak gider (boş alan hiç eklenmez; FormData null'ı "null" metnine çevirir)
            const post = $t.rowPost(r);
            Object.keys(post).forEach(function (k) {
                if (!Array.isArray(post[k]) && post[k] !== null && post[k] !== undefined) {
                    fd.append(k, post[k]);
                }
            });
            fd.append('CHANGE_REASON', reason);
            fd.append('MAIN_FILE', $d.files[r.mainFile.index].file, r.mainFile.name);
            if (r.sourceFile) {
                fd.append('SOURCE_FILE', $d.files[r.sourceFile.index].file, r.sourceFile.name);
            }
            $.ajax({
                url: proxyPath + modulePath + 'setUpload',
                type: 'POST',
                dataType: 'json',
                data: fd,
                processData: false,
                contentType: false,
                success: function (result) {
                    r.uploading = false;
                    if (result && result['resCode'] === 0) {
                        r.done = true;
                        r.errors = [];
                        r.fileIndexes.forEach(function (i) { $d.used[i] = true; });
                        r.documentId = result['resObject']['DOCUMENT_ID'];
                        ok++;
                    } else {
                        r.errors = [result ? result['resMsg'] : $d.lang['INF_ERROR']];
                        failed++;
                    }
                    next();
                },
                error: function (xhr) {
                    r.uploading = false;
                    r.errors = [$d.lang['INF_ERROR'] + ' (' + xhr.status + ')'];
                    failed++;
                    next();
                }
            });
        };
        next();
    },

    // Eklenen dosyalar ve yüklenmemiş satırlar temizlenir; yüklenenler (sunucudan) kalır
    clickClear: function () {
        const $d = $.DLNFrame.data;
        $d.files = [];
        $d.removed = {};
        $d.used = {};
        $d.rows = $d.rows.filter(function (r) { return r.done; });
        $.DLNFrame.render();
    },

    // Ortak istek: bekleme göstergesi, hata bildirimi
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
    },

    esc: function (s) {
        return $('<span>').text(s === null || s === undefined ? '' : s).html();
    }
};
