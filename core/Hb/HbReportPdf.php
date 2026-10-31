<?php
declare(strict_types=1);

namespace Biolab\Core\Hb;

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Hb elektroforez raporu PDF'i (Türkçe, kurumsal tasarım).
 *
 *   1. sayfa(lar): bizim rapor — hasta, sonuçlar, kromatogram + pik tablosu, yorum, onay
 *   son sayfa    : cihazın orijinal çıktısı, değiştirilmeden; üstünde yalnızca hasta/barkod şeridi
 *
 * HTML → PDF: Dompdf (DejaVu Sans: Türkçe ve β/δ/α/≥ karakterleri). Cihaz sayfasının eklenmesi: FPDI.
 * Veritabanına dokunmaz; verileri dizi olarak alır (bkz. render()).
 */
final class HbReportPdf
{
    private const NAVY  = [0x1F, 0x4E, 0x79];   // kurumsal birincil renk (biolab-theme.css --bl-primary)
    private const STRIP_MM = 24;                // cihaz sayfasının üstünde bizim şerit + boşluk

    /**
     * @param array $d {
     *   lab: {lab_name, lab_lines[], method, footer},
     *   logoPng: ?string,
     *   reportNo: string, revisionNo: int, revisionNote: ?string,
     *   patient: {name, barcode, fileNo, gender, birthDate, ageText, institution, acceptedAt},
     *   run: {injectedAt, injectionNo, method},
     *   result: {hba, hba2, hbf, hbfDevice, hbsHbd, hbaManual, a0},
     *   refs: {hba, hba2, hbf}, flags: {hba2High, hbfHigh},
     *   peaks: [{name, rt, areaPct, abnormal}], chromatogramPng: ?string,
     *   comment: string, blocks: ?string,
     *   approver: ?string, approvedAt: ?string
     * }
     * @param bool        $draft     önizleme: "TASLAK" filigranı
     * @param string|null $devicePdf cihazın orijinal PDF'i (varsa son sayfa olarak eklenir)
     */
    public static function render(array $d, bool $draft = false, ?string $devicePdf = null): string
    {
        $html = self::html($d, $draft, $devicePdf !== null);

        $opt = new Options();
        $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('isRemoteEnabled', false);     // dış kaynak yok; görseller data: URI
        $opt->set('isPhpEnabled', false);
        $opt->set('tempDir', sys_get_temp_dir());
        $opt->set('chroot', [dirname(__DIR__, 2)]);

        $pdf = new Dompdf($opt);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        // Alt bilgi: rapor no + sayfa (sayfa sayısı yorum uzunluğuna göre değişebilir)
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $footer = $d['reportNo'] . ($d['revisionNo'] > 1 ? ' · rev. ' . $d['revisionNo'] : '') . '   ·   Sayfa {PAGE_NUM} / {PAGE_COUNT}';
        $canvas->page_text(42, $canvas->get_height() - 28, $footer, $font, 7, [0.45, 0.47, 0.5]);
        $canvas->page_text(42, $canvas->get_height() - 18, $d['lab']['footer'] ?? '', $font, 7, [0.45, 0.47, 0.5]);

        $out = $pdf->output();
        return $devicePdf !== null ? self::appendDevicePage($out, $devicePdf, (string) ($d['title'] ?? $d['reportNo'])) : $out;
    }

    /**
     * PDF dosya adı: Sample ID (barkod) - tarih. Laboratuvar kararı (21.10.2026).
     *   rapor     11714240-21.10.2026.pdf      (tarih: onay tarihi)
     *   düzeltme  11714240-21.10.2026-R2.pdf
     *   önizleme  11714240-21.10.2026-TASLAK.pdf
     * Arşivdeki dosyaların adı indirme anında bundan üretilir; eski kayıtlar da aynı biçimde iner.
     */
    public static function fileName(string $barcode, \DateTimeInterface $date, int $revisionNo = 1, bool $draft = false): string
    {
        $safe = preg_replace('/[^0-9A-Za-z_-]+/', '', $barcode) ?: 'rapor';
        return $safe . '-' . $date->format('d.m.Y') . ($revisionNo > 1 ? '-R' . $revisionNo : '') . ($draft ? '-TASLAK' : '') . '.pdf';
    }

    /* ------------------------------------------------------------------ */
    /*  HTML şablon                                                         */
    /* ------------------------------------------------------------------ */

