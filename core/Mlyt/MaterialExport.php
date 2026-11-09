<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\Material;
use BIO\MLYT\MaterialCategory;
use BIO\MLYT\Study;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Malzemeler ekranının Excel çıktısı. Biçim geri yüklemeye (2. aşama) uygun tutulur:
 *
 *   MALZEMELER  A MALZEME_ID (değiştirilmez; boş satır = yeni malzeme) | Malzeme adı | Kategori | Kullanım birimi | Tedarikçi |
 *               Kısa kod | Katalog no | Not | Ambalaj içeriği | Kaç hastaya yeter | Ambalaj fiyatı | Para birimi |
 *               Ambalaj açıklaması | KDV dahil (E/H) | Bedel (TL) | Birim maliyet (TL) | Kullanılan test
 *               Son iki kolon bilgi amaçlıdır (sistem hesaplar), geri yüklemede okunmaz.
 *   BİLGİ       çalışma (no, ad, kur tarihi), kurlar, tedarikçi kurları, çıktı tarihi, kolon açıklamaları
 *
 * Ekrandaki filtreler (sekme, kategori, tedarikçi, para birimi, arama) aynen uygulanır. Fiyat alanları seçili çalışmanındır.
 */
final class MaterialExport
{
    public const SHEET_MAIN = 'MALZEMELER';
    public const SHEET_INFO = 'BİLGİ';

    private const UNIT_NAMES = [Material::UNIT_PIECE => 'adet', Material::UNIT_TEST => 'test', Material::UNIT_ML => 'ml', Material::UNIT_GR => 'gr'];
    private const CURRENCIES = ['TRY', 'USD', 'EUR', 'GBP'];

    /** @param array{tab?:string, categoryId?:int, supplierId?:int, currency?:string, q?:string} $filters  supplierId -1 = tedarikçisi olmayanlar */
    public function __construct(
        private EntityManagerInterface $em,
        private int $companyId,
        private ?Study $study,
        private array $filters = [],
    ) {}

    public function fileName(): string
    {
        $name = $this->study ? preg_replace('/[^\pL\pN ._-]+/u', '', $this->study->getStudyName()) : 'çalışmasız';
        return 'Malzemeler - ' . trim((string) $name) . ' - ' . date('d.m.Y') . '.xlsx';
    }

