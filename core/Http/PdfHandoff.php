<?php
declare(strict_types=1);

namespace Biolab\Core\Http;

/**
 * Ekranda üretilen PDF'i yeni sekmede gerçek bir adresle açmak için kısa ömürlü teslim.
 *
 * Neden: PDF base64 olarak gelip blob adresiyle açılınca tarayıcı dosya adı olarak blob kimliğini
 * (ör. c5e4613d-….pdf) önerir. Sunucu "Content-Disposition: inline; filename=…" ile gönderirse
 * tarayıcı hem sekmede hem kaydederken bizim adımızı (ör. 11714240-21.10.2026.pdf) kullanır.
 *
 * Akış: ekran PHP'si stash() ile PDF'i geçici klasöre yazar ve jeton alır → tarayıcı /api/pdf.php?t=jeton açar
 * → take() aynı oturum ve kullanıcıysa, süresi dolmadıysa dosyayı verir.
 *  - Dosyalar public dışında (sistem geçici klasörü/biolab-pdf); yanında jetonun bilgisi (.json) durur.
 *    Oturuma yazılmaz: jsonp proxy'si oturumu ekran kodundan önce kapatıyor (session_write_close).
 *  - 10 dakika geçerli; tarayıcı kaydederken adresi yeniden isteyebildiği için tek kullanımlık değil.
 *  - Süresi dolanlar her stash() çağrısında silinir.
 */
final class PdfHandoff
{
    private const TTL_SECONDS = 600;

    // $mime: application/pdf (yeni sekmede açılır) ya da başka bir tür, ör. toplu indirmede application/zip
    public static function stash(string $bytes, string $fileName, string $mime = 'application/pdf'): string
    {
        self::cleanup();
        $token = bin2hex(random_bytes(16));
        $base = self::dir() . DIRECTORY_SEPARATOR . $token;
        $meta = [
            'name'    => $fileName,
            'mime'    => $mime,
            'expires' => time() + self::TTL_SECONDS,
            'user'    => isset($_SESSION['USER_ID']) ? (int) $_SESSION['USER_ID'] : null,
            'session' => hash('sha256', (string) session_id()),   // oturum kimliğinin kendisi diske yazılmaz
        ];
        if (file_put_contents($base . '.pdf', $bytes) === false || file_put_contents($base . '.json', json_encode($meta)) === false) {
            throw new \RuntimeException('Geçici dosya yazılamadı.');
        }
        return $token;
    }

    /** @return array{bytes: string, name: string, mime: string}|null */
    public static function take(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) return null;
        $base = self::dir() . DIRECTORY_SEPARATOR . $token;
        if (!is_file($base . '.json') || !is_file($base . '.pdf')) return null;
        $meta = json_decode((string) file_get_contents($base . '.json'), true);
        if (!is_array($meta) || ($meta['expires'] ?? 0) < time()) {
            self::forget($base);
            return null;
        }
        $user = isset($_SESSION['USER_ID']) ? (int) $_SESSION['USER_ID'] : null;
        if ($meta['user'] === null || $meta['user'] !== $user || !hash_equals((string) $meta['session'], hash('sha256', (string) session_id()))) {
            return null;   // başka oturum ya da kullanıcı: dosyayı silme, sahibinin açabilmesi için kalsın
        }
        $bytes = file_get_contents($base . '.pdf');
        return $bytes === false ? null : ['bytes' => $bytes, 'name' => (string) $meta['name'], 'mime' => (string) ($meta['mime'] ?? 'application/pdf')];
    }

    private static function forget(string $base): void
    {
        @unlink($base . '.pdf');
        @unlink($base . '.json');
    }

    private static function cleanup(): void
    {
        foreach (glob(self::dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $json) {
            $meta = json_decode((string) @file_get_contents($json), true);
            if (!is_array($meta) || ($meta['expires'] ?? 0) < time()) self::forget(substr($json, 0, -5));
        }
        foreach (glob(self::dir() . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $pdf) {   // bilgisi kaybolmuş artıklar
            if (!is_file(substr($pdf, 0, -4) . '.json') && filemtime($pdf) < time() - self::TTL_SECONDS) @unlink($pdf);
        }
    }

    private static function dir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'biolab-pdf';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        return $dir;
    }
}
