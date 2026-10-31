<?php
declare(strict_types=1);

namespace Biolab\Core\Http;

/**
 * Portal ve içindeki bütün uygulamalar (HB Elektroforez vb.) aynı oturumu kullanır.
 * Çerez kök yola (/) yazılır; 30 dk işlem yapılmazsa oturum kapanır.
 */
final class Session
{
    public const NAME         = 'BIOLABSID';
    public const IDLE_MINUTES = 30;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) (self::IDLE_MINUTES * 60));

        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        self::expireIfIdle();
    }

    // Son işlemden bu yana IDLE_MINUTES geçtiyse oturumu boşaltır; değilse süreyi yeniler
    private static function expireIfIdle(): void
    {
        $now  = time();
        $last = $_SESSION['LAST_ACTIVITY'] ?? null;

        if (isset($_SESSION['USER_ID']) && $last !== null && ($now - (int) $last) > self::IDLE_MINUTES * 60) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['LAST_ACTIVITY'] = $now;
    }

    // Girişte çağrılır: oturum sabitleme (session fixation) saldırısına karşı yeni kimlik
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
        session_destroy();
    }
}