    private static function html(array $d, bool $draft, bool $withDevicePage): string
    {
        $e = fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $dash = fn($v) => ($v === null || $v === '') ? '—' : $e($v);
        $p = $d['patient'];
        $r = $d['result'];
        $lab = $d['lab'];

        $logo = !empty($d['logoPng']) ? '<img class="logo" src="data:image/png;base64,' . base64_encode($d['logoPng']) . '">' : '<div class="lab-name">' . $e($lab['lab_name']) . '</div>';
        $labLines = implode('<br>', array_map($e, $lab['lab_lines'] ?? []));

        // Sonuç tablosu
        // $flag: 'H' = yüksek (▲), 'L' = düşük (▼), null = aralıkta
        $row = function (string $name, $value, string $unit, string $ref, ?string $flag = null, string $note = '') use ($e, $dash) {
            $arrow = $flag === 'H' ? ' <span class="arrow">▲</span>' : ($flag === 'L' ? ' <span class="arrow">▼</span>' : '');
            return '<tr class="' . ($flag ? 'high' : '') . '"><td class="tname">' . $name . '</td>'
                 . '<td class="tval">' . $dash($value) . $arrow . '</td>'
                 . '<td>' . $e($unit) . '</td><td class="tref">' . $ref . '</td>'
                 . '<td class="tnote">' . $note . '</td></tr>';
        };
        $hbfNote = ($r['hbfDevice'] !== null && trim((string) $r['hbfDevice']) !== '' && trim((string) $r['hbfDevice']) !== (string) $r['hbf'])
            ? 'cihaz: ' . $e($r['hbfDevice']) : (($r['hbfDevice'] === null || trim((string) $r['hbfDevice']) === '') ? 'cihaz: tespit edilmedi' : '');
        $hbaNote = !empty($r['hbaManual']) ? 'uzman değerlendirmesi (A0 %' . $e($r['a0']) . ')' : '';
        $flags = $d['flags'] ?? [];
        $results = $row('HbA', $r['hba'], '%', $e($d['refs']['hba'] ?? '—'), $flags['hba'] ?? null, $hbaNote)
                 . $row('HbA<sub>2</sub>', $r['hba2'], '%', $e($d['refs']['hba2'] ?? '—'), $flags['hba2'] ?? null)
                 . $row('HbF', $r['hbf'], '%', $e($d['refs']['hbf'] ?? '—'), $flags['hbf'] ?? null, $hbfNote)
                 . '<tr><td class="tname">HbS / HbD</td><td class="tval" colspan="4">' . $e($r['hbsHbd']) . '</td></tr>';

        // Pik tablosu: cihaz tablosuyla aynı sütunlar (Peak, R.time, Height, Area, Area %) + toplam alan
        $peakRows = '';
        foreach ($d['peaks'] ?? [] as $pk) {
            $peakRows .= '<tr class="' . (!empty($pk['abnormal']) ? 'abn' : '') . '"><td>' . $e($pk['name']) . '</td>'
                       . '<td class="num">' . $dash($pk['rt']) . '</td>'
                       . '<td class="num">' . $dash($pk['height'] ?? null) . '</td>'
                       . '<td class="num">' . $dash($pk['area'] ?? null) . '</td>'
                       . '<td class="num">' . $dash($pk['areaPct']) . '</td></tr>';
        }
        if (!empty($d['totalArea'])) {
            $peakRows .= '<tr class="total"><td colspan="3">Toplam alan</td><td class="num">' . $e($d['totalArea']) . '</td><td></td></tr>';
        }
        $chromo = !empty($d['chromatogramPng'])
            ? '<img class="chromo" src="data:image/png;base64,' . base64_encode(self::renderChromatogram($d['chromatogramPng'], $d['peaks'] ?? [])) . '">'
              . '<div class="cap">Bio-Rad D-10 kromatogramı</div>'
            : '<div class="cap">Kromatogram yok</div>';

        // Yorum paragrafları
        $paras = fn(?string $text) => implode('', array_map(fn($para) => '<p>' . $e($para) . '</p>', array_filter(array_map('trim', preg_split('/\R/u', (string) $text)), 'strlen')));
        $comment = $paras($d['comment']);
        // Uzman notu: algoritma yorumunun altında ayrı kutu, onaylayanın adıyla
        $expertNote = !empty($d['expertNote'])
            ? '<div class="note"><div class="note-title">Uzman notu' . ($draft ? '' : ' <span class="note-by">· ' . $dash($d['approver']) . '</span>') . '</div>'
              . $paras($d['expertNote']) . '</div>'
            : '';

        $revision = $d['revisionNote'] ? '<div class="revision">' . $e($d['revisionNote']) . '</div>' : '';
        $watermark = $draft ? '<div class="watermark">TASLAK</div>' : '';
        $approval = $draft
            ? '<div class="sign-name">Onaylanmadı</div><div class="muted">Önizleme — rapor değildir</div>'
            : '<div class="sign-name">' . $dash($d['approver']) . '</div><div class="muted">Onaylayan uzman · ' . $dash($d['approvedAt']) . '</div>';

        // Son sayfa: cihaz çıktısının üstündeki şerit (cihaz sayfası FPDI ile bunun altına yerleştirilir)
        $devicePage = $withDevicePage
            ? '<div class="page-break"></div><div class="strip"><table class="w"><tr>'
              . '<td><b>' . $e($p['name']) . '</b> · Barkod <span class="mono">' . $e($p['barcode']) . '</span> · Dosya no ' . $dash($p['fileNo']) . '</td>'
              . '<td class="right">Cihazın orijinal çıktısı (değiştirilmemiştir) · ' . $e($d['reportNo']) . '</td></tr></table></div>'
            : '';

        $css = self::css();
        return <<<HTML
<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>{$e($d['title'] ?? $d['reportNo'])}</title><style>{$css}</style></head><body>
{$watermark}
<table class="w head"><tr>
  <td>{$logo}<div class="muted small">{$labLines}</div></td>
  <td class="right"><div class="title">Hemoglobin Elektroforezi (HPLC) Raporu</div>
      <div class="muted">Rapor no <span class="mono">{$e($d['reportNo'])}</span> · rev. {$e($d['revisionNo'])}</div></td>
</tr></table>
{$revision}

<table class="w patient">
  <tr><td class="k">Ad soyad</td><td class="v"><b>{$e($p['name'])}</b></td><td class="k">Barkod</td><td class="v mono">{$e($p['barcode'])}</td></tr>
  <tr><td class="k">Cinsiyet · yaş</td><td class="v">{$e($p['gender'])} · {$e($p['ageText'])}</td><td class="k">Dosya no</td><td class="v">{$dash($p['fileNo'])}</td></tr>
  <tr><td class="k">Doğum tarihi</td><td class="v">{$e($p['birthDate'])}</td><td class="k">Kurum</td><td class="v">{$dash($p['institution'])}</td></tr>
  <tr><td class="k">Numune kabul</td><td class="v">{$e($p['acceptedAt'])}</td><td class="k">Analiz</td><td class="v">{$dash($d['run']['injectedAt'])}</td></tr>
  <tr><td class="k">Numune türü</td><td class="v">{$dash($lab['sample_type'] ?? null)}</td><td class="k"></td><td class="v"></td></tr>
</table>

<div class="section">Sonuçlar</div>
<table class="w results">
  <tr class="th"><td>Test</td><td>Sonuç</td><td>Birim</td><td>Referans aralığı</td><td></td></tr>
  {$results}
</table>

<table class="w chromo-wrap"><tr>
  <td class="chromo-cell">{$chromo}</td>
  <td class="peaks-cell"><div class="section small-top">Pik tablosu</div>
    <table class="w peaks"><tr class="th"><td>Pik</td><td class="num">Süre (dk)</td><td class="num">Yükseklik</td><td class="num">Alan</td><td class="num">Alan %</td></tr>{$peakRows}</table>
  </td>
</tr></table>

<div class="section">Yorum</div>
<div class="comment">{$comment}</div>
{$expertNote}

<table class="w sign"><tr>
  <td class="muted small">Yöntem: {$e($lab['method'])}<br>{$e($draft ? '' : 'Elektronik olarak onaylanmıştır.')}</td>
  <td class="right">{$approval}</td>
</tr></table>
{$devicePage}
</body></html>
HTML;
    }

