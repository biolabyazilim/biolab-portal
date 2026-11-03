/**
 * Biolab QMS — giriş ekranı
 *
 * API sözleşmesi (QMS giriş API'si: public/api/qms-auth.php; portalın api/auth.php'sinden ayrıdır):
 *   GET  ?action=me     → { ok:true, user }  oturum yoksa { ok:false, csrf }
 *   POST ?action=login  { username, password, csrf } → { ok:true, user } | { ok:false, message, csrf }
 *   Kullanıcılar Biolab_QMS veritabanındadır; QMS rolü olmayan kullanıcı giriş yapamaz.
 *
 * Girişten sonra HOME_PAGE açılır. Kilit kuralı ve mesajı sunucudadır; ekran sunucunun gönderdiği mesajı gösterir.
 */
(function ($) {
    'use strict';

    var API_AUTH = '../../api/qms-auth.php';
    // './' kullanılır (./index.html değil): tarayıcıda kalmış eski index.html kopyası devreye girmesin
    var HOME_PAGE = './';

    var csrf = null;

    function api(action, data) {
        var isPost = data !== undefined;
        return $.ajax({
            url: API_AUTH + '?action=' + encodeURIComponent(action),
            method: isPost ? 'POST' : 'GET',
            data: isPost ? $.extend({ csrf: csrf }, data) : undefined,
            dataType: 'json',
            cache: false
        }).then(function (res) {
            if (res && res.csrf) { csrf = res.csrf; }
            return res;
        });
    }

    function goTo(page) {
        location.replace(page || HOME_PAGE);
    }

    function showError(message) {
        $('#loginError').prop('hidden', !message).find('.hb-alert-text').text(message || '');
    }

    function setBusy(busy) {
        $('#loginBtn').prop('disabled', busy).text(busy ? 'Giriş yapılıyor...' : 'Giriş yap');
    }

    function onSubmit(e) {
        e.preventDefault();

        var $user = $('#loginUsername');
        var $pass = $('#loginPassword');
        var username = $.trim($user.val());
        var password = $pass.val();

        $user.toggleClass('is-invalid', !username);
        $pass.toggleClass('is-invalid', !password);
        if (!username || !password) {
            showError('Kullanıcı adı ve şifre girin.');
            return;
        }

        showError(null);
        setBusy(true);

        api('login', { username: username, password: password })
            .done(function (res) {
                if (res && res.ok) {
                    goTo();
                    return;
                }
                $pass.val('').trigger('focus');
                showError((res && res.message) || 'Kullanıcı adı veya şifre hatalı.');
                setBusy(false);
            })
            .fail(function (xhr) {
                // Sunucu hata durumunda da JSON döner (ör. süresi dolmuş CSRF): mesajı ve yeni anahtarı kullan
                var res = xhr && xhr.responseJSON;
                if (res && res.csrf) { csrf = res.csrf; }
                showError((res && res.message) || 'Sunucuya ulaşılamadı. Lütfen tekrar deneyin.');
                setBusy(false);
            });
    }

    $(function () {
        $('#loginForm').on('submit', onSubmit);

        $('#loginUsername, #loginPassword').on('input', function () {
            $(this).removeClass('is-invalid');
        });

        $('#loginPassword').on('keyup keydown', function (e) {
            var ev = e.originalEvent;
            if (ev && ev.getModifierState) {
                $('#capsLockWarning').prop('hidden', !ev.getModifierState('CapsLock'));
            }
        });

        // Oturum zaten açıksa giriş ekranını atla; değilse CSRF anahtarını al
        api('me').done(function (res) {
            if (res && res.ok) { goTo(); }
        });
    });

})(jQuery);
