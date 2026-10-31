/**
 * HbRapor — Dashboard
 * Girişten sonra index.html içindeki çerçevede (iframe) açılan ilk ekran.
 * - getSummary: sayaç kartları, son 14 gün grafiği, yorum bloğu dağılımı, özet, son onaylanan raporlar
 * - Kartlar iş listesini ilgili sekmesiyle, rapor satırları inceleme ekranını açar.
 */
const authPath = '../../../api/auth.php';
// Proxy klasörüne (public/lib/php/proxy) göre ekran PHP'sinin yolu. proxyPath lib.js'te site kökünden tanımlı.
const modulePath = 'app=../../../apps/hb-elektroforez/dashboard/php/dashboard-1.0.0@';

$(document).ready(function () {
    $.DLNFrame.init();
});

$.DLNFrame = {
    data: {
        user: null,
        charts: {}
    },
    opt: {
        loginPage: '../login.html',
        refreshMs: 60000,   // ekran açıkken dakikada bir yenilenir
        navy: '#1f4e79',
        brand: '#3c8dc5',
        palette: ['#1f4e79', '#3c8dc5', '#7fb3dc', '#e0a43a', '#c0392b', '#1e9b4f', '#8e6bbf', '#98a2b3']
    },
    init: function () {
        const $t = $.DLNFrame;
        $t.setEvent();
        $t.loadUser();
        $t.loadSummary();
        setInterval($t.loadSummary, $t.opt.refreshMs);
    },
    setEvent: function () {
        const $t = $.DLNFrame;
        $('.js-today').text(new Date().toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));

        $('.db-kpi').on('click', function () {
            $t.openPage('./work_list/workList-1.0.0.html?tab=' + $(this).data('tab'));
        });
        $('.js-open-reports').on('click', function (e) {
            e.preventDefault();
            $t.openPage('./report/report-1.0.0.html');
        });
        $('#dbRecent').on('click', 'tr[data-id]', function () {
            $t.openPage('./report_review/reportReview-1.0.0.html?id=' + $(this).data('id'));
        });
    },
    // Kabuk (index.html) çerçevede açar ve üst menüde ilgili sekmeyi seçer
    openPage: function (url) {
        if (window.parent !== window && typeof window.parent.hbOpenPage === 'function') {
            window.parent.hbOpenPage(url);
        } else {
            location.href = '../' + url.slice(2);
        }
    },
    loadUser: function () {
        const $t = $.DLNFrame;
        $.ajax({ url: authPath + '?action=me', dataType: 'json', cache: false })
            .done(function (res) {
                if (res && res.ok) {
                    $t.data.user = res.user;
                    $('.js-user-fullname').text(res.user.fullName);
                    $('.js-user-username').text(res.user.username);
                    $('.js-user-fullname-wrap').toggle(!!res.user.fullName);
                } else {
                    $t.toLogin();
                }
            })
            // Sunucuya ulaşılamadıysa giriş ekranına atma (giriş ↔ ana ekran döngüsü olur); sadece "oturum yok" cevabında dön
            .fail(function () { $('body').prepend('<div class="alert alert-danger m-3">Sunucuya ulaşılamadı. Sayfayı yenileyin.</div>'); });
    },
    loadSummary: function () {
        const $t = $.DLNFrame;
        $.ajax({
            url: proxyPath + modulePath + 'getSummary',
            type: 'POST',
            dataType: 'json',
            success: function (result) {
                if (result && result['resCode'] === 0) {
                    $t.render(result['records']);
                }
            }
        });
    },

    render: function (d) {
        const $t = $.DLNFrame;
        $.each(d.counts, function (key, n) { $('[data-count="' + key + '"]').text(n); });

        $('[data-stat="reports30"]').text(d.reports30);
        $('[data-stat="tat"]').text(d.tatHours === null ? '—' : $t.fmtHours(d.tatHours));
        const f = d.lastFetch;
        $('[data-stat="fetch"]').html(f
            ? '<span class="db-dot ' + (f.ok ? 'ok' : 'err') + '"></span>' + (f.ok ? 'çalışıyor' : 'ulaşılamadı') + ' · ' + moment(f.at, 'YYYY-MM-DD HH:mm').format('DD.MM HH:mm')
            : 'henüz çekilmedi');

        $t.drawDays(d.days);
        $t.drawBlocks(d.blocks);
        $t.renderRecent(d.recent);
    },
    // 30 saatten kısa süreler saat, uzunları gün olarak
    fmtHours: function (h) {
        return h < 30 ? h.toLocaleString('tr-TR') + ' sa' : (h / 24).toLocaleString('tr-TR', { maximumFractionDigits: 1 }) + ' gün';
    },

    drawDays: function (days) {
        const $t = $.DLNFrame;
        const labels = days.map(function (x) { return moment(x.date, 'YYYY-MM-DD').format('DD.MM'); });
        const runs = days.map(function (x) { return x.runs; });
        const reports = days.map(function (x) { return x.reports; });
        if ($t.data.charts.days) {
            $t.data.charts.days.data.labels = labels;
            $t.data.charts.days.data.datasets[0].data = runs;
            $t.data.charts.days.data.datasets[1].data = reports;
            $t.data.charts.days.update();
            return;
        }
        $t.data.charts.days = new Chart(document.getElementById('chartDays'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Cihaz çalışması', data: runs, backgroundColor: '#7fb3dc', borderRadius: 4, maxBarThickness: 26, order: 2 },
                    { label: 'Onaylanan rapor', data: reports, type: 'line', borderColor: $t.opt.navy, backgroundColor: $t.opt.navy,
                      borderWidth: 2, pointRadius: 3, tension: .3, order: 1 }
                ]
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 12 } } } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#edf0f4' } }
                }
            }
        });
    },

    drawBlocks: function (blocks) {
        const $t = $.DLNFrame;
        const labels = Object.keys(blocks);
        const values = labels.map(function (k) { return blocks[k]; });
        const empty = !labels.length;
        const data = {
            labels: empty ? ['Henüz rapor yok'] : labels,
            datasets: [{ data: empty ? [1] : values, backgroundColor: empty ? ['#eceff3'] : $t.opt.palette, borderWidth: 0 }]
        };
        if ($t.data.charts.blocks) {
            $t.data.charts.blocks.data = data;
            $t.data.charts.blocks.update();
            return;
        }
        $t.data.charts.blocks = new Chart(document.getElementById('chartBlocks'), {
            type: 'doughnut',
            data: data,
            options: {
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    title: { display: true, text: 'Yorum bloğu dağılımı', color: '#667085', font: { size: 12, weight: '600' } },
                    legend: { position: 'right', labels: { boxWidth: 10, font: { size: 11 } } },
                    tooltip: { enabled: !empty }
                }
            }
        });
    },

    renderRecent: function (rows) {
        const esc = function (s) { return $('<span>').text(s == null ? '' : s).html(); };
        if (!rows.length) {
            $('#dbRecent').html('<tr><td colspan="6" class="db-empty">Henüz onaylanan rapor yok.</td></tr>');
            return;
        }
        $('#dbRecent').html(rows.map(function (r) {
            return '<tr data-id="' + r.sampleAcceptanceId + '">'
                + '<td class="db-mono">' + moment(r.approvedAt, 'YYYY-MM-DD HH:mm').format('DD.MM.YYYY HH:mm') + '</td>'
                + '<td class="db-mono">' + esc(r.barcode) + '</td>'
                + '<td>' + esc(r.patientName) + '</td>'
                + '<td>' + (r.blocks ? '<span class="db-badge">' + esc(r.blocks) + '</span>' : '') + '</td>'
                + '<td>' + r.revisionNo + '</td>'
                + '<td>' + esc(r.approvedBy) + '</td>'
                + '</tr>';
        }).join(''));
    },

    // Oturum düştüyse çerçeveyi değil bütün pencereyi giriş ekranına götür
    toLogin: function () {
        window.top.location.replace(new URL($.DLNFrame.opt.loginPage, location.href).href);
    }
};
