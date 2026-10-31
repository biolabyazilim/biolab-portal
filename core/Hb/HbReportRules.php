<?php
declare(strict_types=1);

namespace Biolab\Core\Hb;

/**
 * Hb elektroforez raporlama kuralları — tek yer.
 * Kaynak: docs/hb-rapor-kurallari.md (uzman cevapları + hplcalgoson01.06.docx).
 *
 * Veritabanına dokunmaz; sayılarla çalışır. Uzman onay ekranı, iş listesi ve (5. adımda) rapor PDF'i bunu kullanır.
 * Yazılım şüpheli sonucu kesinleştirmez: hesaplar, blok önerir, uyarır. Kriterin kapsamadığı durumda blok önermez.
 *
 * Uyarı seviyeleri:
 *   block   → giderilmeden onaylanamaz (ör. HbA'yı uzman girmeli, blok seçilmeli)
 *   confirm → "Uyarıları kontrol ettim" işaretlenmeden onaylanamaz
 *   info    → bilgi
 */
final class HbReportRules
{
    public const HBSHBD_NOT_DETECTED = 'SAPTANMADI';
    public const HBSHBD_VERIFY       = 'DOĞRULAMA GEREKLİ';

    // D-10 BTHAL raporunda normalde bulunan pikler; bunların dışındaki her pik "anormal" sayılır
    private const KNOWN_PEAKS = ['a1a', 'a1b', 'f', 'la1c/chb-1', 'a1c', 'p3', 'a0', 'a2'];

    // Bloklarda tekrarlanan kapanış cümlesi: birleşik yorumda en sonda bir kez yazılır
    public const CLOSING_SENTENCE = 'Belirtilen değerler klinik değerlendirmede yol gösterici karar düzeyleri olup, tek başına tanı koydurucu değildir; sonuçların hastanın yaşı, klinik bulguları ve kullanılan yönteme ait referans aralıkları ile birlikte değerlendirilmesi önerilir.';

    // Sınırlar (%)
    private const PEAK_V_MIN      = 3.0;   // bunun altı: V bloğu yok
    private const PEAK_HBA_DRAFT  = 5.0;   // bu ve üstü: HbA taslak, A0 kontrolü
    private const HBF_MIN_REPORT  = 0.8;   // cihaz "< 0.8" ya da boş verirse raporda 0.8
    private const HBA2_VERY_HIGH  = 10.0;  // üstü: interferans uyarısı

    /* ------------------------------------------------------------------ */
    /*  Yardımcılar                                                         */
    /* ------------------------------------------------------------------ */