    private static function css(): string
    {
        [$r, $g, $b] = self::NAVY;
        $navy = "rgb($r,$g,$b)";
        return <<<CSS
@page { margin: 14mm 14mm 16mm 14mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #1d2733; line-height: 1.3; }
.w { width: 100%; border-collapse: collapse; }
.right { text-align: right; }
.muted { color: #667085; }
.small { font-size: 7.4pt; }
.mono { font-family: "DejaVu Sans Mono", monospace; }
.head td { vertical-align: top; }
.head { border-bottom: 2px solid $navy; margin-bottom: 6px; }
.head td { padding-bottom: 6px; }
.logo { height: 13mm; }
.lab-name { font-size: 14pt; font-weight: bold; color: $navy; }
.title { font-size: 12pt; font-weight: bold; color: $navy; margin-top: 2mm; }
.revision { margin: 4px 0; padding: 4px 8px; background: #fff4de; color: #7a4f00; font-size: 7.6pt; }
.patient { background: #f3f6fa; margin: 6px 0 4px; }
.patient td { padding: 1.6px 6px; }
.patient .k { color: #667085; width: 22%; }
.patient .v { width: 28%; }
.section { margin: 8px 0 3px; font-weight: bold; color: $navy; font-size: 9pt; }
.small-top { margin-top: 0; }
.results td { padding: 2.2px 6px; border-bottom: 0.5px solid #dfe3e8; }
.results .th td, .peaks .th td { color: #667085; font-size: 7.6pt; border-bottom: 1px solid #c5ccd5; }
.results .tname { width: 18%; font-weight: bold; }
.results .tval { width: 16%; font-weight: bold; font-size: 9.4pt; }
.results .tref { width: 22%; color: #475467; }
.results .tnote { color: #667085; font-size: 7.4pt; }
.results tr.high td { background: #fff4de; }
.results .arrow { color: #b42318; font-size: 7pt; }
.chromo-wrap { margin-top: 8px; }
.chromo-wrap td { vertical-align: top; }
.chromo-cell { width: 50%; text-align: center; vertical-align: top; }
.chromo { width: 88mm; }   /* tablo ön planda: grafik eski boyutuna yakın */
.cap { font-size: 7pt; color: #667085; }
.peaks-cell { padding-left: 12px; }
.peaks td { padding: 2.2px 5px; border-bottom: 0.5px solid #dfe3e8; font-size: 8.6pt; white-space: nowrap; }
.peaks tr.total td { color: #475467; border-bottom: 0; padding-top: 2px; }
.peaks .num { text-align: right; }
.peaks tr.abn td { background: #fde8e6; color: #8f1d14; font-weight: bold; }
.comment p { margin: 0 0 3px; text-align: justify; }
.comment { font-size: 7.7pt; line-height: 1.28; }
.comment { border-left: 2px solid $navy; padding-left: 8px; }
.note { margin-top: 6px; padding: 5px 8px; background: #f3f6fa; border: 0.5px solid #c5ccd5; page-break-inside: avoid; }
.note-title { font-weight: bold; color: $navy; margin-bottom: 2px; }
.note p { margin: 0 0 3px; font-size: 8pt; }
.note-by { font-weight: normal; color: #667085; font-size: 7.4pt; }
.sign { page-break-inside: avoid; margin-top: 8px; border-top: 0.5px solid #c5ccd5; }
.sign td { padding-top: 6px; vertical-align: top; }
.sign-name { font-weight: bold; font-size: 9pt; }
.watermark { position: fixed; top: 105mm; left: 10mm; width: 190mm; text-align: center; font-size: 96pt; font-weight: bold;
             color: rgba(180, 35, 24, 0.12); transform: rotate(-30deg); }
.page-break { page-break-before: always; }
.strip { background: #f3f6fa; border-bottom: 2px solid $navy; padding: 5px 8px; font-size: 8pt; }
CSS;
    }

    /* ------------------------------------------------------------------ */
    /*  Kromatogram ve cihaz sayfası                                        */
    /* ------------------------------------------------------------------ */

    /*
     * D-10 HbA2/F kromatogramının sabit ekseni (gerçek çıktılardan ölçüldü, 20.10.2026):
     *   y ekseni x=61'de, 0.050 (y=38) … 0.015 (y=401); x ekseni y=401'de, 0 dk (x=61) … 6 dk (x≈318)
     * Eksen yazılarının bulunduğu bölgenin piksel imzası bu sabitlerle birlikte doğrulanır;
     * imza tutmazsa (cihaz ölçeği/yazısı değişmişse) cihazın kendi yazıları korunur, yeniden çizilmez.
     */
    private const AXIS_LEFT_SIGNATURE = 'fe9af62c198095fb2f9166946ace3675';
    private const AXIS_Y_TOP = 0.050;              // y ekseni üst değeri (y=38)
    private const AXIS_Y_BOTTOM = 0.015;           // y ekseni alt değeri (x ekseni)
    private const AXIS_X_MINUTES = 6;              // x ekseni son çentiği (dk)
    private const PEAK_RED = [0xB4, 0x23, 0x18];   // anormal pik etiketi

    /**
     * Rapor için kromatogram: cihazın eğrisi (pikselleri aynen) + bizim büyük eksen yazılarımız.
     *  - Cihazın küçük eksen yazıları silinir; eksen değerleri ve "Süre (dk)" büyük fontla yeniden yazılır.
     *  - Cihazın eğri üstündeki küçük pik yazıları (0.21, A1c, 1.48 …) silinir: okunmuyor ve üst üste biniyordu;
     *    pik adları ve süreleri rapordaki pik tablosunda (laboratuvar kararı, 21.10.2026).
     *    Yazılar eğriye değmeyen küçük ayrı parçalardır (7 gerçek çıktıda ölçüldü); eğri tek büyük parçadır, ona dokunulmaz.
     *  - $peakBand = true: pik adları grafiğin üstündeki şeride yazılır (denendi, istenmedi; varsayılan kapalı).
     * Eksen doğrulanamazsa yalnızca recolorChromatogram() uygulanır (cihaz yazıları yerinde kalır).
     *
     * @param array $peaks [{name, rt, abnormal}]
     */
    public static function renderChromatogram(string $png, array $peaks = [], bool $peakBand = false): string
    {
        try {
            $out = self::renderChromatogramWithAxes($png, $peaks, $peakBand);
            if ($out !== null) return $out;
        } catch (\Throwable $ex) {
            // aşağıdaki yedek yola düş
        }
        return self::recolorChromatogram($png);
    }

    private static function renderChromatogramWithAxes(string $png, array $peaks, bool $peakBand): ?string
    {
        $src = @imagecreatefromstring($png);
        if ($src === false) return null;
        $w = imagesx($src);
        $h = imagesy($src);
        $px = function (int $x, int $y) use ($src) {
            $c = imagecolorsforindex($src, imagecolorat($src, $x, $y));
            $lum = 0.299 * $c['red'] + 0.587 * $c['green'] + 0.114 * $c['blue'];
            if ($lum >= 235) return 0;
            return ($c['blue'] > 150 && $c['red'] < 120 && $c['green'] < 120) ? 2 : 1;   // 1 koyu, 2 mavi
        };
        $black = fn(int $x, int $y) => (imagecolorat($src, $x, $y) & 0xFFFFFF) === 0;

        // Eksenler: en uzun dikey koyu çizgi (y ekseni) ve en uzun yatay koyu çizgi (x ekseni)
        [$ax, $axLen, $axTop] = [0, 0, 0];
        for ($x = 0; $x < $w; $x++) {
            $run = 0;
            for ($y = 0; $y < $h; $y++) {
                $run = $black($x, $y) ? $run + 1 : 0;
                if ($run > $axLen) [$ax, $axLen, $axTop] = [$x, $run, $y - $run + 1];
            }
        }
        [$ay, $ayLen, $ayRight] = [0, 0, 0];
        for ($y = 0; $y < $h; $y++) {
            $run = 0;
            for ($x = 0; $x < $w; $x++) {
                $run = $black($x, $y) ? $run + 1 : 0;
                if ($run > $ayLen) [$ay, $ayLen, $ayRight] = [$y, $run, $x];
            }
        }
        if ($axLen < 300 || $ayLen < 200) return null;

        // Eksen yazısı imzası: ölçek bilinen ölçek mi?
        $left = '';
        for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $ax - 1; $x++) $left .= $black($x, $y) ? '1' : '0';
        if (md5($left) !== self::AXIS_LEFT_SIGNATURE) return null;

        // Çentikler: y ekseninin solunda, x ekseninin altında
        $ticksY = [];
        for ($y = 0; $y < $h; $y++) if ($black($ax - 1, $y) && $black($ax - 2, $y) && !$black($ax - 4, $y)) $ticksY[] = $y;
        $ticksX = [];
        for ($x = 0; $x < $w; $x++) if ($black($x, $ay + 1) && $black($x, $ay + 2)) $ticksX[] = $x;
        if (count($ticksY) < 4 || count($ticksX) < 2) return null;
        $yTop = min($ticksY);                                 // 0.050
        $x6 = max($ticksX);                                   // 6 dk
        $valueAt = fn(int $y) => self::AXIS_Y_TOP - ($y - $yTop) * (self::AXIS_Y_TOP - self::AXIS_Y_BOTTOM) / ($ay - $yTop);
        $xAtMinute = fn(float $min) => $ax + $min * ($x6 - $ax) / self::AXIS_X_MINUTES;

        // Grafik içi (eksenlerin sağı ve üstü) pikseller
        $kind = [];
        $maxX = $ax;
        for ($y = 0; $y < $ay; $y++) {
            for ($x = $ax + 1; $x < $w; $x++) {
                $k = $px($x, $y);
                if ($k) { $kind[$y][$x] = $k; $maxX = max($maxX, $x); }
            }
        }

        // Cihazın küçük pik yazılarını sil: eğriye bağlı olmayan küçük parçalar (8-komşuluk)
        $seen = [];
        foreach ($kind as $y => $row) {
            foreach ($row as $x => $k) {
                if (isset($seen[$y][$x])) continue;
                $stack = [[$x, $y]];
                $seen[$y][$x] = true;
                $comp = [];
                [$cx0, $cy0, $cx1, $cy1] = [$x, $y, $x, $y];
                while ($stack) {
                    [$qx, $qy] = array_pop($stack);
                    $comp[] = [$qx, $qy];
                    $cx0 = min($cx0, $qx); $cx1 = max($cx1, $qx); $cy0 = min($cy0, $qy); $cy1 = max($cy1, $qy);
                    for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $qx + $dx; $ny = $qy + $dy;
                        if (isset($kind[$ny][$nx]) && !isset($seen[$ny][$nx])) { $seen[$ny][$nx] = true; $stack[] = [$nx, $ny]; }
                    }
                }
                $small = count($comp) < 60 && ($cx1 - $cx0) < 30 && ($cy1 - $cy0) < 30 && $cy1 < $ay - 2;
                if ($small) foreach ($comp as [$qx, $qy]) unset($kind[$qy][$qx]);
            }
        }

        // Tuval: solda y değerleri, altta süre için boşluk.
        // Varsayılan: yatay 5, dikey 2 kat — grafik yatay (raporda geniş), sık erken pikler birbirinden ayrılır
        // ve etiketlerine yer açılır. Eğrinin kendisi değişmez; yalnızca eksenlerin ölçeği (en-boy oranı).
        $S = $peakBand ? self::CHROMO_SCALE : 5;
        $SY = 2;
        $font = dirname(__DIR__, 2) . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
        $fontB = dirname(__DIR__, 2) . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
        $axisPt = 40;                          // raporda ~8 pt (pik tablosu yazısıyla aynı)
        $peakPt = $peakBand ? 26 : 34;         // tepe üstü pik yazısı raporda ~7 pt
        // Pik etiketleri. Tepe üstü (varsayılan) cihazın kuralıyla: F / A1c / A2 adıyla, diğerleri süreyle; A0 etiketlenmez.
        $labels = [];
        foreach ($peaks as $p) {
            $rt = is_numeric($p['rt'] ?? null) ? (float) $p['rt'] : null;
            if ($rt === null || $rt < 0 || $rt > self::AXIS_X_MINUTES) continue;
            $name = trim((string) $p['name']);
            if (!$peakBand && strcasecmp($name, 'A0') === 0) continue;
            $text = $peakBand || in_array(strtolower($name), ['f', 'a1c', 'a2'], true) ? $name : number_format($rt, 2, '.', '');
            $labels[] = ['text' => $text, 'rt' => $rt, 'abnormal' => !empty($p['abnormal'])];
        }
        usort($labels, fn($a, $b) => $a['rt'] <=> $b['rt']);
        $maxLabelW = 0;
        foreach ($labels as $l) {
            $bb = imagettfbbox($peakPt, 0, $fontB, $l['text']);
            $maxLabelW = max($maxLabelW, abs($bb[2] - $bb[0]));
        }
        $bbY = imagettfbbox($axisPt, 0, $font, '0.05');
        $L = abs($bbY[2] - $bbY[0]) + 44;                  // y değerlerinin genişliği + çentik + boşluk
        $T = $peakBand ? max(80, $maxLabelW + 40) : (int) ($axisPt * 0.9);
        $B = (int) ($axisPt * 4.2); $R = 40;
        $cw = $L + ($maxX - $ax + 1) * $S + $R;
        $ch = $T + ($ay - $yTop + 1) * $SY + $B;
        $mapX = fn(float $x) => (int) round($L + ($x - $ax) * $S);
        $mapY = fn(float $y) => (int) round($T + ($y - $yTop) * $SY);

        $dst = imagecreatetruecolor($cw, $ch);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        $navy  = imagecolorallocate($dst, ...self::NAVY);
        $blue  = imagecolorallocate($dst, ...self::CHROMO_BLUE);
        $red   = imagecolorallocate($dst, ...self::PEAK_RED);
        $text  = imagecolorallocate($dst, 0x34, 0x40, 0x54);
        $guide = imagecolorallocate($dst, 0x98, 0xA2, 0xB3);

        // Eğri ve alanlar (cihaz pikselleri aynen; önce mavi, sonra lacivert).
        // Her piksel hücresinin ortasına ince (3 px) çizilir; yan komşusu olan tarafa hücre kenarına kadar uzatılır.
        // Böylece yatay büyütmede dik çizgiler kalınlaşmaz, yatay ve eğik çizgiler kopmaz.
        $thin = min($S, 3);
        foreach ([2 => $blue, 1 => $navy] as $k => $col) {
            foreach ($kind as $y => $row) {
                foreach ($row as $x => $v) {
                    if ($v !== $k || $y < $yTop - 2) continue;
                    $x0 = $mapX($x); $y0 = $mapY($y);
                    $c0 = $x0 + intdiv($S - $thin, 2);
                    $c1 = $c0 + $thin - 1;
                    if (isset($kind[$y][$x - 1]) || isset($kind[$y - 1][$x - 1]) || isset($kind[$y + 1][$x - 1])) $c0 = $x0;
                    if (isset($kind[$y][$x + 1]) || isset($kind[$y - 1][$x + 1]) || isset($kind[$y + 1][$x + 1])) $c1 = $x0 + $S - 1;
                    $grow = $k === 1 ? 1 : 0;
                    imagefilledrectangle($dst, $c0, $y0, $c1 + $grow, $y0 + $SY - 1 + $grow, $col);
                }
            }
        }

        // Eksenler, çentikler, değerler
        imagesetthickness($dst, 3);
        imageline($dst, $mapX($ax), $mapY($yTop), $mapX($ax), $mapY($ay), $navy);
        imageline($dst, $mapX($ax), $mapY($ay), $mapX($x6), $mapY($ay), $navy);
        // Çentikler eşit aralıklı: değer sıradan hesaplanır (piksel yuvarlaması değeri kaydırmasın)
        sort($ticksY);
        $step = (self::AXIS_Y_TOP - self::AXIS_Y_BOTTOM) / (count($ticksY) - 1);
        foreach ($ticksY as $i => $ty) {
            imageline($dst, $mapX($ax) - 14, $mapY($ty), $mapX($ax), $mapY($ty), $navy);
            $v = round(self::AXIS_Y_TOP - $i * $step, 4);
            if (abs($v * 100 - round($v * 100)) < 0.0001) {   // yalnızca 0.05, 0.04, 0.03, 0.02 (cihazdaki gibi)
                $label = number_format($v, 2, '.', '');
                $bb = imagettfbbox($axisPt, 0, $font, $label);
                imagettftext($dst, $axisPt, 0, $mapX($ax) - 22 - abs($bb[2] - $bb[0]), $mapY($ty) + (int) ($axisPt * 0.5), $text, $font, $label);
            }
        }
        for ($m = 0; $m <= self::AXIS_X_MINUTES; $m += 2) {
            $tx = $mapX($xAtMinute($m));
            imageline($dst, $tx, $mapY($ay), $tx, $mapY($ay) + 14, $navy);
            $bb = imagettfbbox($axisPt, 0, $font, (string) $m);
            imagettftext($dst, $axisPt, 0, $tx - (int) (abs($bb[2] - $bb[0]) / 2), $mapY($ay) + 18 + (int) ($axisPt * 1.4), $text, $font, (string) $m);
        }
        $title = 'Süre (dk)';
        $bb = imagettfbbox($axisPt, 0, $font, $title);
        imagettftext($dst, $axisPt, 0, (int) (($mapX($ax) + $mapX($x6)) / 2 - abs($bb[2] - $bb[0]) / 2), $mapY($ay) + 30 + (int) ($axisPt * 3.0), $text, $font, $title);

        // Pik etiketleri: süreye göre sıralı, dikey yazı; ince çizgiyle tepeye bağlı.
        //   tepe üstü (varsayılan): her küme kendi en yüksek tepesinin hemen üstünde
        //   şerit ($peakBand): grafiğin üstündeki şeritte
        // Yerleşim: birbirine yakın pikler bir küme olur; küme kendi piklerinin ortasına ortalanır (çizgiler kısa, kesişmez)
        $gap = $peakBand ? $peakPt + 12 : $peakPt + 8;   // tepe üstünde sıkı: erken piklerin grubu P3 ile A0 arasına taşmasın
        $slots = [];
        foreach ($labels as $l) {
            $sx = (float) $xAtMinute($l['rt']);
            $isx = (int) round($sx);
            $apexY = $ay;   // tepe: o sütun civarındaki en üstteki eğri pikseli (cihaz yazıları silindikten sonra)
            for ($dx = -1; $dx <= 1; $dx++) {
                foreach ($kind as $y => $row) {
                    if (isset($row[$isx + $dx]) && $y < $apexY) $apexY = $y;
                }
            }
            $bb = imagettfbbox($peakPt, 0, $fontB, $l['text']);
            $slots[] = $l + ['target' => $mapX($sx), 'sx' => $isx, 'apex' => $mapY(max($apexY, $yTop)), 'len' => abs($bb[2] - $bb[0])];
        }
        if (!$peakBand) {
            self::drawApexLabels($dst, $slots, $gap, $mapX($ax) + (int) ($gap / 2), $cw - $R, $peakPt, $fontB, $navy, $red, $guide);
            ob_start();
            imagepng($dst, null, 9);
            return (string) ob_get_clean();
        }
        $minPos = $mapX($ax) + (int) ($gap / 2);
        $maxPos = $cw - $R;
        $place = function (array $idx) use ($slots, $gap, $minPos, $maxPos) {   // küme başlangıcı (ortalanmış, sınırlar içinde)
            $center = array_sum(array_map(fn($j) => $slots[$j]['target'], $idx)) / count($idx);
            $start = $center - (count($idx) - 1) * $gap / 2;
            return max($minPos, min($start, $maxPos - (count($idx) - 1) * $gap));
        };
        $clusters = array_map(fn($i) => [$i], array_keys($slots));
        do {   // komşu kümeler çakıştıkça birleştir
            $merged = false;
            for ($c = 0; $c < count($clusters) - 1; $c++) {
                $endA = $place($clusters[$c]) + (count($clusters[$c]) - 1) * $gap;
                if ($endA + $gap > $place($clusters[$c + 1])) {
                    array_splice($clusters, $c, 2, [array_merge($clusters[$c], $clusters[$c + 1])]);
                    $merged = true;
                    break;
                }
            }
        } while ($merged);
        foreach ($clusters as $idx) {
            $start = $place($idx);
            foreach ($idx as $k => $i) $slots[$i]['pos'] = (int) round($start + $k * $gap);
        }
        imagesetthickness($dst, 2);
        foreach ($slots as $s) {
            $pos = $s['pos'];
            // Tepe: o sütun civarındaki en üstteki eğri pikseli
            $apexY = $ay;
            for ($dx = -1; $dx <= 1; $dx++) {
                foreach ($kind as $y => $row) {
                    if (isset($row[$s['sx'] + $dx]) && $y < $apexY) $apexY = $y;
                }
            }
            $apex = max($mapY(max($apexY, $yTop)) - 8, $T - 4);
            imageline($dst, $pos, $T - 10, $pos, $T - 4, $guide);
            imageline($dst, $pos, $T - 4, $s['target'], $apex, $guide);
            $col = $s['abnormal'] ? $red : $navy;
            imagettftext($dst, $peakPt, 90, $pos + (int) ($peakPt * 0.45), $T - 16, $col, $fontB, $s['text']);
        }

        ob_start();
        imagepng($dst, null, 9);
        return (string) ob_get_clean();
    }

    /**
     * Pik etiketlerini tepelerin hemen üstüne yazar (dikey). Yakın pikler küme olur: etiketler yan yana dizilir,
     * kümenin en yüksek tepesinin üstünde durur ve her biri ince bir çizgiyle kendi tepesine bağlanır.
     * @param array $slots [{text, abnormal, target(x), apex(y), len(yazı boyu)}] süreye göre sıralı
     */
    private static function drawApexLabels($dst, array $slots, int $gap, int $minPos, int $maxPos, int $pt, string $font, int $navy, int $red, int $guide): void
    {
        if (!$slots) return;
        $place = function (array $idx) use ($slots, $gap, $minPos, $maxPos) {
            $center = array_sum(array_map(fn($j) => $slots[$j]['target'], $idx)) / count($idx);
            $start = $center - (count($idx) - 1) * $gap / 2;
            return max($minPos, min($start, $maxPos - (count($idx) - 1) * $gap));
        };
        $clusters = array_map(fn($i) => [$i], array_keys($slots));
        do {   // komşu kümeler yatayda çakıştıkça birleştir
            $merged = false;
            for ($c = 0; $c < count($clusters) - 1; $c++) {
                $endA = $place($clusters[$c]) + (count($clusters[$c]) - 1) * $gap;
                if ($endA + $gap > $place($clusters[$c + 1])) {
                    array_splice($clusters, $c, 2, [array_merge($clusters[$c], $clusters[$c + 1])]);
                    $merged = true;
                    break;
                }
            }
        } while ($merged);

        imagesetthickness($dst, 2);
        foreach ($clusters as $idx) {
            $start = $place($idx);
            $top = min(array_map(fn($i) => $slots[$i]['apex'], $idx));          // kümenin en yüksek tepesi
            $single = count($idx) === 1;
            $base = $top - ($single ? 10 : 34);                                   // yazının alt ucu (küme ise çizgiye yer)
            $base = max($base, max(array_map(fn($i) => $slots[$i]['len'], $idx)) + 6);   // tuvalin üstünden taşmasın
            foreach ($idx as $k => $i) {
                $s = $slots[$i];
                $pos = (int) round($start + $k * $gap);
                if (!$single || abs($pos - $s['target']) > 3) {
                    imageline($dst, $pos, $base + 4, $s['target'], $s['apex'] - 5, $guide);
                }
                imagettftext($dst, $pt, 90, $pos + (int) ($pt * 0.45), $base, $s['abnormal'] ? $red : $navy, $font, $s['text']);
            }
        }
    }

    private const CHROMO_SCALE = 3;               // her cihaz pikseli 3×3 olur (yumuşatmasız büyütme)
    private const CHROMO_BLUE  = [0x3C, 0x8D, 0xC5]; // cihazın mavi alanı (A1c entegrasyonu) → kurumsal açık mavi

    /**
     * Cihazın kromatogram görüntüsü (360×460, siyah/beyaz/mavi) rapor için netleştirilir. İçerik değişmez:
     *  - boş kenarlar kırpılır (eğri verilen alanı daha çok kaplar)
     *  - 3 kat, yumuşatmadan büyütülür (PDF görüntüleyici bulanıklaştırmaz)
     *  - siyah (eğri, eksen, etiketler) → kurumsal lacivert, çizgiler 1 alt-piksel kalınlaştırılır
     *  - mavi (cihazın taradığı alan) → kurumsal açık mavi
     */
    public static function recolorChromatogram(string $png): string
    {
        $src = @imagecreatefromstring($png);
        if ($src === false) return $png;
        $w = imagesx($src);
        $h = imagesy($src);

        // Sınıflandır ve dolu alanın sınırlarını bul
        $kind = [];   // [y][x] => 1 koyu, 2 mavi
        $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorsforindex($src, imagecolorat($src, $x, $y));
                $lum = 0.299 * $c['red'] + 0.587 * $c['green'] + 0.114 * $c['blue'];
                if ($lum >= 235) continue;                                         // arka plan
                $isBlue = $c['blue'] > 150 && $c['red'] < 120 && $c['green'] < 120;
                $kind[$y][$x] = $isBlue ? 2 : 1;
                $minX = min($minX, $x); $maxX = max($maxX, $x);
                $minY = min($minY, $y); $maxY = max($maxY, $y);
            }
        }
        if ($maxX < 0) return $png;

        $pad = 4;
        $minX = max(0, $minX - $pad); $minY = max(0, $minY - $pad);
        $maxX = min($w - 1, $maxX + $pad); $maxY = min($h - 1, $maxY + $pad);
        $s = self::CHROMO_SCALE;
        $dw = ($maxX - $minX + 1) * $s;
        $dh = ($maxY - $minY + 1) * $s;

        $dst = imagecreatetruecolor($dw, $dh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        $navy = imagecolorallocate($dst, ...self::NAVY);
        $blue = imagecolorallocate($dst, ...self::CHROMO_BLUE);

        // Önce mavi alanlar, sonra lacivert çizgiler (çizgi her zaman üstte kalsın)
        foreach ([2 => $blue, 1 => $navy] as $k => $col) {
            foreach ($kind as $y => $row) {
                foreach ($row as $x => $v) {
                    if ($v !== $k) continue;
                    $x0 = ($x - $minX) * $s;
                    $y0 = ($y - $minY) * $s;
                    $grow = $k === 1 ? 1 : 0;   // koyu çizgiler sağa/aşağı 1 piksel kalınlaşır
                    imagefilledrectangle($dst, $x0, $y0, min($dw - 1, $x0 + $s - 1 + $grow), min($dh - 1, $y0 + $s - 1 + $grow), $col);
                }
            }
        }
        ob_start();
        imagepng($dst, null, 9);
        return (string) ob_get_clean();
    }

    // Bizim PDF'in sayfaları + son sayfaya cihaz PDF'inin ilk sayfası (şeridin altına, ölçeklenerek, içerik değişmeden)
    private static function appendDevicePage(string $ourPdf, string $devicePdf, string $title): string
    {
        $fpdi = new Fpdi('P', 'mm', 'A4');
        $fpdi->SetTitle($title, true);   // birleştirme belge bilgisini sıfırlar: başlık (dosya adı) yeniden yazılır
        $fpdi->SetCreator('HbRapor', true);
        $fpdi->SetAutoPageBreak(false);
        $count = $fpdi->setSourceFile(StreamReader::createByString($ourPdf));
        for ($i = 1; $i <= $count; $i++) {
            $tpl = $fpdi->importPage($i);
            $fpdi->AddPage();
            $fpdi->useTemplate($tpl, 0, 0, 210, 297);
        }

        // Son sayfa (şerit) üzerine cihaz sayfası
        try {
            $fpdi->setSourceFile(StreamReader::createByString($devicePdf));
            $dev = $fpdi->importPage(1);
            $size = $fpdi->getTemplateSize($dev);
            $maxW = 182;
            $maxH = 297 - 14 - self::STRIP_MM - 18;
            $scale = min($maxW / $size['width'], $maxH / $size['height']);
            $fpdi->useTemplate($dev, 14, 14 + self::STRIP_MM, $size['width'] * $scale, $size['height'] * $scale);
        } catch (\Throwable $ex) {
            // Cihaz PDF'i okunamazsa rapor yine üretilir; şerit sayfasında not kalır
            $fpdi->SetFont('Helvetica', '', 9);
            $fpdi->SetXY(14, 14 + self::STRIP_MM);
            $fpdi->Cell(0, 6, 'Device PDF could not be embedded: ' . substr($ex->getMessage(), 0, 120));
        }
        return $fpdi->Output('S');
    }
}
