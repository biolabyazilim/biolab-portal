<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\Material;
use BIO\MLYT\MaterialCategory;
use BIO\MLYT\MaterialPrice;
use BIO\MLYT\RecipeItem;
use BIO\MLYT\Study;
use BIO\MLYT\StudyRate;
use BIO\MLYT\StudySupplierRate;
use BIO\MLYT\Supplier;
use BIO\MLYT\Test;
use BIO\MNG\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Uzmanın fiyatlı malzeme listesinden (MALZEME LİSTESİ, son versiyon 07.11.2026) seçili maliyet çalışmasına aktarım.
 *   A SIRA | B MALZEME | C AMBALAJ | D ADET TÜRÜ | E ADET | F PARA BİRİMİ | G KUR | H BİRİM MALİYET (fiyat) | I AÇIKLAMA
 *   | J (NUCLEUS'ta katalog no, diğerlerinde açıklamanın devamı) | K (NUCLEUS ürün adı)
 *
 * Satır türleri:
 *   fiyatlı          → malzeme + çalışmadaki fiyatı; adı bir testin adıyla aynıysa teste hasta başı 1 bağlanır
 *   BEDELLİ MALZEME  → "Bedelli malzeme" kategorisinde katalog kaydı (fiyatsız)
 *   hesap notu       → fiyatsız, açıklaması dolu satır: aynı adlı testin uzman hesap notuna (COST_NOTE) yazılır
 *   OSGB / gider / boş → yalnız rapor
 *
 * Bölen (fiyat ÷ ?):
 *   - önceki aktarımın Excel'i (referans, "TL BİRİM MALİYET" kolonlu) verilmişse ve satır değişmemişse bölen oradan:
 *     fiyat × kur ÷ TL birim maliyet (Excel'in o zamanki sonucu birebir korunur)
 *   - yoksa: ADET > 1 → ADET (kitten çıkan hasta / ambalaj içeriği); ADET ≤ 1 ve AMBALAJ > 1 → AMBALAJ (fiyat ambalajın tamamı);
 *     Anatolia Genetik PCR kitleri test başı fiyat (bölen 1); ikisi de 1 → 1
 *   - AMBALAJ > 1 ve ADET > 1 ve farklıysa: ambalaj içeriği = AMBALAJ, hasta sayısı = ADET (96 testten 70 hasta)
 *
 * Kur: satırın kuru çalışmanın genel kurundan farklıysa (NUCLEUS EUR 44,7) tedarikçinin sözleşme kuru olarak yazılır
 *   (StudySupplierRate). Genel kur çalışmada yoksa satırların en çok kullandığı kur yazılır. TL katsayısı değiştirilmez.
 * Tedarikçi açıklamadan bulunur (nucleus, Y-EUROIMMUN, ARKİM, DEKİM - MAGLUMİ...).
 * NUCLEUS satırlarında aynı kit birden çok testte tekrar ediyor: katalog no ile tek malzeme açılır, hepsine bağlanır.
 * Malzeme adına (ya da katalog no'ya) göre eşleşir; ekrandan silinmiş malzeme yeniden açılmaz.
 * Teste bağlama yalnız testin reçetesinde kit kategorisinde malzeme yoksa yapılır (çift kit maliyeti olmasın).
 * Transaction / geri alma çağıranın işidir.
 */
final class PriceImport
{
    public const SHEET = 'MALZEME LİSTESİ';

    private const COL_NAME = 2, COL_PACK = 3, COL_PACK_TYPE = 4, COL_QTY = 5, COL_CURRENCY = 6, COL_RATE = 7, COL_PRICE = 8,
                  COL_NOTE = 9, COL_EXTRA = 10, COL_PRODUCT = 11;
    /** Referans (önceki) Excel: ... H fiyat, I TL birim maliyet */
    private const REF_COL_TL_COST = 9;

    private const CURRENCIES = ['TL' => 'TRY', 'TRY' => 'TRY', 'USD' => 'USD', 'DOLAR' => 'USD', 'EURO' => 'EUR', 'EUR' => 'EUR',
                                'STERLİN' => 'GBP', 'GBP' => 'GBP'];

    /** Açıklamada geçen kelime → tedarikçi adı (sistemde yoksa açılır). Sıra önemli: ilk eşleşen. */
    private const SUPPLIERS = [
        'NUCLEUS' => 'NUCLEUS', 'EUROIMMUN' => 'Y-EUROIMMUN', 'ALGEN' => 'ALGEN', 'ANATOLİA' => 'ANATOLİA GENETİK',
        'ARKİM' => 'ARKİM', 'DEKİM' => 'DEKİM MEDİKAL MAGLUMİ', 'DXI' => 'DXI-MEDKİM', 'İNVOGEN' => 'İNVOGEN',
        'KREON' => 'KREON MED.-LARENTİA', 'SALVOR' => 'SALVOR', 'TOKRA' => 'TOKRA-KOAGULASYON', 'SİBO' => 'SİBO RDA GRUP MEDİKAL',
        'BABİRÜS' => 'BABİRÜS MEDİKAL', 'DEFNE' => 'DEFNE KİMYA', 'SAVAŞ' => 'SAVAŞ MEDİKAL', 'MAKRO MEDİKAL' => 'MAKRO MEDİKAL',
    ];
    /** Fiyatı test başı verilen tedarikçi (kitte ADET 1 olsa da ambalaja bölünmez; uzman teyidi) */
    private const PER_TEST_SUPPLIERS = ['ANATOLİA GENETİK'];

    /**
     * Açıklamadaki "testlerine ekle" talimatları: malzeme → bağlanacak testler (adla). Kalibratör / kontrol / sarf,
     * ambalaj içeriğine bölünerek hasta başına yansır.
     */
    private const EXTRA_LINKS = [
        'SEROCON SPESİFİK IMMÜNASSY KONTROL LEVEL 1' => ['ADACIK (ISLET CELL) ANTİKORU', 'ANDROSTENODİON', 'GLUTAMİK ASİT DEKARBOKSİLAZ ANTİKORU (ANTİ GAD)', 'ALDOSTERON', 'PLAC TEST (LP-PLA2)'],
        'SEROCON SPESİFİK IMMÜNASSY KONTROL LEVEL 2' => ['ADACIK (ISLET CELL) ANTİKORU', 'ANDROSTENODİON', 'GLUTAMİK ASİT DEKARBOKSİLAZ ANTİKORU (ANTİ GAD)', 'ALDOSTERON', 'PLAC TEST (LP-PLA2)'],
        'REAKTİF - G6PDH' => ['G6PDH'], 'KALİBRATÖR - G6PDH' => ['G6PDH'], 'KONTROL - G6PDH' => ['G6PDH'], 'LYSE - G6PDH' => ['G6PDH'],
        'VASELINE OIL - 4 X 50 ML / KUTU' => ['ÜROGENİTAL KÜLTÜR PANELİ'],
        'DİSPOSİBLE SAMPLE CUPS / PROTEİN ELEKTROFEZİ SARF MALZEME' => ['PROTEİN ELEKTROFOREZİ', 'PROTEİN ELEKTROFOREZİ (İDRAR)'],
        'SAS-1 APPLİCATOR / PROTEİN ELEKTROFEZİ SARF MALZEME' => ['PROTEİN ELEKTROFOREZİ', 'PROTEİN ELEKTROFOREZİ (İDRAR)'],
        'KEMTROL ABNORMAL / PROTEİN ELEKTROFEZİ SARF MALZEME' => ['PROTEİN ELEKTROFOREZİ', 'PROTEİN ELEKTROFOREZİ (İDRAR)'],
        'KEMTROL NORMAL / PROTEİN ELEKTROFEZİ SARF MALZEME' => ['PROTEİN ELEKTROFOREZİ', 'PROTEİN ELEKTROFOREZİ (İDRAR)'],
        'DİSPOSİBLE SAMPLE CUPS / IMMUNFİKSASYON ELEKTROFEZİ SARF MALZEME' => ['IMMUNFIKSASYON ELEKTROFOREZİ (İDRAR)'],
        'SAS-1 APPLİCATOR / IMMUNFİKSASYON ELEKTROFEZİ SARF MALZEME' => ['IMMUNFIKSASYON ELEKTROFOREZİ (İDRAR)'],
        // CBC cihazı: Excel'deki "HÜCRE SAYISI / HÜCRE TİPİ" satırları test kataloğunda vücut sıvısı adıyla kayıtlı.
        // HEMOGRAM test kataloğunda yok; eklenince buraya yazılır.
        'CBC - KONTROL KANI' => ['HÜCRE SAYIMI (VÜCUT SIVISI)', 'HÜCRE TİPİ (VÜCUT SIVISI)'],
        'HÜCRE SAYISI' => ['HÜCRE SAYIMI (VÜCUT SIVISI)'],
        'HÜCRE TİPİ' => ['HÜCRE TİPİ (VÜCUT SIVISI)'],
    ];

    private ExcelImport $suggest;
    /** @var array<string, MaterialCategory> */ private array $categories = [];
    /** @var array<string, Material> ad → malzeme */ private array $materials = [];
    /** @var array<string, Material> katalog no → malzeme */ private array $byCatalog = [];
    /** @var array<string, true> */ private array $deleted = [];
    /** @var array<string, Supplier> */ private array $suppliers = [];
    /** @var array<string, list<Test>> test adı → testler */ private array $tests = [];
    /** @var array<string, array> referans Excel: ad → [pack, qty, price, currency, rate, tlCost] */ private array $reference = [];
    /** @var array<string, string> çalışmanın genel kurları */ private array $generalRates = [];
    /** @var array<string, StudySupplierRate> "tedarikçiId|para" → sözleşme kuru */ private array $supplierRates = [];
    /** @var array<int, true> bu aktarımda fiyatı yazılan malzemeler */ private array $priced = [];
    private array $report = [];

    public function __construct(private EntityManagerInterface $em, private Company $company, private Study $study)
    {
        $this->suggest = new ExcelImport($em, $company);
    }

    public function run(XlsxReader $xlsx, ?XlsxReader $referenceXlsx = null): array
    {
        if (!$this->study->isEditable()) {
            throw new \RuntimeException("'{$this->study->getStudyName()}' çalışması onaylanmış; fiyatları değiştirilemez.");
        }
        $rows = $xlsx->sheet(self::SHEET);
        $this->assertHeader($rows, self::COL_NAME, 'MALZEME');
        $this->assertHeader($rows, self::COL_QTY, 'ADET');
        $this->assertHeader($rows, self::COL_PRICE, 'BİRİM MALİYET');
        $this->assertHeader($rows, self::COL_NOTE, 'AÇIKLAMA');

        $this->report = [
            'materials_created' => [], 'prices_created' => 0, 'prices_updated' => 0, 'kits_by_catalog' => [],
            'kits_with_patients' => [], 'from_reference' => 0, 'rule_differs' => [], 'links_created' => [], 'links_skipped' => [],
            'extra_links' => [], 'extra_missing' => [], 'methods_set' => 0, 'notes_written' => [], 'notes_no_test' => [],
            'billable' => [], 'osgb' => [], 'overhead' => [], 'empty' => [], 'no_price_material' => [], 'bad_currency' => [],
            'duplicates' => [], 'deleted' => [], 'rates_set' => [], 'supplier_rates_set' => [], 'rate_conflicts' => [],
            'rate_no_supplier' => [], 'try_rates' => [], 'needs_patients' => [], 'odd' => [], 'unmatched_priced' => 0,
        ];
        $this->load();
        if ($referenceXlsx !== null) { $this->loadReference($referenceXlsx); }

        // 1. Genel kurlar: çalışmada yoksa, tedarikçi kuru olmayan satırların en çok kullandığı kur
        $this->applyGeneralRates($rows);

        $seen = [];
        foreach ($rows as $no => $r) {
            if ($no === 1) { continue; }
            $name = $this->clean($r[self::COL_NAME] ?? '');
            if ($name === '') { continue; }
            $key = $this->key($name);
            $label = "satır {$no}: {$name}";
            $note = $this->joinNote($r);
            $noteKey = $this->upper($note);
            $price = $this->num($r[self::COL_PRICE] ?? null);

            if ($price === null || $price <= 0) {
                $this->unpriced($name, $key, $label, $note, $noteKey);
                continue;
            }
            if (isset($seen[$key])) { $this->report['duplicates'][] = "{$label} (satır {$seen[$key]} ile aynı ad; bu satır geçerli)"; }
            $seen[$key] = $no;
            $this->priced($r, $no, $name, $key, $label, $note, $noteKey, $price);
        }
        $this->em->flush();
        return $this->report;
    }

    /* ---------- Fiyatlı satır ---------- */

    private function priced(array $r, int $no, string $name, string $key, string $label, string $note, string $noteKey, float $price): void
    {
        $currency = self::CURRENCIES[$this->upper($this->clean($r[self::COL_CURRENCY] ?? ''))] ?? null;
        if ($currency === null) { $this->report['bad_currency'][] = "{$label} ('" . ($r[self::COL_CURRENCY] ?? '') . "')"; return; }
        $pack = $this->num($r[self::COL_PACK] ?? null);
        $qty = $this->num($r[self::COL_QTY] ?? null);
        $rate = $this->num($r[self::COL_RATE] ?? null);
        $packType = $this->upper($this->clean($r[self::COL_PACK_TYPE] ?? ''));
        $supplier = $this->supplierFromNote($noteKey);

        // NUCLEUS: katalog no (J) ile tek kit; adı ürün adı (K) + katalog no
        $catalog = null;
        $extra = $this->clean($r[self::COL_EXTRA] ?? '');
        if ($supplier !== null && $supplier->getSupplierName() === 'NUCLEUS' && preg_match('/^\d{6,}$/', $extra)) { $catalog = $extra; }

        if ($catalog === null && isset($this->deleted[$key]) && !isset($this->materials[$key])) {
            $this->report['deleted'][] = "{$label} (ekrandan silinmiş; yeniden açılmadı)";
            return;
        }
        $material = $catalog !== null
            ? $this->catalogMaterial($catalog, $this->clean($r[self::COL_PRODUCT] ?? '') ?: $name, $label)
            : $this->material($name, $packType === 'KİT' ? MaterialCategory::CODE_KIT : null, $label);
        if ($supplier !== null && $material->getSupplier() === null) { $material->setSupplier($supplier); }

        // Bölen
        [$packageQty, $patientQty, $why] = $this->divisor($key, $pack, $qty, $packType, $price, $currency, $rate, $supplier);
        if ($why !== null) { $this->report['odd'][] = "{$label} ({$why})"; }
        if ($patientQty !== null) { $this->report['kits_with_patients'][] = "{$name}: " . $this->fmt($packageQty) . ' test, ' . $this->fmt($patientQty) . ' hasta'; }
        if (str_contains($noteKey, 'HASTA SAYISI')) { $this->report['needs_patients'][] = "{$label} (bir ambalaj kaç hastaya yeter? ekrandan girilecek)"; }

        // Kur
        if ($currency === 'TRY') {
            $k = (string) ($rate ?? 1);
            $this->report['try_rates'][$k] = ($this->report['try_rates'][$k] ?? 0) + 1;
        } elseif ($rate !== null && $rate > 0) {
            $this->supplierRate($currency, $rate, $supplier, $label);
        }

        // Fiyat (aynı malzeme bu aktarımda ikinci kez geliyorsa ilkini ezmez: katalogla birleşen NUCLEUS satırları)
        $mid = spl_object_id($material);
        if (!isset($this->priced[$mid])) {
            $this->priced[$mid] = true;
            $p = $material->getMaterialId() ? $this->em->getRepository(MaterialPrice::class)->findOneBy(['study' => $this->study, 'material' => $material]) : null;
            if ($p === null) {
                $p = new MaterialPrice($this->study, $material, $this->dec($packageQty), $currency, $this->dec($price, 4));
                $this->em->persist($p);
                $this->report['prices_created']++;
            } else {
                $p->setPackageQty($this->dec($packageQty))->setCurrency($currency)->setPackagePrice($this->dec($price, 4));
                $this->report['prices_updated']++;
            }
            $desc = trim(($pack !== null ? 'ambalaj ' . $this->fmt($pack) : '') . ($packType !== '' ? ' · ' . $packType : '')
                . ($qty !== null ? ' · adet ' . $this->fmt($qty) : '') . ($catalog !== null ? ' · kat. ' . $catalog : ''), ' ·');
            $p->setPatientQty($patientQty !== null ? $this->dec($patientQty) : null)
              ->setPackageDesc($desc !== '' ? mb_substr('Excel: ' . $desc, 0, 100) : null)
              ->setNote($note !== '' ? mb_substr($note, 0, 500) : null);
        }

        // Teste bağlama: adı aynı test(ler) + açıklamadaki "ekle" talimatı
        $linked = $this->linkByName($material, $key, $label);
        if (!$linked && $material->getCategory()->getCategoryCode() === MaterialCategory::CODE_KIT) { $this->report['unmatched_priced']++; }
        foreach (self::EXTRA_LINKS[$key] ?? [] as $testName) {
            $tests = $this->tests[$this->key($testName)] ?? [];
            if (!$tests) { $this->report['extra_missing'][] = "{$name} → '{$testName}' testi bulunamadı"; continue; }
            foreach ($tests as $t) {
                if ($this->addRecipeItem($t, $material, false)) { $this->report['extra_links'][] = "{$name} → {$t->getTestCode()} {$t->getTestName()}"; }
            }
        }
    }

    /** [ambalaj içeriği, hasta sayısı | null, uyarı | null] */
    private function divisor(string $key, ?float $pack, ?float $qty, string $packType, float $price, string $currency, ?float $rate, ?Supplier $supplier): array
    {
        $pack = $pack ?? 0.0;
        $qty = $qty ?? 0.0;

        // Kural
        if ($qty > 1 && $pack > 1 && abs($pack - $qty) > 1e-9) {
            $rule = [$pack, $qty];                                  // 96 testten 70 hasta
        } elseif ($qty > 1) {
            $rule = [$qty, null];                                   // ambalaj içeriği ADET (lam kutusu 50, 1 LT = 1000 ml)
        } elseif ($pack > 1 && !($packType === 'KİT' && $supplier !== null && in_array($supplier->getSupplierName(), self::PER_TEST_SUPPLIERS, true))) {
            $rule = [$pack, null];                                  // fiyat ambalajın tamamı
        } else {
            $rule = [1.0, null];                                    // birim / test başı fiyat
        }

        // Referans: satır önceki Excel'de aynıysa bölen Excel'in o zamanki sonucundan
        $ref = $this->reference[$key] ?? null;
        if ($ref !== null && $ref['tlCost'] > 0 && abs($ref['price'] - $price) < 1e-9 && $ref['currency'] === $currency
            && abs(($ref['pack'] ?? 0) - $pack) < 1e-9 && abs(($ref['qty'] ?? 0) - $qty) < 1e-9) {
            $refRate = $ref['rate'] > 0 ? $ref['rate'] : 1.0;
            $d = round($price * $refRate / $ref['tlCost'], 3);
            $ruleDivisor = $rule[1] ?? $rule[0];
            if (abs($d - $ruleDivisor) > max(0.001, $d * 0.005)) {
                $this->report['rule_differs'][] = "{$key}: Excel'in sonucu ÷" . $this->fmt($d) . ", kural ÷" . $this->fmt($ruleDivisor) . ' (Excel\'deki alındı)';
            }
            $this->report['from_reference']++;
            if ($rule[1] !== null && abs($d - $rule[1]) < 1e-6) { return [$rule[0], $rule[1], null]; }
            return [$d, null, $d < 1 ? 'bölen 1\'den küçük: Excel ADET ile çarpıyor; kontrol edin' : null];
        }
        return [$rule[0], $rule[1], null];
    }

    /** Satırın kuru genel kurdan farklıysa tedarikçinin sözleşme kuru olur */
    private function supplierRate(string $currency, float $rate, ?Supplier $supplier, string $label): void
    {
        $general = isset($this->generalRates[$currency]) ? (float) $this->generalRates[$currency] : null;
        if ($general !== null && abs($general - $rate) < 1e-9) { return; }
        if ($supplier === null) {
            $this->report['rate_no_supplier'][] = "{$label} ({$currency} {$rate}; genel kur " . ($general ?? '-') . ' kullanılacak)';
            return;
        }
        $k = spl_object_id($supplier) . '|' . $currency;
        if (!isset($this->supplierRates[$k])) {
            $sr = $supplier->getSupplierId()
                ? $this->em->getRepository(StudySupplierRate::class)->findOneBy(['study' => $this->study, 'supplier' => $supplier, 'currency' => $currency])
                : null;
            if ($sr === null) {
                $sr = new StudySupplierRate($this->study, $supplier, $currency, $this->dec($rate, 4));
                $this->em->persist($sr);
                $this->report['supplier_rates_set'][] = "{$supplier->getSupplierName()}: {$currency} = " . $this->fmt($rate);
            } elseif (abs((float) $sr->getRate() - $rate) > 1e-9) {
                $this->report['rate_conflicts'][] = "{$supplier->getSupplierName()} {$currency}: çalışmada " . (float) $sr->getRate() . ", Excel'de {$rate} (çalışmadaki korundu)";
            }
            $this->supplierRates[$k] = $sr;
        } elseif (abs((float) $this->supplierRates[$k]->getRate() - $rate) > 1e-9) {
            $this->report['rate_conflicts'][] = "{$label}: {$supplier->getSupplierName()} {$currency} için farklı kur {$rate}";
        }
    }

    private function applyGeneralRates(array $rows): void
    {
        foreach ($this->em->getRepository(StudyRate::class)->findBy(['study' => $this->study]) as $r) { $this->generalRates[$r->getCurrency()] = $r->getRate(); }
        $counts = [];
        foreach ($rows as $no => $r) {
            if ($no === 1) { continue; }
            $cur = self::CURRENCIES[$this->upper($this->clean($r[self::COL_CURRENCY] ?? ''))] ?? null;
            $rate = $this->num($r[self::COL_RATE] ?? null);
            if ($cur === null || $cur === 'TRY' || $rate === null || $rate <= 0) { continue; }
            if (str_contains($this->upper($this->joinNote($r)), 'NUCLEUS')) { continue; }   // sözleşme kuru genel kuru belirlemesin
            $counts[$cur][(string) $rate] = ($counts[$cur][(string) $rate] ?? 0) + 1;
        }
        foreach ($counts as $cur => $c) {
            if (isset($this->generalRates[$cur])) { continue; }
            arsort($c);
            $rate = (string) array_key_first($c);
            $this->em->persist(new StudyRate($this->study, $cur, $rate));
            $this->generalRates[$cur] = $rate;
            $this->report['rates_set'][] = "{$cur} = {$rate}";
        }
    }

    /* ---------- Fiyatsız satır ---------- */

    private function unpriced(string $name, string $key, string $label, string $note, string $noteKey): void
    {
        if (str_contains($noteKey, 'BEDELLİ')) {
            if (isset($this->deleted[$key]) && !isset($this->materials[$key])) { $this->report['deleted'][] = $label; return; }
            $m = $this->material($name, 'BILLABLE', $label);
            if ($m->getCategory()->getCategoryCode() !== 'BILLABLE' && !$m->getMaterialId()) { $m->setCategory($this->categories['BILLABLE']); }
            $this->report['billable'][] = $name;
            return;
        }
        if (str_contains($noteKey, 'OSGB')) { $this->report['osgb'][] = $name . ($note !== '' ? " ({$note})" : ''); return; }
        if (str_contains($key, 'GİDERİ')) { $this->report['overhead'][] = $label; return; }
        if ($note === '') { $this->report['empty'][] = $label; return; }

        // Hesap notu: aynı adlı teste
        $tests = $this->tests[$key] ?? [];
        if (!$tests) { $this->report['notes_no_test'][] = "{$label} → {$note}"; return; }
        foreach ($tests as $t) {
            $t->setCostNote(mb_substr($note, 0, 2000));
            $this->report['notes_written'][] = "{$t->getTestCode()} {$t->getTestName()}";
        }
    }

    /* ---------- Teste bağlama ---------- */

    private function linkByName(Material $material, string $key, string $label): bool
    {
        $tests = $this->tests[$key] ?? [];
        $any = false;
        foreach ($tests as $t) {
            if ($this->addRecipeItem($t, $material, true)) {
                $this->report['links_created'][] = "{$t->getTestCode()} {$t->getTestName()} ← {$material->getMaterialName()}";
            }
            $any = true;
        }
        return $any;
    }

    /**
     * Testin reçetesine hasta başı 1 satır. $onlyIfNoKit: test zaten bir kite bağlıysa ekleme (çift kit maliyeti olmasın).
     * Hesaplamalı testlere eklenmez. Yöntemi belirsiz test kit bağlanınca Kit olur.
     */
    private function addRecipeItem(Test $test, Material $material, bool $onlyIfNoKit): bool
    {
        if ($test->getCostMethod() === Test::METHOD_CALCULATED) {
            $this->report['links_skipped'][] = "{$test->getTestCode()}: hesaplamalı test";
            return false;
        }
        $items = $test->getTestId() ? $this->em->getRepository(RecipeItem::class)->findBy(['test' => $test]) : [];
        foreach ($items as $ri) {
            if ($ri->isDeleted()) { continue; }
            if ($ri->getMaterial() === $material) { return false; }
            if ($onlyIfNoKit && $ri->getMaterial()->getCategory()->getCategoryCode() === MaterialCategory::CODE_KIT) {
                $this->report['links_skipped'][] = "{$test->getTestCode()}: zaten kiti var ({$ri->getMaterial()->getMaterialName()})";
                return false;
            }
        }
        foreach ($this->pending[spl_object_id($test)] ?? [] as $m) { if ($m === $material) { return false; } }
        $sort = (count($items) + count($this->pending[spl_object_id($test)] ?? []) + 1) * 10;
        $this->em->persist(new RecipeItem($test, $material, '1', RecipeItem::PER_PATIENT, $sort));
        $this->pending[spl_object_id($test)][] = $material;
        if ($test->getCostMethod() === Test::METHOD_UNDEFINED && $material->getCategory()->getCategoryCode() === MaterialCategory::CODE_KIT) {
            $test->setCostMethod(Test::METHOD_KIT);
            $this->report['methods_set']++;
        }
        return true;
    }
    /** @var array<int, list<Material>> bu aktarımda teste eklenen (henüz flush edilmemiş) malzemeler */
    private array $pending = [];

    /* ---------- Malzeme, tedarikçi ---------- */

    private function material(string $name, ?string $categoryCode, string $label): Material
    {
        $key = $this->key($name);
        if (isset($this->materials[$key])) { return $this->materials[$key]; }
        $code = $categoryCode ?? $this->suggest->categoryFor($name);
        $category = $this->categories[$code] ?? $this->categories[MaterialCategory::CODE_OTHER];
        $m = new Material($name, $category, $this->suggest->unitFor($name, $code));
        $m->setCompany($this->company);
        $this->em->persist($m);
        $this->materials[$key] = $m;
        $this->report['materials_created'][] = "{$label} → {$category->getCategoryName()}";
        return $m;
    }

    private function catalogMaterial(string $catalog, string $productName, string $label): Material
    {
        if (isset($this->byCatalog[$catalog])) { return $this->byCatalog[$catalog]; }
        $name = mb_substr($productName . ' · ' . $catalog, 0, 250);
        $m = $this->materials[$this->key($name)] ?? null;
        if ($m === null) {
            $m = new Material($name, $this->categories[MaterialCategory::CODE_KIT], Material::UNIT_TEST);
            $m->setCompany($this->company);
            $this->em->persist($m);
            $this->materials[$this->key($name)] = $m;
            $this->report['materials_created'][] = "{$label} → Kit (katalog {$catalog})";
        }
        $m->setCatalogNo($catalog);
        $this->byCatalog[$catalog] = $m;
        $this->report['kits_by_catalog'][$catalog] = $name;
        return $m;
    }

    private function supplierFromNote(string $noteKey): ?Supplier
    {
        foreach (self::SUPPLIERS as $word => $supplierName) {
            if (!str_contains($noteKey, $word)) { continue; }
            $k = $this->key($supplierName);
            if (!isset($this->suppliers[$k])) {
                $s = new Supplier($supplierName);
                $s->setCompany($this->company);
                $this->em->persist($s);
                $this->suppliers[$k] = $s;
            }
            return $this->suppliers[$k];
        }
        return null;
    }

    private function load(): void
    {
        $cid = $this->company->getCompanyId();
        foreach ($this->em->getRepository(MaterialCategory::class)->findAll() as $c) { $this->categories[$c->getCategoryCode()] = $c; }
        if (!isset($this->categories['BILLABLE'])) { throw new \RuntimeException("'Bedelli malzeme' kategorisi yok; önce migration'ları uygulayın."); }
        foreach ($this->em->getRepository(Material::class)->findBy(['company' => $cid]) as $m) {
            if ($m->isDeleted()) { $this->deleted[$this->key($m->getMaterialName())] = true; continue; }
            $this->materials[$this->key($m->getMaterialName())] = $m;
            if ($m->getCatalogNo() !== null) { $this->byCatalog[$m->getCatalogNo()] = $m; }
        }
        foreach ($this->em->getRepository(Supplier::class)->findBy(['company' => $cid]) as $s) { $this->suppliers[$this->key($s->getSupplierName())] = $s; }
        foreach ($this->em->getRepository(Test::class)->findBy(['company' => $cid]) as $t) {
            if (!$t->isDeleted()) { $this->tests[$this->key($t->getTestName())][] = $t; }
        }
    }

    /** Önceki Excel (TL BİRİM MALİYET kolonlu): ad → değerler */
    private function loadReference(XlsxReader $x): void
    {
        $rows = $x->sheet(self::SHEET);
        if (!str_contains($this->key($rows[1][self::REF_COL_TL_COST] ?? ''), 'TL BİRİM')) {
            throw new \RuntimeException("Referans Excel'de 'TL BİRİM MALİYET' kolonu (I) yok; önceki aktarımın dosyası verilmeli.");
        }
        foreach ($rows as $no => $r) {
            if ($no === 1) { continue; }
            $name = $this->clean($r[self::COL_NAME] ?? '');
            if ($name === '') { continue; }
            $this->reference[$this->key($name)] = [
                'pack' => $this->num($r[self::COL_PACK] ?? null), 'qty' => $this->num($r[self::COL_QTY] ?? null),
                'price' => $this->num($r[self::COL_PRICE] ?? null) ?? 0.0,
                'currency' => self::CURRENCIES[$this->upper($this->clean($r[self::COL_CURRENCY] ?? ''))] ?? '',
                'rate' => $this->num($r[self::COL_RATE] ?? null) ?? 0.0, 'tlCost' => $this->num($r[self::REF_COL_TL_COST] ?? null) ?? 0.0,
            ];
        }
    }

    /* ---------- Yardımcılar ---------- */

    /** Açıklama (I) + devamı (J; NUCLEUS'ta katalog no olduğundan alınmaz) */
    private function joinNote(array $r): string
    {
        $note = $this->clean($r[self::COL_NOTE] ?? '');
        $note = $note === '#REF!' ? '' : $note;
        $extra = $this->clean($r[self::COL_EXTRA] ?? '');
        if ($extra !== '' && !preg_match('/^\d{6,}$/', $extra)) { $note = trim($note . ' — ' . $extra, ' —'); }
        return $note;
    }

    private function assertHeader(array $rows, int $col, string $expect): void
    {
        if (!str_contains($this->key($rows[1][$col] ?? ''), $this->upper($expect))) {
            throw new \RuntimeException("'" . self::SHEET . "' sayfasının başlığı beklenen yapıda değil (kolon {$col}: '"
                . ($rows[1][$col] ?? '') . "', beklenen: '{$expect}'). Aktarım SIRA | MALZEME | AMBALAJ | ADET TÜRÜ | ADET | "
                . 'PARA BİRİMİ | KUR | BİRİM MALİYET | AÇIKLAMA yapısını bekler.');
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

    private function dec(float $v, int $scale = 3): string { return number_format(round($v, $scale), $scale, '.', ''); }
    private function fmt(float $v): string { return rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ','); }
    private function clean(string $v): string { return trim(preg_replace('/\s+/u', ' ', $v)); }
    private function upper(string $v): string { return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $v), 'UTF-8'); }
    private function key(string $v): string { return $this->upper($this->clean($v)); }
}
