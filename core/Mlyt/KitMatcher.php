<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\MaterialCategory;
use BIO\MLYT\Test;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Kit – test eşleştirme önerisi (Kit eşleştirme ekranı). Hiçbir şey yazmaz; bağlamayı ekran yapar.
 *
 * Aday testler: Biolab birimi, aktif; yöntemi Kit, Belirsiz ya da Reçete; reçetesinde kit dışı malzeme olmayan.
 * Kitler: "Kit" kategorisindeki malzemeler.
 *
 * Uyum (match) = testin ayırt edici kelimelerinden kaçı kitin adında geçiyor (0–1)
 *   - ad sadeleştirilir: Türkçe büyük harf, noktalama atılır, "B-19" → "B19", ayırt etmeyen kelimeler (KİT, TEST,
 *     ANTİKORU, ANTİJEN...) çıkarılır; tek harf / rakam kalır (TİP 1 ↔ TİP 2)
 *   - IgG / IgM / IgA / IgE uyuşmazsa öneri olmaz; ortak kelime yalnız IgG / IgM ise de öneri olmaz
 * Güven uyumdan: Yüksek ≥ 0,99 (testin bütün kelimeleri kitte), Orta ≥ 0,6, altı öneri değildir.
 * Sıralama: testin tedarikçisi kitinkiyle aynıysa öne (+0,1), kit başka teste bağlıysa biraz geriye (−0,02).
 */
final class KitMatcher
{
    public const HIGH   = 'HIGH';
    public const MEDIUM = 'MEDIUM';
    public const NONE   = 'NONE';
    public const LINKED = 'LINKED';   // teste zaten kit bağlı

    private const HIGH_SCORE   = 0.99;
    private const MEDIUM_SCORE = 0.6;
    private const MAX_SUGGESTIONS = 3;

    /** Ayırt etmeyen kelimeler: eşleşmeyi bunlar belirlemesin */
    private const STOP_WORDS = ['KİT', 'KİTİ', 'KIT', 'TEST', 'TESTİ', 'ANTİ', 'ANTİKOR', 'ANTİKORU', 'ANTİKORLARI', 'ANTİJEN', 'ANTİJENİ',
                                'TAYİNİ', 'SERUM', 'PLAZMA', 'DIŞLAB', 'ELİSA', 'PCR', 'HPLC', 'V1', 'ML', 'Lİ', 'LI', 'LÜ', 'LU'];
    private const CLASS_WORDS = ['IGG', 'IGM', 'IGA', 'IGE'];

    /** @var list<array{materialId:int, materialName:string, supplierKey:string, linkedCount:int, words:list<string>}> */
    private array $kits = [];

    public function __construct(private EntityManagerInterface $em, private int $companyId)
    {
        $this->loadKits();
    }

    /**
     * Aday testler ve önerileri.
     * @return list<array{testId:int, testCode:string, testName:string, costMethod:string, supplierName:?string,
     *                    confidence:string, suggestions:list<array>, linked:list<array>}>
     */
    public function rows(): array
    {
        $tests = $this->em->createQuery("
            SELECT t.testId, t.testCode, t.testName, t.costMethod, s.supplierName
              FROM BIO\MLYT\Test t
              LEFT JOIN t.supplier s
             WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0 AND t.activeFl = 'Y'
               AND t.labUnit = :BIOLAB AND t.costMethod IN (:METHODS)
        ")->setParameters([
            'COMPANY_ID' => $this->companyId,
            'BIOLAB'     => Test::UNIT_BIOLAB,
            'METHODS'    => [Test::METHOD_KIT, Test::METHOD_UNDEFINED, Test::METHOD_RECIPE],
        ])->getArrayResult();

        // Testlerin reçete satırları: kit olanlar "bağlı" listesi, kit dışı satırı olan test aday değildir
        $linked = [];
        $hasOther = [];
        foreach ($this->em->createQuery("
            SELECT IDENTITY(ri.test) AS testId, m.materialId, m.materialName, ri.quantity, c.categoryCode
              FROM BIO\MLYT\RecipeItem ri
              JOIN ri.material m
              JOIN m.category c
              JOIN ri.test t
             WHERE IDENTITY(t.company) = :COMPANY_ID AND ri.recordFl > 0 AND ri.activeFl = 'Y'
        ")->setParameter('COMPANY_ID', $this->companyId)->getArrayResult() as $r) {
            if ($r['categoryCode'] === MaterialCategory::CODE_KIT) {
                $linked[(int) $r['testId']][] = ['materialId' => (int) $r['materialId'], 'materialName' => $r['materialName'], 'quantity' => (float) $r['quantity']];
            } else {
                $hasOther[(int) $r['testId']] = true;
            }
        }

        $out = [];
        foreach ($tests as $t) {
            $id = (int) $t['testId'];
            if (isset($hasOther[$id])) { continue; }
            $suggestions = isset($linked[$id]) ? [] : $this->suggest($t['testName'], $t['supplierName']);
            $confidence = isset($linked[$id]) ? self::LINKED
                : (!$suggestions ? self::NONE : ($suggestions[0]['match'] >= self::HIGH_SCORE ? self::HIGH : self::MEDIUM));
            $out[] = [
                'testId'       => $id,
                'testCode'     => $t['testCode'],
                'testName'     => $t['testName'],
                'costMethod'   => $t['costMethod'],
                'supplierName' => $t['supplierName'],
                'confidence'   => $confidence,
                'suggestions'  => $suggestions,
                'linked'       => $linked[$id] ?? [],
            ];
        }
        return $out;
    }

