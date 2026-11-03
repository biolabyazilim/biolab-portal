/**
 * Biolab QMS — kabuk (index.html)
 * Üst bar + sol menü + ekranların açıldığı çerçeve.
 * Giriş ve menü QMS API'sinden gelir (api/qms-auth.php): kullanıcılar ve yetkiler Biolab_QMS veritabanındadır,
 * oturum portaldan ayrıdır. Kullanıcı yalnızca rolünün erişebildiği ekranları görür.
 * Oturum yoksa giriş ekranına döner.
 */
(function ($) {
    'use strict';

    var API_AUTH = '../../api/qms-auth.php';
    var LOGIN_PAGE = './login.html';
    var APP_CODE = 'biolab-qms';   // mng_menu kök kaydının MENU_CODE'u

    var csrf = null;
    var menu = null;

    function toLogin() {
        location.replace(LOGIN_PAGE);
    }

    function renderUser(user) {
        $('.js-user-fullname').text(user.fullName);
        $('.js-user-company').text(user.companyName ? '· ' + user.companyName : '');
        document.body.hidden = false;
    }

    // Çerçevede bir ekran açar; başka bir ekrandan açıldıysa menüde de o ekran işaretlenir
    function openPage(url) {
        $('#mainContentFrame').attr('src', url);
        if (menu) { menu.setActive(url); }
    }
    window.hbOpenPage = openPage;

    // withActions: "Çıkış yap" düğmesi (başka kullanıcıyla girmek için)
    function showPanel(title, text, withActions) {
        document.body.hidden = false;
        $('#mainContentFrame').remove();
        var $panel = $('<div class="pm-empty">').append($('<h2>').text(title), $('<p>').text(text));
        if (withActions) {
            $panel.append($('<div class="pm-empty-actions">').append(
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
                    'Kullanıcınıza QMS için tanımlı bir rol yok. Yetki için QMS sistem yöneticisine başvurun.', true);
                return;
            }
            if (!res.first) {
                showPanel('Açabileceğiniz ekran yok',
                    'QMS rolünüz var ama rolünüze bağlı bir ekran tanımlı değil. QMS sistem yöneticisine başvurun.', true);
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
