<?php
declare(strict_types=1);

namespace Biolab\Core\Http;

/**
 * POST isteklerinde CSRF anahtarı. Anahtar oturumda tutulur, her yanıtta istemciye gönderilir.
 */
final class Csrf
{
    private const KEY = 'CSRF_TOKEN';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function isValid(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION[self::KEY])
            && hash_equals($_SESSION[self::KEY], $token);
    }

    // Girişten sonra yeni anahtar
    public static function rotate(): string
    {
        unset($_SESSION[self::KEY]);
        return self::token();
    }
}
