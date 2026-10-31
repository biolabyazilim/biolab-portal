<?php
declare(strict_types=1);

/**
 * Maliyet Excel'i içe aktarma (komut satırı). mlyt-import.bat'tan çalıştırılır.
 *
 *   php mlyt-import.php ["C:\...\mlyt programı.xlsx"]            → DENEME: her şey yapılır, rapor yazılır, geri alınır
 *   php mlyt-import.php ["C:\...\mlyt programı.xlsx"] --apply    → veritabanına yazar
 *   php mlyt-import.php ["C:\...\xlsx"] --prices --study=7 [--apply] → yalnız MALZEME LİSTESİ fiyatları, 7 no'lu çalışmaya
 *                                                                      (--study verilmezse çalışmalar listelenir)
 *   dosya verilmezse DEFAULT_FILE kullanılır
 *   seçenekler: --company=BIOLAB (şirket kodu)  --user=ssadmin (kayıtlarda "oluşturan")
 *
 * Varsayılan yol burada (bat'ta değil): cmd, chcp 65001 açıkken Türkçe karakterli bat satırlarını kaydırarak
 * okuyor ("'u' is not recognized" hatası, boş yol). Bu yüzden mlyt-import.bat yalnızca ASCII içerir.
 */
const DEFAULT_FILE = '%USERPROFILE%\Documents\Maliyet proje notları\mlyt programı.xlsx';

use Biolab\Core\Mlyt\ExcelImport;
use Biolab\Core\Mlyt\PriceImport;
use Biolab\Core\Mlyt\XlsxReader;
use BIO\MLYT\Study;
use BIO\MNG\AppUser;
use BIO\MNG\Company;

if (PHP_SAPI !== 'cli') {
    exit("Yalnızca komut satırından çalışır.\n");
}

$args  = array_slice($argv, 1);
$file  = null;
$apply = false;
$companyCode = 'BIOLAB';
$username    = 'ssadmin';
$prices      = false;
$studyId     = 0;
foreach ($args as $a) {
    if ($a === '--apply') { $apply = true; }
    elseif ($a === '--prices') { $prices = true; }
    elseif (str_starts_with($a, '--study=')) { $studyId = (int) substr($a, 8); }
    elseif (str_starts_with($a, '--company=')) { $companyCode = substr($a, 10); }
    elseif (str_starts_with($a, '--user=')) { $username = substr($a, 7); }
    else { $file = $a; }
}
if ($file === null || trim($file) === '') {
    $file = str_replace('%USERPROFILE%', (string) getenv('USERPROFILE'), DEFAULT_FILE);
}
if (!is_file($file)) {
    fwrite(STDERR, "Excel dosyası bulunamadı: " . ($file ?? '(verilmedi)') . "\n");
    exit(1);
}

/** @var \Doctrine\ORM\EntityManagerInterface $em */
$em = require __DIR__ . '/bootstrap.php';
$company = $em->getRepository(Company::class)->findOneBy(['code' => $companyCode]);
$user    = $em->getRepository(AppUser::class)->findOneBy(['username' => $username]);
if ($company === null) {
    fwrite(STDERR, "Şirket bulunamadı: {$companyCode}\n");
    exit(1);
}
// AuditListener C_BY / U_BY'yi oturumdan yazar
$_SESSION = ['USER_ID' => $user?->getUserId(), 'COMPANY_ID' => $company->getCompanyId()];

if ($prices) {
    runPrices($em, $company, $file, $studyId, $apply);
    exit(0);
}

$conn = $em->getConnection();
$conn->beginTransaction();
try {
    $report = (new ExcelImport($em, $company))->run(new XlsxReader($file));
    if ($apply) {
        $conn->commit();
    } else {
        $conn->rollBack();
    }
} catch (\Throwable $e) {
    if ($conn->isTransactionActive()) { $conn->rollBack(); }
    fwrite(STDERR, "HATA: " . $e->getMessage() . "\nHiçbir şey yazılmadı.\n");
    exit(1);
}

$line = fn (string $s = '') => print($s . PHP_EOL);
$list = function (string $title, array $items, int $max = 40) use ($line) {
    if (!$items) { return; }
    $line("  {$title} (" . count($items) . "):");
    foreach (array_slice($items, 0, $max) as $i) { $line("    - {$i}"); }

    if (count($items) > $max) { $line('    ... ve ' . (count($items) - $max) . ' tane daha'); }
};

