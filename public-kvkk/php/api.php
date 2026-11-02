<?php
declare(strict_types=1);

/**
 * Biolab — KVKK sayfası veri kaynağı (herkese açık, giriş yok, yalnızca okuma)
 *
 *   GET php/api.php  →  { "ok": true, "nodes": [ başlık, … ] }
 *
 *   başlık = { "name": "AYDINLATMA METİNLERİ",
 *              "desc": null | "İş, yardım, burs vb. başvuru alanı değildir",
 *              "documents": [ { "name": "…", "url": "php/doc.php?k=…" }, … ],
 *              "children":  [ başlık, … ] }
 *
 * Kurallar (yönetim ekranındaki seçimlere göre):
 *   - Başlık: "Yayında görünsün = EVET" (ACTIVE_FL = 'Y') ve silinmemiş. Pasif başlığın altındakiler de görünmez.
 *   - Belge:  "Yayında = EVET" (PUBLISH_FL = 'Y'), silinmemiş ve en az bir PDF'i yüklenmiş.
 *   - Altında yayında belge kalmayan başlık gösterilmez (boş başlık çıkmasın).
 *   - Dışarıya yalnızca ad, açıklama ve PDF bağlantısı verilir; kimlik numarası, yükleyen gibi iç bilgiler verilmez.
 *     Bağlantıdaki anahtar (PUBLIC_KEY) rastgeledir, sıra numarası değildir.
 *
 * Şirket oturumdan değil config.php'deki kvkk.company_id ayarından gelir (ziyaretçinin oturumu yok).
 * Bu dosya portalın jsonp.php proxy'sini kullanmaz: proxy giriş ister ve yönetim ekranlarına da kapı açar.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Yönetimde yapılan değişiklik en geç 1 dakikada görünsün
header('Cache-Control: public, max-age=60');

// Yalnızca okuma: GET dışındaki istekler reddedilir
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false]);
    exit;
}

try {
    // Proje kökündeki ortak kurulum: $em (Doctrine) ve $settings (config.php)
    $em = require dirname(__DIR__, 2) . '/bootstrap.php';
    $companyId = (int) ($settings['kvkk']['company_id'] ?? 0);

    // --- Yayındaki başlıklar ---
    $nodes = $em->createQuery("
        SELECT n.nodeId, IDENTITY(n.parent) parentNodeId, n.nodeName, n.nodeDesc
          FROM BIO\KVK\KvkkNode n
         WHERE IDENTITY(n.company) = :COMPANY_ID
           AND n.activeFl = 'Y'
           AND n.recordFl > 0
         ORDER BY n.sortNo, n.nodeName
    ")->setParameter('COMPANY_ID', $companyId)->getArrayResult();

    // --- Yayındaki belgeler (PDF'i olanlar) ---
    $documents = $em->createQuery("
        SELECT IDENTITY(d.node) nodeId, d.documentName, d.publicKey
          FROM BIO\KVK\KvkkDocument d
         WHERE IDENTITY(d.company) = :COMPANY_ID
           AND d.publishFl = 'Y'
           AND d.activeFl = 'Y'
           AND d.recordFl > 0
           AND EXISTS (SELECT 1 FROM BIO\KVK\KvkkDocumentFile f WHERE f.document = d AND f.recordFl > 0)
         ORDER BY d.sortNo, d.documentName
    ")->setParameter('COMPANY_ID', $companyId)->getArrayResult();

    // Başlıkları üst başlığa göre, belgeleri başlığa göre grupla (kök başlıkların üst başlığı 0 sayılır)
    $childrenOf = [];
    foreach ($nodes as $n) {
        $childrenOf[(int) ($n['parentNodeId'] ?? 0)][] = $n;
    }
    $documentsOf = [];
    foreach ($documents as $d) {
        $documentsOf[(int) $d['nodeId']][] = [
            'name' => $d['documentName'],
            'url'  => 'php/doc.php?k=' . rawurlencode($d['publicKey']),
        ];
    }

    // Ağacı kökten aşağı kurar. Pasif başlık listede olmadığı için altındakilere hiç inilmez.
    // $depth: bozuk veride (başlık kendi altına bağlanmışsa) sonsuz döngü olmasın diye sınır
    $build = function (int $parentId, int $depth) use (&$build, $childrenOf, $documentsOf): array {
        $result = [];
        if ($depth > 20) {
            return $result;
        }
        foreach ($childrenOf[$parentId] ?? [] as $n) {
            $id       = (int) $n['nodeId'];
            $children = $build($id, $depth + 1);
            $docs     = $documentsOf[$id] ?? [];
            if (!$children && !$docs) {
                continue;   // altında yayında belge yok
            }
            $result[] = [
                'name'      => $n['nodeName'],
                'desc'      => $n['nodeDesc'],
                'documents' => $docs,
                'children'  => $children,
            ];
        }
        return $result;
    };

    echo json_encode(['ok' => true, 'nodes' => $build(0, 0)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    // Hata ayrıntısı ziyaretçiye gösterilmez, sunucu loguna yazılır
    error_log('KVKK api.php: ' . $e->getMessage());
    http_response_code(500);
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false]);
}
