<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\Material;
use BIO\MLYT\MaterialCategory;
use BIO\MLYT\MaterialPrice;
use BIO\MLYT\Study;
use BIO\MLYT\StudyRate;
use BIO\MLYT\Supplier;
use BIO\MNG\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Uzmanın fiyatlı malzeme listesinden (MALZEME LİSTESİ, 28.10.2026 yapısı) seçili maliyet çalışmasına fiyat aktarımı.
 *   A SIRA | B MALZEME | C AMBALAJ | D ADET TÜRÜ | E ADET | F PARA BİRİMİ | G KUR | H BİRİM MALİYET (fiyat) | I TL BİRİM MALİYET | J AÇIKLAMA
 *
 * Excel'de bölen satıra göre değişiyor (eski satırlar ÷ AMBALAJ, yeniler ÷ ADET, bazıları × ADET ya da bölmesiz).
 * Bu yüzden bölen Excel'in kendi sonucundan çıkarılır: bölen = fiyat × kur ÷ TL birim maliyet. Böylece her satırın
 * birim maliyeti Excel'deki gibi kalır.
 *   bölen = AMBALAJ ise           → ambalaj içeriği = AMBALAJ
 *   kitte bölen ≠ AMBALAJ ise     → ambalaj içeriği = AMBALAJ (kitteki test), hasta sayısı = bölen (kitten çıkan hasta)
 *   diğerlerinde                  → ambalaj içeriği = bölen
 *
 * Malzeme adına göre eşleşir; yoksa açılır (kategori addan önerilir, ADET TÜRÜ "KİT" ise Kit).
 * Fiyat çalışmada varsa üzerine yazılır. Döviz kurları çalışmada boşsa Excel'deki kurla doldurulur, doluysa değiştirilmez.
 * TL öngörü katsayısı değiştirilmez; Excel'deki TL kurları rapora yazılır (çalışmadan girilir).
 * Fiyatsız satırlar (genel gider, panel toplamı) fiyat almaz, rapora düşer. Ekrandan silinmiş malzeme yeniden açılmaz.
 * Transaction / geri alma çağıranın işidir.
 */
final class PriceImport
{
    public const SHEET = 'MALZEME LİSTESİ';

    private const COL_NAME = 2, COL_PACK = 3, COL_PACK_TYPE = 4, COL_QTY = 5, COL_CURRENCY = 6, COL_RATE = 7, COL_PRICE = 8,
                  COL_TL_COST = 9, COL_NOTE = 10;

    private const CURRENCIES = ['TL' => 'TRY', 'TRY' => 'TRY', 'USD' => 'USD', 'DOLAR' => 'USD', 'EURO' => 'EUR', 'EUR' => 'EUR',
                                'STERLİN' => 'GBP', 'GBP' => 'GBP'];

    /** Açıklama kolonunda tedarikçi adı yazan satırlar: malzemenin tedarikçisi boşsa bu yazılır */
    private const NOTE_SUPPLIERS = ['ALGEN', 'ANATOLİA GENETİK'];

    private ExcelImport $suggest;
    /** @var array<string, MaterialCategory> */ private array $categories = [];
    /** @var array<string, Material> */         private array $materials = [];
    /** @var array<string, true> ekrandan silinmiş malzeme adları (yeniden açılmaz) */ private array $deleted = [];
    /** @var array<string, Supplier> */         private array $suppliers = [];
    private array $report = [];

    public function __construct(private EntityManagerInterface $em, private Company $company, private Study $study)
    {
        $this->suggest = new ExcelImport($em, $company);
    }