    public function bytes(): string
    {
        $studyId = $this->study?->getStudyId() ?? 0;
        $calc = new CostCalculator($this->em, $this->companyId, $this->study?->getStudyId());

        $where = '';
        $params = ['COMPANY_ID' => $this->companyId, 'STUDY_ID' => $studyId];
        $billed = "(c.categoryCode = 'BILLABLE' AND m.billablePrice IS NOT NULL)";
        $tab = (string) ($this->filters['tab'] ?? 'all');
        if ($tab === 'missing') { $where .= " AND p.materialPriceId IS NULL AND NOT $billed"; }
        if ($tab === 'priced')  { $where .= " AND (p.materialPriceId IS NOT NULL OR $billed)"; }
        if ((int) ($this->filters['categoryId'] ?? 0) > 0) { $where .= ' AND c.categoryId = :CATEGORY_ID'; $params['CATEGORY_ID'] = (int) $this->filters['categoryId']; }
        if ((int) ($this->filters['supplierId'] ?? 0) === -1) { $where .= ' AND s.supplierId IS NULL'; }   // tedarikçisi olmayanlar
        elseif ((int) ($this->filters['supplierId'] ?? 0) > 0) { $where .= ' AND s.supplierId = :SUPPLIER_ID'; $params['SUPPLIER_ID'] = (int) $this->filters['supplierId']; }
        if (in_array($this->filters['currency'] ?? '', self::CURRENCIES, true)) { $where .= ' AND p.currency = :CURRENCY'; $params['CURRENCY'] = $this->filters['currency']; }
        $q = trim((string) ($this->filters['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (m.materialName LIKE :Q OR s.supplierName LIKE :Q OR m.shortCode LIKE :Q OR m.catalogNo LIKE :Q)';
            $params['Q'] = '%' . $q . '%';
        }

        $rows = $this->em->createQuery("
            SELECT m.materialId, m.materialName, m.usageUnit, m.note, m.shortCode, m.catalogNo, m.billablePrice,
                   c.categoryCode, c.categoryName, s.supplierName,
                   p.materialPriceId, p.packageQty, p.patientQty, p.packagePrice, p.currency, p.packageDesc, p.vatIncludedFl,
                   (SELECT COUNT(ri.recipeItemId) FROM BIO\MLYT\RecipeItem ri
                     WHERE ri.material = m AND ri.recordFl > 0 AND ri.activeFl = 'Y') AS usedCount
              FROM BIO\MLYT\Material m
              JOIN m.category c
              LEFT JOIN m.supplier s
              LEFT JOIN BIO\MLYT\MaterialPrice p WITH p.material = m AND p.study = :STUDY_ID
             WHERE IDENTITY(m.company) = :COMPANY_ID AND m.recordFl > 0 $where
             ORDER BY m.materialName, m.materialId
        ")->setParameters($params)->getArrayResult();

        $w = new XlsxWriter();
        $main = $w->sheet(self::SHEET_MAIN, [12, 46, 18, 10, 24, 10, 16, 30, 12, 12, 14, 10, 28, 10, 12, 16, 12]);
        $info = $w->sheet(self::SHEET_INFO, [34, 60]);

        $header = $main->row(['MALZEME_ID', 'Malzeme adı', 'Kategori', 'Kullanım birimi', 'Tedarikçi', 'Kısa kod', 'Katalog no', 'Not',
            'Ambalaj içeriği', 'Kaç hastaya yeter', 'Ambalaj fiyatı', 'Para birimi', 'Ambalaj açıklaması', 'KDV dahil (E/H)',
            'Bedel (TL)', 'Birim maliyet (TL)', 'Kullanılan test'], XlsxWriter::HEADER);

        foreach ($rows as $r) {
            $billable = $r['categoryCode'] === MaterialCategory::CODE_BILLABLE;
            $hasPrice = $r['materialPriceId'] !== null;
            $unitCost = $calc->unitCost((int) $r['materialId']);
            $main->row([
                ['v' => (int) $r['materialId'], 's' => XlsxWriter::NUMBER],
                ['v' => $r['materialName'], 's' => XlsxWriter::TEXT],
                ['v' => $r['categoryName'], 's' => XlsxWriter::TEXT],
                ['v' => self::UNIT_NAMES[$r['usageUnit']] ?? $r['usageUnit'], 's' => XlsxWriter::TEXT],
                ['v' => $r['supplierName'], 's' => XlsxWriter::TEXT],
                ['v' => $r['shortCode'], 's' => XlsxWriter::TEXT],
                ['v' => $r['catalogNo'], 's' => XlsxWriter::TEXT],
                ['v' => $r['note'], 's' => XlsxWriter::TEXT],
                ['v' => $hasPrice ? (float) $r['packageQty'] : null, 's' => XlsxWriter::NUMBER],
                ['v' => $hasPrice && $r['patientQty'] !== null ? (float) $r['patientQty'] : null, 's' => XlsxWriter::NUMBER],
                ['v' => $hasPrice ? (float) $r['packagePrice'] : null, 's' => XlsxWriter::MONEY],
                ['v' => $hasPrice ? $r['currency'] : null, 's' => XlsxWriter::TEXT],
                ['v' => $hasPrice ? $r['packageDesc'] : null, 's' => XlsxWriter::TEXT],
                ['v' => $hasPrice ? ($r['vatIncludedFl'] === 'Y' ? 'E' : 'H') : null, 's' => XlsxWriter::TEXT],
                ['v' => $billable && $r['billablePrice'] !== null ? (float) $r['billablePrice'] : null, 's' => XlsxWriter::MONEY],
                ['v' => $unitCost !== null ? round($unitCost, 4) : null, 's' => $unitCost !== null ? XlsxWriter::UNIT_COST : XlsxWriter::WARN],
                ['v' => (int) $r['usedCount'], 's' => XlsxWriter::NUMBER],
            ]);
        }
        $main->freeze($header)->autoFilter($header, 17);

        $this->writeInfo($info, count($rows));
        return $w->bytes();
    }

    private function writeInfo(XlsxSheet $s, int $count): void
    {
        $t = XlsxWriter::TEXT;
        $s->row(['Malzemeler · Excel çıktısı'], XlsxWriter::TITLE);
        $s->row([['v' => 'Çıktı tarihi', 's' => $t], ['v' => date('d.m.Y H:i'), 's' => $t]]);
        $s->row([['v' => 'Malzeme sayısı', 's' => $t], ['v' => $count, 's' => XlsxWriter::NUMBER]]);
        if ($this->study) {
            $s->row([['v' => 'Çalışma no (STUDY_ID)', 's' => $t], ['v' => $this->study->getStudyId(), 's' => XlsxWriter::NUMBER]]);
            $s->row([['v' => 'Çalışma', 's' => $t], ['v' => $this->study->getStudyName(), 's' => $t]]);
            $s->row([['v' => 'Kur tarihi', 's' => $t], ['v' => $this->study->getRateDate()->format('d.m.Y'), 's' => $t]]);
            $s->row([['v' => 'TL öngörü katsayısı', 's' => $t], ['v' => (float) $this->study->getTryFactor(), 's' => XlsxWriter::UNIT_COST]]);
            foreach ($this->em->createQuery("SELECT r.currency, r.rate FROM BIO\MLYT\StudyRate r WHERE r.study = :S ORDER BY r.currency")
                         ->setParameter('S', $this->study)->getArrayResult() as $r) {
                $s->row([['v' => 'Kur ' . $r['currency'], 's' => $t], ['v' => (float) $r['rate'], 's' => XlsxWriter::UNIT_COST]]);
            }
            foreach ($this->em->createQuery("
                SELECT su.supplierName, r.currency, r.rate FROM BIO\MLYT\StudySupplierRate r JOIN r.supplier su
                 WHERE r.study = :S AND r.recordFl > 0 ORDER BY su.supplierName, r.currency
            ")->setParameter('S', $this->study)->getArrayResult() as $r) {
                $s->row([['v' => 'Tedarikçi kuru ' . $r['supplierName'] . ' ' . $r['currency'], 's' => $t], ['v' => (float) $r['rate'], 's' => XlsxWriter::UNIT_COST]]);
            }
        } else {
            $s->row([['v' => 'Çalışma', 's' => $t], ['v' => 'Seçilmedi: fiyat kolonları boş', 's' => XlsxWriter::WARN]]);
        }
        $s->row([]);
        $s->row(['Kolonlar'], XlsxWriter::TITLE);
        foreach ([
            ['MALZEME_ID', 'Sistemdeki kayıt numarası. Değiştirmeyin; yeni malzeme için boş bırakın.'],
            ['Kategori', 'Malzemeler ekranındaki kategori adı (Kit, Sarf, Bedelli malzeme…).'],
            ['Kullanım birimi', 'adet / test / ml / gr. Reçetedeki miktar bu birimle yazılır.'],
            ['Ambalaj içeriği … KDV dahil', 'Seçili çalışmanın fiyatı. Fiyatı olmayan malzemede boş.'],
            ['Kaç hastaya yeter', 'Doluysa birim maliyet = fiyat × kur ÷ hasta sayısı; boşsa ÷ ambalaj içeriği.'],
            ['Para birimi', 'TRY / USD / EUR / GBP.'],
            ['KDV dahil', 'E = evet, H = hayır.'],
            ['Bedel (TL)', 'Yalnız Bedelli malzemede: bir kere tanımlanan sabit bedel.'],
            ['Birim maliyet, Kullanılan test', 'Bilgi amaçlı, sistem hesaplar; geri yüklemede okunmaz.'],
        ] as [$a, $b]) {
            $s->row([['v' => $a, 's' => $t], ['v' => $b, 's' => $t]]);
        }
    }
}
