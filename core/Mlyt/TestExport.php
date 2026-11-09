<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\RecipeItem;
use BIO\MLYT\Test;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Test tanımları ekranının Excel çıktısı. Biçim geri yüklemeye (2. aşama) uygun tutulur:
 *
 *   TESTLER  A TEST_ID (değiştirilmez; boş satır = yeni test) | Kod | Test adı | Hizmet grubu | Birim | Maliyet yöntemi |
 *            Tedarikçi | Patoloji düzeyi | SUT kodu | Numune tipi | Sorumlu | Not | Hesap notu | Aktif (E/H) |
 *            Malzemeler (hasta başı) | Bileşenler
 *     Malzemeler: "TK=1; L=1; LM=1" (kısa kodu olan kısa koduyla, olmayan adıyla). Hasta başı olmayan satır "(gün)" vb. ile.
 *     Bileşenler: hesaplamalı / birleşik testte "KOD×adet" (oran %100 değilse "@%50"). Bilgi amaçlı.
 *   BİLGİ    çıktı tarihi, kolon açıklamaları, maliyet yöntemi ve birim kodları
 *
 * Ekrandaki filtreler (sekme, hizmet grubu, yöntem, birim, tedarikçi, arama) aynen uygulanır.
 */
final class TestExport
{
    public const SHEET_MAIN = 'TESTLER';
    public const SHEET_INFO = 'BİLGİ';

    public const METHOD_NAMES = [
        Test::METHOD_KIT => 'Kit / cihaz', Test::METHOD_RECIPE => 'Reçete', Test::METHOD_SERVICE => 'Hizmet alımı',
        Test::METHOD_CALCULATED => 'Hesaplamalı', Test::METHOD_UNDEFINED => 'Belirsiz',
    ];
    public const UNIT_NAMES = [Test::UNIT_BIOLAB => 'Biolab', Test::UNIT_PATHOLOGY => 'Patoloji'];
    private const CONSUMPTION = [RecipeItem::PER_RUN => 'çalışma', RecipeItem::PER_DAY => 'gün', RecipeItem::PER_WEEK => 'hafta', RecipeItem::PER_MONTH => 'ay'];

    /** @param array{tab?:string, serviceGroupId?:int, costMethod?:string, labUnit?:string, supplierId?:int, q?:string} $filters  supplierId -1 = tedarikçisi olmayanlar */
    public function __construct(
        private EntityManagerInterface $em,
        private int $companyId,
        private array $filters = [],
    ) {}

    public function fileName(): string
    {
        return 'Test tanımları - ' . date('d.m.Y') . '.xlsx';
    }

