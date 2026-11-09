<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\Study;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maliyet Excel'lerinin ortak "MALZEME FİYATLARI" sayfası (Patoloji ve Biolab Excel'i).
 *
 *   A1 başlık · B2 USD · B3 EUR · B4 GBP · B5 TL öngörü katsayısı
 *   Malzeme | Kategori | Birim | Ambalaj içeriği | Para birimi | Ambalaj fiyatı | Kur | Birim maliyet (TL) | Hasta sayısı
 *     Kur: tedarikçi sözleşme kuru varsa sabit, yoksa B2:B5'e bağlı formül
 *     Birim maliyet (H) = fiyat × kur ÷ (hasta sayısı varsa o, yoksa ambalaj içeriği); ana sayfa bu hücreye bağlanır
 */
final class PriceSheet
{
    public const NAME = 'MALZEME FİYATLARI';

    /**
     * @param list<int> $materialIds  ana sayfada geçen malzemeler
     * @return array<int, int>        malzemeId → satır no (H{satır} birim maliyet)
     */
    public static function write(EntityManagerInterface $em, Study $study, XlsxSheet $s, array $materialIds, CostCalculator $calc, string $mainSheet): array
    {
        $rates = ['USD' => null, 'EUR' => null, 'GBP' => null];
        foreach ($em->createQuery("SELECT r.currency, r.rate FROM BIO\MLYT\StudyRate r WHERE r.study = :STUDY")
                    ->setParameter('STUDY', $study)->getArrayResult() as $r) {
            $rates[$r['currency']] = (float) $r['rate'];
        }
        $s->row(['Maliyet çalışması: ' . $study->getStudyName() . ' · kur tarihi ' . $study->getRateDate()->format('d.m.Y')], XlsxWriter::TITLE);
        $s->row([['v' => 'USD', 's' => XlsxWriter::TEXT], ['v' => $rates['USD'], 's' => XlsxWriter::UNIT_COST]]);   // B2
        $s->row([['v' => 'EUR', 's' => XlsxWriter::TEXT], ['v' => $rates['EUR'], 's' => XlsxWriter::UNIT_COST]]);   // B3
        $s->row([['v' => 'GBP', 's' => XlsxWriter::TEXT], ['v' => $rates['GBP'], 's' => XlsxWriter::UNIT_COST]]);   // B4
        $tryFactor = (float) $study->getTryFactor();
        $s->row([['v' => 'TL öngörü katsayısı', 's' => XlsxWriter::TEXT], ['v' => $tryFactor, 's' => XlsxWriter::UNIT_COST]]);   // B5
        $s->row(['Fiyat ya da kur değiştirilirse ' . $mainSheet . ' sayfası kendiliğinden yeniden hesaplanır.']);
        $header = $s->row(['Malzeme', 'Kategori', 'Birim', 'Ambalaj içeriği', 'Para birimi', 'Ambalaj fiyatı', 'Kur', 'Birim maliyet (TL)', 'Hasta sayısı'], XlsxWriter::HEADER);

        $rows = [];
        if ($materialIds) {
            $rows = $em->createQuery("
                SELECT m.materialId, m.materialName, m.usageUnit, m.billablePrice, c.categoryCode, c.categoryName,
                       p.packageQty, p.patientQty, p.currency, p.packagePrice, sr.rate AS supplierRate
                  FROM BIO\MLYT\Material m
                  JOIN m.category c
                  LEFT JOIN BIO\MLYT\MaterialPrice p WITH p.material = m AND p.study = :STUDY
                  LEFT JOIN BIO\MLYT\StudySupplierRate sr WITH sr.study = p.study AND sr.supplier = m.supplier
                                                             AND sr.currency = p.currency AND sr.recordFl > 0
                 WHERE m.materialId IN (:IDS)
                 ORDER BY m.materialName
            ")->setParameters(['STUDY' => $study, 'IDS' => $materialIds])->getArrayResult();
        }
        $units = ['PIECE' => 'adet', 'TEST' => 'test', 'ML' => 'ml', 'GR' => 'gr'];
        $rowOf = [];
        foreach ($rows as $i => $m) {
            $r = $header + 1 + $i;
            $rowOf[$m['materialId']] = $r;
            // Bedelli malzeme: sabit bedel (TL / birim); kur ve çalışma fiyatı kullanılmaz
            if ($m['categoryCode'] === 'BILLABLE' && $m['billablePrice'] !== null) {
                $s->row([
                    ['v' => $m['materialName'], 's' => XlsxWriter::TEXT],
                    ['v' => $m['categoryName'], 's' => XlsxWriter::TEXT],
                    ['v' => $units[$m['usageUnit']] ?? $m['usageUnit'], 's' => XlsxWriter::TEXT],
                    ['v' => 1, 's' => XlsxWriter::NUMBER],
                    ['v' => 'TRY', 's' => XlsxWriter::TEXT],
                    ['v' => (float) $m['billablePrice'], 's' => XlsxWriter::MONEY],
                    ['v' => 1, 's' => XlsxWriter::UNIT_COST],
                    ['f' => "F{$r}*G{$r}/D{$r}", 'v' => (float) $m['billablePrice'], 's' => XlsxWriter::UNIT_COST],
                    ['v' => 'sabit bedel', 's' => XlsxWriter::TEXT],
                ]);
                continue;
            }
            $has = $m['packagePrice'] !== null;
            $s->row([
                ['v' => $m['materialName'], 's' => XlsxWriter::TEXT],
                ['v' => $m['categoryName'], 's' => XlsxWriter::TEXT],
                ['v' => $units[$m['usageUnit']] ?? $m['usageUnit'], 's' => XlsxWriter::TEXT],
                ['v' => $has ? (float) $m['packageQty'] : null, 's' => XlsxWriter::NUMBER],
                ['v' => $has ? $m['currency'] : null, 's' => XlsxWriter::TEXT],
                ['v' => $has ? (float) $m['packagePrice'] : null, 's' => XlsxWriter::MONEY],
                // Kur: tedarikçi sözleşme kuru varsa sabit değer, yoksa üstteki genel kurlara bağlı formül
                $m['supplierRate'] !== null
                    ? ['v' => (float) $m['supplierRate'], 's' => XlsxWriter::UNIT_COST]
                    : ['f' => "IF(E{$r}=\"TRY\",\$B\$5,IF(E{$r}=\"USD\",\$B\$2,IF(E{$r}=\"EUR\",\$B\$3,IF(E{$r}=\"GBP\",\$B\$4,\"\"))))",
                       'v' => $has ? ($m['currency'] === 'TRY' ? $tryFactor : $rates[$m['currency']]) : null, 's' => XlsxWriter::UNIT_COST],
                // Bölen: hasta sayısı (I) girilmişse o, yoksa ambalaj içeriği (D)
                ['f' => "IF(AND(MAX(N(D{$r}),N(I{$r}))>0,N(F{$r})>0,N(G{$r})>0),F{$r}*G{$r}/IF(N(I{$r})>0,I{$r},D{$r}),\"\")",
                 'v' => $calc->unitCost((int) $m['materialId']), 's' => XlsxWriter::UNIT_COST],
                ['v' => $has && $m['patientQty'] !== null ? (float) $m['patientQty'] : null, 's' => XlsxWriter::NUMBER],
            ]);
        }
        $s->freeze($header);
        return $rowOf;
    }
}
