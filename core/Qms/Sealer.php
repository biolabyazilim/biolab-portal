<?php
declare(strict_types=1);

namespace Biolab\Core\Qms;

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * İmzalı dokümanın mühürlenmesi. Yüklenen PDF'e dokunulmaz; sonuna "Elektronik onay sayfası" eklenir:
 * doküman bilgisi, PDF'in SHA-256'sı, imza akışı (rol, ad, unvan, pozisyon, zaman, imza resmi, kayıt hash'i).
 * Her imzadan sonra sayfa baştan üretilir ve özgün PDF ile yeniden birleştirilir.
 *
 * Birleştirme sırası:
 *   1. FPDI (ücretsiz sürüm PDF 1.4'e kadar; sıkıştırılmış xref/object stream'li PDF'leri açamaz)
 *   2. qpdf (config.php → qms_qpdf: qpdf.exe yolu; her PDF'i birleştirir)
 *   3. ikisi de olmazsa birleştirilmez: onay sayfası ayrı dosya olarak saklanır, ekranda PDF ile birlikte gösterilir
 */
final class Sealer
{
    public const ROLE_LABELS = ['PREPARED' => 'Hazırlayan', 'CHECKED' => 'Kontrol eden', 'APPROVED' => 'Onaylayan'];

    /**
     * Onay sayfası (PDF içeriği).
     * @param array{code:?string, variant:string, title:string, revisionNo:int, type:string, owner:string, pdfHash:string} $doc
     * @param array<int, array{role:string, name:string, title:?string, position:?string, status:string,
     *                         signedAt:?\DateTimeInterface, imagePng:?string, recordHash:?string}> $steps imza sırasıyla
     */
    public static function certificate(array $doc, array $steps): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($steps as $s) {
            $signed = $s['status'] === 'SIGNED';
            $img = $signed && $s['imagePng'] !== null
                ? '<img src="data:image/png;base64,' . base64_encode($s['imagePng']) . '">'
                : '<span class="wait">' . ($s['status'] === 'ACTIVE' ? 'İmza bekleniyor' : 'Sırası gelmedi') . '</span>';
            $rows .= '<tr>'
                . '<td class="role">' . $e(self::ROLE_LABELS[$s['role']] ?? $s['role']) . '</td>'
                . '<td><b>' . $e(trim(($s['title'] ? $s['title'] . ' ' : '') . $s['name'])) . '</b><br><span class="muted">' . $e($s['position']) . '</span></td>'
                . '<td class="sig">' . $img . '</td>'
                . '<td>' . ($signed && $s['signedAt'] ? $e($s['signedAt']->format('d.m.Y H:i:s')) : '—') . '</td>'
                . '</tr>'
                . ($signed && $s['recordHash'] ? '<tr class="hash"><td></td><td colspan="3">İmza kaydı: ' . $e($s['recordHash']) . '</td></tr>' : '');
        }
        $code = trim(($doc['code'] ?? '') . ($doc['variant'] !== '' ? ' · ' . $doc['variant'] : ''));

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>
            @page { margin: 22mm 18mm; }
            body { font-family: "DejaVu Sans"; font-size: 9.5pt; color: #1f2937; }
            h1 { font-size: 14pt; margin: 0 0 4mm; letter-spacing: .5pt; }
            .box { border: 1px solid #d0d5dd; border-radius: 4px; padding: 3mm 4mm; margin-bottom: 6mm; }
            .box td { padding: 1mm 2mm 1mm 0; vertical-align: top; }
            .box td.k { color: #667085; width: 38mm; }
            table.flow { width: 100%; border-collapse: collapse; }
            table.flow th { text-align: left; font-size: 8.5pt; color: #475467; border-bottom: 1px solid #d0d5dd; padding: 2mm 2mm 2mm 0; }
            table.flow td { padding: 2.5mm 2mm 2.5mm 0; vertical-align: middle; border-bottom: 1px solid #eef0f3; }
            table.flow td.role { width: 28mm; font-weight: bold; }
            table.flow td.sig { width: 55mm; height: 20mm; }
            table.flow td.sig img { max-width: 52mm; max-height: 19mm; }
            table.flow tr.hash td { border-bottom: 1px solid #d0d5dd; font-size: 7pt; color: #98a2b3; padding-top: 0; }
            .muted { color: #667085; font-size: 8.5pt; } .wait { color: #98a2b3; font-style: italic; }
            .note { margin-top: 8mm; font-size: 8pt; color: #475467; line-height: 1.5; }
            .mono { font-family: "DejaVu Sans Mono"; font-size: 8pt; }
        </style></head><body>
            <h1>ELEKTRONİK ONAY SAYFASI</h1>
            <div class="box"><table>
                <tr><td class="k">Doküman</td><td><b>' . $e($code) . '</b> ' . $e($doc['title']) . '</td></tr>
                <tr><td class="k">Revizyon</td><td>' . str_pad((string) $doc['revisionNo'], 2, '0', STR_PAD_LEFT) . '</td></tr>
                <tr><td class="k">Doküman tipi</td><td>' . $e($doc['type']) . '</td></tr>
                <tr><td class="k">Sahip departman</td><td>' . $e($doc['owner']) . '</td></tr>
                <tr><td class="k">Dokümanın SHA-256 özeti</td><td class="mono">' . $e($doc['pdfHash']) . '</td></tr>
            </table></div>
            <table class="flow">
                <tr><th>Adım</th><th>Ad soyad / pozisyon</th><th>İmza</th><th>Tarih</th></tr>' . $rows . '
            </table>
            <div class="note">Bu sayfa, yukarıda özeti verilen dokümanın ayrılmaz parçasıdır. İmzalar Biolab QMS sisteminde, imzacının kendi
            oturumu ve şifresiyle atılmıştır; imza kayıtları değiştirilemez ve birbirine zincirlenmiştir. Dokümanın özeti sistemdeki
            kayıtla karşılaştırılarak içeriğin imzadan sonra değişmediği doğrulanabilir.<br>
            Sayfa oluşturma zamanı: ' . (new \DateTimeImmutable())->format('d.m.Y H:i:s') . '</div>
        </body></html>';

        $opt = new Options();
        $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('isRemoteEnabled', false);
        $opt->set('isPhpEnabled', false);
        $opt->set('tempDir', sys_get_temp_dir());
        $opt->set('chroot', [dirname(__DIR__, 2)]);
        $pdf = new Dompdf($opt);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        return $pdf->output();
    }

    /**
     * Özgün PDF + onay sayfası → tek PDF. Birleştirilemezse null (onay sayfası ayrı saklanır).
     */
    public static function merge(string $originalPath, string $certificatePdf): ?string
    {
        try {
            $fpdi = new Fpdi();
            $fpdi->SetAutoPageBreak(false);
            foreach ([StreamReader::createByFile($originalPath), StreamReader::createByString($certificatePdf)] as $source) {
                $count = $fpdi->setSourceFile($source);
                for ($i = 1; $i <= $count; $i++) {
                    $tpl  = $fpdi->importPage($i);
                    $size = $fpdi->getTemplateSize($tpl);
                    $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $fpdi->useTemplate($tpl);
                }
            }
            return $fpdi->Output('S');
        } catch (\Throwable) {
            return self::mergeWithQpdf($originalPath, $certificatePdf);
        }
    }

    /**
     * Form doldurma: ekranda çizilen katmanlar (sayfa başına şeffaf PNG) PDF'in kopyasının sayfalarına tam sayfa basılır.
     * @param array<int, string> $pageImages sayfa no (1'den) → PNG baytları; işaret olmayan sayfa yoktur
     * @param array<int, array{0: float, 1: float}> $pageSizes sayfa no → [genişlik, yükseklik] (pt); qpdf yedeği için
     * @return string|null doldurulmuş PDF; PDF açılamazsa ve qpdf yoksa null
     */
    public static function overlay(string $originalPath, array $pageImages, array $pageSizes): ?string
    {
        $tmp = [];
        try {
            foreach ($pageImages as $no => $png) {
                $tmp[$no] = tempnam(sys_get_temp_dir(), 'qms');   // tür Image() çağrısında verilir; uzantı gerekmez
                file_put_contents($tmp[$no], $png);
            }
            try {
                $fpdi = new Fpdi();
                $fpdi->SetAutoPageBreak(false);
                $count = $fpdi->setSourceFile(StreamReader::createByFile($originalPath));
                for ($i = 1; $i <= $count; $i++) {
                    $tpl  = $fpdi->importPage($i);
                    $size = $fpdi->getTemplateSize($tpl);
                    $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $fpdi->useTemplate($tpl);
                    if (isset($tmp[$i])) {
                        $fpdi->Image($tmp[$i], 0, 0, $size['width'], $size['height'], 'PNG');
                    }
                }
                return $fpdi->Output('S');
            } catch (\Throwable) {
                return self::overlayWithQpdf($originalPath, $tmp, $pageSizes);
            }
        } finally {
            foreach ($tmp as $f) {
                @unlink($f);
            }
        }
    }

    // FPDI açamadıysa: işaretlerden ayrı bir PDF (aynı sayfa boyutlarında) üretilir, qpdf --overlay ile üst üste basılır
    private static function overlayWithQpdf(string $originalPath, array $images, array $pageSizes): ?string
    {
        $settings = require dirname(__DIR__, 2) . '/config.php';
        $qpdf = (string) ($settings['qms_qpdf'] ?? '');
        if ($qpdf === '' || !is_file($qpdf) || !$pageSizes) {
            return null;
        }
        $layer = new \FPDF('P', 'pt');
        $layer->SetAutoPageBreak(false);
        for ($i = 1; $i <= max(array_keys($pageSizes)); $i++) {
            [$w, $h] = $pageSizes[$i] ?? [595.28, 841.89];
            $layer->AddPage($w > $h ? 'L' : 'P', [$w, $h]);
            if (isset($images[$i])) {
                $layer->Image($images[$i], 0, 0, $w, $h, 'PNG');
            }
        }
        $layerPath = tempnam(sys_get_temp_dir(), 'qms');
        $out = tempnam(sys_get_temp_dir(), 'qms');
        try {
            $layer->Output('F', $layerPath);
            $cmd = escapeshellarg($qpdf) . ' ' . escapeshellarg($originalPath) . ' --overlay ' . escapeshellarg($layerPath) . ' -- ' . escapeshellarg($out);
            exec($cmd . ' 2>&1', $lines, $code);
            return in_array($code, [0, 3], true) && filesize($out) > 0 ? file_get_contents($out) : null;
        } finally {
            @unlink($layerPath);
            @unlink($out);
        }
    }

    private static function mergeWithQpdf(string $originalPath, string $certificatePdf): ?string
    {
        $settings = require dirname(__DIR__, 2) . '/config.php';
        $qpdf = (string) ($settings['qms_qpdf'] ?? '');
        if ($qpdf === '' || !is_file($qpdf)) {
            return null;
        }
        $cert = tempnam(sys_get_temp_dir(), 'qms');
        $out  = tempnam(sys_get_temp_dir(), 'qms');
        try {
            file_put_contents($cert, $certificatePdf);
            $cmd = escapeshellarg($qpdf) . ' --empty --pages ' . escapeshellarg($originalPath) . ' ' . escapeshellarg($cert) . ' -- ' . escapeshellarg($out);
            exec($cmd . ' 2>&1', $lines, $code);
            // qpdf: 0 başarılı, 3 uyarılarla başarılı
            return in_array($code, [0, 3], true) && filesize($out) > 0 ? file_get_contents($out) : null;
        } finally {
            @unlink($cert);
            @unlink($out);
        }
    }
}
