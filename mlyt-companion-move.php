<?php
declare(strict_types=1);

/**
 * "Kitle birlikte kullanılan malzemeler" (mlyt_material_companion) kayıtlarını testlerin reçetesine taşır.
 * Malzemeler ekranındaki bu liste kaldırılıyor: reçete tek yerden (Test maliyetleri) yönetilsin, gizli ekleme kalmasın.
 *
 *   php mlyt-companion-move.php            → DENEME: her şey yapılır, rapor yazılır, GERİ ALINIR (veri değişmez)
 *   php mlyt-companion-move.php --apply    → uygular; ama yalnız hiçbir testin maliyeti değişmiyorsa (aksi hâlde geri alır)
 *
 * Kural (her kitin, kiti hasta başı kullanan her testi için):
 *   test başı satır  → reçeteye hasta başı satır:  miktar = reçetedeki kit miktarı × listedeki adet
 *   kit başına satır → reçeteye kit başına satır:  miktar = reçetedeki kit miktarı × listedeki kutu adedi
 *   Reçetede aynı malzeme aynı türde zaten varsa miktarlar toplanır (bugün ikisi de sayılıyor; toplam aynı kalır).
 * Liste satırı silinmez, pasifleştirilir (RECORD_FL = 0): gerekirse geri açılabilir.
 *
 * Güvenlik: işlem tek transaction'dadır. Önce ve sonra bütün çalışmalarda bütün testlerin maliyeti ve durumu hesaplanır;
 * tek kuruş fark varsa değişiklik geri alınır ve farklar listelenir. --apply olmadan hiçbir zaman yazılmaz.
 */

use Biolab\Core\Mlyt\CostCalculator;

if (PHP_SAPI !== 'cli') {
    exit("Yalnızca komut satırından çalışır.\n");
}
$apply = in_array('--apply', array_slice($argv, 1), true);

$em = require __DIR__ . '/bootstrap.php';
$c = $em->getConnection();

/** Her şirket × çalışma için bütün testlerin maliyeti: "şirket|çalışma|test" → [maliyet, durum] */
$snapshot = function () use ($em, $c): array {
    $out = [];
    $studies = $c->fetchAllAssociative("SELECT STUDY_ID, COMPANY_ID FROM mlyt_study WHERE RECORD_FL > 0");
    $studies[] = null;   // çalışmasız hesap da (fiyatsız) karşılaştırılsın
    foreach ($c->fetchFirstColumn("SELECT DISTINCT COMPANY_ID FROM mlyt_test WHERE RECORD_FL > 0") as $companyId) {
        foreach ($studies as $s) {
            if ($s !== null && (int) $s['COMPANY_ID'] !== (int) $companyId) { continue; }
            $calc = new CostCalculator($em, (int) $companyId, $s === null ? null : (int) $s['STUDY_ID']);
            foreach ($c->fetchFirstColumn("SELECT TEST_ID FROM mlyt_test WHERE RECORD_FL > 0 AND COMPANY_ID = ?", [$companyId]) as $testId) {
                $r = $calc->result((int) $testId);
                $out[$companyId . '|' . ($s['STUDY_ID'] ?? '-') . '|' . $testId] = [$r['cost'], $r['status']];
            }
        }
    }
    return $out;
};

