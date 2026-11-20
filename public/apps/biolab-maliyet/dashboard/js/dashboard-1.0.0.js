/**
 * Maliyet — Özet (dashboard)
 *
 * - Üstte maliyet çalışması, dönem ve birim seçilir; her değişiklikte getDashboard tek istekte bütün kartları getirir.
 * - Kartlar: özet kutuları, veri tamlığı (birim × maliyet durumu), önce tamamlanacak testler (dönem hacmine göre),
 *   kur hassasiyeti (kaydırıcılar ekranda hesaplar), maliyet dağılımı, dönemin malzeme maliyeti (Pareto), en pahalı testler,
 *   hizmet grubu ortalaması, aylık takip trendi.
 * - Satıra / çubuğa tıklayınca ilgili ekran kabukta açılır (window.parent.hbOpenPage).
 * - Seçim kutuları $.fn.slb ile dolar (initX → getX); select id'si alan adıdır. Grafikler Chart.js 3.
 */
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/biolab-maliyet/dashboard/php/dashboard-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(['general.json','mlyt_dashboard/dashboard-1.0.0.json']);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(['general.json','mlyt_dashboard/dashboard-1.0.0.json']);
        });
    }
});

$.DLNFrame = {
    data: {
        lang: null,
        ready: { study: false, period: false },
        result: null,
        charts: {},
        timer: null
    },
    opt: {
        storeKey: 'mlyt.dashboard.',
        pages: { testCost: './test_cost/testCost-1.0.0.html', monthly: './monthly/monthly-1.0.0.html' },
        color: { blue: '#2a78d6', orange: '#eb6834', aqua: '#1baf7a', yellow: '#eda100', violet: '#6250d6', red: '#e34948', gray: '#b9bfc8', ink: '#5c6b7e', grid: 'rgba(23,38,58,.07)' },
        statusColor: { OK: '#2a78d6', MISSING_PRICE: '#eb6834', COMPONENT_MISSING: '#eda100', NEEDS_VOLUME: '#6250d6', NO_RECIPE: '#b9bfc8', CYCLE: '#e34948' },
        currencyColor: { EUR: '#2a78d6', USD: '#1baf7a', GBP: '#6250d6', TRY: '#b9bfc8' },
        unitNames: { BIOLAB: 'Biolab', PATHOLOGY: 'Patoloji' },
        months: ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara']
    },

    init: function () {
        const $t = $.DLNFrame;
        // Dil dosyaları document.ready'de yüklenir; yüklenince 'langLoaded' tetiklenir ve ekran start() ile başlar
        $t.setEvent();
    },

    setEvent: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;

        const body = $('body');
        body.off('langLoaded.lang');
        body.on('langLoaded.lang', function () {
            $d.lang = getLangDatas() || {};
            $.DLN.LangManager.setLanguageTemplate($(document));
            $t.start();
        });

        $('#STUDY_ID, #PERIOD_ID, #LAB_UNIT').on('change', function () {
            $t.storeSet(this.id, $(this).val() || '');
            $t.load();
        });
        $('#FX_EUR, #FX_USD, #FX_GBP').on('input', $t.renderFxImpact);
        $('#todoList, #topList').on('click', 'li', function () { $t.openPage('testCost'); });
    },

    start: function () {
        const $t = $.DLNFrame;
        $t.chartDefaults();
        $t.initLAB_UNIT();
        $t.initSTUDY_ID();
        $t.initPERIOD_ID();
    },

    /* ---------- Seçim kutuları ---------- */

    initSTUDY_ID: function () {
        const $t = $.DLNFrame;
        $('#STUDY_ID').slb({
            field:{ value:'studyId', text:'studyText', disabledFL:'activeFl' },
            select2:{ placeholder:'Çalışma yok', allowClear:false, minimumResultsForSearch:-1, width:'100%' },
            ajax:{ url:proxyPath + modulePath + 'getSTUDY_ID' },
            event:{ afterLoad:function (el) { $t.pick(el, 'STUDY_ID', true); $t.data.ready.study = true; $t.load(); } }
        });
    },

    initPERIOD_ID: function () {
        const $t = $.DLNFrame;
        $('#PERIOD_ID').slb({
            field:{ value:'periodId', text:'periodText', disabledFL:'activeFl' },
            select2:{ placeholder:'Dönem yok', allowClear:true, minimumResultsForSearch:-1, width:'100%' },
            ajax:{ url:proxyPath + modulePath + 'getPERIOD_ID' },
            event:{ afterLoad:function (el) { $t.pick(el, 'PERIOD_ID', true); $t.data.ready.period = true; $t.load(); } }
        });
    },

    initLAB_UNIT: function () {
        const $t = $.DLNFrame;
        $('#LAB_UNIT').slb({
            field:{ value:'labUnit', text:'labUnitName', disabledFL:'activeFl' },
            select2:{ placeholder:'Tüm birimler', allowClear:true, minimumResultsForSearch:-1, width:'100%' },
            ajax:{ url:proxyPath + modulePath + 'getLAB_UNIT' },
            event:{ afterLoad:function (el) { $t.pick(el, 'LAB_UNIT', false); } }
        });
    },

    // Son seçilen değer (hâlâ listedeyse) ya da firstDefault ise ilk seçenek; change tetiklenmez (yükleme hazır olunca load)
    pick: function (el, key, firstDefault) {
        const $el = $(el);
        let v = $.DLNFrame.storeGet(key);
        if (!v || !$el.find('option[value="' + v + '"]').length) { v = firstDefault ? ($el.find('option[value!=""]').first().val() || '') : ''; }
        $el.val(v).trigger('change.select2');
    },

    /* ---------- Veri ---------- */

    load: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$d.ready.study || !$d.ready.period) { return; }
        if (!$('#STUDY_ID').val()) { $('#dsBody').html('<div class="ds-glass ds-msg">Henüz maliyet çalışması yok.</div>'); return; }
        clearTimeout($d.timer);
        $d.timer = setTimeout(function () {
            $('#dsBody').addClass('ds-loading');
            $.ajaxq('frame', {
                url: proxyPath + modulePath + 'getDashboard',
                type: 'POST',
                dataType: 'json',
                data: { STUDY_ID: $('#STUDY_ID').val(), PERIOD_ID: $('#PERIOD_ID').val() || '', LAB_UNIT: $('#LAB_UNIT').val() || '' },
                success: function (result) {
                    if (!result || result['resCode'] !== 0) {
                        $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Özet alınamadı.' });
                        return;
                    }
                    $d.result = result['resObject'];
                    $t.render();
                },
                error: function () { $.fn.ntyi({ type: 'error', layout: 'topRight', text: 'Sunucuya ulaşılamadı.' }); },
                complete: function () { $('#dsBody').removeClass('ds-loading'); }
            });
        }, 50);
    },

    render: function () {
        const $t = $.DLNFrame;
        const r = $t.data.result;
        $t.renderKpis(r);
        $t.renderStatus(r.completeness);
        $t.renderTodo(r.todo, r.period);
        $t.renderFx(r);
        $t.renderDist(r.structure);
        $t.renderPareto(r.monthly, r.period);
        $t.renderTop(r.structure.top);
        $t.renderGroups(r.structure.groups);
        $t.renderTrend(r.trend);
    },

    /* ---------- Kartlar ---------- */

    renderKpis: function (r) {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        const c = r.completeness, s = r.structure, m = r.monthly;
        const last = r.trend.filter(function (x) { return r.period && x.periodYm === r.period.periodYm; })[0] || null;
        const pct = function (a, b) { return b ? Math.round(a / b * 100) : 0; };
        const tile = function (icon, label, value, note, meter) {
            return '<div class="ds-glass ds-kpi"><div class="ds-kpi-label"><i class="' + icon + '" aria-hidden="true"></i>' + esc(label) + '</div>'
                + '<div class="ds-kpi-value">' + value + '</div>' + (note ? '<div class="ds-kpi-note">' + esc(note) + '</div>' : '')
                + (meter !== undefined ? '<div class="ds-meter"><i style="width:' + meter + '%"></i></div>' : '') + '</div>';
        };
        $('#kpis').html(
            tile('fas fa-clipboard-check', 'Maliyeti hesaplanan test', '%' + pct(c.costed, c.tests), $t.int(c.costed) + ' / ' + $t.int(c.tests), pct(c.costed, c.tests))
            + tile('fas fa-tags', 'Fiyatı girilen malzeme', '%' + pct(c.pricedMaterials, c.materials), $t.int(c.pricedMaterials) + ' / ' + $t.int(c.materials), pct(c.pricedMaterials, c.materials))
            + tile('fas fa-vial', 'Medyan test maliyeti', s.median === null ? '—' : $t.money0(s.median) + ' <small>TL</small>', s.p90 === null ? '' : 'Testlerin %10\'u ' + $t.money0(s.p90) + ' TL üstünde')
            + tile('fas fa-coins', 'Dönemin malzeme maliyeti', m.hasData ? $t.short(m.total) + ' <small>TL</small>' : '—',
                m.hasData ? r.period.periodText + ' · hacmin %' + pct(m.volumeCosted, m.volumeAll) + '\'i hesaplı' : 'Dönemde test sayıları yok')
            + tile('fas fa-redo', 'Tekrar maliyeti', last && last.has.tests ? $t.short(last.repeatPresent) + ' <small>TL</small>' : '—',
                last && last.has.tests ? 'Sunulabilir · doğrulanacak ' + $t.short(last.repeatCheck) + ' TL' : 'Dönem seçin')
            + tile('fas fa-ban', 'Red / kabul edilmeyen', last && last.has.samples ? $t.int(last.samples) + ' <small>numune</small>' : '—',
                last && last.has.samples ? $t.int(last.tubes) + ' tüp' + (last.has.fire ? ' · fire / zayi ' + $t.int(last.fire) + ' satır' : '') : 'Dönem seçin')
        );
    },

    renderStatus: function (c) {
        const $t = $.DLNFrame;
        const units = Object.keys(c.byUnit);
        const order = ['OK', 'MISSING_PRICE', 'COMPONENT_MISSING', 'NEEDS_VOLUME', 'NO_RECIPE', 'CYCLE'];
        const used = order.filter(function (s) { return units.some(function (u) { return c.byUnit[u][s]; }); });
        $('#lgStatus').html(used.map(function (s) {
            const n = units.reduce(function (a, u) { return a + (c.byUnit[u][s] || 0); }, 0);
            return '<span><i style="background:' + $t.opt.statusColor[s] + '"></i>' + $t.esc(c.statuses[s]) + ' ' + $t.int(n) + '</span>';
        }).join(''));
        $t.chart('chStatus', {
            type: 'bar',
            data: {
                labels: units.map(function (u) { return $t.opt.unitNames[u] || u; }),
                datasets: used.map(function (s) {
                    return { label: c.statuses[s], data: units.map(function (u) { return c.byUnit[u][s] || 0; }),
                             backgroundColor: $t.alpha($t.opt.statusColor[s], .8), borderRadius: 4, barThickness: 22, borderSkipped: false };
                })
            },
            options: { indexAxis: 'y', scales: { x: { stacked: true, grid: { color: $t.opt.color.grid } }, y: { stacked: true, grid: { display: false } } },
                       onClick: function () { $t.openPage('testCost'); } }
        });
    },

    renderTodo: function (rows, period) {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        $('.js-todo-sub').text(period ? period.periodText + ' test sayısına göre' : 'dönem seçilmedi: koda göre');
        if (!rows.length) { $('#todoList').html('<li class="ds-msg" style="display:block;cursor:default">Maliyeti eksik test yok.</li>'); return; }
        $('#todoList').html(rows.slice(0, 10).map(function (r) {
            return '<li title="Test maliyetleri ekranında aç"><span class="ds-code">' + esc(r.code) + '</span><span class="ds-name">' + esc(r.name)
                + '<span class="ds-sub">' + esc(r.statusText) + (r.missing.length ? ' · ' + esc(r.missing[0]) : '') + '</span></span>'
                + '<span class="ds-num">' + (r.volume ? $t.int(r.volume) + ' <span class="ds-sub" style="display:inline">test</span>' : '') + '</span></li>';
        }).join(''));
    },

    renderFx: function (r) {
        const $t = $.DLNFrame;
        // Dönem varsa hacim ağırlıklı (o ayın malzeme maliyeti), yoksa her test bir kez sayılır
        const src = r.monthly.hasData ? r.monthly.fx : r.structure.fx;
        $t.data.fx = src;
        $t.data.fxBase = r.monthly.hasData ? r.monthly.total : null;
        $('.js-fx-sub').text(r.monthly.hasData ? r.period.periodText + ' hacmine göre' : 'her test bir kez sayılır');
        $('#lgFx').html(src.map(function (x) {
            return '<span><i style="background:' + ($t.opt.currencyColor[x.currency] || '#888') + '"></i>' + x.currency + ' %' + $t.pct1(x.share) + '</span>';
        }).join('') + '<span>· kur ' + Object.keys(r.structure.rates).map(function (k) { return k + ' ' + $t.money0(r.structure.rates[k]); }).join(', ') + '</span>');
        $t.chart('chFx', {
            type: 'doughnut',
            data: { labels: src.map(function (x) { return x.currency; }),
                    datasets: [{ data: src.map(function (x) { return Math.round(x.share * 1000) / 10; }), borderWidth: 2, borderColor: 'rgba(255,255,255,.9)',
                                 backgroundColor: src.map(function (x) { return $t.alpha($t.opt.currencyColor[x.currency] || '#888', .78); }) }] },
            options: { cutout: '68%', scales: {}, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.label + ': %' + c.raw; } } } } }
        });
        $t.renderFxImpact();
    },

    renderFxImpact: function () {
        const $t = $.DLNFrame;
        const fx = $t.data.fx || [];
        let impact = 0;
        ['EUR', 'USD', 'GBP'].forEach(function (cur) {
            const v = Number($('#FX_' + cur).val());
            $('#FX_' + cur + '_O').text((v > 0 ? '+' : '') + '%' + v);
            const sh = (fx.filter(function (x) { return x.currency === cur; })[0] || { share: 0 }).share;
            impact += sh * v;
        });
        $('#fxImpact').text((impact > 0 ? '+' : '') + '%' + (Math.round(impact * 10) / 10).toLocaleString('tr-TR'));
        $('#fxImpactTl').text($t.data.fxBase ? '· dönemin malzeme maliyetinde ' + (impact >= 0 ? '+' : '−') + $t.short(Math.abs($t.data.fxBase * impact / 100)) + ' TL' : '');
    },

    renderDist: function (s) {
        const $t = $.DLNFrame;
        $('.js-dist-sub').text($t.int(s.count) + ' test · medyan ' + (s.median === null ? '—' : $t.money0(s.median) + ' TL'));
        $t.chart('chDist', {
            type: 'bar',
            data: { labels: s.distribution.map(function (x) { return x.label; }),
                    datasets: [{ label: 'Test', data: s.distribution.map(function (x) { return x.count; }), backgroundColor: $t.alpha($t.opt.color.blue, .7),
                                 hoverBackgroundColor: $t.opt.color.blue, borderRadius: 6, maxBarThickness: 46 }] },
            options: { scales: { x: { grid: { display: false } }, y: { grid: { color: $t.opt.color.grid }, ticks: { precision: 0 } } },
                       plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return $t.int(c.raw) + ' test'; } } } } }
        });
    },

    renderPareto: function (m, period) {
        const $t = $.DLNFrame;
        if (!m.hasData) {
            $('.js-pareto-sub').text('');
            $('#paretoMsg').text(period ? period.periodText + ' döneminde LabAsistan test sayıları yüklenmedi.' : 'Dönem seçin: test sayısı × birim maliyet bu kartta hesaplanır.').prop('hidden', false);
            $('#paretoBox').prop('hidden', true);
            return;
        }
        $('#paretoMsg').prop('hidden', true);
        $('#paretoBox').prop('hidden', false);
        const rows = m.pareto.slice(0, 12);
        const lastShare = rows.length ? rows[rows.length - 1].cumShare : 0;
        $('.js-pareto-sub').text('toplam ' + $t.short(m.total) + ' TL · ilk ' + rows.length + ' test %' + $t.pct0(lastShare));
        $t.chart('chPareto', {
            type: 'bar',
            data: { labels: rows.map(function (x) { return x.code; }),
                    datasets: [{ label: 'TL', data: rows.map(function (x) { return Math.round(x.amount); }), borderRadius: 6, maxBarThickness: 30,
                                 backgroundColor: rows.map(function (x) { return $t.alpha(x.check ? $t.opt.color.yellow : $t.opt.color.blue, .72); }) }] },
            options: { scales: { x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 45 } },
                                 y: { grid: { color: $t.opt.color.grid }, ticks: { callback: function (v) { return $t.short(v); } } } },
                       onClick: function () { $t.openPage('testCost'); },
                       plugins: { legend: { display: false }, tooltip: { callbacks: {
                           title: function (c) { const x = rows[c[0].dataIndex]; return x.code + ' · ' + x.name; },
                           label: function (c) { const x = rows[c.dataIndex]; return [$t.money0(x.amount) + ' TL (' + $t.int(x.qty) + ' test × ' + $t.money(x.unitCost) + ' TL)',
                               'Birikimli %' + $t.pct0(x.cumShare) + (x.check ? ' · birim maliyet yüksek: fiyat kontrol edilmeli' : '')]; } } } } }
        });
    },

    renderTop: function (rows) {
        const $t = $.DLNFrame;
        const esc = $t.esc;
        if (!rows.length) { $('#topList').html('<li class="ds-msg" style="display:block;cursor:default">Maliyeti hesaplı test yok.</li>'); return; }
        $('#topList').html(rows.map(function (r) {
            return '<li title="Test maliyetleri ekranında aç"><span class="ds-code">' + esc(r.code) + '</span><span class="ds-name">' + esc(r.name)
                + '<span class="ds-sub">' + esc($t.opt.unitNames[r.unit] || r.unit) + '</span></span><span class="ds-num">' + $t.money(r.cost) + ' TL</span></li>';
        }).join(''));
    },

    renderGroups: function (rows) {
        const $t = $.DLNFrame;
        $t.chart('chGroups', {
            type: 'bar',
            data: { labels: rows.map(function (x) { return x.name; }),
                    datasets: [{ label: 'Ortalama', data: rows.map(function (x) { return Math.round(x.avg * 100) / 100; }), backgroundColor: $t.alpha($t.opt.color.aqua, .7),
                                 hoverBackgroundColor: $t.opt.color.aqua, borderRadius: 5, barThickness: 16 }] },
            options: { indexAxis: 'y', scales: { x: { grid: { color: $t.opt.color.grid }, ticks: { callback: function (v) { return $t.short(v); } } }, y: { grid: { display: false } } },
                       plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { const x = rows[c.dataIndex]; return $t.money(x.avg) + ' TL ortalama · ' + x.count + ' test'; } } } } }
        });
    },

    renderTrend: function (rows) {
        const $t = $.DLNFrame;
        const C = $t.opt.color;
        const labels = rows.map(function (x) { const p = x.periodYm.split('-'); return $t.opt.months[Number(p[1]) - 1] + ' ' + p[0].slice(2); });
        $('.js-trend-sub').text(rows.length ? rows.length + ' dönem' + (rows.length < 3 ? ' · yeni dönemler açıldıkça dolar' : '') : 'henüz dönem yok');
        const line = function (label, color, data, dash) {
            return { label: label, data: data, borderColor: color, backgroundColor: $t.alpha(color, .12), fill: true, tension: .35, borderWidth: 2,
                     pointRadius: 4, pointBackgroundColor: color, pointBorderColor: '#fff', pointBorderWidth: 2, borderDash: dash || [] };
        };
        const opts = { interaction: { mode: 'index', intersect: false }, onClick: function () { $t.openPage('monthly'); },
                       scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: C.grid }, ticks: { callback: function (v) { return $t.short(v); } } } },
                       plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + $t.int(c.raw); } } } } };
        $t.chart('chTrendRepeat', { type: 'line', data: { labels: labels, datasets: [
            line('Sunulabilir', C.blue, rows.map(function (x) { return x.has.tests ? Math.round(x.repeatPresent) : null; })),
            line('Doğrulanacak', C.yellow, rows.map(function (x) { return x.has.tests ? Math.round(x.repeatCheck) : null; }), [5, 4])] }, options: opts });
        $t.chart('chTrendTubes', { type: 'line', data: { labels: labels, datasets: [
            line('Kurumlar', C.blue, rows.map(function (x) { return x.has.netsis ? Math.round(x.netsisTubes.CUSTOMER) : null; })),
            line('OSGB', C.orange, rows.map(function (x) { return x.has.netsis ? Math.round(x.netsisTubes.OSGB) : null; }), [5, 4]),
            line('Şubeler', C.aqua, rows.map(function (x) { return x.has.netsis ? Math.round(x.netsisTubes.BRANCH) : null; }), [2, 3])] }, options: opts });
    },

    /* ---------- Grafik yardımcıları ---------- */

    chartDefaults: function () {
        if (!window.Chart) { return; }
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.font.size = 11;
        Chart.defaults.color = $.DLNFrame.opt.color.ink;
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.plugins.legend.display = false;
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(23,38,58,.88)';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
    },

    // Aynı tuvalde eski grafik yok edilip yenisi çizilir
    chart: function (id, cfg) {
        const $t = $.DLNFrame;
        if ($t.data.charts[id]) { $t.data.charts[id].destroy(); }
        cfg.options = cfg.options || {};
        cfg.options.responsive = true;
        cfg.options.maintainAspectRatio = false;
        $t.data.charts[id] = new Chart(document.getElementById(id), cfg);
    },

    alpha: function (hex, a) {
        const n = parseInt(hex.slice(1), 16);
        return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    },

    /* ---------- Yardımcılar ---------- */

    openPage: function (key) {
        const url = $.DLNFrame.opt.pages[key];
        if (window.parent && window.parent !== window && typeof window.parent.hbOpenPage === 'function') { window.parent.hbOpenPage(url); }
    },

    esc: function (v) { return $('<span>').text(v === null || v === undefined ? '' : v).html(); },
    int: function (v) { return Number(v || 0).toLocaleString('tr-TR', { maximumFractionDigits: 0 }); },
    money: function (v) { return Number(v || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    money0: function (v) { return Number(v || 0).toLocaleString('tr-TR', { maximumFractionDigits: 0 }); },
    pct0: function (v) { return Math.round(Number(v || 0) * 100); },
    pct1: function (v) { return (Math.round(Number(v || 0) * 1000) / 10).toLocaleString('tr-TR'); },
    // 7.636.115 → "7,6 mn"; 95.435 → "95,4 bin"
    short: function (v) {
        const n = Number(v || 0), a = Math.abs(n);
        if (a >= 1e6) { return (Math.round(n / 1e5) / 10).toLocaleString('tr-TR') + ' mn'; }
        if (a >= 1e4) { return (Math.round(n / 1e2) / 10).toLocaleString('tr-TR') + ' bin'; }
        return Math.round(n).toLocaleString('tr-TR');
    },

    storeGet: function (k) { try { return window.localStorage.getItem($.DLNFrame.opt.storeKey + k); } catch (e) { return null; } },
    storeSet: function (k, v) { try { window.localStorage.setItem($.DLNFrame.opt.storeKey + k, v); } catch (e) { /* gizli pencere */ } }
};
