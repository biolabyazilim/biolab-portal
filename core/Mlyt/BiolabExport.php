<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\RecipeItem;
use BIO\MLYT\Study;
use BIO\MLYT\Test;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Biolab maliyet Excel'i: maliyet Excel'indeki TÜM TEST LİSTESİ kolonları + maliyet, canlı formüllerle.
 * Biolab testlerinin malzemeleri testten teste değiştiği için (kit, sarf, kontrol…) patolojideki gibi sabit malzeme
 * kolonları yerine reçete ayrı sayfada satır satır yazılır.
 *
 *   BİOLAB             Kod | Adı | Hizmet Grubu | Dışlab | Tedarik | Yöntem | Malzemeler | TOPLAM MALİYET | DURUM | EKSİK
 *     TOPLAM          = ETOPLA(REÇETE!A:A; Kod; REÇETE!G:G)   (reçeteli / kitli test)
 *                       hesaplamalı testte bileşen satırlarının toplamı (=H12+2*H40); Biolab dışı bileşen değeriyle
 *   REÇETE             Kod | Test | Malzeme | Miktar | Birim | Birim maliyet (TL) | Tutar | Tüketim
 *     Birim maliyet   = N('MALZEME FİYATLARI'!H{satır});  Tutar = Miktar × Birim maliyet (hasta başı satırlar)
 *   MALZEME FİYATLARI  kurlar + reçetelerde geçen malzemeler (PriceSheet, Patoloji Excel'iyle aynı)
 *
 * Excel'de bir fiyat ya da kur değiştirilince tüm tablo yeniden hesaplanır. Değerler sistemdeki hesapla
 * (CostCalculator) aynıdır; dosya açılmadan önce de hücrelerde bu değerler yazılıdır.
 */
final class BiolabExport
{
    public const SHEET_MAIN   = 'BİOLAB';
    public const SHEET_RECIPE = 'REÇETE';

    private const METHOD_NAMES = [
        Test::METHOD_KIT => 'KİT / CİHAZ', Test::METHOD_RECIPE => 'REÇETE', Test::METHOD_SERVICE => 'HİZMET ALIMI',
        Test::METHOD_CALCULATED => 'HESAPLAMALI', Test::METHOD_UNDEFINED => 'BELİRSİZ',
    ];
    private const CONSUMPTION = [RecipeItem::PER_PATIENT => 'hasta başı', RecipeItem::PER_RUN => 'çalışma başı',
        RecipeItem::PER_DAY => 'gün', RecipeItem::PER_WEEK => 'hafta', RecipeItem::PER_MONTH => 'ay'];

    public function __construct(private EntityManagerInterface $em, private int $companyId, private Study $study) {}

    public function fileName(): string
    {
        $name = preg_replace('/[^\pL\pN ._-]+/u', '', $this->study->getStudyName());
        return 'Biolab maliyet - ' . trim($name) . ' - ' . date('d.m.Y') . '.xlsx';
    }

    public function bytes(): string
    {
        $calc = new CostCalculator($this->em, $this->companyId, $this->study->getStudyId());
        $tests = $this->em->createQuery("
            SELECT t.testId, t.testCode, t.testName, g.serviceGroupName, t.costMethod, s.supplierName, t.sourceSupplierText
              FROM BIO\MLYT\Test t
              JOIN t.serviceGroup g
              LEFT JOIN t.supplier s
             WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0 AND t.activeFl = 'Y' AND t.labUnit = :UNIT
        ")->setParameters(['COMPANY_ID' => $this->companyId, 'UNIT' => Test::UNIT_BIOLAB])->getArrayResult();
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
        $main   = $w->sheet(self::SHEET_MAIN, [10, 46, 22, 10, 22, 14, 50, 14, 16, 40]);
        $recipe = $w->sheet(self::SHEET_RECIPE, [10, 40, 46, 10, 8, 14, 14, 12]);
        $prices = $w->sheet(PriceSheet::NAME, [46, 18, 8, 12, 10, 14, 10, 16]);

        $priceRow = PriceSheet::write($this->em, $this->study, $prices, array_keys($materialIds), $calc, self::SHEET_MAIN);

        // REÇETE: hesaplamalı olmayan testlerin malzeme satırları
        $recipe->row(['Kod', 'Test', 'Malzeme', 'Miktar', 'Birim', 'Birim maliyet (TL)', 'Tutar', 'Tüketim'], XlsxWriter::HEADER);
        $lastRecipeRow = 1;
        foreach ($tests as $t) {
            if ($t['costMethod'] === Test::METHOD_CALCULATED) { continue; }
            foreach ($results[$t['testId']]['lines'] as $l) {
                if ($l['type'] !== 'MATERIAL') { continue; }
                $r = $lastRecipeRow + 1;
                $perPatient = $l['consumption'] === RecipeItem::PER_PATIENT;
                $recipe->row([
                    ['v' => $t['testCode'], 's' => XlsxWriter::TEXT],
                    ['v' => $t['testName'], 's' => XlsxWriter::TEXT],
                    ['v' => $l['materialName'], 's' => XlsxWriter::TEXT],
                    ['v' => (float) $l['quantity'], 's' => XlsxWriter::NUMBER],
                    ['v' => $l['unitName'], 's' => XlsxWriter::TEXT],
                    // N(): fiyatı olmayan malzemede birim maliyet boş ("") → 0 sayılır (yoksa #DEĞER!)
                    ['f' => 'N(' . XlsxWriter::ref(PriceSheet::NAME, 'H' . $priceRow[$l['materialId']]) . ')', 'v' => $l['unitCost'] ?? 0, 's' => XlsxWriter::UNIT_COST],
                    // Gün / hafta / ay başı tüketim test hacmi gerektirir: tutar hesaplanmaz (sistemde de öyle)
                    $perPatient ? ['f' => "D{$r}*F{$r}", 'v' => $l['amount'] ?? 0, 's' => XlsxWriter::MONEY] : ['s' => XlsxWriter::MONEY],
                    ['v' => self::CONSUMPTION[$l['consumption']] ?? $l['consumption'], 's' => $perPatient ? XlsxWriter::TEXT : XlsxWriter::WARN],
                ]);
                $lastRecipeRow = $r;
            }
        }
        $recipe->freeze(1)->autoFilter(1, 8);

        // BİOLAB: önce satır numaraları (hesaplamalı testlerin formülü bileşen satırına bağlanır)
        $rowOf = [];
        foreach ($tests as $i => $t) { $rowOf[$t['testId']] = $i + 2; }
        $header = ['Kod', 'Adı', 'Hizmet Grubu', 'Dışlab', 'Tedarik', 'Yöntem', 'Malzemeler', 'TOPLAM MALİYET', 'DURUM', 'EKSİK'];
        $main->row($header, XlsxWriter::HEADER);
        $sumRange = XlsxWriter::ref(self::SHEET_RECIPE, '$A$2:$A$' . max(2, $lastRecipeRow));
        $valRange = XlsxWriter::ref(self::SHEET_RECIPE, '$G$2:$G$' . max(2, $lastRecipeRow));

        foreach ($tests as $t) {
            $res = $results[$t['testId']];
            $r = $rowOf[$t['testId']];
            $isCalc = $t['costMethod'] === Test::METHOD_CALCULATED;

            $parts = [];
            foreach ($res['lines'] as $l) {
                $parts[] = $l['type'] === 'MATERIAL'
                    ? $l['materialName'] . ' ×' . self::qty((float) $l['quantity'])
                    : $l['testCode'] . ' ×' . self::qty((float) $l['quantity']) . ((float) $l['applyRate'] != 100.0 ? ' %' . self::qty((float) $l['applyRate']) : '');
            }

            if ($isCalc) {
                $terms = [];
                $other = 0.0;
                foreach ($res['lines'] as $l) {
                    $k = $l['quantity'] * $l['applyRate'] / 100;
                    if (isset($rowOf[$l['testId']])) {
                        $terms[] = ($k == 1 ? '' : self::qty($k) . '*') . 'H' . $rowOf[$l['testId']];
                    } else {
                        $other += ($l['unitCost'] ?? 0) * $k;   // Biolab dışı (patoloji) bileşen: değeri yazılır
                    }
                }
                if ($other) { $terms[] = self::qty(round($other, 4)); }
                $total = $terms ? ['f' => implode('+', $terms), 'v' => $res['cost'], 's' => XlsxWriter::MONEY_TOTAL] : ['s' => XlsxWriter::MONEY_TOTAL];
            } else {
                $total = $res['lines']
                    ? ['f' => "SUMIF({$sumRange},A{$r},{$valRange})", 'v' => $res['cost'], 's' => XlsxWriter::MONEY_TOTAL]
                    : ['s' => XlsxWriter::MONEY_TOTAL];
            }

            $ok = $res['status'] === CostCalculator::OK;
            $main->row([
                ['v' => $t['testCode'], 's' => XlsxWriter::TEXT],
                ['v' => $t['testName'], 's' => XlsxWriter::TEXT],
                ['v' => $t['serviceGroupName'], 's' => XlsxWriter::TEXT],
                ['v' => 'BİOLAB', 's' => XlsxWriter::TEXT],
                ['v' => $t['supplierName'] ?? $t['sourceSupplierText'], 's' => XlsxWriter::TEXT],
                ['v' => self::METHOD_NAMES[$t['costMethod']] ?? $t['costMethod'], 's' => XlsxWriter::TEXT],
                ['v' => implode('; ', $parts), 's' => XlsxWriter::TEXT],
                $total,
                ['v' => $calc->statusText($res['status']), 's' => $ok ? XlsxWriter::TEXT : XlsxWriter::WARN],
                ['v' => implode('; ', $res['missing']), 's' => XlsxWriter::TEXT],
            ]);
        }
        $main->freeze(1)->autoFilter(1, count($header));

        return $w->bytes();
    }

    /** Formül içinde sayı: nokta ondalık, gereksiz sıfırsız */
    private static function qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