$rows = $c->fetchAllAssociative("
    SELECT mc.MATERIAL_COMPANION_ID, mc.KIT_MATERIAL_ID, mc.MATERIAL_ID, mc.QUANTITY, mc.BASIS,
           k.MATERIAL_NAME AS KIT_NAME, m.MATERIAL_NAME
      FROM mlyt_material_companion mc
      JOIN mlyt_material k ON k.MATERIAL_ID = mc.KIT_MATERIAL_ID
      JOIN mlyt_material m ON m.MATERIAL_ID = mc.MATERIAL_ID
     WHERE mc.RECORD_FL > 0 AND mc.ACTIVE_FL = 'Y'
     ORDER BY k.MATERIAL_NAME, mc.SORT_NO, mc.MATERIAL_COMPANION_ID
");
echo ($apply ? "UYGULAMA" : "DENEME (veri değişmez)") . " · taşınacak liste satırı: " . count($rows) . PHP_EOL;
if (!$rows) {
    echo "Taşınacak kayıt yok.\n";
    exit(0);
}

$c->beginTransaction();
try {
    $before = $snapshot();
    $moved = 0;
    foreach ($rows as $r) {
        $cons = $r['BASIS'] === 'KIT' ? 'KIT' : 'PATIENT';
        echo PHP_EOL . "• " . $r['KIT_NAME'] . " → " . $r['MATERIAL_NAME'] . " (" . (float) $r['QUANTITY'] . ($cons === 'KIT' ? " kit başına" : " test başı") . ")" . PHP_EOL;
        // Kiti hasta başı kullanan testler (hesapta yalnız etkin satırlar sayılır)
        $uses = $c->fetchAllAssociative("
            SELECT ri.TEST_ID, ri.QUANTITY, t.TEST_CODE
              FROM mlyt_recipe_item ri JOIN mlyt_test t ON t.TEST_ID = ri.TEST_ID
             WHERE ri.MATERIAL_ID = ? AND ri.CONSUMPTION = 'PATIENT' AND ri.RECORD_FL > 0 AND ri.ACTIVE_FL = 'Y'
        ", [$r['KIT_MATERIAL_ID']]);
        if (!$uses) { echo "    kit hiçbir testte yok (maliyete etkisi yoktu)\n"; }
        foreach ($uses as $u) {
            $qty = round((float) $u['QUANTITY'] * (float) $r['QUANTITY'], 4);
            $ex = $c->fetchAssociative("SELECT RECIPE_ITEM_ID, QUANTITY, RECORD_FL, ACTIVE_FL FROM mlyt_recipe_item
                                         WHERE TEST_ID = ? AND MATERIAL_ID = ? AND CONSUMPTION = ?", [$u['TEST_ID'], $r['MATERIAL_ID'], $cons]);
            if ($ex === false) {
                $sort = (int) $c->fetchOne("SELECT ISNULL(MAX(SORT_NO), 0) FROM mlyt_recipe_item WHERE TEST_ID = ?", [$u['TEST_ID']]) + 10;
                $c->executeStatement("INSERT INTO mlyt_recipe_item (TEST_ID, MATERIAL_ID, QUANTITY, CONSUMPTION, SORT_NO, NOTE, ACTIVE_FL, RECORD_FL)
                                      VALUES (?, ?, ?, ?, ?, ?, 'Y', 1)",
                    [$u['TEST_ID'], $r['MATERIAL_ID'], $qty, $cons, $sort, 'Kitle birlikte listesinden taşındı (' . $r['KIT_NAME'] . ')']);
                echo "    {$u['TEST_CODE']}: eklendi, miktar $qty\n";
            } elseif ((int) $ex['RECORD_FL'] > 0 && $ex['ACTIVE_FL'] === 'Y') {
                $sum = round((float) $ex['QUANTITY'] + $qty, 4);
                $c->executeStatement("UPDATE mlyt_recipe_item SET QUANTITY = ?, U_AT = SYSDATETIME() WHERE RECIPE_ITEM_ID = ?", [$sum, $ex['RECIPE_ITEM_ID']]);
                echo "    {$u['TEST_CODE']}: reçetede vardı (" . (float) $ex['QUANTITY'] . "), toplam $sum\n";
            } else {
                // Silinmiş / pasif satır hesaba girmiyordu: yeni miktarla yeniden etkin
                $c->executeStatement("UPDATE mlyt_recipe_item SET QUANTITY = ?, RECORD_FL = 1, ACTIVE_FL = 'Y', U_AT = SYSDATETIME() WHERE RECIPE_ITEM_ID = ?", [$qty, $ex['RECIPE_ITEM_ID']]);
                echo "    {$u['TEST_CODE']}: eski (pasif) satır miktar $qty ile etkinleştirildi\n";
            }
        }
        $c->executeStatement("UPDATE mlyt_material_companion SET RECORD_FL = 0, ACTIVE_FL = 'N', D_AT = SYSDATETIME() WHERE MATERIAL_COMPANION_ID = ?", [$r['MATERIAL_COMPANION_ID']]);
        $moved++;
    }

    $after = $snapshot();
    $diffs = [];
    foreach ($before as $key => [$cost, $status]) {
        [$cost2, $status2] = $after[$key] ?? [null, '?'];
        if ($status !== $status2 || abs((float) $cost - (float) $cost2) > 0.005 || ($cost === null) !== ($cost2 === null)) {
            [$co, $st, $te] = explode('|', $key);
            $code = $c->fetchOne("SELECT TEST_CODE FROM mlyt_test WHERE TEST_ID = ?", [$te]);
            $diffs[] = sprintf("  çalışma %s · %s: %s (%s) → %s (%s)", $st, $code, $cost ?? '—', $status, $cost2 ?? '—', $status2);
        }
    }
    echo PHP_EOL . "Karşılaştırılan test × çalışma: " . count($before) . " · farklı çıkan: " . count($diffs) . PHP_EOL;

    if ($diffs) {
        $c->rollBack();
        echo "MALİYET DEĞİŞİYOR, GERİ ALINDI (hiçbir şey yazılmadı):\n" . implode(PHP_EOL, array_slice($diffs, 0, 50)) . PHP_EOL;
        exit(1);
    }
    if ($apply) {
        $c->commit();
        echo "UYGULANDI: $moved liste satırı reçetelere taşındı; hiçbir testin maliyeti değişmedi.\n";
    } else {
        $c->rollBack();
        echo "DENEME TAMAM: maliyetler aynı kalıyor. Uygulamak için: --apply\n";
    }
} catch (Throwable $e) {
    if ($c->isTransactionActive()) { $c->rollBack(); }
    echo "HATA, GERİ ALINDI: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
