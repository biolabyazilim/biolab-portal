<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\MaterialCategory;
use BIO\MLYT\MonthlyPeriod;
use BIO\MLYT\PeriodFile;
use BIO\MLYT\Study;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maliyet dashboard'u: seçili maliyet çalışması (ve isteğe bağlı aylık dönem) için özet veriler. Salt okunur.
 *
 *   Veri tamlığı : test maliyet durumu (birim × durum), fiyatı girilen malzeme, kit bağlı test;
 *                  "önce bunları tamamla": dönemde en çok yapılan ama maliyeti olmayan testler (LabAsistan test sayıları)
 *   Maliyet yapısı: test maliyeti dağılımı, en pahalı testler, hizmet grubu ortalaması,
 *                  kur payı (test maliyetlerinin para birimine göre dağılımı; kur hassasiyeti ekranda hesaplanır)
 *   Aylık        : dönemin malzeme maliyeti (test sayısı × birim maliyet) ve Pareto; dönemler boyunca tekrar / red / fire / Netsis
 *
 * Hesap CostCalculator ile; kur payı reçete satırlarının (kit, sarf, kitle birlikte gelen) para biriminden. Hesaplamalı
 * testlerde bileşenlerin dağılımı taşınır. Fiyatı TL girilen ve bedelli malzemeler TRY sayılır.
 */
final class Dashboard
{
    private CostCalculator $calc;
    /** @var array<int, array{code:string, name:string, unit:string, group:string, method:string}> */
    private array $tests = [];
    /** @var array<int, string> malzeme → para birimi */
    private array $currency = [];
    /** @var array<int, array<string,float>> test → para birimi → TL */
    private array $fxMemo = [];
    /** @var array<int, array> */
    private array $results = [];

