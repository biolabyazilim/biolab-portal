/**
 * HbRapor — kabuk (index.html)
 * Üst bar + ekranların açıldığı çerçeve. Girişten sonra ilk açılan ekran dashboard.
 * Oturum yoksa giriş ekranına döner. Kullanıcının bu uygulamaya ait (mng_role.APP_CODE) rolü yoksa
 * ekranlar açılmaz, "yetkiniz bulunmamaktadır" paneli gösterilir (asıl engel sunucuda: jsonp.php).
 * Sekmeler menüden (mng_menu, rolün ekranlarıyla süzülmüş) gelen ekranlara göre gösterilir; yetkisi olmayan sekme gizlenir.
 */
(function ($) {
    'use strict';

    var API_AUTH = '../../api/auth.php';
    var LOGIN_PAGE = './login.html';
    var START_PAGE = './dashboard/dashboard-1.0.0.html';
    var APP_CODE = 'hb-elektroforez';   // mng_role.APP_CODE

    var csrf = null;

    function toLogin() {
        location.replace(LOGIN_PAGE);
    }

    function renderUser(user) {
        $('.js-user-fullname').text(user.fullName);
        $('.js-user-username').text(user.username);
        document.body.hidden = false;
    }

    // Çerçevede bir ekran açar (ileride sekmeli menü de bunu kullanabilir)
    function openPage(url) {
        $('#mainContentFrame').attr('src', url);
        // Başka bir ekrandan açıldıysa (ör. iş listesi → numune kabul) üstteki sekme de o ekranı göstersin
        const page = String(url).split('?')[0];
        const $tab = $('#mainTabs .nav-link').filter(function () { return $(this).data('url') === page; });
        if ($tab.length) {
            $('#mainTabs .nav-link').removeClass('active');
            $tab.addClass('active');
        }
    }
    window.hbOpenPage = openPage;

    function showServerError() {
        document.body.hidden = false;
        $('.hb-frame-main').html(
            '<div class="hb-panel" style="margin:32px;">Sunucuya ulaşılamadı. ' +
            'Lütfen sayfayı yenileyin (Ctrl+F5); sorun sürerse sistem yöneticisine başvurun.</div>'
        );
    }

    function showNoAccess(title, text) {
        document.body.hidden = false;
        $('.hb-tabs').remove();
        $('.hb-frame-main').empty().append(
            $('<div class="hb-panel" style="margin:32px;">').append(
                $('<h2 style="margin:0 0 8px;font-size:18px;">').text(title),
                $('<p style="margin:0 0 16px;">').text(text),
                $('<a class="btn btn-primary mr-2" href="../../">').html('<i class="fas fa-th-large"></i> Portala dön'),
                $('<button type="button" class="btn btn-outline-secondary">').html('<i class="fas fa-sign-out-alt"></i> Çıkış yap').on('click', logout)
            )
        );
    }

    // Menüdeki ekran adresleri (./dashboard/dashboard-1.0.0.html …)
    function menuUrls(nodes, out) {
        $.each(nodes || [], function (i, n) {
            if (n.url) { out.push(n.url); }
            menuUrls(n.children, out);
        });
        return out;
    }

    // Yalnız yetkili sekmeler kalır; ilk açılan: dashboard, yoksa kalan ilk sekme
    function applyTabs(urls) {
        $('#mainTabs .nav-link').each(function () {
            if (urls.indexOf($(this).data('url')) < 0) { $(this).closest('.nav-item').remove(); }
        });
        if (urls.indexOf(START_PAGE) >= 0) { return START_PAGE; }
        const $first = $('#mainTabs .nav-link').first();
        return $first.length ? $first.data('url') : null;
    }

    // Oturum var: uygulama yetkisi ve yetkili ekranlar sorulur, varsa ilk ekran açılır
    function start(user) {
        $.ajax({ url: API_AUTH + '?action=menu&app=' + APP_CODE, dataType: 'json', cache: false })
            .done(function (res) {
                if (!res || !res.ok) { toLogin(); return; }
                renderUser(user);
                if (!res.allowed) {
                    showNoAccess('Bu uygulama için yetkiniz bulunmamaktadır',
                        'Kullanıcınıza HB Elektroforez için tanımlı bir rol yok. Yetki için sistem yöneticisine başvurun.');
                    return;
                }
                const first = applyTabs(menuUrls(res.menu, []));
                if (!first) {
                    showNoAccess('Açabileceğiniz ekran yok',
                        'Rolünüze HB Elektroforez ekranı tanımlanmamış. Yetki için sistem yöneticisine başvurun.');
                    return;
                }
                openPage(first);
            })
            .fail(function (xhr) { if (xhr.status === 401) { toLogin(); } else { showServerError(); } });
    }

    function logout() {
        $('#logoutBtn').prop('disabled', true);
        $.ajax({
            url: API_AUTH + '?action=logout',
            method: 'POST',
            data: { csrf: csrf },
            dataType: 'json'
        }).always(toLogin);
    }

    $(function () {
        $('#logoutBtn').on('click', logout);

        $.ajax({ url: API_AUTH + '?action=me', dataType: 'json', cache: false })
            .done(function (res) {
                csrf = res && res.csrf;
                if (res && res.ok) { start(res.user); } else { toLogin(); }
            })
            // Sunucuya ulaşılamadıysa giriş ekranına atma: giriş ekranı "oturum açık" deyip geri yollar, döngü olur
            .fail(showServerError);
    })

    $('#mainTabs').on('click', '.nav-link', function (e) {
        e.preventDefault();
        $('#mainTabs .nav-link').removeClass('active');
        $(this).addClass('active');
        openPage($(this).data('url'));
    });

})(jQuery);
