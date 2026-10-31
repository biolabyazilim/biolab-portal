<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\Material;
use BIO\MLYT\MaterialCategory;
use BIO\MLYT\RecipeItem;
use BIO\MLYT\ServiceGroup;
use BIO\MLYT\Supplier;
use BIO\MLYT\Test;
use BIO\MLYT\TestComponent;
use BIO\MNG\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maliyet Excel'inden (mlyt programı.xlsx) test kataloğu ve malzeme listesi aktarımı.
 *   TÜM TEST LİSTESİ → mlyt_service_group, mlyt_supplier, mlyt_test
 *   PATOLOJİ         → mlyt_test (düzey, SUT kodu, malzeme notu) + mlyt_test_component (birleşik testler)
 *   MALZEME LİSTESİ  → mlyt_material (kategori ve birim addan önerilir)
 *   PATOLOJİ notu    → mlyt_recipe_item ("2 TK, 2L, 2 LM, NSİ" → kaset 2, lam 2, lamel 2, kap 1; hasta başına)
 *
 * Tekrar çalıştırılabilir: test koduna / malzeme adına göre eşleşir.
 * Mevcut testte maliyet yöntemi ve tedarikçi yalnızca hâlâ UNDEFINED ise yazılır (ekrandan yapılan seçim korunur);
 * mevcut malzemenin kategorisi / birimi değiştirilmez; reçetesi olan teste reçete yazılmaz.
 * Fiyat aktarılmaz (Excel'de fiyat kolonları boş).
 */
final class ExcelImport
{
    public const SHEET_TESTS     = 'TÜM TEST LİSTESİ';
    public const SHEET_PATHOLOGY = 'PATOLOJİ';
    public const SHEET_MATERIALS = 'MALZEME LİSTESİ';

    /** Aktarılmayan test kodları */
    private const SKIP_CODES = ['XXN'];   // "ÖRNEK" satırı

    /** Excel "Tedarik" → maliyet yöntemi; supplier: false = tedarikçi değil (yöntem bilgisi) */
    private const SUPPLIER_RULES = [
        'HESAPLAMALI TEST'     => [Test::METHOD_CALCULATED, false],
        'MİKROBİYOLOJİ'        => [Test::METHOD_RECIPE, false],
        'MİKROBİYOLOJİ KÜLTÜR' => [Test::METHOD_RECIPE, false],
        'MANUEL TESTLER'       => [Test::METHOD_RECIPE, false],
        'MALZEME SAYFASI'      => [Test::METHOD_RECIPE, false],
        'OSGB'                 => [Test::METHOD_SERVICE, true],
        // Kit mi hizmet alımı mı netleşmedi → ekrandan seçilecek
        'ICP - MS'             => [Test::METHOD_UNDEFINED, true],
        'ANATOLİA GENETİK'     => [Test::METHOD_UNDEFINED, true],
        'MİKROBİYATO'          => [Test::METHOD_UNDEFINED, true],
        'CBC'                  => [Test::METHOD_UNDEFINED, true],
        'AŞI-ÖK'               => [Test::METHOD_UNDEFINED, true],
        'ECZANE'               => [Test::METHOD_UNDEFINED, true],
    ];
    // Listede olmayan dolu değer → KIT + tedarikçi (NUCLEUS, Y-EUROIMMUN, DEKİM MEDİKAL MAGLUMİ...)

    /** Malzeme adı → kategori kodu (sırayla denenir, ilk eşleşen) */
    private const CATEGORY_RULES = [
        'SERVICE'    => ['BAKTERİ TİPLENDİRME', 'SOLUNUM TEST PANELİ', 'CİHAZLI SPERMİOGRAM', 'PEG MALİYETİ', 'KURU BUZ', 'HB ELEKTROFOREZ'],
        'CONTROL'    => ['KONTROL', 'CALİBRATİON', 'KALİBRAT', 'ASSAYYED', '1000UG/ML'],   // 1000ug/ml: ICP-MS standartları
        'MEDIA'      => ['AGAR', 'PLAK', 'PEDRİ', 'BESİYER'],
        'KIT'        => ['KİT', 'TEST', 'ANTİJEN', 'ANTİ-', 'ANTİ ', 'TPHA', 'VDRL', 'MONOSPOT', 'STREP A', 'INFLUENZA', 'RSV', 'BRUCELLA',
                         'SALMONELLA', 'ECHİNOCOCCUS', 'PYLORİ', 'ÜREAPLAZMA', 'NOROVİRÜS', 'ROTA-', 'GİARDİA', 'CAMPYLOBACTER', 'SARS',
                         'AMİP', 'CLOSTRIDIUM', 'VİTAMİN', 'LEVETRACETAM', 'COPPER', 'BİLE ACİDS', 'BAĞIMLILIK', 'A.F. GENİTAL', 'MXA'],
        'CHEMICAL'   => ['BOYA', 'SOLÜSYON', 'SOL.', ' SOL ', 'ASİT', 'KSİLEN', 'EOSİN', 'HEMATOKSİLEN', 'LÜGOL', 'FUCSİN', 'GİEMSA', 'METİLEN',
                         'VİOLE', 'ALKOL', 'FORMALDEHİT', 'ENTELLAN', 'PARAFİN', 'SUDAN', 'MGW', 'DİFF QUİCK', 'PÜRSPERM', 'BENEDİKT', 'LAKTOFENOL',
                         'GRAM BOYAMA', 'ÇÖZELTİ', 'SERUM FİZYOLOJİK', 'LAKTOZ', 'EA 50', 'OG 6', 'TÜBERKÜL',
                         'BUTANOL', 'TRİTON', 'AMONYUM', 'TMAH', 'METANOL', 'NİTRİK'],
        // Antibiyotik diskleri (Bacitracin, Penisilin, SXT...) bilerek yok: "Diğer"e düşer, uzman karar verir
        'CONSUMABLE' => ['TÜP', 'PİPET', 'LAM', 'KAP', 'KABI', 'TORBA', 'VACUETTE', 'EPENDORF', 'ENJEKTÖR', 'ŞIRINGA', 'SWAB', 'KANÜL', 'KASET', 'ÇUBU', 'STRİB', 'FİLM',
                         'CD SARF', 'BONE', 'TURNİKE', 'LANSET', 'FLAKON', 'FIRÇA', 'KAĞIDI', 'VİAL', 'FALCON', 'BIÇAĞI', 'AĞIZLIĞI', 'ŞİŞE',
                         'COPAN', 'MACUN', 'NSİ', 'SPS', 'BYK', 'İDRAR KABI', 'PH METRE', 'DİSK'],
    ];

    /**
     * Patoloji notundaki kısaltma → malzeme adı (MALZEME LİSTESİ'ndeki ad; SIRA no açıklamada).
     * L ve LM için katalogda birden fazla lam / lamel var; patolojinin kullandığı seçildi, gerekirse burada değişir.
     */
    private const PATHOLOGY_MATERIALS = [
        'TK'  => 'DOKU TAKİP KASETİ',                                            // 171
        'L'   => 'LAM 50 Lİ DÜZ LAM /ISOTHERM',                                  // 159
        'LM'  => 'LAMEL 24*60 TEKPATH',                                          // 160
        'NSİ' => 'NSİ(NON STERİL İDRAR KABI)',                                   // 172
        'SPS' => 'SPS(MİNİK PAT. KABI) -15 CC FORMOLLÜ KAP- MİDE BİYOPSİ KABI',  // 173
        'BYK' => 'BYK( BÜYÜK PAT.KABI)',                                         // 174
    ];

    /** @var list<array{0:Test, 1:string}> reçeteye çevrilecek patoloji notları */
    private array $pathologyNotes = [];

    private EntityManagerInterface $em;
    private Company $company;

    /** @var array<string, ServiceGroup> */ private array $groups = [];
    /** @var array<string, Supplier> */     private array $suppliers = [];
    /** @var array<string, Test> */         private array $tests = [];
    /** @var array<string, MaterialCategory> */ private array $categories = [];

    private array $report = [];

    public function __construct(EntityManagerInterface $em, Company $company)
    {
        $this->em = $em;
        $this->company = $company;
    }

    /** Excel'i aktarır, raporu döndürür. Transaction / geri alma çağıranın işidir. */
    public function run(XlsxReader $xlsx): array
    {
        $this->report = [
            'groups_created' => 0, 'suppliers_created' => 0,
            'tests_created' => 0, 'tests_updated' => 0, 'tests_skipped' => [],
            'methods' => [], 'undefined' => [],
            'pathology_updated' => 0, 'pathology_missing' => [],
            'components_created' => 0, 'components_ambiguous' => [], 'components_unknown' => [],
            'materials_created' => 0, 'materials_updated' => 0, 'material_categories' => [], 'materials_other' => [],
            'recipes_created' => 0, 'recipe_items_created' => 0, 'recipes_existing' => 0, 'recipes_unparsed' => [], 'recipe_material_missing' => [],
        ];
        $this->loadExisting();

        $this->importTests($xlsx->sheet(self::SHEET_TESTS));
        $this->em->flush();
        $this->importPathology($xlsx->sheet(self::SHEET_PATHOLOGY));
        $this->em->flush();
        $this->importMaterials($xlsx->sheet(self::SHEET_MATERIALS));
        $this->em->flush();
        $this->importPathologyRecipes();   // malzemeler yüklendikten sonra
        $this->em->flush();

        foreach ($this->tests as $t) {
            $this->report['methods'][$t->getCostMethod()] = ($this->report['methods'][$t->getCostMethod()] ?? 0) + 1;
            if ($t->getCostMethod() === Test::METHOD_UNDEFINED) {
                $this->report['undefined'][] = $t->getTestCode() . ' | ' . $t->getTestName() . ' | ' . ($t->getSourceSupplierText() ?? '(boş)');
            }
        }
        ksort($this->report['methods']);
        return $this->report;
    }

    private function loadExisting(): void
    {
        $cid = $this->company->getCompanyId();
        foreach ($this->em->getRepository(ServiceGroup::class)->findBy(['company' => $cid]) as $g) {
            $this->groups[$this->key($g->getServiceGroupName())] = $g;
        }
        foreach ($this->em->getRepository(Supplier::class)->findBy(['company' => $cid]) as $s) {
            $this->suppliers[$this->key($s->getSupplierName())] = $s;
        }
        foreach ($this->em->getRepository(Test::class)->findBy(['company' => $cid]) as $t) {
            $this->tests[$t->getTestCode()] = $t;
        }
        foreach ($this->em->getRepository(MaterialCategory::class)->findAll() as $c) {
            $this->categories[$c->getCategoryCode()] = $c;
        }
    }

    /* ---------- TÜM TEST LİSTESİ: A Kod | B Adı | C Hizmet Grubu | D Dışlab | E Düzey | F Tedarik ---------- */

    private function importTests(array $rows): void
    {
        $this->assertHeader($rows, 1, 'kod', self::SHEET_TESTS);
        foreach ($rows as $no => $r) {
            if ($no === 1) { continue; }
            $code = $this->clean($r[1] ?? '');
            $name = $this->clean($r[2] ?? '');
            if ($code === '' && $name === '') { continue; }
            if ($code === '' || $name === '' || in_array($code, self::SKIP_CODES, true)) {
                $this->report['tests_skipped'][] = "satır {$no}: " . ($code ?: '(kod yok)') . ' ' . $name;
                continue;
            }
            $groupName = $this->clean($r[3] ?? '') ?: 'TANIMSIZ';
            $labUnit   = $this->key($r[4] ?? '') === 'PATOLOJİ' ? Test::UNIT_PATHOLOGY : Test::UNIT_BIOLAB;
            $supplierText = $this->clean($r[6] ?? '');
            if (str_starts_with($supplierText, '#')) { $supplierText = ''; }   // #N/A gibi formül hatası

            $test = $this->tests[$code] ?? null;
            $isNew = $test === null;
            if ($isNew) {
                $test = new Test($code, $name, $this->group($groupName));
                $test->setCompany($this->company);
                $this->em->persist($test);
                $this->tests[$code] = $test;
                $this->report['tests_created']++;
            } else {
                $test->setTestName($name)->setServiceGroup($this->group($groupName));
                $this->report['tests_updated']++;
            }
            $test->setLabUnit($labUnit)->setSource($supplierText ?: null, $this->clean($r[5] ?? '') ?: null, $test->getSourceMaterialText());

            // Yöntem / tedarikçi: yeni testte ya da hâlâ belirlenmemişse
            if ($isNew || $test->getCostMethod() === Test::METHOD_UNDEFINED) {
                [$method, $supplier] = $this->methodFor($supplierText);
                $test->setCostMethod($method)->setSupplier($supplier);
            }
        }
    }

    /** @return array{0:string, 1:?Supplier} */
    private function methodFor(string $supplierText): array
    {
        if ($supplierText === '') {
            return [Test::METHOD_UNDEFINED, null];
        }
        $rule = self::SUPPLIER_RULES[$this->key($supplierText)] ?? null;
        if ($rule === null) {
            return [Test::METHOD_KIT, $this->supplier($supplierText)];
        }
        return [$rule[0], $rule[1] ? $this->supplier($supplierText) : null];
    }

    /* ---------- PATOLOJİ: A Kod | B Adı | ... | E DÜZEYLER | F HANGİ MALZEME ---------- */

    private function importPathology(array $rows): void
    {
        $this->assertHeader($rows, 1, 'kod', self::SHEET_PATHOLOGY);

        // 1) Tekil testler: düzey, SUT kodu, malzeme notu
        $composites = [];
        foreach ($rows as $no => $r) {
            if ($no === 1) { continue; }
            $code = $this->clean($r[1] ?? '');
            if ($code === '') { continue; }
            $test = $this->tests[$code] ?? null;
            if ($test === null) {
                $this->report['pathology_missing'][] = $code;
                continue;
            }
            $levelText = $this->clean($r[5] ?? '');
            $material  = $this->clean($r[6] ?? '');
            $test->setSource($test->getSourceSupplierText(), $levelText ?: null, $material ?: null);
            $this->report['pathology_updated']++;

            if ($this->key($levelText) === 'HESAPLAMALI') {
                $test->setPathologyLevel(null)->setSutCode(null);
                if ($test->getCostMethod() === Test::METHOD_UNDEFINED) { $test->setCostMethod(Test::METHOD_CALCULATED); }
                $composites[] = $test;
                continue;
            }
            if ($material !== '') { $this->pathologyNotes[] = [$test, $material]; }
            $test->setPathologyLevel(preg_match('/^(\d)\s*\.\s*DÜZEY/u', $levelText, $m) ? (int) $m[1] : null);
            $test->setSutCode(preg_match('/\b(\d{6})\b/', $test->getTestName(), $m) ? $m[1] : null);
            if ($test->getCostMethod() === Test::METHOD_UNDEFINED) {
                // "TEDARİK" yazanlar hizmet alımı olabilir → belirsiz kalır, ekrandan seçilir
                $test->setCostMethod($this->key($material) === 'TEDARİK' ? Test::METHOD_UNDEFINED : Test::METHOD_RECIPE);
            }
        }

        // 2) Birleşik testler: addaki SUT kodları → bileşen (X2 → adet 2)
        $bySut = [];
        foreach ($this->tests as $t) {
            if ($t->getSutCode() !== null) { $bySut[$t->getSutCode()][] = $t; }
        }
        $existing = [];
        foreach ($this->em->getRepository(TestComponent::class)->findAll() as $tc) {
            $existing[$tc->getTest()->getTestCode() . '|' . $tc->getComponentTest()->getTestCode()] = true;
        }
        foreach ($composites as $test) {
            preg_match_all('/(\d{6})\)?\s*(?:[Xx]\s*(\d+))?/u', $test->getTestName(), $mm, PREG_SET_ORDER);
            foreach ($mm as $m) {
                $sut = $m[1];
                $qty = isset($m[2]) && $m[2] !== '' ? $m[2] : '1';
                $candidates = $bySut[$sut] ?? [];
                if (count($candidates) !== 1) {
                    $key = count($candidates) ? 'components_ambiguous' : 'components_unknown';
                    $this->report[$key][] = $test->getTestCode() . ' → ' . $sut
                        . (count($candidates) ? ' (' . implode(' / ', array_map(fn ($c) => $c->getTestCode(), $candidates)) . ')' : '');
                    continue;
                }
                $k = $test->getTestCode() . '|' . $candidates[0]->getTestCode();
                if (isset($existing[$k])) { continue; }
                $this->em->persist(new TestComponent($test, $candidates[0], $qty));
                $existing[$k] = true;
                $this->report['components_created']++;
            }
        }
    }

    /* ---------- PATOLOJİ notu → reçete ---------- */

    /**
     * "2 TK, 2L, 2 LM, NSİ" → kaset 2, lam 2, lamel 2, NSİ kap 1 (adet yoksa 1), hasta başına.
     * Kalıba uymayan notlar (TEDARİK, boya notu, sıvı bazlı sitoloji...) çevrilmez, ekrandan girilir.
     */
    private function importPathologyRecipes(): void
    {
        $withRecipe = array_flip(array_map('intval', $this->em->createQuery("
            SELECT DISTINCT IDENTITY(ri.test) FROM BIO\MLYT\RecipeItem ri WHERE ri.recordFl > 0
        ")->getSingleColumnResult()));

        $materials = [];
        foreach ($this->em->getRepository(Material::class)->findBy(['company' => $this->company->getCompanyId()]) as $m) {
            if (!$m->isDeleted()) { $materials[$this->key($m->getMaterialName())] = $m; }
        }

        foreach ($this->pathologyNotes as [$test, $note]) {
            if ($test->getTestId() !== null && isset($withRecipe[$test->getTestId()])) {
                $this->report['recipes_existing']++;
                continue;
            }
            $lines = [];
            foreach (explode(',', $note) as $part) {
                $part = $this->clean($part);
                if ($part === '') { continue; }
                if (!preg_match('/^(\d+)?\s*(TK|LM|L|NSİ|SPS|BYK)$/u', $this->upper($part), $m)) {
                    $lines = null;
                    break;
                }
                $lines[$m[2]] = ($lines[$m[2]] ?? 0) + ($m[1] !== '' ? (int) $m[1] : 1);
            }
            if (!$lines) {
                $this->report['recipes_unparsed'][] = $test->getTestCode() . ': ' . $note;
                continue;
            }

            $sort = 0;
            $created = 0;
            foreach ($lines as $abbr => $qty) {
                $material = $materials[$this->key(self::PATHOLOGY_MATERIALS[$abbr])] ?? null;
                if ($material === null) {
                    $this->report['recipe_material_missing'][self::PATHOLOGY_MATERIALS[$abbr]] = true;
                    continue;
                }
                $this->em->persist(new RecipeItem($test, $material, (string) $qty, RecipeItem::PER_PATIENT, $sort += 10));
                $created++;
            }
            if ($created) {
                $this->report['recipes_created']++;
                $this->report['recipe_items_created'] += $created;
            }
        }
        $this->report['recipe_material_missing'] = array_keys($this->report['recipe_material_missing']);
    }

    /* ---------- MALZEME LİSTESİ: A SIRA | B MALZEME | ... ---------- */

    private function importMaterials(array $rows): void
    {
        $this->assertHeader($rows, 2, 'malzeme', self::SHEET_MATERIALS);
        $existing = [];
        foreach ($this->em->getRepository(Material::class)->findBy(['company' => $this->company->getCompanyId()]) as $m) {
            if (!$m->isDeleted()) { $existing[$this->key($m->getMaterialName())] = $m; }
        }
        foreach ($rows as $no => $r) {
            if ($no === 1) { continue; }
            $name = $this->clean($r[2] ?? '');
            if ($name === '') { continue; }
            $sira = is_numeric($r[1] ?? null) ? (int) $r[1] : null;

            $material = $existing[$this->key($name)] ?? null;
            if ($material !== null) {
                $material->setSourceRowNo($sira);
                $this->report['materials_updated']++;
                continue;
            }
            $code = $this->categoryFor($name);
            $material = new Material($name, $this->categories[$code], $this->unitFor($name, $code));
            $material->setCompany($this->company)->setSourceRowNo($sira);
            $this->em->persist($material);
            $existing[$this->key($name)] = $material;
            $this->report['materials_created']++;
            $this->report['material_categories'][$code] = ($this->report['material_categories'][$code] ?? 0) + 1;
            if ($code === 'OTHER') { $this->report['materials_other'][] = $name; }
        }
    }

    /** Malzeme adından kategori kodu önerisi (fiyat aktarımı da kullanır) */
    public function categoryFor(string $name): string
    {
        $u = ' ' . $this->upper($name) . ' ';
        foreach (self::CATEGORY_RULES as $code => $words) {
            foreach ($words as $w) {
                if (str_contains($u, $w)) { return $code; }
            }
        }
        return 'OTHER';
    }

    /** Kullanım birimi önerisi: kit → test, sıvı → ml, toz → gr, diğerleri → adet */
    public function unitFor(string $name, string $categoryCode): string
    {
        $u = $this->upper($name);
        if ($categoryCode === 'KIT') { return Material::UNIT_TEST; }
        if (preg_match('/\d\s*GR\b|GR KUTU|\bGR\)/u', $u)) { return Material::UNIT_GR; }
        if ($categoryCode === 'CHEMICAL' && preg_match('/\d\s*(ML|LT|CC)\b|\b1 LT\b/u', $u)) { return Material::UNIT_ML; }
        return Material::UNIT_PIECE;
    }

    /* ---------- Yardımcılar ---------- */

    private function group(string $name): ServiceGroup
    {
        $k = $this->key($name);
        if (!isset($this->groups[$k])) {
            $g = new ServiceGroup($name);
            $g->setCompany($this->company);
            $this->em->persist($g);
            $this->groups[$k] = $g;
            $this->report['groups_created']++;
        }
        return $this->groups[$k];
    }

    private function supplier(string $name): Supplier
    {
        $k = $this->key($name);
        if (!isset($this->suppliers[$k])) {
            $s = new Supplier($name);
            $s->setCompany($this->company);
            $this->em->persist($s);
            $this->suppliers[$k] = $s;
            $this->report['suppliers_created']++;
        }
        return $this->suppliers[$k];
    }

    private function assertHeader(array $rows, int $col, string $expect, string $sheet): void
    {
        $h = mb_strtolower($this->clean($rows[1][$col] ?? ''));
        if (!str_contains($h, $expect)) {
            throw new \RuntimeException("'{$sheet}' sayfasının başlığı beklenen gibi değil (kolon {$col}: '{$h}', beklenen: '{$expect}').");
        }
    }

    /** Baş/son boşluk, satır sonu ve çift boşluk temizlenir */
    private function clean(string $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', $v));
    }

    /** Türkçe büyük harf (i → İ, ı → I) */
    private function upper(string $v): string
    {
        return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $v), 'UTF-8');
    }

    /** Eşleştirme anahtarı: temiz + Türkçe büyük harf */
    private function key(string $v): string
    {
        return $this->upper($this->clean($v));
    }
}