    /** @return list<array{materialId:int, materialName:string}> bütün kitler (ekrandaki "başka kit" seçimi) */
    public function kits(): array
    {
        return array_map(fn ($k) => ['materialId' => $k['materialId'], 'materialName' => $k['materialName']], $this->kits);
    }

    /** Test adına en uygun kitler (puan sırasıyla, en çok 3) */
    private function suggest(string $testName, ?string $supplierName): array
    {
        $words = $this->words($testName);
        if (!$words) { return []; }
        $testClass = array_values(array_intersect($words, self::CLASS_WORDS));
        $supplierKey = $supplierName !== null ? $this->upper(trim($supplierName)) : '';

        $found = [];
        foreach ($this->kits as $k) {
            $kitClass = array_intersect($k['words'], self::CLASS_WORDS);
            if ($testClass && $kitClass && !array_intersect($testClass, $kitClass)) { continue; }   // IgG ↔ IgM
            $common = array_intersect($words, $k['words']);
            // Yalnız IgG / IgM ortaksa öneri değil (adı yalnız "IgA" olan test her IgA kitine uymasın)
            if (!array_diff($common, self::CLASS_WORDS)) { continue; }
            $match = count($common) / count($words);   // güven bundan: tedarikçi bonusu güveni yükseltmez
            if ($match < self::MEDIUM_SCORE) { continue; }
            $rank = $match;                            // sıralama: tedarikçi aynıysa öne, başka teste bağlıysa geriye
            if ($supplierKey !== '' && $supplierKey === $k['supplierKey']) { $rank += 0.1; }
            if ($k['linkedCount'] > 0) { $rank -= 0.02; }
            $found[] = ['materialId' => $k['materialId'], 'materialName' => $k['materialName'], 'match' => round($match, 3),
                        'rank' => $rank, 'linkedCount' => $k['linkedCount']];
        }
        usort($found, fn ($a, $b) => $b['rank'] <=> $a['rank'] ?: strcmp($a['materialName'], $b['materialName']));
        return array_map(function ($f) { unset($f['rank']); return $f; }, array_slice($found, 0, self::MAX_SUGGESTIONS));
    }

    private function loadKits(): void
    {
        foreach ($this->em->createQuery("
            SELECT m.materialId, m.materialName, s.supplierName,
                   (SELECT COUNT(ri.recipeItemId) FROM BIO\MLYT\RecipeItem ri WHERE ri.material = m AND ri.recordFl > 0 AND ri.activeFl = 'Y') AS linkedCount
              FROM BIO\MLYT\Material m
              JOIN m.category c
              LEFT JOIN m.supplier s
             WHERE IDENTITY(m.company) = :COMPANY_ID AND m.recordFl > 0 AND c.categoryCode = :KIT
             ORDER BY m.materialName
        ")->setParameters(['COMPANY_ID' => $this->companyId, 'KIT' => MaterialCategory::CODE_KIT])->getArrayResult() as $k) {
            $this->kits[] = [
                'materialId'   => (int) $k['materialId'],
                'materialName' => $k['materialName'],
                'supplierKey'  => $k['supplierName'] !== null ? $this->upper(trim($k['supplierName'])) : '',
                'linkedCount'  => (int) $k['linkedCount'],
                'words'        => $this->words($k['materialName']),
            ];
        }
    }

    /** Ayırt edici kelimeler: "PARVOVİRÜS B-19 IgM" → [PARVOVİRÜS, B19, IGM] */
    private function words(string $name): array
    {
        $s = $this->upper($name);
        $s = preg_replace('/(\p{L})-(\d)/u', '$1$2', $s);           // B-19 → B19
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        // Tek harf / rakam da kalır: "HSV TİP 1" ↔ "TİP 2", "VİTAMİN A" ↔ "VİTAMİN E" ayrılsın
        $words = array_filter(explode(' ', $s), fn ($w) => $w !== '');
        return array_values(array_diff(array_unique($words), self::STOP_WORDS));
    }

    /** Türkçe büyük harf (i → İ, ı → I); "IgG" → "IGG" */
    private function upper(string $v): string
    {
        return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $v), 'UTF-8');
    }
}
