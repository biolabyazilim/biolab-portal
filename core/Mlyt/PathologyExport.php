<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\Study;
use BIO\MLYT\Test;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Patoloji maliyet Excel'i: maliyet Excel'indeki PATOLOJİ sayfasının düzeninde, canlı formüllerle.
 *
 *   PATOLOJİ           Kod | Adı | Hizmet Grubu | Dışlab | DÜZEYLER | HANGİ MALZEME |
 *                      TAKİP KASET | LAM | LAMEL | NSİ | SPS | BYK | BOYA 1 | BOYA2 | FIRÇA | DİBİ KONİK | FLAKON | DİĞER |
 *                      TOPLAM MALİYET | DURUM | EKSİK
 *     malzeme hücresi = adet × N('MALZEME FİYATLARI'!H{satır})   (aynı kolona düşen birden fazla malzeme toplanır)
 *     TOPLAM          = TOPLA(G:R); birleşik testte bileşen satırlarının toplamı (=S12+S40+2*S88)
 *   MALZEME FİYATLARI  kurlar (B2:B4) + reçetelerde geçen malzemeler; birim maliyet = fiyat × kur ÷ ambalaj içeriği
 *
 * Excel'de bir fiyat ya da kur değiştirilince tüm tablo yeniden hesaplanır. Değerler sistemdeki hesapla
 * (CostCalculator) aynıdır; dosya açılmadan önce de hücrelerde bu değerler yazılıdır.
 */
final class PathologyExport
{
    public const SHEET_MAIN   = 'PATOLOJİ';
    public const SHEET_PRICES = PriceSheet::NAME;

    /**
     * Malzeme kolonları: başlık → malzeme adında aranan kelimeler (sırayla; ilk eşleşen).
     * Eşleşmeyen malzeme DİĞER kolonuna yazılır, böylece toplam eksik kalmaz.
     */
    private const COLUMNS = [
        'TAKİP KASET' => ['KASET'],
        'LAMEL'       => ['LAMEL'],
        'LAM'         => ['LAM'],
        'NSİ'         => ['NSİ', 'NON STERİL'],
        'SPS'         => ['SPS'],
        'BYK'         => ['BYK'],
        'BOYA 1'      => ['HEMATOKS'],
        'BOYA2'       => ['EOSİN'],
        'FLAKON'      => ['FLAKON', 'SIVI BAZLI'],
        'FIRÇA'       => ['FIRÇA'],
        'DİBİ KONİK'  => ['KONİK'],
    ];
    /** Excel'deki kolon sırası (eşleştirme sırası yukarıda, LAMEL LAM'dan önce aranır) */
    private const ORDER = ['TAKİP KASET', 'LAM', 'LAMEL', 'NSİ', 'SPS', 'BYK', 'BOYA 1', 'BOYA2', 'FIRÇA', 'DİBİ KONİK', 'FLAKON', 'DİĞER'];

    private const FIRST_MAT_COL = 7;   // G

    public function __construct(private EntityManagerInterface $em, private int $companyId, private Study $study) {}

    public function fileName(): string
    {
        $name = preg_replace('/[^\pL\pN ._-]+/u', '', $this->study->getStudyName());
        return 'Patoloji maliyet - ' . trim($name) . ' - ' . date('d.m.Y') . '.xlsx';
    }

