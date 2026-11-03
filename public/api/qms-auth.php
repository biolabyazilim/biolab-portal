<?php
declare(strict_types=1);

/**
 * Biolab QMS — giriş API'si (api/auth.php'nin QMS karşılığı)
 * Kullanıcılar Biolab_QMS veritabanındadır; oturum portaldan ayrıdır (Session::QMS_NAME çerezi).
 *   GET  ?action=me      oturumdaki kullanıcı
 *   POST ?action=login   { username, password, csrf }   QMS rolü (APP_CODE = biolab-qms) olmayan giriş yapamaz
 *   POST ?action=logout  { csrf }
 *   GET  ?action=menu&app=biolab-qms    kullanıcının rolüne göre süzülmüş menü
 *   GET  ?action=access&app=biolab-qms  kullanıcı uygulamaya girebilir mi → { ok, allowed }
 * Her yanıt güncel CSRF anahtarını taşır.
 */

use Biolab\Core\Http\Csrf;
use Biolab\Core\Http\JsonResponse;
use Biolab\Core\Http\Session;
use Biolab\Core\Qms\Auth\QmsAuthService;
use Biolab\Core\Qms\Auth\QmsFormAccess;

$root = dirname(__DIR__, 2);   // public/api → proje kökü
require $root . '/vendor/autoload.php';

const QMS_APP = 'biolab-qms';

Session::start(Session::QMS_NAME);

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
            $user = QmsAuthService::currentUser();
            JsonResponse::send($user
                ? ['ok' => true, 'user' => $user, 'csrf' => Csrf::token()]
                : ['ok' => false, 'csrf' => Csrf::token()]);

        case 'login':
            if (!$isPost) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz istek.'], 405);
            }
            /** @var \Doctrine\ORM\EntityManagerInterface $em */
            $em     = require $root . '/bootstrap-qms.php';
            $result = (new QmsAuthService($em))->login(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                QMS_APP   // QMS'te uygulama tek: rolü olmayan hiç giriş yapamaz
            );

            JsonResponse::send($result->success
                ? ['ok' => true, 'user' => $result->user, 'csrf' => Csrf::rotate()]
                : ['ok' => false, 'message' => $result->message, 'csrf' => Csrf::token()]);

        case 'access':
        case 'menu':
            $user = QmsAuthService::currentUser();
            if ($user === null) {
                JsonResponse::send(['ok' => false, 'csrf' => Csrf::token()], 401);
            }
            if (($_GET['app'] ?? QMS_APP) !== QMS_APP) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz uygulama.'], 400);
            }
            // Oturumu yalnız okuyoruz: kilidi hemen bırak
            $csrf = Csrf::token();
            session_write_close();
            /** @var \Doctrine\ORM\EntityManagerInterface $em */
            $em      = require $root . '/bootstrap-qms.php';
            $access  = new QmsFormAccess($em);
            $allowed = $access->canEnterApp($user['userId'], $user['companyId'], QMS_APP);
            if ($action === 'access' || !$allowed) {
                JsonResponse::send(['ok' => true, 'allowed' => $allowed, 'menu' => [], 'csrf' => $csrf]);
            }
            JsonResponse::send([
                'ok'      => true,
                'allowed' => true,
                'menu'    => $access->menuTree(QMS_APP, $user['userId'], $user['companyId'], (string) ($_SESSION['lang'] ?? 'tr')),
                'roles'   => $access->roleCodes($user['userId'], $user['companyId']),
                'csrf'    => $csrf,
            ]);

        case 'logout':
            if (!$isPost) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz istek.'], 405);
            }
            Session::destroy();
            Session::start(Session::QMS_NAME);   // giriş ekranı için yeni, boş oturum ve CSRF anahtarı
            JsonResponse::send(['ok' => true, 'csrf' => Csrf::token()]);

        default:
            JsonResponse::send(['ok' => false, 'message' => 'Bilinmeyen işlem.'], 400);
    }
} catch (\Throwable $e) {
    error_log('[QMS auth] ' . $e);
    JsonResponse::send(['ok' => false, 'message' => 'Beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.'], 500);
}
