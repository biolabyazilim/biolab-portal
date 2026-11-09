<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\Material;
use BIO\MLYT\MaterialCategory;
use BIO\MLYT\RecipeItem;
use BIO\MLYT\Test;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Test maliyeti hesabı (hasta başı, TL). Ekran ve Excel çıktısı aynı motoru kullanır.
 *
 *   malzeme birim maliyeti = ambalaj fiyatı × kur ÷ bölen                (seçili çalışmanın fiyat ve kurları)
 *                            bölen: hasta sayısı (bir ambalajdan çıkan hasta; kit, standart) girilmişse o, yoksa ambalaj içeriği
 *                            TL'nin kuru çalışmanın öngörü katsayısıdır (TRY_FACTOR, varsayılan 1)
 *                            kur: malzemenin tedarikçisinin bu çalışmadaki sözleşme kuru (StudySupplierRate, ör. NUCLEUS EUR 44,7)
 *                            varsa o, yoksa çalışmanın genel kuru
 *                            bedelli malzeme (kategori BILLABLE, bedeli girilmiş): sabit bedel (TL / birim), çalışmadan bağımsız
 *   reçeteli / kitli test  = Σ miktar × birim maliyet                    (mlyt_recipe_item, hasta başına; kit de bir reçete satırıdır)
 *   hesaplamalı test       = Σ bileşen maliyeti × adet × oran ÷ 100     (mlyt_test_component; bileşenler iç içe)
 *
 * Eksik parça varsa maliyet kısmi hesaplanır ve durum ile eksikler döner:
 *   OK, MISSING_PRICE (fiyat / kur yok), NO_RECIPE (reçete ya da bileşen yok), COMPONENT_MISSING (bileşenin hesabı eksik),
 *   NEEDS_VOLUME (gün / hafta / ay başı tüketim: test hacmi gerekiyor, henüz hesaplanmıyor), CYCLE (bileşen döngüsü)
 *
 * Tüm veri dört sorguda yüklenir, hesap bellekte yapılır (1000+ test tek seferde).
 */
final class CostCalculator
{
    public const OK                = 'OK';
    public const MISSING_PRICE     = 'MISSING_PRICE';
    public const NO_RECIPE         = 'NO_RECIPE';
    public const COMPONENT_MISSING = 'COMPONENT_MISSING';
    public const NEEDS_VOLUME      = 'NEEDS_VOLUME';
    public const CYCLE             = 'CYCLE';

    /** Birden fazla sorun varsa gösterilecek durum (öncelik sırası) */
    private const PRIORITY = [self::CYCLE, self::NO_RECIPE, self::COMPONENT_MISSING, self::NEEDS_VOLUME, self::MISSING_PRICE, self::OK];

    private const UNIT_NAMES = [
        Material::UNIT_PIECE => ['adet', 'piece'], Material::UNIT_TEST => ['test', 'test'],
        Material::UNIT_ML    => ['ml', 'ml'],      Material::UNIT_GR   => ['gr', 'g'],
    ];

    /** @var array<int, array{name:string, unit:string, unitCost:?float, reason:?string}> */
    private array $materials = [];
    /** @var array<int, array{testId:int, testCode:string, testName:string, costMethod:string, activeFl:string}> */
    private array $tests = [];
    /** @var array<int, list<array>> */
    private array $items = [];
    /** @var array<int, list<array>> */
    private array $components = [];
    /** @var array<int, array> */
    private array $memo = [];
    /** @var array<int, true> */
    private array $visiting = [];

    public function __construct(
        private EntityManagerInterface $em,
        private int $companyId,
        private ?int $studyId,
        private string $lang = 'tr',
    ) {
        $this->load();
    }

