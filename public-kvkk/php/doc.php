<?php
declare(strict_types=1);

/**
 * Biolab — KVKK sayfası PDF açıcı (herkese açık, giriş yok, yalnızca okuma)
 *
 *   GET php/doc.php?k=<PUBLIC_KEY>  →  belgenin SON PDF versiyonu, tarayıcıda açılır
 *
 * Kurallar (api.php ile aynı):
 *   - Belge: "Yayında = EVET" (PUBLISH_FL = 'Y'), aktif ve silinmemiş.
 *   - Belgenin başlığı ve bütün üst başlıkları "Yayında görünsün = EVET" ve silinmemiş olmalı.
 *     (Sayfada görünmeyen bir belge, anahtarı bilinse bile açılmaz.)
 *   - Uymayan ya da bulunamayan her durumda aynı "bulunamadı" sayfası döner; neden açılmadığı söylenmez.
 * Anahtar 32 haneli rastgele koddur (KvkkDocument::PUBLIC_KEY); belge güncellense de değişmez,
 * bu yüzden biolabltd.com.tr'ye verilen bağlantı her zaman son versiyonu açar.
 * Şirket config.php'deki kvkk.company_id ayarından gelir (ziyaretçinin oturumu yok).
 */

use BIO\KVK\KvkkDocument;
use BIO\KVK\KvkkDocumentFile;

// Belge bulunamadığında (ya da yayında değilse) gösterilen sade sayfa
function kvkNotFound(int $code = 404): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Belge bulunamadı · Biolab KVKK</title>'
       . '<p style="font-family:Segoe UI,Arial,sans-serif;padding:32px;color:#475467">'
       . 'Aradığınız belge bulunamadı ya da yayından kaldırıldı. '
       . '<a href="../" style="color:#1F4E79">KVKK sayfasına dönün</a>.</p></html>';
    exit;
}

// Yalnızca okuma: GET dışındaki istekler reddedilir
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    kvkNotFound(405);
}

// Anahtar biçimi: 32 haneli küçük harf onaltılık. Biçime uymayan istek veritabanına hiç gitmez.
$key = (string) ($_GET['k'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
    kvkNotFound();
}

try {
    // Proje kökündeki ortak kurulum: $em (Doctrine) ve $settings (config.php)
    $em = require dirname(__DIR__, 2) . '/bootstrap.php';
    $companyId = (int) ($settings['kvkk']['company_id'] ?? 0);

    // --- Belge: yayında, aktif, silinmemiş ve bu şirketin ---
    $document = $em->createQuery("
        SELECT d FROM BIO\KVK\KvkkDocument d
         WHERE d.publicKey = :PUBLIC_KEY
           AND IDENTITY(d.company) = :COMPANY_ID
           AND d.publishFl = 'Y'
           AND d.activeFl = 'Y'
           AND d.recordFl > 0
    ")->setParameters(['PUBLIC_KEY' => $key, 'COMPANY_ID' => $companyId])->getOneOrNullResult();

    if (!$document instanceof KvkkDocument) {
        kvkNotFound();
    }

    // --- Başlık ve bütün üst başlıklar yayında mı? (biri pasifse sayfada görünmüyordur) ---
    // En fazla 20 seviye: bozuk veride sonsuz döngü olmasın
    for ($node = $document->getNode(), $i = 0; $node !== null && $i < 20; $node = $node->getParent(), $i++) {
        if (!$node->isActive() || $node->isDeleted()) {
            kvkNotFound();
        }
    }

    // --- Son PDF versiyonu ---
    $file = $em->createQuery("
        SELECT f FROM BIO\KVK\KvkkDocumentFile f
         WHERE f.document = :DOCUMENT
           AND f.recordFl > 0
         ORDER BY f.versionNo DESC
    ")->setParameter('DOCUMENT', $document)->setMaxResults(1)->getOneOrNullResult();

    if (!$file instanceof KvkkDocumentFile) {
        kvkNotFound();
    }

    $bytes = $file->getContent();

} catch (Throwable $e) {
    // Hata ayrıntısı ziyaretçiye gösterilmez, sunucu loguna yazılır
    error_log('KVKK doc.php: ' . $e->getMessage());
    kvkNotFound(500);
}

// Tarayıcının önereceği dosya adı: belgenin yayındaki adı (yüklenen dosyanın adı değil).
// ASCII yedek ad + UTF-8 ad birlikte verilir (RFC 6266); Türkçe karakterli ad da doğru görünür.
$name  = trim(preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', ' ', $document->getDocumentName())) . '.pdf';
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . strlen($bytes));
header('X-Content-Type-Options: nosniff');
// Belge güncellenince en geç 5 dakikada yeni versiyon görünür
header('Cache-Control: public, max-age=300');
echo $bytes;
