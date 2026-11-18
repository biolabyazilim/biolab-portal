<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\Depot;
use BIO\MLYT\MonthlyPeriod;
use BIO\MLYT\PeriodFile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aylık takip raporu: dönemin yüklenen dosyalarından hesaplanır (Biolab\Core\Mlyt\MonthlyImport yazar).
 *
 *   Tekrar      : LabAsistan test sayıları × testin birim maliyeti (dönemin maliyet çalışması, CostCalculator)
 *                 tekrar maliyeti = tekrar × birim maliyet. Tutar üçe ayrılır:
 *                   sunulabilir      → maliyeti hesaplı, uyarısız testler (alt sınır)
 *                   doğrulanacak     → tekrar oranı %20 ve üzeri ya da test sayısı 0 (laboratuvar "gerçek tekrar mı" bakmalı)
 *                   fiyatı kontrol   → birim maliyet 1.000 TL ve üzeri (kit fiyatı / ambalaj içeriği kontrol edilmeli)
 *   Numune      : kabul edilmeyen + reddedilen listeleri, Örnek No ile tekilleştirilir; tüp = barkod sayısı
 *   Netsis      : depodan çıkan malzeme, giriş deposuna göre (mlyt_depot; 5000+ cari = kurum / doktor)
 *
 * Kayıp (TL) hesaplanmaz: ay sonu stok sayımı yok (Aşama 3).
 */
final class MonthlyReport
{
    public const RATE_LIMIT = 0.20;     // tekrar oranı bu ve üzeri → doğrulanacak
    public const PRICE_LIMIT = 1000.0;  // birim maliyet bu ve üzeri (TL) → fiyatı kontrol edilmeli

    public const FLAG_RATE  = 'RATE';
    public const FLAG_PRICE = 'PRICE';

    public const TYPE_NAMES = [
        Depot::TYPE_CUSTOMER => 'Kurumlar (cari)', Depot::TYPE_OSGB => 'OSGB', Depot::TYPE_BRANCH => 'Şubeler',
        Depot::TYPE_LAB => 'Kahramanlar lab / kan alma', Depot::TYPE_STORE => 'Ana depo', Depot::TYPE_OTHER => 'Diğer',
    ];

    private ?array $repeats = null;
    private ?array $samples = null;
    /** @var array<int, array{name:string, type:string}> */
    private array $depots = [];