    public function bytes(): string
    {
        $calc = new CostCalculator($this->em, $this->companyId, $this->study->getStudyId());
        $tests = $this->em->createQuery("
            SELECT t.testId, t.testCode, t.testName, g.serviceGroupName, t.costMethod, t.pathologyLevel, t.sourceLevelText, t.sourceMaterialText
              FROM BIO\MLYT\Test t
              JOIN t.serviceGroup g
             WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0 AND t.activeFl = 'Y' AND t.labUnit = :UNIT
        ")->setParameters(['COMPANY_ID' => $this->companyId, 'UNIT' => Test::UNIT_PATHOLOGY])->getArrayResult();
        $collator = class_exists('Collator') ? new \Collator('tr_TR') : null;
        usort($tests, fn ($a, $b) => $collator ? $collator->compare($a['testName'], $b['testName']) : strcmp($a['testName'], $b['testName']));

        $results = [];
        $materialIds = [];
        foreach ($tests as $t) {
            $results[$t['testId']] = $calc->result((int) $t['testId']);
            foreach ($results[$t['testId']]['lines'] as $l) {
                if ($l['type'] === 'MATERIAL') { $materialIds[$l['materialId']] = true; }
            }
        }

        $w = new XlsxWriter();
        $main = $w->sheet(self::SHEET_MAIN, array_merge([10, 46, 16, 10, 12, 22], array_fill(0, count(self::ORDER), 10), [14, 14, 40]));
        $prices = $w->sheet(self::SHEET_PRICES, [46, 18, 8, 12, 10, 14, 10, 16]);

        $priceRow = PriceSheet::write($this->em, $this->study, $prices, array_keys($materialIds), $calc, self::SHEET_MAIN);

        // PATOLOJİ: önce satır numaraları (birleşik testlerin formülü bileşen satırına bağlanır)
        $rowOf = [];
        foreach ($tests as $i => $t) { $rowOf[$t['testId']] = $i + 2; }

        $header = array_merge(['Kod', 'Adı', 'Hizmet Grubu', 'Dışlab', 'DÜZEYLER', 'HANGİ MALZEME'], self::ORDER, ['TOPLAM MALİYET', 'DURUM', 'EKSİK']);
        $main->row($header, XlsxWriter::HEADER);
        $totalCol = XlsxWriter::col(self::FIRST_MAT_COL + count(self::ORDER));   // S
        $firstMat = XlsxWriter::col(self::FIRST_MAT_COL);                         // G
        $lastMat  = XlsxWriter::col(self::FIRST_MAT_COL + count(self::ORDER) - 1); // R

        foreach ($tests as $t) {
            $res = $results[$t['testId']];
            $r = $rowOf[$t['testId']];
            $isCalc = $t['costMethod'] === Test::METHOD_CALCULATED;

            $cells = [
                $t['testCode'], $t['testName'], $t['serviceGroupName'], 'PATOLOJİ',
                $isCalc ? 'HESAPLAMALI' : ($t['pathologyLevel'] ? $t['pathologyLevel'] . '. DÜZEY' : ($t['sourceLevelText'] ?? '')),
                $t['sourceMaterialText'] ?? '',
            ];

            // Malzeme kolonları: adet × birim maliyet (MALZEME FİYATLARI)
            $byCol = array_fill_keys(self::ORDER, ['terms' => [], 'value' => 0.0, 'any' => false]);
            if (!$isCalc) {
                foreach ($res['lines'] as $l) {
                    $col = $this->columnFor($l['materialName']);
                    // N(): fiyatı olmayan malzemede birim maliyet boş ("") → 0 sayılır (yoksa #DEĞER!)
                    $byCol[$col]['terms'][] = self::qty($l['quantity']) . '*N(' . XlsxWriter::ref(self::SHEET_PRICES, 'H' . $priceRow[$l['materialId']]) . ')';
                    $byCol[$col]['value'] += $l['amount'] ?? 0;
                    $byCol[$col]['any'] = true;
                }
            }
            foreach (self::ORDER as $col) {
                $c = $byCol[$col];
                $cells[] = $c['any'] ? ['f' => implode('+', $c['terms']), 'v' => round($c['value'], 4), 's' => XlsxWriter::MONEY] : ['s' => XlsxWriter::MONEY];
            }

            // Toplam: reçetede kolonların toplamı; birleşik testte bileşen satırları
            if ($isCalc) {
                $terms = [];
                $other = 0.0;
                foreach ($res['lines'] as $l) {
                    $k = $l['quantity'] * $l['applyRate'] / 100;
                    if (isset($rowOf[$l['testId']])) {
                        $terms[] = ($k == 1 ? '' : self::qty($k) . '*') . $totalCol . $rowOf[$l['testId']];
                    } else {
                        $other += ($l['unitCost'] ?? 0) * $k;   // patoloji dışı bileşen: değeri yazılır
                    }
                }
                if ($other) { $terms[] = self::qty(round($other, 4)); }
                $cells[] = $terms ? ['f' => implode('+', $terms), 'v' => $res['cost'], 's' => XlsxWriter::MONEY_TOTAL] : ['s' => XlsxWriter::MONEY_TOTAL];
            } else {
                $cells[] = $res['lines'] ? ['f' => "SUM({$firstMat}{$r}:{$lastMat}{$r})", 'v' => $res['cost'], 's' => XlsxWriter::MONEY_TOTAL] : ['s' => XlsxWriter::MONEY_TOTAL];
            }

            $ok = $res['status'] === CostCalculator::OK;
            $cells[] = ['v' => $calc->statusText($res['status']), 's' => $ok ? XlsxWriter::TEXT : XlsxWriter::WARN];
            $cells[] = ['v' => implode('; ', $res['missing']), 's' => XlsxWriter::TEXT];

            foreach ([0, 1, 2, 3, 4, 5] as $i) {
                $cells[$i] = ['v' => $cells[$i], 's' => XlsxWriter::TEXT];
            }
            $main->row($cells);
        }
        $main->freeze(1)->autoFilter(1, count($header));

        return $w->bytes();
    }

    private function columnFor(string $materialName): string
    {
        $u = mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $materialName), 'UTF-8');
        foreach (self::COLUMNS as $col => $words) {
            foreach ($words as $word) {
                if ($word === 'LAM' ? preg_match('/\bLAM\b/u', $u) : str_contains($u, $word)) { return $col; }
            }
        }
        return 'DİĞER';
    }

    /** Formül içinde sayı: nokta ondalık, gereksiz sıfırsız */
    private static function qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
