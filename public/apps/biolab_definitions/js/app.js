/**
 * Tanımlamalar — kabuk (index.html)
 * Üst bar + sol menü + ekranların açıldığı çerçeve.
 * Menü rol bazlıdır (api/auth.php?action=menu): kullanıcı yalnızca yetkili olduğu ekranları görür.
 * Girişten sonra son açılan ekran (hâlâ yetkiliyse) ya da menüdeki ilk ekran açılır.
 * Oturum yoksa giriş ekranına döner.
 */
(function ($) {
    'use strict';

    var API_AUTH = '../../api/auth.php';
    var LOGIN_PAGE = './login.html';
    var APP_CODE = 'biolab_definitions';   // mng_menu kök kaydının MENU_CODE'u

    var csrf = null;
    var menu = null;

    function toLogin() {
        location.replace(LOGIN_PAGE);
    }

    function renderUser(user) {
        $('.js-user-fullname').text(user.fullName);
        $('.js-user-username').text(user.username);
        document.body.hidden = false;
    }

    // Çerçevede bir ekran açar; başka bir ekrandan açıldıysa menüde de o ekran işaretlenir
    function openPage(url) {
        $('#mainContentFrame').attr('src', url);
        if (menu) { menu.setActive(url); }
    }
    window.hbOpenPage = openPage;

    window.ReloadMenu = function (){
        if (!menu) {return;}
        const url = menu.activeUrl();
        menu.load().then(function () {menu.setActive(url); });
    };

    // withActions: "Portala dön" ve "Çıkış yap" düğmeleri (başka kullanıcıyla girmek için)
    function showPanel(title, text, withActions) {
        document.body.hidden = false;
        $('#mainContentFrame').remove();
        const $panel = $('<div class="pm-empty">').append($('<h2>').text(title), $('<p>').text(text));
        if (withActions) {
            $panel.append($('<div class="pm-empty-actions">').append(
                $('<a class="btn btn-primary" href="../../">').html('<i class="fas fa-th-large"></i> Portala dön'),
                $('<button type="button" class="btn btn-outline-secondary">').html('<i class="fas fa-sign-out-alt"></i> Çıkış yap').on('click', logout)));
        }
        $('.pm-content').append($panel);
    }

    function showServerError() {
        showPanel('Sunucuya ulaşılamadı', 'Lütfen sayfayı yenileyin (Ctrl+F5); sorun sürerse sistem yöneticisine başvurun.');
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

    function startMenu() {
        // Ortak menü dosyası yüklenmediyse (ör. sunucuya kopyalanmamış → 404) anlaşılır mesaj göster
        if (!window.PortalMenu) {
            showPanel('Menü yüklenemedi',
                'lib/app/js/portal-menu.js dosyası bulunamadı. Sunucuya kopyalandığını kontrol edin (Ctrl+F5 ile yenileyin).');
            return;
        }
        menu = window.PortalMenu.create({
            app: APP_CODE,
            api: API_AUTH,
            layout: document.getElementById('appLayout'),
            nav: document.getElementById('appMenu'),
            crumbs: document.getElementById('appCrumbs'),
            toggle: document.getElementById('menuToggle'),
            backdrop: document.getElementById('menuBackdrop'),
            collapseBtn: document.getElementById('menuCollapse'),
            onOpen: function (url) { $('#mainContentFrame').attr('src', url); }
        });

        menu.load().then(function (res) {
            if (!res.ok) {
                if (res.status === 401) { toLogin(); } else { showServerError(); }
                return;
            }
            if (!res.allowed) {
                showPanel('Bu uygulama için yetkiniz bulunmamaktadır',
                    'Kullanıcınıza tanımlamalar için yetki verilmemiş. Yetki için sistem yöneticisine başvurun.', true);
                return;
            }
            if (!res.first) {
                showPanel('Açabileceğiniz ekran yok',
                    'Tanımlamalar rolünüz var ama rolünüze bağlı bir ekran tanımlı değil. Sistem yöneticisine başvurun.', true);
                return;
            }
            openPage(res.first);
        }).catch(showServerError);
    }

    $(function () {
        $('#logoutBtn').on('click', logout);

        $.ajax({ url: API_AUTH + '?action=me', dataType: 'json', cache: false })
            .done(function (res) {
                csrf = res && res.csrf;
                if (res && res.ok) { renderUser(res.user); startMenu(); } else { toLogin(); }
            })
            // Sunucuya ulaşılamadıysa giriş ekranına atma: giriş ekranı "oturum açık" deyip geri yollar, döngü olur
            .fail(showServerError);
    });

})(jQuery);