    public function __construct(private EntityManagerInterface $em, private MonthlyPeriod $period)
    {
        foreach ($em->createQuery("
            SELECT d.depotCode, d.depotName, d.depotType FROM BIO\MLYT\Depot d
             WHERE d.company = :COMPANY AND d.recordFl > 0
        ")->setParameter('COMPANY', $period->getCompany())->getArrayResult() as $d) {
            $this->depots[(int) $d['depotCode']] = ['name' => $d['depotName'], 'type' => $d['depotType']];
        }
    }

    /* ---------- Dosyalar ---------- */

    /** @return list<array> dönemin dosyaları (kaynak, birim, satır, tarih, uyarı) */
    public function files(): array
    {
        return $this->em->createQuery("
            SELECT f.fileId, f.sourceCode, f.unitName, f.fileName, f.rowCount, f.dateFrom, f.dateTo, f.warnings, f.cAt
              FROM BIO\MLYT\PeriodFile f
             WHERE f.period = :PERIOD AND f.recordFl > 0
             ORDER BY f.sourceCode, f.unitName
        ")->setParameter('PERIOD', $this->period)->getArrayResult();
    }

    /* ---------- Tekrar ---------- */

    /**
     * @return list<array{group:?string, code:string, name:string, testQty:int, repeatQty:int, rate:?float, unitCost:?float,
     *                    amount:?float, status:string, matched:bool, flags:list<string>}>
     */
    public function repeats(): array
    {
        if ($this->repeats !== null) { return $this->repeats; }
        $rows = $this->em->createQuery("
            SELECT c.testGroup, c.testCode, c.testName, c.testQty, c.repeatQty
              FROM BIO\MLYT\LabTestCount c JOIN c.file f
             WHERE f.period = :PERIOD AND f.recordFl > 0
             ORDER BY c.repeatQty DESC
        ")->setParameter('PERIOD', $this->period)->getArrayResult();

        $tests = [];
        foreach ($this->em->createQuery("
            SELECT t.testId, t.testCode FROM BIO\MLYT\Test t
             WHERE t.company = :COMPANY AND t.recordFl > 0 AND t.testCode IS NOT NULL
        ")->setParameter('COMPANY', $this->period->getCompany())->getArrayResult() as $t) {
            $tests[mb_strtoupper(trim($t['testCode']))] = (int) $t['testId'];
        }

        $study = $this->period->getStudy();
        $calc = $study ? new CostCalculator($this->em, (int) $this->period->getCompany()->getCompanyId(), $study->getStudyId()) : null;

        $out = [];
        foreach ($rows as $r) {
            $n = (int) $r['testQty']; $t = (int) $r['repeatQty'];
            $unit = null; $status = 'Uygulamada tanımlı değil'; $matched = isset($tests[$r['testCode']]);
            if ($matched && $calc) {
                $res = $calc->result($tests[$r['testCode']]);
                if ($res['cost'] !== null && $res['cost'] > 0) { $unit = (float) $res['cost']; }
                $status = $calc->statusText($res['status']);
            } elseif ($matched) {
                $status = 'Maliyet çalışması seçilmedi';
            }
            $flags = [];
            if ($t > 0 && ($n === 0 || $t / $n >= self::RATE_LIMIT)) { $flags[] = self::FLAG_RATE; }
            if ($unit !== null && $unit >= self::PRICE_LIMIT) { $flags[] = self::FLAG_PRICE; }
            $out[] = [
                'group' => $r['testGroup'], 'code' => $r['testCode'], 'name' => $r['testName'], 'testQty' => $n, 'repeatQty' => $t,
                'rate' => $n > 0 ? $t / $n : null, 'unitCost' => $unit, 'amount' => $unit !== null ? $unit * $t : null,
                'status' => $status, 'matched' => $matched, 'flags' => $flags,
            ];
        }
        return $this->repeats = $out;
    }

    /** Tekrar tutarları: sunulabilir / doğrulanacak / fiyatı kontrol, hesaplanamayan tekrar adedi */
    public function repeatTotals(): array
    {
        $s = ['present' => 0.0, 'rate' => 0.0, 'price' => 0.0, 'total' => 0.0, 'testQty' => 0, 'repeatQty' => 0,
              'costedRepeat' => 0, 'uncostedRepeat' => 0, 'tests' => 0, 'matchedTests' => 0];
        foreach ($this->repeats() as $r) {
            $s['tests']++; $s['matchedTests'] += $r['matched'] ? 1 : 0;
            $s['testQty'] += $r['testQty']; $s['repeatQty'] += $r['repeatQty'];
            if ($r['repeatQty'] <= 0) { continue; }
            if ($r['amount'] === null) { $s['uncostedRepeat'] += $r['repeatQty']; continue; }
            $s['costedRepeat'] += $r['repeatQty'];
            $s['total'] += $r['amount'];
            if (in_array(self::FLAG_PRICE, $r['flags'], true))     { $s['price'] += $r['amount']; }
            elseif (in_array(self::FLAG_RATE, $r['flags'], true)) { $s['rate'] += $r['amount']; }
            else                                                  { $s['present'] += $r['amount']; }
        }
        return $s;
    }

    /* ---------- Numuneler (kabul edilmeyen / reddedilen) ---------- */

    /** @return array<string, array{arrival:string, institution:string, unit:string, tubes:int, tests:int, notAccepted:bool, rejected:bool, acceptedThenRejected:bool}> */
    public function samples(): array
    {
        if ($this->samples !== null) { return $this->samples; }
        $rows = $this->em->createQuery("
            SELECT s.sampleNo, s.acceptedFl, s.approvedFl, s.arrivalType, s.institution, s.tubeQty, s.testQty, f.sourceCode, f.unitName
              FROM BIO\MLYT\LabSample s JOIN s.file f
             WHERE f.period = :PERIOD AND f.recordFl > 0
        ")->setParameter('PERIOD', $this->period)->getArrayResult();
        $out = [];
        foreach ($rows as $r) {
            $k = $r['sampleNo'];
            $s = $out[$k] ?? ['arrival' => $r['arrivalType'] ?: '(boş)', 'institution' => $r['institution'] ?: '(Kurum belirtilmemiş / hekim)',
                              'unit' => $r['unitName'], 'tubes' => 0, 'tests' => 0, 'notAccepted' => false, 'rejected' => false, 'acceptedThenRejected' => false,
                              'approved' => false];
            if ($r['approvedFl'] === 'Y') { $s['approved'] = true; }
            $s['tubes'] = max($s['tubes'], (int) $r['tubeQty']);
            $s['tests'] = max($s['tests'], (int) $r['testQty']);
            if ($r['sourceCode'] === PeriodFile::LAB_NOT_ACCEPTED) { $s['notAccepted'] = true; }
            if ($r['sourceCode'] === PeriodFile::LAB_REJECTED) { $s['rejected'] = true; if ($r['acceptedFl'] === 'Y') { $s['acceptedThenRejected'] = true; } }
            $out[$k] = $s;
        }
        return $this->samples = $out;
    }

    /**
     * Testleri onaylanmış ama yalnız "kabul edilmeyen" listesinde olan numune: laboratuvar kabul kaydı atlanmış, numune
     * kaybolmamış. Red ve kayıp sayımlarına girmez, ayrıca gösterilir. Reddedilen listesindeyse red sayılır.
     */
    public static function isApprovedOnly(array $s): bool
    {
        return $s['approved'] && !$s['rejected'];
    }

    /** Numuneleri bir alana göre toplar: arrival (geliş tipi), institution (kurum), unit (LabAsistan birimi) */
    public function samplesBy(string $key): array
    {
        $g = [];
        foreach ($this->samples() as $s) {
            if (self::isApprovedOnly($s)) { continue; }
            $k = $s[$key];
            $g[$k] ??= ['name' => $k, 'samples' => 0, 'notAccepted' => 0, 'rejected' => 0, 'acceptedThenRejected' => 0, 'tubes' => 0, 'tests' => 0];
            $g[$k]['samples']++;
            $g[$k]['notAccepted'] += $s['notAccepted'] ? 1 : 0;
            $g[$k]['rejected'] += $s['rejected'] ? 1 : 0;
            $g[$k]['acceptedThenRejected'] += $s['acceptedThenRejected'] ? 1 : 0;
            $g[$k]['tubes'] += $s['tubes'];
            $g[$k]['tests'] += $s['tests'];
        }
        usort($g, fn ($a, $b) => $b['samples'] <=> $a['samples'] ?: strcmp((string) $a['name'], (string) $b['name']));
        return $g;
    }

    public function sampleTotals(): array
    {
        $t = ['samples' => 0, 'notAccepted' => 0, 'rejected' => 0, 'both' => 0, 'acceptedThenRejected' => 0, 'tubes' => 0, 'tests' => 0, 'external' => 0, 'externalTubes' => 0,
              'approved' => 0, 'approvedTubes' => 0];
        foreach ($this->samples() as $s) {
            if (self::isApprovedOnly($s)) { $t['approved']++; $t['approvedTubes'] += $s['tubes']; continue; }
            $t['samples']++;
            $t['notAccepted'] += $s['notAccepted'] ? 1 : 0;
            $t['rejected'] += $s['rejected'] ? 1 : 0;
            $t['both'] += ($s['notAccepted'] && $s['rejected']) ? 1 : 0;
            $t['acceptedThenRejected'] += $s['acceptedThenRejected'] ? 1 : 0;
            $t['tubes'] += $s['tubes'];
            $t['tests'] += $s['tests'];
            if ($s['arrival'] === 'DISLAB') { $t['external']++; $t['externalTubes'] += $s['tubes']; }
        }
        return $t;
    }

    /* ---------- Netsis ---------- */

    /** Giriş kodu → [ad, tür] (5000+ ve listede olmayan cari) */
    public function depot(?int $code): array
    {
        if ($code === null) { return ['name' => '(Giriş deposu yok)', 'type' => Depot::TYPE_OTHER]; }
        if (isset($this->depots[$code])) { return $this->depots[$code]; }
        return $code >= 5000 ? ['name' => 'Kurumlar (cari)', 'type' => Depot::TYPE_CUSTOMER] : ['name' => 'Depo ' . $code, 'type' => Depot::TYPE_OTHER];
    }

    /** Birim (giriş deposu) bazında satır ve fiş sayısı; cari kodlar tek satırda */
    public function netsisByDepot(): array
    {
        // Cari kodlar tek satırda toplandığı için fiş ve malzeme sayıları kod bazında değil, satırlardan tekil sayılır
        $rows = $this->em->createQuery("
            SELECT o.toCode, o.docNo, o.stockCode, COUNT(o.outflowId) AS lines
              FROM BIO\MLYT\NetsisOutflow o JOIN o.file f
             WHERE f.period = :PERIOD AND f.recordFl > 0
             GROUP BY o.toCode, o.docNo, o.stockCode
        ")->setParameter('PERIOD', $this->period)->getArrayResult();
        $g = []; $sets = [];
        foreach ($rows as $r) {
            $d = $this->depot($r['toCode'] === null ? null : (int) $r['toCode']);
            $k = $d['type'] === Depot::TYPE_CUSTOMER ? 'CARI' : (string) $r['toCode'];
            $g[$k] ??= ['code' => $d['type'] === Depot::TYPE_CUSTOMER ? null : $r['toCode'], 'name' => $d['name'], 'type' => $d['type'],
                        'typeName' => self::TYPE_NAMES[$d['type']] ?? $d['type'], 'lines' => 0, 'docs' => 0, 'items' => 0, 'customers' => 0];
            $g[$k]['lines'] += (int) $r['lines'];
            $sets[$k]['docs'][$r['docNo']] = true;
            $sets[$k]['items'][$r['stockCode']] = true;
            $sets[$k]['customers'][(string) $r['toCode']] = true;
        }
        foreach ($g as $k => &$row) {
            $row['docs'] = count($sets[$k]['docs']);
            $row['items'] = count($sets[$k]['items']);
            $row['customers'] = $row['type'] === Depot::TYPE_CUSTOMER ? count($sets[$k]['customers']) : 0;
        }
        unset($row);
        usort($g, fn ($a, $b) => $b['lines'] <=> $a['lines']);
        return array_values($g);
    }

    /**
     * Malzeme bazında çıkış: stok kodu × birim türü (kurum / OSGB / şube / lab / diğer) miktarları, 1. ölçü biriminde.
     * $depotType verilirse yalnız o türe gidenler; $q stok kodu / adında arar.
     */
    public function netsisItems(?string $depotType = null, string $q = ''): array
    {
        $rows = $this->em->createQuery("
            SELECT o.stockCode, MAX(o.stockName) AS stockName, o.unit, o.toCode, SUM(o.quantity) AS qty, COUNT(o.outflowId) AS lines
              FROM BIO\MLYT\NetsisOutflow o JOIN o.file f
             WHERE f.period = :PERIOD AND f.recordFl > 0
             GROUP BY o.stockCode, o.unit, o.toCode
        ")->setParameter('PERIOD', $this->period)->getArrayResult();
        $qn = MonthlyImport::norm($q);
        $g = [];
        foreach ($rows as $r) {
            $type = $this->depot($r['toCode'] === null ? null : (int) $r['toCode'])['type'];
            if ($depotType !== null && $depotType !== '' && $type !== $depotType) { continue; }
            if ($qn !== '' && !str_contains(MonthlyImport::norm($r['stockCode'] . ' ' . $r['stockName']), $qn)) { continue; }
            $k = $r['stockCode'] . '|' . $r['unit'];
            $g[$k] ??= ['stockCode' => $r['stockCode'], 'stockName' => $r['stockName'], 'unit' => $r['unit'], 'total' => 0.0, 'lines' => 0,
                        Depot::TYPE_CUSTOMER => 0.0, Depot::TYPE_OSGB => 0.0, Depot::TYPE_BRANCH => 0.0, Depot::TYPE_LAB => 0.0, Depot::TYPE_OTHER => 0.0];
            $col = isset($g[$k][$type]) && $type !== Depot::TYPE_STORE ? $type : Depot::TYPE_OTHER;
            $g[$k][$col] += (float) $r['qty'];
            $g[$k]['total'] += (float) $r['qty'];
            $g[$k]['lines'] += (int) $r['lines'];
        }
        $g = array_values($g);
        usort($g, fn ($a, $b) => $b['lines'] <=> $a['lines'] ?: strcmp($a['stockCode'], $b['stockCode']));
        return $g;
    }

    /** Netsis özet: satır, fiş, kod sayısı, tarih aralığı */
    public function netsisTotals(): array
    {
        $r = $this->em->createQuery("
            SELECT COUNT(o.outflowId) AS lines, COUNT(DISTINCT o.docNo) AS docs, COUNT(DISTINCT o.stockCode) AS items, MIN(o.docDate) AS dFrom, MAX(o.docDate) AS dTo
              FROM BIO\MLYT\NetsisOutflow o JOIN o.file f
             WHERE f.period = :PERIOD AND f.recordFl > 0
        ")->setParameter('PERIOD', $this->period)->getSingleResult();
        return ['lines' => (int) $r['lines'], 'docs' => (int) $r['docs'], 'items' => (int) $r['items'], 'dateFrom' => $r['dFrom'], 'dateTo' => $r['dTo']];
    }

    /* ---------- Fire / zayi ---------- */

    public const CAT_FIRE     = 'FIRE';       // işin doğasındaki, beklenen kayıp (açık kit stabilitesi)
    public const CAT_ZAYI     = 'ZAYI';       // malzeme / test kullanılamaz hale geldi (kırılma, soğuk zincir, SKT, kontaminasyon…)
    public const CAT_SUPPLIER = 'SUPPLIER';   // tedarikçi kaynaklı (kit kusuru): iade / değişim takibi
    public const CAT_OTHER    = 'OTHER';
    public const CAT_NAMES = [self::CAT_FIRE => 'Fire', self::CAT_ZAYI => 'Zayi', self::CAT_SUPPLIER => 'Tedarikçi kaynaklı', self::CAT_OTHER => 'Diğer'];

    /** Fire bildirimi gelmesi beklenen birimler: şubeler + PCR */
    public const FIRE_UNIT_PCR = 'PCR';

    private ?array $fires = null;

    /** Formdaki sebepten kategori: kullanıcı yalnız sebebi seçer, fire / zayi ayrımı buradan çıkar */
    public static function reasonCategory(string $reason): string
    {
        $r = MonthlyImport::norm($reason);
        if (str_contains($r, 'STABILITE')) { return self::CAT_FIRE; }
        if (str_contains($r, 'KUSUR')) { return self::CAT_SUPPLIER; }
        if (str_starts_with($r, 'DIGER')) { return self::CAT_OTHER; }
        return self::CAT_ZAYI;
    }

    /**
     * Fire / zayi satırları; TL karşılığı malzeme adı uygulamadaki malzemeyle birebir eşleşir ve ölçü kullanım birimiyle
     * aynıysa (Test / Adet / ml) hesaplanır. Kontroller: tedarikçi kaynaklıda lot no; Netsis'te o ay o birime giden
     * miktardan fazla bildirim (yalnız Adet ölçüsünde, stok kodu ve depo kodu varsa).
     * @return list<array>
     */
    public function fires(): array
    {
        if ($this->fires !== null) { return $this->fires; }
        $rows = $this->em->createQuery("
            SELECT r.fireId, r.eventDate, r.unitName, r.depotCode, r.stockCode, r.materialText, r.quantity, r.measure, r.reason,
                   r.runNo, r.affectedTests, r.retestedFl, r.lotNo, r.note
              FROM BIO\MLYT\FireReport r JOIN r.file f
             WHERE f.period = :PERIOD AND f.recordFl > 0
             ORDER BY r.eventDate, r.fireId
        ")->setParameter('PERIOD', $this->period)->getArrayResult();
        if (!$rows) { return $this->fires = []; }

        // Malzeme adı → [id, kullanım birimi] (birebir ad; büyük / küçük harf ve boşluk farkı yok sayılır)
        $mats = [];
        foreach ($this->em->createQuery("
            SELECT m.materialId, m.materialName, m.usageUnit FROM BIO\MLYT\Material m
             WHERE m.company = :COMPANY AND m.recordFl > 0 AND m.mergedInto IS NULL
        ")->setParameter('COMPANY', $this->period->getCompany())->getArrayResult() as $m) {
            $mats[MonthlyImport::norm($m['materialName'])] = [(int) $m['materialId'], $m['usageUnit']];
        }
        $study = $this->period->getStudy();
        $calc = $study ? new CostCalculator($this->em, (int) $this->period->getCompany()->getCompanyId(), $study->getStudyId()) : null;
        $measureUnit = ['TEST' => 'TEST', 'ADET' => 'PIECE', 'ML' => 'ML'];

        // Netsis: bu ay depo × stok kodu çıkışı (AD)
        $out = [];
        foreach ($this->em->createQuery("
            SELECT o.toCode, o.stockCode, SUM(o.quantity) AS qty FROM BIO\MLYT\NetsisOutflow o JOIN o.file f
             WHERE f.period = :PERIOD AND f.recordFl > 0 AND o.unit = 'AD' GROUP BY o.toCode, o.stockCode
        ")->setParameter('PERIOD', $this->period)->getArrayResult() as $o) {
            $out[$o['toCode'] . '|' . $o['stockCode']] = (float) $o['qty'];
        }
        $reported = [];
        foreach ($rows as $r) {
            if ($r['depotCode'] !== null && $r['stockCode'] !== null && mb_strtoupper((string) $r['measure']) === 'ADET') {
                $k = $r['depotCode'] . '|' . $r['stockCode']; $reported[$k] = ($reported[$k] ?? 0) + (float) $r['quantity'];
            }
        }

        $res = [];
        foreach ($rows as $r) {
            $cat = self::reasonCategory($r['reason']);
            $qty = (float) $r['quantity'];
            $unitCost = null; $amount = null; $costNote = 'Malzeme uygulamada eşleşmedi';
            $name = MonthlyImport::norm(preg_replace('/^\S+\s+[–-]\s+/u', '', $r['materialText']));
            $mat = $mats[MonthlyImport::norm($r['materialText'])] ?? $mats[$name] ?? null;
            if ($mat !== null) {
                $mu = $measureUnit[MonthlyImport::norm((string) $r['measure'])] ?? null;
                if (!$calc) { $costNote = 'Maliyet çalışması seçilmedi'; }
                elseif ($mu === null || $mu !== $mat[1]) { $costNote = 'Ölçü (' . ($r['measure'] ?: '-') . ') malzemenin kullanım biriminden farklı'; }
                elseif (($uc = $calc->unitCost($mat[0])) === null) { $costNote = 'Fiyatı eksik'; }
                else { $unitCost = $uc; $amount = $uc * $qty; $costNote = 'Hesaplandı'; }
            }
            $flags = [];
            if ($cat === self::CAT_SUPPLIER && ($r['lotNo'] ?? '') === '') { $flags[] = 'Tedarikçi kaynaklı: lot no yok (iade / değişim için gerekli)'; }
            if ($r['depotCode'] !== null && $r['stockCode'] !== null && mb_strtoupper((string) $r['measure']) === 'ADET') {
                $k = $r['depotCode'] . '|' . $r['stockCode'];
                if (($reported[$k] ?? 0) > ($out[$k] ?? 0)) {
                    $flags[] = 'Bu ay bu birime Netsis\'ten ' . ($out[$k] ?? 0) . ' adet çıkmış; bildirilen toplam ' . $reported[$k] . ' adet (önceki aylardan stok olabilir)';
                }
            }
            $res[] = [
                'fireId' => (int) $r['fireId'], 'date' => $r['eventDate']->format('d.m.Y'), 'unit' => $r['unitName'], 'stockCode' => $r['stockCode'],
                'material' => $r['materialText'], 'quantity' => $qty, 'measure' => $r['measure'], 'reason' => $r['reason'],
                'category' => $cat, 'categoryName' => self::CAT_NAMES[$cat], 'runNo' => $r['runNo'], 'affectedTests' => $r['affectedTests'],
                'retested' => $r['retestedFl'], 'lotNo' => $r['lotNo'], 'note' => $r['note'],
                'unitCost' => $unitCost, 'amount' => $amount, 'costNote' => $costNote, 'warning' => implode('; ', $flags),
            ];
        }
        return $this->fires = $res;
    }

    /** Fire / zayi satırlarını bir alana göre toplar: unit (birim), reason (sebep), categoryName (kategori) */
    public function firesBy(string $key): array
    {
        $g = [];
        foreach ($this->fires() as $r) {
            $k = (string) $r[$key];
            $g[$k] ??= ['name' => $k, 'count' => 0, 'affectedTests' => 0, 'amount' => 0.0, 'uncosted' => 0,
                        self::CAT_FIRE => 0, self::CAT_ZAYI => 0, self::CAT_SUPPLIER => 0, self::CAT_OTHER => 0];
            $g[$k]['count']++;
            $g[$k][$r['category']]++;
            $g[$k]['affectedTests'] += (int) $r['affectedTests'];
            if ($r['amount'] !== null) { $g[$k]['amount'] += $r['amount']; } else { $g[$k]['uncosted']++; }
        }
        usort($g, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
        return $g;
    }

    public function fireTotals(): array
    {
        $t = ['count' => 0, 'amount' => 0.0, 'uncosted' => 0, 'affectedTests' => 0, 'warnings' => 0,
              self::CAT_FIRE => 0, self::CAT_ZAYI => 0, self::CAT_SUPPLIER => 0, self::CAT_OTHER => 0];
        foreach ($this->fires() as $r) {
            $t['count']++; $t[$r['category']]++;
            $t['affectedTests'] += (int) $r['affectedTests'];
            if ($r['amount'] !== null) { $t['amount'] += $r['amount']; } else { $t['uncosted']++; }
            if ($r['warning'] !== '') { $t['warnings']++; }
        }
        $t['units'] = $this->fireUnits();
        return $t;
    }

    /** Bildirim beklenen birimler (şubeler + PCR) ve dosyası gelenler; boş form da "geldi" sayılır */
    public function fireUnits(): array
    {
        $expected = [];
        foreach ($this->depots as $code => $d) { if ($d['type'] === Depot::TYPE_BRANCH) { $expected[] = $code . ' ' . $d['name']; } }
        sort($expected, SORT_NATURAL);
        $expected[] = self::FIRE_UNIT_PCR;
        $got = $this->em->createQuery("
            SELECT f.unitName, f.rowCount FROM BIO\MLYT\PeriodFile f
             WHERE f.period = :PERIOD AND f.sourceCode = :SRC AND f.recordFl > 0
        ")->setParameter('PERIOD', $this->period)->setParameter('SRC', PeriodFile::FIRE_REPORT)->getArrayResult();
        $received = array_column($got, 'rowCount', 'unitName');
        return ['expected' => $expected, 'received' => $received, 'missing' => array_values(array_diff($expected, array_keys($received)))];
    }

    /* ---------- Özet ---------- */

    public function summary(): array
    {
        $study = $this->period->getStudy();
        return [
            'periodYm' => $this->period->getPeriodYm(),
            'study'    => $study ? ['studyId' => $study->getStudyId(), 'studyName' => $study->getStudyName(), 'status' => $study->getStatus()] : null,
            'repeat'   => $this->repeatTotals(),
            'samples'  => $this->sampleTotals(),
            'netsis'   => $this->netsisTotals(),
            'fire'     => $this->fireTotals(),
            'limits'   => ['rate' => self::RATE_LIMIT, 'price' => self::PRICE_LIMIT],
        ];
    }

    /* ---------- Excel ---------- */

    public function fileName(): string
    {
        return 'Aylik_takip_' . $this->period->getPeriodYm() . '.xlsx';
    }

    public function excel(): string
    {
        $W = XlsxWriter::class;
        $w = new XlsxWriter();
        $sum = $this->summary(); $rt = $sum['repeat']; $st = $sum['samples']; $nt = $sum['netsis'];
        $study = $sum['study'];

        $o = $w->sheet('Özet', [58, 18, 70]);
        $o->row(['Aylık takip – ' . $this->period->getPeriodYm()], $W::TITLE);
        $o->row(['Birim maliyetler: ' . ($study ? $study['studyName'] . ' (' . $study['status'] . ')' : 'maliyet çalışması seçilmedi')], $W::TITLE);
        $o->row([]);
        $o->row(['Gösterge', 'Değer', 'Açıklama'], $W::HEADER);
        $o->row(['TEKRAR', null, null], $W::TITLE);
        $o->row(['Tekrar maliyeti – sunulabilir', ['v' => round($rt['present'], 2), 's' => $W::MONEY_TOTAL], 'TL · maliyeti hesaplı, uyarısız testler · alt sınır'], $W::TEXT);
        $o->row(['Tekrar oranı %' . (int) (self::RATE_LIMIT * 100) . ' ve üzeri testler', ['v' => round($rt['rate'], 2), 's' => $W::MONEY], 'TL · laboratuvar "gerçek tekrar mı" diye teyit etmeli'], $W::WARN);
        $o->row(['Birim maliyeti ' . number_format(self::PRICE_LIMIT, 0, ',', '.') . ' TL ve üzeri testler', ['v' => round($rt['price'], 2), 's' => $W::MONEY], 'TL · kit fiyatı / ambalaj içeriği kontrol edilmeli'], $W::WARN);
        $o->row(['Maliyeti hesaplanamayan tekrar adedi', ['v' => $rt['uncostedRepeat'], 's' => $W::NUMBER], 'Tutara dahil değil (reçetesi eksik / uygulamada tanımlı olmayan testler)'], $W::TEXT);
        $o->row(['Not', null, 'LabAsistan tekrarı parametre bazında sayar (1 hemogram tekrarı ~22 satır); toplam tekrar adedi tek başına yorumlanmamalı.'], $W::TEXT);
        $o->row([]);
        $o->row(['RED VE KABUL EDİLMEYEN NUMUNE', null, null], $W::TITLE);
        $o->row(['Tekil numune', ['v' => $st['samples'], 's' => $W::NUMBER], 'Kabul edilmeyen ' . $st['notAccepted'] . ' + reddedilen ' . $st['rejected'] . ' − ikisinde de olan ' . $st['both']], $W::TEXT);
        $o->row(['Boşa giden tüp (barkod)', ['v' => $st['tubes'], 's' => $W::NUMBER], ''], $W::TEXT);
        $o->row(['Yapılamayan test', ['v' => $st['tests'], 's' => $W::NUMBER], ''], $W::TEXT);
        $o->row(['Kabul edildikten sonra reddedilen', ['v' => $st['acceptedThenRejected'], 's' => $W::NUMBER], ''], $W::TEXT);
        $o->row(['Dış Lab (kurum) numunesi', ['v' => $st['external'], 's' => $W::NUMBER], $st['samples'] ? '%' . number_format($st['external'] / $st['samples'] * 100, 1, ',', '.') : ''], $W::TEXT);
        $o->row(['Testleri onaylanmış, kabul kaydı eksik (sayılmadı)', ['v' => $st['approved'], 's' => $W::NUMBER],
            $st['approvedTubes'] . ' tüp · Onay Tarih dolu, yalnız kabul edilmeyen listesinde: numune kaybolmamış, yukarıdaki sayılara dahil değil'], $W::TEXT);
        $o->row([]);
        $o->row(['NETSİS DEPODAN ÇIKIŞ', null, null], $W::TITLE);
        $o->row(['Satır / fiş / malzeme kodu', ['v' => $nt['lines'], 's' => $W::NUMBER], $nt['docs'] . ' fiş, ' . $nt['items'] . ' malzeme kodu'], $W::TEXT);
        $o->row([]);
        $ft = $sum['fire'];
        $o->row(['FİRE / ZAYİ BİLDİRİMİ', null, null], $W::TITLE);
        $o->row(['Bildirim satırı', ['v' => $ft['count'], 's' => $W::NUMBER],
            'Fire ' . $ft[self::CAT_FIRE] . ' · zayi ' . $ft[self::CAT_ZAYI] . ' · tedarikçi kaynaklı ' . $ft[self::CAT_SUPPLIER] . ' · diğer ' . $ft[self::CAT_OTHER]], $W::TEXT);
        $o->row(['TL karşılığı (hesaplanabilen)', ['v' => round($ft['amount'], 2), 's' => $W::MONEY], $ft['uncosted'] . ' satırın TL karşılığı yok (malzeme eşleşmedi ya da ölçü kutu / kit)'], $W::TEXT);
        $o->row(['PCR: boşa giden test', ['v' => $ft['affectedTests'], 's' => $W::NUMBER], 'Etkilenen test sayısı toplamı'], $W::TEXT);
        $o->row(['Dosyası gelmeyen birim', ['v' => count($ft['units']['missing']), 's' => $W::NUMBER], implode(', ', $ft['units']['missing'])],
            $ft['units']['missing'] ? $W::WARN : $W::TEXT);
        $o->row([]);
        $o->row(['Kayıp (TL) hesaplanmadı: ay sonu stok sayımı yok.'], $W::TEXT);

        $fs = $w->sheet('Fire - zayi', [11, 22, 9, 40, 9, 8, 30, 16, 12, 10, 14, 12, 14, 16, 40, 30]);
        $fs->row(['Tarih', 'Birim', 'Stok kodu', 'Malzeme / kit', 'Miktar', 'Ölçü', 'Sebep', 'Kategori', 'Run / plaka', 'Etkilenen test',
                  'Tekrar çalışıldı', 'Lot no', 'Birim maliyet (TL)', 'Tutar (TL)', 'Uyarı', 'Açıklama'], $W::HEADER);
        foreach ($this->fires() as $r) {
            $s = $r['warning'] !== '' ? $W::WARN : $W::TEXT;
            $fs->row([['v' => $r['date'], 's' => $s], ['v' => $r['unit'], 's' => $s], ['v' => $r['stockCode'], 's' => $s], ['v' => $r['material'], 's' => $s],
                ['v' => $r['quantity'], 's' => $W::NUMBER], ['v' => $r['measure'], 's' => $s], ['v' => $r['reason'], 's' => $s], ['v' => $r['categoryName'], 's' => $s],
                ['v' => $r['runNo'], 's' => $s], ['v' => $r['affectedTests'], 's' => $W::NUMBER], ['v' => $r['retested'] === null ? null : ($r['retested'] === 'Y' ? 'Evet' : 'Hayır'), 's' => $s],
                ['v' => $r['lotNo'], 's' => $s], ['v' => $r['unitCost'] !== null ? round($r['unitCost'], 4) : null, 's' => $W::UNIT_COST],
                ['v' => $r['amount'] !== null ? round($r['amount'], 2) : null, 's' => $W::MONEY], ['v' => $r['warning'], 's' => $s], ['v' => $r['note'], 's' => $s]]);
        }
        $fs->freeze(1)->autoFilter(1, 16);

        $t = $w->sheet('Tekrar', [22, 10, 42, 12, 10, 10, 14, 16, 24, 50]);
        $t->row(['Test grubu', 'Kod', 'Test', 'Test sayısı', 'Tekrar', 'Tekrar oranı %', 'Birim maliyet (TL)', 'Tekrar maliyeti (TL)', 'Maliyet durumu', 'Uyarı'], $W::HEADER);
        foreach ($this->repeats() as $r) {
            if ($r['repeatQty'] <= 0) { continue; }
            $warn = self::flagText($r['flags']); $s = $warn !== '' ? $W::WARN : $W::TEXT;
            $t->row([['v' => $r['group'], 's' => $s], ['v' => $r['code'], 's' => $s], ['v' => $r['name'], 's' => $s], ['v' => $r['testQty'], 's' => $W::NUMBER],
                ['v' => $r['repeatQty'], 's' => $W::NUMBER], ['v' => $r['rate'] !== null ? round($r['rate'] * 100, 1) : null, 's' => $W::NUMBER],
                ['v' => $r['unitCost'] !== null ? round($r['unitCost'], 4) : null, 's' => $W::UNIT_COST], ['v' => $r['amount'] !== null ? round($r['amount'], 2) : null, 's' => $W::MONEY],
                ['v' => $r['status'], 's' => $s], ['v' => $warn, 's' => $s]]);
        }
        $t->freeze(1)->autoFilter(1, 10);

        foreach (['arrival' => ['Red - geliş tipi', 'Geliş tipi'], 'institution' => ['Red - kurum', 'Kurum (LabAsistan Bölüm)'], 'unit' => ['Red - birim', 'LabAsistan birimi']] as $key => [$sheet, $label]) {
            $s = $w->sheet($sheet, [46, 12, 16, 12, 22, 12, 14]);
            $s->row([$label, 'Numune', 'Kabul edilmeyen', 'Reddedilen', 'Kabulden sonra reddedilen', 'Tüp', 'Yapılamayan test'], $W::HEADER);
            foreach ($this->samplesBy($key) as $g) {
                $s->row([$g['name'], ['v' => $g['samples'], 's' => $W::NUMBER], ['v' => $g['notAccepted'], 's' => $W::NUMBER], ['v' => $g['rejected'], 's' => $W::NUMBER],
                    ['v' => $g['acceptedThenRejected'], 's' => $W::NUMBER], ['v' => $g['tubes'], 's' => $W::NUMBER], ['v' => $g['tests'], 's' => $W::NUMBER]], $W::TEXT);
            }
            $s->freeze(1);
        }

        $d = $w->sheet('Netsis - birim', [30, 26, 10, 10, 12, 12]);
        $d->row(['Birim (giriş deposu)', 'Tür', 'Satır', 'Fiş', 'Malzeme kodu', 'Cari sayısı'], $W::HEADER);
        foreach ($this->netsisByDepot() as $g) {
            $d->row([$g['name'], $g['typeName'], ['v' => $g['lines'], 's' => $W::NUMBER], ['v' => $g['docs'], 's' => $W::NUMBER], ['v' => $g['items'], 's' => $W::NUMBER],
                ['v' => $g['customers'] ?: null, 's' => $W::NUMBER]], $W::TEXT);
        }
        $d->freeze(1);

        $m = $w->sheet('Netsis - malzeme', [12, 46, 8, 14, 14, 14, 14, 14, 14]);
        $m->row(['Stok kodu', 'Stok adı', 'Birim', 'Kurumlar', 'OSGB', 'Şubeler', 'Kahramanlar lab', 'Diğer', 'Toplam'], $W::HEADER);
        foreach ($this->netsisItems() as $g) {
            $m->row([$g['stockCode'], $g['stockName'], $g['unit'], ['v' => $g[Depot::TYPE_CUSTOMER], 's' => $W::NUMBER], ['v' => $g[Depot::TYPE_OSGB], 's' => $W::NUMBER],
                ['v' => $g[Depot::TYPE_BRANCH], 's' => $W::NUMBER], ['v' => $g[Depot::TYPE_LAB], 's' => $W::NUMBER], ['v' => $g[Depot::TYPE_OTHER], 's' => $W::NUMBER],
                ['v' => $g['total'], 's' => $W::NUMBER]], $W::TEXT);
        }
        $m->freeze(1)->autoFilter(1, 9);

        $n = $w->sheet('Kaynak', [110]);
        $n->row(['YÜKLENEN DOSYALAR'], $W::TITLE);
        foreach ($this->files() as $f) {
            $n->row([self::sourceName($f['sourceCode']) . ($f['unitName'] !== '-' ? ' · ' . $f['unitName'] : '') . ' – ' . $f['fileName'] . ' (' . $f['rowCount'] . ' satır)'], $W::TEXT);
        }
        return $w->bytes();
    }

    /* ---------- Metinler ---------- */

    public static function flagText(array $flags): string
    {
        $t = [];
        if (in_array(self::FLAG_PRICE, $flags, true)) { $t[] = 'Birim maliyet yüksek: kit fiyatı kontrol edilmeli'; }
        if (in_array(self::FLAG_RATE, $flags, true))  { $t[] = 'Tekrar oranı yüksek: laboratuvar teyit etmeli'; }
        return implode('; ', $t);
    }

    public static function sourceName(string $code): string
    {
        return [
            PeriodFile::NETSIS_OUT => 'Netsis depodan çıkan malzeme', PeriodFile::LAB_TEST_COUNT => 'LabAsistan test sayıları',
            PeriodFile::LAB_NOT_ACCEPTED => 'Kabul edilmeyen numuneler', PeriodFile::LAB_REJECTED => 'Reddedilen numuneler',
            PeriodFile::FIRE_REPORT => 'Fire / zayi bildirimi',
        ][$code] ?? $code;
    }
}