    public function run(XlsxReader $xlsx): array
    {
        if (!$this->study->isEditable()) {
            throw new \RuntimeException("'{$this->study->getStudyName()}' çalışması onaylanmış; fiyatları değiştirilemez.");
        }
        $rows = $xlsx->sheet(self::SHEET);
        $this->assertHeader($rows, self::COL_NAME, 'malzeme');
        $this->assertHeader($rows, self::COL_QTY, 'adet');
        $this->assertHeader($rows, self::COL_PRICE, 'birim maliyet');

        $this->report = [
            'materials_created' => [], 'prices_created' => 0, 'prices_updated' => 0, 'suppliers_set' => [],
            'kits_with_patients' => [], 'no_price' => [], 'overhead' => [], 'bad_currency' => [], 'odd_divisor' => [],
            'no_tl_cost' => [], 'needs_patients' => [], 'duplicates' => [], 'deleted' => [], 'rates_set' => [], 'rates_conflict' => [], 'try_rates' => [],
        ];
        $this->load();

        $fxRates = [];
        $seen = [];
        foreach ($rows as $no => $r) {
            if ($no === 1) { continue; }
            $name = $this->clean($r[self::COL_NAME] ?? '');
            if ($name === '') { continue; }
            $key = $this->key($name);
            $label = "satır {$no}: {$name}";
            if (isset($seen[$key])) { $this->report['duplicates'][] = "{$label} (satır {$seen[$key]} ile aynı ad; sonraki geçerli)"; }
            $seen[$key] = $no;
            if (isset($this->deleted[$key]) && !isset($this->materials[$key])) {
                $this->report['deleted'][] = "{$label} (ekrandan silinmiş; yeniden açılmadı)";
                continue;
            }

            $price    = $this->num($r[self::COL_PRICE] ?? null);
            $tlCost   = $this->num($r[self::COL_TL_COST] ?? null);
            $pack     = $this->num($r[self::COL_PACK] ?? null);
            $qty      = $this->num($r[self::COL_QTY] ?? null);
            $packType = $this->upper($this->clean($r[self::COL_PACK_TYPE] ?? ''));
            $note     = $this->clean($r[self::COL_NOTE] ?? '');
            $note     = $note === '#REF!' ? '' : $note;

            if ($price === null || $price <= 0) {
                if (str_contains($this->upper($name), 'GİDERİ')) { $this->report['overhead'][] = $label; continue; }
                if ($tlCost !== null) { $this->report['no_price'][] = "{$label} (fiyat yok, TL toplamı var: panel / toplam satırı; aktarılmadı)"; continue; }
                $this->material($name, $packType, $label);   // katalogda dursun
                $this->report['no_price'][] = "{$label} (fiyat yok)";
                continue;
            }
            $currency = self::CURRENCIES[$this->upper($this->clean($r[self::COL_CURRENCY] ?? ''))] ?? null;
            if ($currency === null) { $this->report['bad_currency'][] = "{$label} ('" . ($r[self::COL_CURRENCY] ?? '') . "')"; continue; }
            $rate = $this->num($r[self::COL_RATE] ?? null);
            if ($currency === 'TRY') {
                $this->report['try_rates'][(string) ($rate ?? 1)] = ($this->report['try_rates'][(string) ($rate ?? 1)] ?? 0) + 1;
            } elseif ($rate !== null && $rate > 0) {
                $fxRates[$currency][(string) $rate] = ($fxRates[$currency][(string) $rate] ?? 0) + 1;
            }

            // Bölen: Excel'in TL sonucundan (fiyat × kur ÷ TL birim maliyet); sonuç yoksa ADET / AMBALAJ'dan büyüğü
            $rateUsed = $rate !== null && $rate > 0 ? $rate : 1.0;
            if ($tlCost !== null && $tlCost > 0) {
                $divisor = round($price * $rateUsed / $tlCost, 3);
            } else {
                $divisor = max($pack ?? 0, $qty ?? 0, 1);
                $this->report['no_tl_cost'][] = "{$label} (TL birim maliyet yok; bölen " . $this->fmt($divisor) . ' alındı)';
            }
            if ($divisor < 1) {
                $this->report['odd_divisor'][] = "{$label} (bölen " . $this->fmt($divisor) . ': Excel ADET ile çarpıyor, birim maliyet Excel\'deki gibi alındı; kontrol edin)';
            }

            $material = $this->material($name, $packType, $label);
            $isKit = $packType === 'KİT' || $material->getCategory()->getCategoryCode() === MaterialCategory::CODE_KIT;
            $patientQty = null;
            $packageQty = $divisor;
            if ($pack !== null && $pack > 0 && abs($divisor - $pack) / $pack < 0.005) {
                $packageQty = $pack;
            } elseif ($isKit && $pack !== null && $divisor > 1 && $divisor < $pack) {
                // Kitten çıkan hasta, kitteki testten az (96 testten 70 hasta). Bölen 1 ise fiyat zaten test başıdır (PCR kitleri).
                $packageQty = $pack;
                $patientQty = $divisor;
                $this->report['kits_with_patients'][] = "{$name}: " . $this->fmt($pack) . ' test, ' . $this->fmt($divisor) . ' hasta';
            }
            if (str_contains($this->upper($note), 'HASTA SAYISI')) {
                $this->report['needs_patients'][] = "{$label} (bir ambalaj kaç hastaya yeter? ekrandan girilecek)";
            }

            if ($note !== '' && in_array($this->upper($note), self::NOTE_SUPPLIERS, true) && $material->getSupplier() === null) {
                $material->setSupplier($this->supplier($note));
                $this->report['suppliers_set'][] = "{$name} → {$note}";
            }

            $p = $material->getMaterialId() ? $this->em->getRepository(MaterialPrice::class)->findOneBy(['study' => $this->study, 'material' => $material]) : null;
            if ($p === null) {
                $p = new MaterialPrice($this->study, $material, $this->dec($packageQty), $currency, $this->dec($price, 4));
                $this->em->persist($p);
                $this->report['prices_created']++;
            } else {
                $p->setPackageQty($this->dec($packageQty))->setCurrency($currency)->setPackagePrice($this->dec($price, 4));
                $this->report['prices_updated']++;
            }
            $desc = trim(($pack !== null ? 'ambalaj ' . $this->fmt($pack) : '') . ($packType !== '' ? ' · ' . $packType : '') . ($qty !== null ? ' · adet ' . $this->fmt($qty) : ''), ' ·');
            $p->setPatientQty($patientQty !== null ? $this->dec($patientQty) : null)
              ->setPackageDesc($desc !== '' ? mb_substr('Excel: ' . $desc, 0, 100) : null)
              ->setNote($note !== '' ? mb_substr($note, 0, 500) : null);
        }

        $this->applyRates($fxRates);
        $this->em->flush();
        return $this->report;
    }