    /** "< 0.8 *" → ['value' => 0.8, 'below' => true]; boş → null */
    public static function parseValue(?string $raw): array
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return ['value' => null, 'below' => false];
        }
        $below = str_contains($s, '<');
        $num = preg_replace('/[^0-9.,\-]/', '', $s);
        $num = str_replace(',', '.', $num);
        return ['value' => is_numeric($num) ? (float) $num : null, 'below' => $below];
    }

    public static function ageGroup(int $ageMonths): string
    {
        if ($ageMonths < 12) return 'A';
        if ($ageMonths < 72) return 'B';
        return 'C';
    }

    /**
     * HbF yaşa göre referans. 8. aydan itibaren belgede tek değer var ("≥ %10.5" …); üst sınır kabul edildi.
     * @return array{lower: ?float, upper: float, label: string}
     */
    public static function hbfReference(int $ageDays, int $ageMonths): array
    {
        if ($ageMonths < 1 && $ageDays <= 30) return ['lower' => 22.8, 'upper' => 92.0, 'label' => '0–30 gün: %22.8–92'];
        if ($ageMonths < 2)  return ['lower' => 7.6, 'upper' => 89.8, 'label' => '1–2 ay: %7.6–89.8'];
        if ($ageMonths < 5)  return ['lower' => 1.6, 'upper' => 42.2, 'label' => '2–5 ay: %1.6–42.2'];
        if ($ageMonths < 8)  return ['lower' => 0.0, 'upper' => 16.7, 'label' => '5–8 ay: %0–16.7'];
        if ($ageMonths < 12) return ['lower' => null, 'upper' => 10.5, 'label' => '8–12 ay: %10.5'];
        if ($ageMonths < 17) return ['lower' => null, 'upper' => 7.9, 'label' => '12–17 ay: %7.9'];
        if ($ageMonths < 24) return ['lower' => null, 'upper' => 6.3, 'label' => '17–24 ay: %6.3'];
        return ['lower' => null, 'upper' => 3.0, 'label' => '24 ay ve üstü: %3.0'];
    }

    public static function isAbnormalPeak(string $name): bool
    {
        return !in_array(mb_strtolower(trim($name)), self::KNOWN_PEAKS, true);
    }

    // Uzmanın belirttiği anormal pikler: Unknown, S-Window, D-Window. Diğer beklenmeyen adlar ayrıca uyarılır.
    private static function isExpectedAbnormalName(string $name): bool
    {
        return (bool) preg_match('/^unknown|s-window|d-window/i', trim($name));
    }

    public static function hba2Band(float $a2): array
    {
        if ($a2 < 3.5) return ['code' => 'N',  'label' => '< 3.5'];
        if ($a2 < 4.0) return ['code' => 'BL', 'label' => '3.5 – 4.0'];
        if ($a2 < 5.5) return ['code' => 'H',  'label' => '4.0 – 5.5'];
        return ['code' => 'VH', 'label' => '≥ 5.5'];
    }

    /* ------------------------------------------------------------------ */
    /*  Değerlendirme                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @param array{
     *   ageDays: int, ageMonths: int,
     *   a2: ?string, f: ?string,                      // cihaz değerleri (metin)
     *   peaks: list<array{name: string, areaPct: ?string, rt?: ?string}>,
     *   deviceComment?: ?string,
     *   hbaManual?: ?float,                           // anormal pik ≥ %5 ise uzmanın girdiği HbA
     *   runCount?: int
     * } $in
     */
    public static function evaluate(array $in): array
    {
        $warnings = [];
        $warn = function (string $code, string $level, string $text) use (&$warnings) {
            $warnings[] = ['code' => $code, 'level' => $level, 'text' => $text];
        };

        $ageMonths = (int) $in['ageMonths'];
        $group     = self::ageGroup($ageMonths);
        $ref       = self::hbfReference((int) $in['ageDays'], $ageMonths);

        // HbA2
        $a2 = self::parseValue($in['a2'] ?? null)['value'];
        if ($a2 === null) {
            $warn('NO_A2', 'block', 'Cihaz HbA2 değeri vermedi; bu çalışma raporlanamaz.');
        }

        // HbF: "< 0.8" ya da hiç yoksa raporda 0.8 (uzman cevabı, Soru 2)
        $fParsed = self::parseValue($in['f'] ?? null);
        $fBelow  = $fParsed['value'] === null || $fParsed['below'] || $fParsed['value'] < self::HBF_MIN_REPORT;
        $f       = $fBelow ? self::HBF_MIN_REPORT : $fParsed['value'];
        if ($fBelow) {
            $warn('F_BELOW', 'info', trim((string) ($in['f'] ?? '')) === ''
                ? 'Cihaz HbF vermedi (tespit edilmedi); raporda ve HbA hesabında 0.8 kullanıldı.'
                : 'Cihaz HbF "' . trim((string) $in['f']) . '" verdi; raporda ve HbA hesabında 0.8 kullanıldı.');
        }

        // Pikler: A0 ve anormaller
        $a0 = null;
        $abnormal = [];
        foreach ($in['peaks'] ?? [] as $p) {
            $name = trim((string) $p['name']);
            if (mb_strtolower($name) === 'a0') {
                $a0 = $p['areaPct'];
            }
            if (!self::isAbnormalPeak($name)) continue;
            $pct = self::parseValue($p['areaPct'] ?? null);
            $abnormal[] = [
                'name'     => $name,
                'pct'      => $pct['value'] ?? 0.0,
                'pctText'  => (string) $p['areaPct'],
                'rt'       => $p['rt'] ?? null,
                'expected' => self::isExpectedAbnormalName($name),
            ];
        }
        usort($abnormal, fn($x, $y) => $y['pct'] <=> $x['pct']);
        $maxPct     = $abnormal ? $abnormal[0]['pct'] : 0.0;
        $hasVariant = $maxPct >= self::PEAK_V_MIN;   // V bloğuna giren anormal pik

        // HbA = 100 − (HbA2 + HbF). Anormal pik ≥ %5 ise taslak: uzman A0'a bakıp değeri girer (Soru 1)
        $hbaCalc  = $a2 !== null ? round(100 - ($a2 + $f), 1) : null;
        $hbaDraft = $maxPct >= self::PEAK_HBA_DRAFT;
        $hbaManual = isset($in['hbaManual']) && $in['hbaManual'] !== null ? round((float) $in['hbaManual'], 1) : null;
        $hba = $hbaDraft ? $hbaManual : $hbaCalc;
        if ($hbaDraft) {
            $warn('HBA_DRAFT', $hbaManual === null ? 'block' : 'confirm',
                'Anormal pik ≥ %5: HbA hesaplanan değer (' . self::fmt($hbaCalc) . ') taslaktır. Kromatogramdaki A0 değerini kontrol ediniz'
                . ($a0 !== null ? ' (A0: %' . $a0 . ')' : '') . '. Raporlanacak HbA değerini girin.');
        }

        // HbS / HbD (Soru 3): anormal pik varsa otomatik "SAPTANMADI" yazılmaz
        $hbsNeedsVerify = count($abnormal) > 0;
        if ($hbsNeedsVerify) {
            $warn('HBSHBD_VERIFY', 'block', 'S/D penceresi veya Unknown pik var: HbS/HbD için "SAPTANMADI" otomatik yazılmadı. Kromatogramı kontrol edip sonucu seçin.');
        }

        // Diğer pik uyarıları (Soru 4, 5, 6)
        $sig = array_filter($abnormal, fn($p) => $p['pct'] >= self::PEAK_V_MIN);
        if ($maxPct >= self::PEAK_V_MIN && $maxPct < self::PEAK_HBA_DRAFT) {
            $warn('V1_CHECK', 'confirm', 'Anormal pik %3–5 arasında: V1 yorum taslağı hazırlandı. Kromatogramı kontrol edip uygunsa rapora ekleyin.');
        }
        if (count($sig) >= 2) {
            $warn('MULTI_PEAK', 'confirm', count($sig) . ' anormal pik %3 ve üzerinde: karar yalnızca en büyük pike göre verilmemeli, uzman kontrolü gerekli.');
        }
        if ($abnormal && $maxPct < self::PEAK_V_MIN) {
            $warn('PEAK_LOW', 'info', 'Anormal pik %3\'ün altında: V bloğu oluşturulmadı, HbA hesabından düşülmedi.');
        }
        foreach ($abnormal as $p) {
            if (!$p['expected']) {
                $warn('UNEXPECTED_PEAK', 'confirm', 'Beklenmeyen pik adı: "' . $p['name'] . '" (%' . $p['pctText'] . '). Anormal pik olarak değerlendirildi.');
            }
        }
        if ($a2 !== null && $hasVariant && $a2 >= 3.5) {
            $warn('VARIANT_HBA2', 'confirm', 'Anormal pik HbA2 ölçümünü etkileyebilir: HbA2 ve kromatogramı kontrol ediniz.');
        }
        if ($a2 !== null && $a2 > self::HBA2_VERY_HIGH) {
            $warn('HBA2_VERY_HIGH', 'confirm', 'HbA2 beklenenden yüksek; kromatogramı ve olası varyant interferansını kontrol ediniz.');
        }

        // Yaş bloğu
        $age = $a2 !== null ? self::ageBlock($group, $a2, $f, $hba, $ageMonths, $ref, $warn) : ['code' => null, 'candidates' => [], 'reason' => 'HbA2 yok'];
        if ($age['code'] === null) {
            $warn('AGE_BLOCK_MANUAL', 'block', 'Yorum bloğu otomatik seçilemedi: ' . $age['reason'] . '. Bloğu uzman seçmeli.');
        }

        // C3 / C4 notu: HbF > 5
        $note = null;
        if (in_array($age['code'], ['C3', 'C4'], true) && $f > 5) {
            $note = $age['code'] . 'N';
        }

        // V bloğu
        $v = self::variantBlock($maxPct, $hba, $f, $hbaDraft);
        if ($hasVariant && $v['code'] === null) {
            $warn('V_BLOCK_MANUAL', 'block', 'Varyant bloğu otomatik seçilemedi: ' . $v['reason'] . '. Uzman seçmeli (ya da "V bloğu yok").');
        }

        // Cihaz yorumu ve tekrar çalışma
        if (!empty($in['deviceComment'])) {
            $warn('DEVICE_COMMENT', 'confirm', 'Cihaz yorumu: ' . $in['deviceComment']);
        }
        if (($in['runCount'] ?? 1) > 1) {
            $warn('MULTI_RUN', 'info', 'Numune ' . $in['runCount'] . ' kez çalışıldı; raporlanacak çalışmayı uzman seçer.');
        }

        // Seviyeye göre sırala: block → confirm → info
        $order = ['block' => 0, 'confirm' => 1, 'info' => 2];
        usort($warnings, fn($x, $y) => $order[$x['level']] <=> $order[$y['level']]);

        $suggested = array_values(array_filter([$age['code'], $note, $v['code']]));

        return [
            'ageGroup'        => $group,
            'ageMonths'       => $ageMonths,
            'hbfRef'          => $ref,
            'a2'              => $a2,
            'a2Band'          => $a2 !== null ? self::hba2Band($a2) : null,
            'f'               => $f,
            'fDevice'         => $in['f'] ?? null,
            'fBelow'          => $fBelow,
            'a0'              => $a0,
            'hbaCalc'         => $hbaCalc,
            'hbaDraft'        => $hbaDraft,
            'hba'             => $hba,
            'abnormalPeaks'   => $abnormal,
            'maxAbnormalPct'  => $maxPct,
            'hasVariant'      => $hasVariant,
            'hbsHbd'          => ['suggested' => $hbsNeedsVerify ? null : self::HBSHBD_NOT_DETECTED, 'needsVerify' => $hbsNeedsVerify],
            'ageBlock'        => $age,
            'noteBlock'       => $note,
            'vBlock'          => $v,
            'suggestedBlocks' => $suggested ? implode('+', $suggested) : null,
            'warnings'        => $warnings,
        ];
    }

    /** @return array{code: ?string, candidates: list<string>, reason: string} */
    private static function ageBlock(string $group, float $a2, float $f, ?float $hba, int $ageMonths, array $ref, callable $warn): array
    {
        $pick = fn(string $code, string $reason) => ['code' => $code, 'candidates' => [], 'reason' => $reason];
        $none = fn(array $cands, string $reason) => ['code' => null, 'candidates' => $cands, 'reason' => $reason];

        if ($group === 'A') {
            if ($a2 >= 3.5) {
                $warn('A_HBA2_HIGH', 'confirm', 'A grubunda (< 1 yaş) HbA2 ' . self::fmt($a2) . ': bu durum için kriter tanımlı değil, kontrol ediniz.');
            }
            if ($ageMonths >= 2 && $f > 90) {
                if ($hba === null) return $none(['A4', 'A3'], 'HbF > 90; A4 için HbA değeri gerekli');
                if ($hba < 10)     return $pick('A4', 'HbF > 90 ve HbA < 10 (2 ay sonrası): major alarm');
            }
            $lower = $ref['lower'] ?? 0.0;
            if ($f >= $lower && $f <= $ref['upper']) {
                $warn('A_LIMITS', 'info', 'A2 ("üst sınıra yakın") ve A3 ("%20 üstü") sınırları henüz sayısal tanımlı değil; gerekirse bloğu değiştirin.');
                return $pick('A1', 'HbF yaş aralığında (' . $ref['label'] . ')');
            }
            return $f > $ref['upper']
                ? $none(['A2', 'A3', 'A4'], 'HbF yaş üst sınırının üzerinde (' . $ref['label'] . '); A2/A3 seçimi uzmanda')
                : $none(['A1', 'A2', 'A3'], 'HbF yaş alt sınırının altında (' . $ref['label'] . ')');
        }

        if ($group === 'B') {
            $fHigh = $f >= $ref['upper'];   // belgede "≥ %x" olarak verilmiş
            if ($a2 < 3.5) return $fHigh ? $pick('B5', 'İzole HbF yüksekliği (' . $ref['label'] . ')') : $pick('B1', 'HbA2 < 3.5');
            if ($a2 < 4.0) return $fHigh ? $none(['B2', 'B5'], 'HbA2 3.5–4.0 ve HbF yaşa göre yüksek; kriterlerde tanımlı değil') : $pick('B2', 'HbA2 3.5–4.0');
            if ($a2 < 5.5) return $fHigh ? $none(['B3', 'B6'], 'HbA2 4.0–5.5 ve HbF yaşa göre yüksek; kriterlerde tanımlı değil') : $pick('B3', 'HbA2 4.0–5.5');
            return $fHigh ? $pick('B6', 'HbA2 ≥ 5.5 ve HbF yaşa göre yüksek') : $pick('B4', 'HbA2 ≥ 5.5');
        }

        // C (≥ 6 yaş)
        if ($a2 >= 5.5) return $pick('C4', 'HbA2 ≥ 5.5');
        if ($a2 >= 4.0) return $pick('C3', 'HbA2 4.0–5.5');
        if ($a2 >= 3.5) {
            return $f < 2 ? $pick('C2', 'HbA2 3.5–4.0')
                          : $none(['C2', 'C5', 'C6'], 'HbA2 3.5–4.0 ve HbF ' . self::fmt($f) . '; kriterlerde tanımlı değil');
        }
        // HbA2 < 3.5: HbF'ye göre
        if ($f < 2)    return $pick('C1', 'HbA2 < 3.5 ve HbF < 2');
        if ($f <= 5)   return $pick('C5', 'HbF 2–5 ve HbA2 < 3.5');
        if ($f <= 20)  return $pick('C6', 'HbF 5.1–20 ve HbA2 < 3.5');
        if ($hba === null) return $none(['C7', 'C8', 'C9'], 'HbF ' . self::fmt($f) . '; blok için HbA değeri gerekli');
        if ($f <= 60)  return $hba >= 30 ? $pick('C7', 'HbF 20.1–60 ve HbA ≥ 30')
                                         : $none(['C7', 'C8'], 'HbF 20.1–60 ama HbA < 30; kriterlerde tanımlı değil');
        if ($f < 85)   return ($hba >= 10 && $hba <= 30) ? $pick('C8', 'HbF 60.1–85 ve HbA 10–30')
                                                         : $none(['C7', 'C8', 'C9'], 'HbF 60.1–85 ama HbA 10–30 aralığında değil');
        return $hba <= 10 ? $pick('C9', 'HbF ≥ 85 ve HbA ≤ 10')
                          : $none(['C8', 'C9'], 'HbF ≥ 85 ama HbA > 10; kriterlerde tanımlı değil');
    }

    /** @return array{code: ?string, candidates: list<string>, reason: string} */
    private static function variantBlock(float $p, ?float $hba, float $f, bool $hbaDraft): array
    {
        $pick = fn(string $code, string $reason) => ['code' => $code, 'candidates' => [], 'reason' => $reason];
        $none = fn(array $cands, string $reason) => ['code' => null, 'candidates' => $cands, 'reason' => $reason];
        $all  = ['V1', 'V2', 'V3', 'V4', 'V5', 'V6', 'V7'];

        if ($p < self::PEAK_V_MIN) return ['code' => null, 'candidates' => [], 'reason' => 'Anormal pik < %3'];
        if ($hba === null) {
            return $none($all, $hbaDraft ? 'önce raporlanacak HbA değeri girilmeli' : 'HbA hesaplanamadı');
        }
        if ($p < 5) {
            return $hba >= 70 ? $pick('V1', 'Anormal pik %3–5, HbA ≥ 70') : $none($all, 'Anormal pik %3–5 ama HbA < 70');
        }
        if ($p < 10) {
            return $hba >= 70 ? $pick('V2', 'Anormal pik %5–10, HbA ≥ 70') : $none($all, 'Anormal pik %5–10 ama HbA < 70; kriterlerde tanımlı değil');
        }
        if ($p <= 50) {
            if ($hba >= 70) return $f >= 5 ? $pick('V5', 'Anormal pik %10–50, HbA ≥ 70, HbF ≥ 5') : $pick('V3', 'Anormal pik %10–50, HbA ≥ 70');
            if ($hba >= 10) return $f >= 5 ? $pick('V6', 'Anormal pik %10–50, HbA 10–70, HbF ≥ 5') : $pick('V4', 'Anormal pik %10–50, HbA 10–70');
            return $none($all, 'Anormal pik %10–50 ama HbA < 10; kriterlerde tanımlı değil');
        }
        return $hba <= 10 ? $pick('V7', 'Anormal pik > %50, HbA ≤ 10') : $none($all, 'Anormal pik > %50 ama HbA > 10; kriterlerde tanımlı değil');
    }

    /* ------------------------------------------------------------------ */
    /*  Yorum metni                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Blok metinlerini tek yoruma birleştirir (Soru 5):
     *  - sıra: yaş bloğu → ek not → V bloğu → kapanış cümlesi (bir kez)
     *  - varyant varsa "…ek bir pik saptanmamış…" cümlesi çıkar
     *  - ek not varsa yaş bloğundaki kısa "Eşlik eden HbF yüksekliği bulunması durumunda…" cümlesi çıkar (not bunu genişletiyor)
     *  - bloklar arasında birebir tekrarlanan cümleler bir kez yazılır
     */
    public static function composeComment(?string $ageBody, ?string $noteBody, ?string $vBody, bool $hasVariant): string
    {
        $hadClosing = false;
        $seen = [];
        $paragraphs = [];

        foreach ([['age', $ageBody], ['note', $noteBody], ['v', $vBody]] as [$kind, $body]) {
            if ($body === null || trim($body) === '') continue;
            foreach (preg_split('/\R+/u', $body) as $para) {
                $keep = [];
                foreach (self::sentences($para) as $s) {
                    $norm = self::norm($s);
                    if ($norm === self::norm(self::CLOSING_SENTENCE)) { $hadClosing = true; continue; }
                    if ($hasVariant && str_contains($norm, 'ek bir pik saptanmamış')) continue;
                    if ($kind === 'age' && $noteBody !== null && str_starts_with($norm, self::norm('Eşlik eden HbF yüksekliği bulunması durumunda'))) continue;
                    if (isset($seen[$norm])) continue;
                    $seen[$norm] = true;
                    $keep[] = $s;
                }
                if ($keep) $paragraphs[] = implode(' ', $keep);
            }
        }
        if ($hadClosing) $paragraphs[] = self::CLOSING_SENTENCE;
        return implode("\n", $paragraphs);
    }

    // Cümlelere böler: nokta + boşluk + büyük harf/rakam/parantez ("3.5" ve "± hemogram)" bölünmez)
    private static function sentences(string $para): array
    {
        $para = trim(preg_replace('/\s+/u', ' ', $para));
        if ($para === '') return [];
        return preg_split('/(?<=[.!?])\s+(?=[A-ZÇĞİÖŞÜ0-9(])/u', $para);
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    private static function fmt(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 1, '.', '');
    }
}