    public function bytes(): string
    {
        $where = '';
        $params = ['COMPANY_ID' => $this->companyId];
        $tab = (string) ($this->filters['tab'] ?? 'all');
        if ($tab === 'undefined') { $where .= " AND t.costMethod = 'UNDEFINED' AND t.activeFl = 'Y'"; }
        if ($tab === 'inactive')  { $where .= " AND t.activeFl = 'N'"; }
        if ((int) ($this->filters['serviceGroupId'] ?? 0) > 0) { $where .= ' AND g.serviceGroupId = :GROUP_ID'; $params['GROUP_ID'] = (int) $this->filters['serviceGroupId']; }
        if (isset(self::METHOD_NAMES[$this->filters['costMethod'] ?? ''])) { $where .= ' AND t.costMethod = :METHOD'; $params['METHOD'] = $this->filters['costMethod']; }
        if (isset(self::UNIT_NAMES[$this->filters['labUnit'] ?? ''])) { $where .= ' AND t.labUnit = :UNIT'; $params['UNIT'] = $this->filters['labUnit']; }
        if ((int) ($this->filters['supplierId'] ?? 0) === -1) { $where .= ' AND s.supplierId IS NULL'; }   // tedarikçisi olmayanlar
        elseif ((int) ($this->filters['supplierId'] ?? 0) > 0) { $where .= ' AND s.supplierId = :SUPPLIER_ID'; $params['SUPPLIER_ID'] = (int) $this->filters['supplierId']; }
        $q = trim((string) ($this->filters['q'] ?? ''));
        if ($q !== '') { $where .= ' AND (t.testCode LIKE :Q OR t.testName LIKE :Q OR s.supplierName LIKE :Q)'; $params['Q'] = '%' . $q . '%'; }

        $tests = $this->em->createQuery("
            SELECT t.testId, t.testCode, t.testName, g.serviceGroupName, t.labUnit, t.costMethod, s.supplierName,
                   t.pathologyLevel, t.sutCode, t.sampleType, t.responsibleName, t.note, t.costNote, t.activeFl
              FROM BIO\MLYT\Test t
              JOIN t.serviceGroup g
              LEFT JOIN t.supplier s
             WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0 $where
             ORDER BY t.testName, t.testId
        ")->setParameters($params)->getArrayResult();

        // Reçete satırları ve bileşenler: tek sorguda, teste göre gruplanır
        $materials = [];
        foreach ($this->em->createQuery("
            SELECT IDENTITY(ri.test) AS testId, m.shortCode, m.materialName, ri.quantity, ri.consumption
              FROM BIO\MLYT\RecipeItem ri JOIN ri.material m JOIN ri.test t
             WHERE IDENTITY(t.company) = :COMPANY_ID AND ri.recordFl > 0 AND ri.activeFl = 'Y'
             ORDER BY ri.sortNo, ri.recipeItemId
        ")->setParameter('COMPANY_ID', $this->companyId)->getArrayResult() as $r) {
            $materials[(int) $r['testId']][] = ($r['shortCode'] ?: $r['materialName']) . '=' . self::qty($r['quantity'])
                . (isset(self::CONSUMPTION[$r['consumption']]) ? ' (' . self::CONSUMPTION[$r['consumption']] . ')' : '');
        }
        $components = [];
        foreach ($this->em->createQuery("
            SELECT IDENTITY(tc.test) AS testId, c.testCode, tc.quantity, tc.applyRate
              FROM BIO\MLYT\TestComponent tc JOIN tc.componentTest c JOIN tc.test t
             WHERE IDENTITY(t.company) = :COMPANY_ID AND tc.recordFl > 0
             ORDER BY c.testCode
        ")->setParameter('COMPANY_ID', $this->companyId)->getArrayResult() as $r) {
            $components[(int) $r['testId']][] = $r['testCode'] . '×' . self::qty($r['quantity'])
                . ((float) $r['applyRate'] != 100.0 ? '@%' . self::qty($r['applyRate']) : '');
        }

        $w = new XlsxWriter();
        $main = $w->sheet(self::SHEET_MAIN, [10, 12, 44, 24, 10, 14, 22, 10, 10, 16, 18, 26, 40, 10, 40, 30]);
        $info = $w->sheet(self::SHEET_INFO, [30, 70]);

        $header = $main->row(['TEST_ID', 'Kod', 'Test adı', 'Hizmet grubu', 'Birim', 'Maliyet yöntemi', 'Tedarikçi', 'Patoloji düzeyi',
            'SUT kodu', 'Numune tipi', 'Sorumlu', 'Not', 'Hesap notu', 'Aktif (E/H)', 'Malzemeler (hasta başı)', 'Bileşenler'], XlsxWriter::HEADER);

        $t = XlsxWriter::TEXT;
        foreach ($tests as $r) {
            $id = (int) $r['testId'];
            $main->row([
                ['v' => $id, 's' => XlsxWriter::NUMBER],
                ['v' => $r['testCode'], 's' => $t],
                ['v' => $r['testName'], 's' => $t],
                ['v' => $r['serviceGroupName'], 's' => $t],
                ['v' => self::UNIT_NAMES[$r['labUnit']] ?? $r['labUnit'], 's' => $t],
                ['v' => self::METHOD_NAMES[$r['costMethod']] ?? $r['costMethod'], 's' => $r['costMethod'] === Test::METHOD_UNDEFINED ? XlsxWriter::WARN : $t],
                ['v' => $r['supplierName'], 's' => $t],
                ['v' => $r['pathologyLevel'] !== null ? (int) $r['pathologyLevel'] : null, 's' => XlsxWriter::NUMBER],
                ['v' => $r['sutCode'], 's' => $t],
                ['v' => $r['sampleType'], 's' => $t],
                ['v' => $r['responsibleName'], 's' => $t],
                ['v' => $r['note'], 's' => $t],
                ['v' => $r['costNote'], 's' => $t],
                ['v' => $r['activeFl'] === 'N' ? 'H' : 'E', 's' => $t],
                ['v' => implode('; ', $materials[$id] ?? []), 's' => $t],
                ['v' => implode('; ', $components[$id] ?? []), 's' => $t],
            ]);
        }
        $main->freeze($header)->autoFilter($header, 16);

        $info->row(['Test tanımları · Excel çıktısı'], XlsxWriter::TITLE);
        $info->row([['v' => 'Çıktı tarihi', 's' => $t], ['v' => date('d.m.Y H:i'), 's' => $t]]);
        $info->row([['v' => 'Test sayısı', 's' => $t], ['v' => count($tests), 's' => XlsxWriter::NUMBER]]);
        $info->row([]);
        $info->row(['Kolonlar'], XlsxWriter::TITLE);
        foreach ([
            ['TEST_ID', 'Sistemdeki kayıt numarası. Değiştirmeyin; yeni test için boş bırakın.'],
            ['Kod, Test adı, Hizmet grubu, Birim', 'Tenay test listesinden gelir; mevcut testte değiştirilmez.'],
            ['Maliyet yöntemi', implode(' / ', self::METHOD_NAMES)],
            ['Birim', implode(' / ', self::UNIT_NAMES)],
            ['Patoloji düzeyi', '1 ile 4 arası.'],
            ['SUT kodu', '6 haneli.'],
            ['Aktif', 'E = evet, H = hayır (pasif test maliyet çalışmasına girmez).'],
            ['Malzemeler (hasta başı)', '"KOD=adet" noktalı virgülle: TK=1; L=1; LM=1. Kısa kodu olmayan malzeme adıyla yazılır. '
                . 'Hasta başı olmayan satırda tüketim parantez içinde: (çalışma), (gün), (hafta), (ay).'],
            ['Bileşenler', 'Hesaplamalı / birleşik testte bileşen testler: KOD×adet, oran %100 değilse @%oran. Bilgi amaçlı.'],
        ] as [$a, $b]) {
            $info->row([['v' => $a, 's' => $t], ['v' => $b, 's' => $t]]);
        }
        return $w->bytes();
    }

    /** 1.0000 → "1", 0.5000 → "0,5" */
    private static function qty($v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.'));
    }
}
