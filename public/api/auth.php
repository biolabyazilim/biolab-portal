<?php
declare(strict_types=1);

/**
 * Biolab Portal — ortak giriş API'si (bütün PHP uygulamaları kullanır)
 *   GET  ?action=me      oturumdaki kullanıcı
 *   POST ?action=login   { username, password, csrf, app }   app verilirse o uygulamanın rolü olmayan giriş yapamaz
 *   POST ?action=logout  { csrf }
 *   GET  ?action=menu&app=biolab-maliyet   kullanıcının rolüne göre süzülmüş uygulama menüsü
 *   GET  ?action=access&app=hb-elektroforez kullanıcı bu uygulamaya girebilir mi (rolün APP_CODE'u) → { ok, allowed }
 * Her yanıt güncel CSRF anahtarını taşır.
 * Girişten sonra hangi sayfanın açılacağına her uygulamanın kendi login.js'i karar verir.
 */

use Biolab\Core\Auth\AuthService;
use Biolab\Core\Auth\FormAccess;
use Biolab\Core\Http\Csrf;
use Biolab\Core\Http\JsonResponse;
use Biolab\Core\Http\Session;

$root = dirname(__DIR__, 2);   // public/api → proje kökü
require $root . '/vendor/autoload.php';

Session::start();

$action = $_GET['action'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    if ($isPost && !Csrf::isValid($_POST['csrf'] ?? null)) {
        JsonResponse::send([
            'ok'      => false,
            'message' => 'Oturum süresi doldu. Sayfayı yenileyip tekrar deneyin.',
            'csrf'    => Csrf::token(),
        ], 403);
    }

    switch ($action) {
        case 'me':
            $user = AuthService::currentUser();
            JsonResponse::send($user
                ? ['ok' => true, 'user' => $user, 'csrf' => Csrf::token()]
                : ['ok' => false, 'csrf' => Csrf::token()]);

        case 'login':
            if (!$isPost) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz istek.'], 405);
            }
            $app = (string) ($_POST['app'] ?? '');
            if ($app !== '' && !preg_match('/^[\w.-]+$/', $app)) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz uygulama.', 'csrf' => Csrf::token()], 400);
            }
            /** @var \Doctrine\ORM\EntityManagerInterface $em */
            $em     = require $root . '/bootstrap.php';
            $result = (new AuthService($em))->login(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                $app !== '' ? $app : null
            );

            JsonResponse::send($result->success
                ? ['ok' => true, 'user' => $result->user, 'csrf' => Csrf::rotate()]
                : ['ok' => false, 'message' => $result->message, 'csrf' => Csrf::token()]);

        case 'access':
        case 'menu':
            $user = AuthService::currentUser();
            $app  = (string) ($_GET['app'] ?? '');
            if ($user === null) {
                JsonResponse::send(['ok' => false, 'csrf' => Csrf::token()], 401);
            }
            if (!preg_match('/^[\w.-]+$/', $app)) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz uygulama.'], 400);
            }
            // Oturumu yalnız okuyoruz: kilidi hemen bırak (uzun bir sorgu aynı kullanıcının diğer isteklerini bekletmesin)
            $csrf = Csrf::token();
            session_write_close();
            /** @var \Doctrine\ORM\EntityManagerInterface $em */
            $em      = require $root . '/bootstrap.php';
            $access  = new FormAccess($em);
            $allowed = $access->canEnterApp($user['userId'], $user['companyId'], $app);
            if ($action === 'access' || !$allowed) {
                JsonResponse::send(['ok' => true, 'allowed' => $allowed, 'menu' => [], 'csrf' => $csrf]);
            }
            JsonResponse::send([
                'ok'      => true,
                'allowed' => true,
                'menu'    => $access->menuTree($app, $user['userId'], $user['companyId'], (string) ($_SESSION['lang'] ?? 'tr')),
                'roles'   => $access->roleCodes($user['userId'], $user['companyId']),
                'csrf'    => $csrf,
            ]);

        case 'logout':
            if (!$isPost) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz istek.'], 405);
            }
            Session::destroy();
            Session::start();   // giriş ekranı için yeni, boş oturum ve CSRF anahtarı
            JsonResponse::send(['ok' => true, 'csrf' => Csrf::token()]);

        default:
            JsonResponse::send(['ok' => false, 'message' => 'Bilinmeyen işlem.'], 400);
    }
} catch (\Throwable $e) {
    error_log('[Portal auth] ' . $e);
    JsonResponse::send(['ok' => false, 'message' => 'Beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.'], 500);
}