    /** @return array<int, array> testId → sonuç */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->tests) as $id) {
            $out[$id] = $this->result($id);
        }
        return $out;
    }

    /**
     * @return array{testId:int, cost:?float, status:string, missing:list<string>, lines:list<array>}
     *   lines: MATERIAL satırı (malzeme, miktar, birim maliyet, tutar) ya da COMPONENT satırı (bileşen test, adet, oran, maliyet)
     */
    public function result(int $testId): array
    {
        if (isset($this->memo[$testId])) {
            return $this->memo[$testId];
        }
        if (isset($this->visiting[$testId])) {
            return ['testId' => $testId, 'cost' => null, 'status' => self::CYCLE, 'missing' => ['Bileşen döngüsü'], 'lines' => []];
        }
        $this->visiting[$testId] = true;

        $flags = [];
        $missing = [];
        $lines = [];
        $cost = 0.0;
        $method = $this->tests[$testId]['costMethod'] ?? Test::METHOD_UNDEFINED;

        if ($method === Test::METHOD_CALCULATED) {
            foreach ($this->components[$testId] ?? [] as $c) {
                $sub = $this->result($c['componentId']);
                $amount = $sub['cost'] !== null ? $sub['cost'] * $c['quantity'] * $c['applyRate'] / 100 : null;
                if ($sub['status'] !== self::OK) {
                    $flags[] = $sub['status'] === self::CYCLE ? self::CYCLE : self::COMPONENT_MISSING;
                    $missing[] = ($this->tests[$c['componentId']]['testCode'] ?? '?') . ': ' . $this->statusText($sub['status']);
                }
                $cost += $amount ?? 0;
                $lines[] = [
                    'type'      => 'COMPONENT',
                    'testId'    => $c['componentId'],
                    'testCode'  => $this->tests[$c['componentId']]['testCode'] ?? '?',
                    'testName'  => $this->tests[$c['componentId']]['testName'] ?? '',
                    'quantity'  => $c['quantity'],
                    'applyRate' => $c['applyRate'],
                    'unitCost'  => $sub['cost'],
                    'amount'    => $amount,
                    'status'    => $sub['status'],
                ];
            }
            if (!isset($this->components[$testId])) {
                $flags[] = self::NO_RECIPE;
                $missing[] = 'Bileşen tanımlı değil';
            }
        } else {
            foreach ($this->items[$testId] ?? [] as $i) {
                $m = $this->materials[$i['materialId']] ?? ['name' => '?', 'unit' => Material::UNIT_PIECE, 'unitCost' => null, 'reason' => 'Malzeme yok'];
                $amount = null;
                if ($i['consumption'] !== RecipeItem::PER_PATIENT) {
                    $flags[] = self::NEEDS_VOLUME;
                    $missing[] = $m['name'] . ': test hacmi gerekli';
                } elseif ($m['unitCost'] === null) {
                    $flags[] = self::MISSING_PRICE;
                    $missing[] = $m['name'] . ': ' . $m['reason'];
                } else {
                    $amount = $m['unitCost'] * $i['quantity'];
                    $cost += $amount;
                }
                $lines[] = [
                    'type'         => 'MATERIAL',
                    'materialId'   => $i['materialId'],
                    'materialName' => $m['name'],
                    'unitName'     => self::UNIT_NAMES[$m['unit']][$this->lang === 'en' ? 1 : 0] ?? $m['unit'],
                    'quantity'     => $i['quantity'],
                    'consumption'  => $i['consumption'],
                    'unitCost'     => $m['unitCost'],
                    'amount'       => $amount,
                    'reason'       => $m['reason'],
                ];
            }
            if (!isset($this->items[$testId])) {
                $flags[] = self::NO_RECIPE;
                $missing[] = $method === Test::METHOD_KIT ? 'Kit bağlanmamış' : 'Reçete tanımlı değil';
            }
        }

        $status = self::OK;
        foreach (self::PRIORITY as $p) {
            if (in_array($p, $flags, true)) { $status = $p; break; }
        }
        unset($this->visiting[$testId]);

        return $this->memo[$testId] = [
            'testId'  => $testId,
            'cost'    => ($status === self::NO_RECIPE || $status === self::CYCLE) && !$lines ? null : round($cost, 4),
            'status'  => $status,
            'missing' => array_values(array_unique($missing)),
            'lines'   => $lines,
        ];
    }

    /** Malzemenin seçili çalışmadaki birim maliyeti (TL / kullanım birimi); yoksa null */
    public function unitCost(int $materialId): ?float
    {
        return $this->materials[$materialId]['unitCost'] ?? null;
    }

    public function statusText(string $status): string
    {
        $en = $this->lang === 'en';
        return match ($status) {
            self::OK                => $en ? 'Calculated' : 'Hesaplandı',
            self::MISSING_PRICE     => $en ? 'Price missing' : 'Fiyatı eksik',
            self::NO_RECIPE         => $en ? 'No recipe / kit' : 'Reçete / kit yok',
            self::COMPONENT_MISSING => $en ? 'Component incomplete' : 'Bileşeni eksik',
            self::NEEDS_VOLUME      => $en ? 'Volume needed' : 'Hacim gerekli',
            self::CYCLE             => $en ? 'Component cycle' : 'Bileşen döngüsü',
            default                 => $status,
        };
    }

    private function load(): void
    {
        $cid = ['COMPANY_ID' => $this->companyId];

        // TRY'de kur kaydı yok: kuru çalışmanın öngörü katsayısı
        $tryFactor = $this->studyId === null ? 1.0 : (float) ($this->em->createQuery(
            "SELECT s.tryFactor FROM BIO\MLYT\Study s WHERE s.studyId = :STUDY_ID"
        )->setParameter('STUDY_ID', $this->studyId)->getOneOrNullResult()['tryFactor'] ?? 1);

        // Malzemeler + seçili çalışmadaki fiyat ve kur. Malzemenin tedarikçisinin bu çalışmada sözleşme kuru varsa (sr) o,
        // yoksa çalışmanın genel kuru (r)
        $rows = $this->em->createQuery("
            SELECT m.materialId, m.materialName, m.usageUnit, p.packagePrice, p.packageQty, p.patientQty, p.currency,
                   COALESCE(sr.rate, r.rate) AS rate,
                   m.billablePrice, c.categoryCode
              FROM BIO\MLYT\Material m
              JOIN m.category c
              LEFT JOIN BIO\MLYT\MaterialPrice p WITH p.material = m AND p.study = :STUDY_ID
              LEFT JOIN BIO\MLYT\StudyRate r WITH r.study = p.study AND r.currency = p.currency
              LEFT JOIN BIO\MLYT\StudySupplierRate sr WITH sr.study = p.study AND sr.supplier = m.supplier AND sr.currency = p.currency
                                                         AND sr.recordFl > 0
             WHERE IDENTITY(m.company) = :COMPANY_ID AND m.recordFl > 0
        ")->setParameters($cid + ['STUDY_ID' => $this->studyId ?? 0])->getArrayResult();
        foreach ($rows as $r) {
            $unitCost = null;
            $reason = null;
            if ($r['categoryCode'] === MaterialCategory::CODE_BILLABLE && $r['billablePrice'] !== null) {
                $unitCost = (float) $r['billablePrice'];   // bedelli malzeme: sabit bedel
            } elseif ($this->studyId === null) {
                $reason = 'Maliyet çalışması seçilmedi';
            } elseif ($r['packagePrice'] === null) {
                $reason = 'Fiyat girilmemiş';
            } elseif ($r['currency'] !== 'TRY' && $r['rate'] === null) {
                $reason = $r['currency'] . ' kuru yok';
            } elseif ((float) $r['packageQty'] <= 0 && (float) $r['patientQty'] <= 0) {
                $reason = 'Ambalaj içeriği yok';
            } else {
                $rate = $r['currency'] === 'TRY' ? $tryFactor : (float) $r['rate'];
                $divisor = (float) $r['patientQty'] > 0 ? (float) $r['patientQty'] : (float) $r['packageQty'];
                $unitCost = (float) $r['packagePrice'] * $rate / $divisor;
            }
            $this->materials[(int) $r['materialId']] = [
                'name' => $r['materialName'], 'unit' => $r['usageUnit'], 'unitCost' => $unitCost, 'reason' => $reason,
            ];
        }

        foreach ($this->em->createQuery("
            SELECT t.testId, t.testCode, t.testName, t.costMethod, t.activeFl
              FROM BIO\MLYT\Test t
             WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0
        ")->setParameters($cid)->getArrayResult() as $t) {
            $this->tests[(int) $t['testId']] = $t;
        }

        foreach ($this->em->createQuery("
            SELECT IDENTITY(ri.test) AS testId, IDENTITY(ri.material) AS materialId, ri.quantity, ri.consumption
              FROM BIO\MLYT\RecipeItem ri
              JOIN ri.test t
             WHERE IDENTITY(t.company) = :COMPANY_ID AND ri.recordFl > 0 AND ri.activeFl = 'Y'
             ORDER BY ri.sortNo, ri.recipeItemId
        ")->setParameters($cid)->getArrayResult() as $i) {
            $this->items[(int) $i['testId']][] = [
                'materialId' => (int) $i['materialId'], 'quantity' => (float) $i['quantity'], 'consumption' => $i['consumption'],
            ];
        }

        foreach ($this->em->createQuery("
            SELECT IDENTITY(tc.test) AS testId, IDENTITY(tc.componentTest) AS componentId, tc.quantity, tc.applyRate
              FROM BIO\MLYT\TestComponent tc
              JOIN tc.test t
             WHERE IDENTITY(t.company) = :COMPANY_ID AND tc.recordFl > 0 AND tc.activeFl = 'Y'
        ")->setParameters($cid)->getArrayResult() as $c) {
            $this->components[(int) $c['testId']][] = [
                'componentId' => (int) $c['componentId'], 'quantity' => (float) $c['quantity'], 'applyRate' => (float) $c['applyRate'],
            ];
        }
    }
}