    /** Döviz kurları: çalışmada yoksa Excel'de en çok kullanılan kur yazılır; varsa ve farklıysa yalnız rapor */
    private function applyRates(array $fxRates): void
    {
        $existing = [];
        foreach ($this->em->getRepository(StudyRate::class)->findBy(['study' => $this->study]) as $r) { $existing[$r->getCurrency()] = $r; }
        foreach ($fxRates as $cur => $counts) {
            arsort($counts);
            $rate = (string) array_key_first($counts);
            if (count($counts) > 1) { $this->report['rates_conflict'][] = "{$cur}: Excel'de birden çok kur (" . implode(', ', array_keys($counts)) . "); {$rate} alındı"; }
            if (!isset($existing[$cur])) {
                $this->em->persist(new StudyRate($this->study, $cur, $rate));
                $this->report['rates_set'][] = "{$cur} = {$rate}";
            } elseif ((float) $existing[$cur]->getRate() !== (float) $rate) {
                $this->report['rates_conflict'][] = "{$cur}: çalışmada " . (float) $existing[$cur]->getRate() . ", Excel'de {$rate} (çalışmadaki korundu)";
            }
        }
    }

    private function material(string $name, string $packType, string $label): Material
    {
        $key = $this->key($name);
        if (isset($this->materials[$key])) { return $this->materials[$key]; }
        $code = $packType === 'KİT' ? MaterialCategory::CODE_KIT : $this->suggest->categoryFor($name);
        $m = new Material($name, $this->categories[$code] ?? $this->categories[MaterialCategory::CODE_OTHER], $this->suggest->unitFor($name, $code));
        $m->setCompany($this->company);
        $this->em->persist($m);
        $this->materials[$key] = $m;
        $this->report['materials_created'][] = "{$label} → " . ($this->categories[$code] ?? $this->categories[MaterialCategory::CODE_OTHER])->getCategoryName();
        return $m;
    }

    private function supplier(string $name): Supplier
    {
        $key = $this->key($name);
        if (!isset($this->suppliers[$key])) {
            $s = new Supplier($name);
            $s->setCompany($this->company);
            $this->em->persist($s);
            $this->suppliers[$key] = $s;
        }
        return $this->suppliers[$key];
    }

    private function load(): void
    {
        $cid = $this->company->getCompanyId();
        foreach ($this->em->getRepository(MaterialCategory::class)->findAll() as $c) { $this->categories[$c->getCategoryCode()] = $c; }
        foreach ($this->em->getRepository(Material::class)->findBy(['company' => $cid]) as $m) {
            if (!$m->isDeleted()) { $this->materials[$this->key($m->getMaterialName())] = $m; }
            else { $this->deleted[$this->key($m->getMaterialName())] = true; }
        }
        foreach ($this->em->getRepository(Supplier::class)->findBy(['company' => $cid]) as $s) { $this->suppliers[$this->key($s->getSupplierName())] = $s; }
    }

    private function assertHeader(array $rows, int $col, string $expect): void
    {
        // Türkçe büyük harfle karşılaştırılır (mb_strtolower "İ"yi noktalı i + birleşik nokta yapıyor)
        $h = $this->key($rows[1][$col] ?? '');
        if (!str_contains($h, $this->upper($expect))) {
            throw new \RuntimeException("'" . self::SHEET . "' sayfasının başlığı beklenen yapıda değil (kolon {$col}: '{$h}', beklenen: '{$expect}'). "
                . 'Fiyat aktarımı AMBALAJ | ADET TÜRÜ | ADET | PARA BİRİMİ | KUR | BİRİM MALİYET yapısını bekler.');
        }
    }

    /** "1.234,56" / "1234.56" / "7.0000000000000007E-2" → float; boş / #REF! → null */
    private function num($v): ?float
    {
        $v = str_replace(["\u{00A0}", ' '], '', trim((string) $v));
        if ($v === '' || !preg_match('/^-?[\d.,]+(E-?\d+)?$/i', $v)) { return null; }
        if (str_contains($v, ',')) { $v = str_replace(['.', ','], ['', '.'], $v); }
        return is_numeric($v) ? (float) $v : null;
    }

    private function dec(float $v, int $scale = 3): string
    {
        return number_format(round($v, $scale), $scale, '.', '');
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    }

    private function clean(string $v): string { return trim(preg_replace('/\s+/u', ' ', $v)); }
    private function upper(string $v): string { return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $v), 'UTF-8'); }
    private function key(string $v): string { return $this->upper($this->clean($v)); }
}