$line(str_repeat('=', 70));
$line($apply ? '  UYGULANDI — veritabanına yazıldı' : '  DENEME — hiçbir şey yazılmadı (uygulamak için --apply)');
$line('  Dosya: ' . $file);
$line(str_repeat('=', 70));
$line('TESTLER');
$line("  yeni: {$report['tests_created']}   güncellenen: {$report['tests_updated']}   hizmet grubu (yeni): {$report['groups_created']}   tedarikçi (yeni): {$report['suppliers_created']}");
$line('  maliyet yöntemi: ' . implode('   ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($report['methods']), $report['methods'])));
$list('aktarılmayan satırlar', $report['tests_skipped']);
$list('yöntemi belirsiz (ekrandan seçilecek)', $report['undefined']);
$line('PATOLOJİ');
$line("  güncellenen: {$report['pathology_updated']}   birleşik test bileşeni (yeni): {$report['components_created']}");
$list('test listesinde olmayan kod', $report['pathology_missing']);
$list('SUT kodu birden fazla teste uyuyor — bileşen eklenmedi, karar gerekli', $report['components_ambiguous']);
$list('SUT kodu tekil testlerde yok — bileşen eklenmedi', $report['components_unknown']);
$line('MALZEMELER');
$line("  yeni: {$report['materials_created']}   güncellenen: {$report['materials_updated']}");
$line('  önerilen kategori: ' . implode('   ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($report['material_categories']), $report['material_categories'])));
$list('kategorisi bulunamayan (Diğer) — ekrandan düzeltilmeli', $report['materials_other']);
$line('PATOLOJİ REÇETELERİ (notlardan)');
$line("  yeni reçete: {$report['recipes_created']}   reçete satırı: {$report['recipe_items_created']}   zaten reçetesi olan (dokunulmadı): {$report['recipes_existing']}");
$list('kalıba uymayan not — Test maliyetleri ekranından girilecek', $report['recipes_unparsed']);
$list('katalogda bulunamayan malzeme — bu satırlar reçeteye eklenmedi', $report['recipe_material_missing']);

/** Fiyat aktarımı (--prices): MALZEME LİSTESİ → seçili çalışmanın fiyatları */
function runPrices(\Doctrine\ORM\EntityManagerInterface $em, Company $company, string $file, int $studyId, bool $apply): void
{
    $line = fn (string $s = '') => print($s . PHP_EOL);
    $list = function (string $title, array $items, int $max = 30) use ($line) {
        if (!$items) { return; }
        $line("  {$title} (" . count($items) . "):");
        foreach (array_slice($items, 0, $max) as $i) { $line("    - {$i}"); }
        if (count($items) > $max) { $line('    ... ve ' . (count($items) - $max) . ' tane daha'); }
    };

    $study = $studyId > 0 ? $em->getRepository(Study::class)->findOneBy(['studyId' => $studyId, 'company' => $company->getCompanyId()]) : null;
    if ($study === null || $study->isDeleted()) {
        $line($studyId > 0 ? "Çalışma bulunamadı: {$studyId}" : 'Fiyatların yazılacağı çalışmanın numarasını verin (--study=NO). Çalışmalar:');
        foreach ($em->getRepository(Study::class)->findBy(['company' => $company->getCompanyId()], ['studyId' => 'ASC']) as $s) {
            if (!$s->isDeleted()) { $line("  {$s->getStudyId()}  {$s->getStudyName()}  ({$s->getStatus()})"); }
        }
        exit(1);
    }

    $conn = $em->getConnection();
    $conn->beginTransaction();
    try {
        $r = (new PriceImport($em, $company, $study))->run(new XlsxReader($file));
        $apply ? $conn->commit() : $conn->rollBack();
    } catch (\Throwable $e) {
        if ($conn->isTransactionActive()) { $conn->rollBack(); }
        fwrite(STDERR, "HATA: " . $e->getMessage() . "\nHiçbir şey yazılmadı.\n");
        exit(1);
    }

    $line(str_repeat('=', 70));
    $line($apply ? '  FİYATLAR UYGULANDI — veritabanına yazıldı' : '  FİYAT DENEMESİ — hiçbir şey yazılmadı (uygulamak için --apply)');
    $line('  Dosya: ' . $file);
    $line("  Çalışma: {$study->getStudyId()} {$study->getStudyName()}");
    $line(str_repeat('=', 70));
    $line("FİYATLAR  yeni: {$r['prices_created']}   güncellenen: {$r['prices_updated']}   yeni malzeme: " . count($r['materials_created']));
    $list('yeni malzemeler (kategori addan önerildi; ekrandan kontrol edin)', $r['materials_created'], 80);
    $list('kitler: kitteki test / kitten çıkan hasta', $r['kits_with_patients'], 40);
    $list('tedarikçisi açıklamadan yazılan', $r['suppliers_set']);
    $list('çalışmaya yazılan kurlar', $r['rates_set']);
    $list('kur uyuşmazlığı', $r['rates_conflict']);
    $line('  Excel\'deki TL kurları (öngörü katsayısı; çalışmadan girilir, aktarım değiştirmez): '
        . implode('   ', array_map(fn ($k, $v) => "{$k} → {$v} satır", array_keys($r['try_rates']), $r['try_rates'])));
    $list('hasta sayısı girilmeli (STANDART - HASTA SAYISI)', $r['needs_patients']);
    $list('bölen 1\'den küçük (Excel ADET ile çarpıyor) — kontrol', $r['odd_divisor']);
    $list('TL birim maliyeti boş, bölen tahmin edildi', $r['no_tl_cost']);
    $list('fiyatsız satırlar', $r['no_price']);
    $list('genel gider satırları (aktarılmadı)', $r['overhead']);
    $list('para birimi tanınmayan', $r['bad_currency']);
    $list('Excel\'de aynı ad iki kez', $r['duplicates']);
    $list('ekrandan silinmiş malzemeler (yeniden açılmadı)', $r['deleted']);
}
