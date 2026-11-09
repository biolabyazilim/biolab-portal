/**
 * Biolab Portal — uygulama kartları
 *
 * Kartlar config/apps.json dosyasından okunur. Yeni uygulama eklemek için sadece o dosyaya kayıt eklenir.
 *   code        : uygulamanın sabit kodu
 *   name        : kart başlığı
 *   description : kart açıklaması
 *   icon        : Font Awesome 5 sınıfı (ör. fa-vial)
 *   url         : uygulamanın giriş (login) sayfası
 *                 ":8090/login" gibi ":" ile başlarsa portal hangi adresten açıldıysa (IP / alan adı) o adresin bu portu kullanılır
 *   status      : "active" tıklanabilir | "soon" yakında, tıklanamaz
 *   target      : "_blank" yeni sekme (varsayılan) | "_self" aynı sekme
 */
(function ($) {
    'use strict';

    var APPS_CONFIG = './config/apps.json';

    // ":8090/login" → aynı protokol ve sunucu adı, farklı port (192.168.1.50:8090, biolab.com.tr:8090 …)
    function resolveUrl(url) {
        if (url.charAt(0) === ':') {
            return window.location.protocol + '//' + window.location.hostname + url;
        }
        return url;
    }

    function renderApps(apps) {
        var $grid = $('#appGrid').empty();
        var tpl = document.getElementById('appCardTpl');

        if (!apps.length) {
            $grid.append($('<p class="app-message">').text('Tanımlı uygulama yok.'));
            return;
        }

        $.each(apps, function (i, app) {
            var $card = $(document.importNode(tpl.content, true)).children().first();
            var active = app.status === 'active' && app.url;

            $card.attr('data-code', app.code);
            $card.find('.app-icon i').addClass(app.icon || 'fa-th-large');
            $card.find('.app-name').text(app.name);
            $card.find('.app-desc').text(app.description || '');

            if (active) {
                $card.attr('href', resolveUrl(app.url));
                if (app.target !== '_self') {
                    $card.attr({ target: '_blank', rel: 'noopener' });
                }
            } else {
                $card.addClass('is-soon').attr('aria-disabled', 'true');
            }

            $grid.append($card);
        });
    }

    $(function () {
        var now = new Date();
        $('.js-year').text(now.getFullYear());
        $('.js-today').text(now.toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));

        $.getJSON(APPS_CONFIG, { _: Date.now() })
            .done(function (res) { renderApps((res && res.apps) || []); })
            .fail(function () {
                $('#appGrid').empty().append(
                    $('<p class="app-message">').text('Uygulama listesi yüklenemedi. Lütfen sayfayı yenileyin.')
                );
            });
    });

})(jQuery);