    public function __construct(private EntityManagerInterface $em, private int $companyId, private Study $study, private ?string $labUnit = null)
    {
        $this->calc = new CostCalculator($em, $companyId, (int) $study->getStudyId());
        foreach ($em->createQuery("
            SELECT t.testId, t.testCode, t.testName, t.labUnit, t.costMethod, g.serviceGroupName
              FROM BIO\MLYT\Test t LEFT JOIN t.serviceGroup g
             WHERE IDENTITY(t.company) = :COMPANY AND t.recordFl > 0
        ")->setParameter('COMPANY', $companyId)->getArrayResult() as $t) {
            $this->tests[(int) $t['testId']] = ['code' => (string) $t['testCode'], 'name' => $t['testName'], 'unit' => $t['labUnit'],
                                                'group' => $t['serviceGroupName'] ?? '(Grup yok)', 'method' => $t['costMethod']];
        }
        foreach ($em->createQuery("
            SELECT IDENTITY(p.material) AS materialId, p.currency, c.categoryCode
              FROM BIO\MLYT\MaterialPrice p JOIN p.material m LEFT JOIN m.category c
             WHERE p.study = :STUDY AND p.recordFl > 0
        ")->setParameter('STUDY', $study)->getArrayResult() as $p) {
            $this->currency[(int) $p['materialId']] = $p['categoryCode'] === MaterialCategory::CODE_BILLABLE ? 'TRY' : ($p['currency'] ?: 'TRY');
        }
    }

    /** Birim filtresine uyan testlerin sonuçları */
    private function results(): array
    {
        if ($this->results) { return $this->results; }
        foreach ($this->tests as $id => $t) {
            if ($this->labUnit !== null && $t['unit'] !== $this->labUnit) { continue; }
            $this->results[$id] = $this->calc->result($id);
        }
        return $this->results;
    }

    /** Testin maliyetinin para birimine göre dağılımı (TL); hesaplamalı testte bileşenlerden taşınır */
    private function fx(int $testId, int $depth = 0): array
    {
        if (isset($this->fxMemo[$testId])) { return $this->fxMemo[$testId]; }
        if ($depth > 6) { return []; }
        $out = [];
        foreach ($this->calc->result($testId)['lines'] as $l) {
            if (($l['amount'] ?? null) === null) { continue; }
            if ($l['type'] === 'COMPONENT') {
                $sub = $this->fx((int) $l['testId'], $depth + 1);
                $subTotal = array_sum($sub);
                foreach ($sub as $cur => $v) { $out[$cur] = ($out[$cur] ?? 0) + ($subTotal > 0 ? $v / $subTotal * $l['amount'] : 0); }
            } else {
                $cur = $this->currency[(int) $l['materialId']] ?? 'TRY';
                $out[$cur] = ($out[$cur] ?? 0) + $l['amount'];
            }
        }
        return $this->fxMemo[$testId] = $out;
    }

    /* ---------- Veri tamlığı ---------- */

    public function completeness(): array
    {
        $byUnit = []; $ok = 0; $n = 0;
        foreach ($this->results() as $id => $r) {
            $u = $this->tests[$id]['unit'];
            $byUnit[$u][$r['status']] = ($byUnit[$u][$r['status']] ?? 0) + 1;
            $n++; $ok += $r['status'] === CostCalculator::OK ? 1 : 0;
        }
        $total = (int) $this->em->createQuery("
            SELECT COUNT(m.materialId) FROM BIO\MLYT\Material m
             WHERE IDENTITY(m.company) = :COMPANY AND m.recordFl > 0 AND m.mergedInto IS NULL
        ")->setParameter('COMPANY', $this->companyId)->getSingleScalarResult();
        $priced = (int) $this->em->createQuery("
            SELECT COUNT(DISTINCT m.materialId) FROM BIO\MLYT\MaterialPrice p JOIN p.material m
             WHERE p.study = :STUDY AND p.recordFl > 0 AND p.packagePrice > 0 AND m.recordFl > 0 AND m.mergedInto IS NULL
        ")->setParameter('STUDY', $this->study)->getSingleScalarResult();
        $mat = ['total' => $total, 'priced' => $priced];
        $statuses = [];
        foreach ([CostCalculator::OK, CostCalculator::MISSING_PRICE, CostCalculator::COMPONENT_MISSING, CostCalculator::NEEDS_VOLUME, CostCalculator::NO_RECIPE, CostCalculator::CYCLE] as $s) {
            $statuses[$s] = $this->calc->statusText($s);
        }
        return ['tests' => $n, 'costed' => $ok, 'byUnit' => $byUnit, 'statuses' => $statuses,
                'materials' => (int) $mat['total'], 'pricedMaterials' => (int) $mat['priced']];
    }

    /** Dönemde en çok yapılan ama maliyeti hesaplanamayan testler (LabAsistan test sayıları) */
    public function todo(?MonthlyPeriod $period, int $limit = 15): array
    {
        $counts = $period ? $this->periodCounts($period) : [];
        $byCode = [];
        foreach ($this->tests as $id => $t) { $byCode[mb_strtoupper($t['code'])] = $id; }
        $rows = [];
        foreach ($this->results() as $id => $r) {
            if ($r['status'] === CostCalculator::OK) { continue; }
            $t = $this->tests[$id];
            $vol = $counts[mb_strtoupper($t['code'])] ?? 0;
            $rows[] = ['testId' => $id, 'code' => $t['code'], 'name' => $t['name'], 'unit' => $t['unit'], 'volume' => $vol,
                       'status' => $r['status'], 'statusText' => $this->calc->statusText($r['status']), 'missing' => array_slice($r['missing'], 0, 2)];
        }
        usort($rows, fn ($a, $b) => $b['volume'] <=> $a['volume'] ?: strcmp($a['code'], $b['code']));
        return array_slice($rows, 0, $limit);
    }

    /* ---------- Maliyet yapısı ---------- */

    public function structure(): array
    {
        $costs = []; $groups = []; $fxTot = [];
        foreach ($this->results() as $id => $r) {
            if ($r['status'] !== CostCalculator::OK || $r['cost'] === null) { continue; }
            $t = $this->tests[$id];
            $costs[] = ['testId' => $id, 'code' => $t['code'], 'name' => $t['name'], 'unit' => $t['unit'], 'cost' => (float) $r['cost']];
            $groups[$t['group']][] = (float) $r['cost'];
            foreach ($this->fx($id) as $cur => $v) { $fxTot[$cur] = ($fxTot[$cur] ?? 0) + $v; }
        }
        $vals = array_column($costs, 'cost'); sort($vals); $n = count($vals);
        $buckets = ['10 TL altı' => [0, 10], '10–50' => [10, 50], '50–100' => [50, 100], '100–500' => [100, 500], '500 TL üstü' => [500, INF]];
        $dist = [];
        foreach ($buckets as $k => [$lo, $hi]) { $dist[] = ['label' => $k, 'count' => count(array_filter($vals, fn ($v) => $v >= $lo && $v < $hi))]; }
        usort($costs, fn ($a, $b) => $b['cost'] <=> $a['cost']);
        $g = [];
        foreach ($groups as $name => $v) { $g[] = ['name' => $name, 'count' => count($v), 'avg' => array_sum($v) / count($v)]; }
        usort($g, fn ($a, $b) => $b['count'] <=> $a['count']);
        $fxSum = array_sum($fxTot);
        arsort($fxTot);
        return [
            'count' => $n,
            'median' => $n ? $vals[intdiv($n, 2)] : null,
            'p90' => $n ? $vals[min($n - 1, (int) floor($n * 0.9))] : null,
            'distribution' => $dist,
            'top' => array_slice($costs, 0, 10),
            'groups' => array_slice($g, 0, 12),
            // Test maliyetlerinin (eşit ağırlık: her test bir kez) para birimine göre payı; kur hassasiyeti ekranda
            'fx' => array_map(fn ($cur, $v) => ['currency' => $cur, 'amount' => $v, 'share' => $fxSum > 0 ? $v / $fxSum : 0], array_keys($fxTot), $fxTot),
            'rates' => $this->em->getConnection()->fetchAllKeyValue("SELECT CURRENCY, RATE FROM mlyt_study_rate WHERE STUDY_ID = ? AND D_AT IS NULL", [$this->study->getStudyId()]),
        ];
    }

    /* ---------- Aylık ---------- */

    /** Dönemin test sayıları: Entegrasyon kod → test sayısı (tekrar hariç) */
    private function periodCounts(MonthlyPeriod $period): array
    {
        $out = [];
        foreach ($this->em->createQuery("
            SELECT c.testCode, c.testQty FROM BIO\MLYT\LabTestCount c JOIN c.file f
             WHERE f.period = :PERIOD AND f.recordFl > 0
        ")->setParameter('PERIOD', $period)->getArrayResult() as $r) {
            $out[mb_strtoupper($r['testCode'])] = (int) $r['testQty'];
        }
        return $out;
    }

    /** Dönemin malzeme maliyeti: test sayısı × birim maliyet; Pareto (en büyük 20) ve kur payı (hacim ağırlıklı) */
    public function monthlyCost(MonthlyPeriod $period): array
    {
        $counts = $this->periodCounts($period);
        if (!$counts) { return ['hasData' => false]; }
        $byCode = [];
        foreach ($this->tests as $id => $t) { $byCode[mb_strtoupper($t['code'])] = $id; }
        $rows = []; $total = 0.0; $volCosted = 0; $volAll = 0; $fxTot = [];
        $results = $this->results();
        foreach ($counts as $code => $qty) {
            $volAll += $qty;
            $id = $byCode[$code] ?? null;
            if ($id === null || !isset($results[$id]) || $results[$id]['status'] !== CostCalculator::OK) { continue; }
            $amount = (float) $results[$id]['cost'] * $qty;
            if ($amount <= 0) { continue; }
            $volCosted += $qty; $total += $amount;
            $rows[] = ['code' => $this->tests[$id]['code'], 'name' => $this->tests[$id]['name'], 'qty' => $qty, 'unitCost' => (float) $results[$id]['cost'], 'amount' => $amount,
                       'check' => (float) $results[$id]['cost'] >= MonthlyReport::PRICE_LIMIT];   // birim maliyet yüksek: fiyat kontrol edilmeli
            foreach ($this->fx($id) as $cur => $v) { $fxTot[$cur] = ($fxTot[$cur] ?? 0) + $v * $qty; }
        }
        usort($rows, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $cum = 0.0; $top = [];
        foreach (array_slice($rows, 0, 20) as $r) { $cum += $r['amount']; $r['cumShare'] = $total > 0 ? $cum / $total : 0; $top[] = $r; }
        $fxSum = array_sum($fxTot); arsort($fxTot);
        return [
            'hasData' => true, 'periodYm' => $period->getPeriodYm(), 'total' => $total, 'tests' => count($rows),
            'volumeCosted' => $volCosted, 'volumeAll' => $volAll, 'pareto' => $top,
            'fx' => array_map(fn ($cur, $v) => ['currency' => $cur, 'amount' => $v, 'share' => $fxSum > 0 ? $v / $fxSum : 0], array_keys($fxTot), $fxTot),
        ];
    }

    /** Dönemler boyunca aylık takip özeti (çalışmanın birim maliyetleriyle değil, her dönemin kendi çalışmasıyla) */
    public function trend(): array
    {
        $periods = $this->em->createQuery("
            SELECT p FROM BIO\MLYT\MonthlyPeriod p WHERE IDENTITY(p.company) = :COMPANY AND p.recordFl > 0 ORDER BY p.periodYm
        ")->setParameter('COMPANY', $this->companyId)->getResult();
        $out = [];
        foreach (array_slice($periods, -12) as $p) {
            $rep = new MonthlyReport($this->em, $p);
            $s = $rep->summary();
            $tubes = ['CUSTOMER' => 0.0, 'OSGB' => 0.0, 'BRANCH' => 0.0];
            foreach ($rep->netsisItems() as $it) {
                if ($it['unit'] !== 'AD' || !preg_match('/T[UÜ]P/u', mb_strtoupper($it['stockName']))) { continue; }
                foreach ($tubes as $k => $_) { $tubes[$k] += $it[$k]; }
            }
            $files = array_count_values(array_column($rep->files(), 'sourceCode'));
            $out[] = [
                'periodYm' => $p->getPeriodYm(), 'repeatPresent' => $s['repeat']['present'], 'repeatCheck' => $s['repeat']['rate'] + $s['repeat']['price'],
                'samples' => $s['samples']['samples'], 'tubes' => $s['samples']['tubes'], 'fire' => $s['fire']['count'],
                'fireAmount' => $s['fire']['amount'], 'netsisTubes' => $tubes,
                'has' => ['netsis' => isset($files[PeriodFile::NETSIS_OUT]), 'tests' => isset($files[PeriodFile::LAB_TEST_COUNT]),
                          'samples' => isset($files[PeriodFile::LAB_NOT_ACCEPTED]) || isset($files[PeriodFile::LAB_REJECTED]), 'fire' => isset($files[PeriodFile::FIRE_REPORT])],
            ];
        }
        return $out;
    }
}
