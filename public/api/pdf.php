<?php
declare(strict_types=1);

/**
 * Biolab Portal — ekranda hazırlanan dosyayı açar ya da indirir (rapor PDF'i, toplu indirmede ZIP).
 *   GET ?t=<jeton>   (jeton ekran PHP'sinden gelir: Biolab\Core\Http\PdfHandoff::stash)
 * Dosya adını sunucu verir (Content-Disposition), böylece tarayıcı kaydederken de bu adı önerir
 * (ör. 11714240-21.10.2026.pdf). Yalnızca aynı oturumda ve 10 dakika içinde çalışır.
 */

use Biolab\Core\Http\PdfHandoff;
use Biolab\Core\Http\Session;

$root = dirname(__DIR__, 2);   // public/api → proje kökü
require $root . '/vendor/autoload.php';

Session::start();

$fail = function (int $code, string $message) {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Rapor</title>'
       . '<p style="font-family:sans-serif;padding:24px;color:#475467">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
};

if (empty($_SESSION['USER_ID'])) {
    $fail(401, 'Oturum kapalı. Uygulamaya yeniden giriş yapıp raporu tekrar açın.');
}

$pdf = PdfHandoff::take((string) ($_GET['t'] ?? ''));
if ($pdf === null) {
    $fail(404, 'Bu rapor bağlantısının süresi doldu. Raporu ekrandan yeniden açın.');
}

// Dosya adı: ASCII yedek + UTF-8 (RFC 6266)
$name = $pdf['name'];
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
// PDF yeni sekmede açılır (?dl=1 ile indirilir); ZIP gibi diğer türler her zaman indirilir
$inline = $pdf['mime'] === 'application/pdf' && empty($_GET['dl']);
header('Content-Type: ' . $pdf['mime']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . strlen($pdf['bytes']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $pdf['bytes'];
