<?php
declare(strict_types=1);

namespace Biolab\Core\Qms;

/**
 * QMS dosya deposu: web kökü dışında (config.php → qms_storage, yoksa proje/storage/qms).
 * Veritabanında depoya göre yol tutulur (ör. doc/12/r15/main-3fa9c2d1.pdf); depo taşınırsa yalnızca ayar değişir.
 * Dosyalar üzerine yazılmaz: adında içeriğin SHA-256'sının başı vardır.
 */
final class FileStorage
{
    private static ?string $root = null;

    public static function root(): string
    {
        if (self::$root === null) {
            $settings = require dirname(__DIR__, 2) . '/config.php';
            $root = rtrim((string) ($settings['qms_storage'] ?? dirname(__DIR__, 2) . '/storage/qms'), '\\/');
            if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
                throw new \RuntimeException('Dosya deposu oluşturulamadı: ' . $root);
            }
            self::$root = $root;
        }
        return self::$root;
    }

    /**
     * Dosyayı depoya koyar, depoya göre yolu döner. $uploaded: tarayıcıdan gelen geçici dosya (move_uploaded_file);
     * değilse sunucuda üretilmiş dosya (ör. görselden PDF) kopyalanır.
     */
    public static function put(string $from, string $dir, string $kind, string $ext, bool $uploaded): string
    {
        $hash = hash_file('sha256', $from);
        $rel  = trim($dir, '/') . '/' . $kind . '-' . substr($hash, 0, 8) . '.' . strtolower($ext);
        $abs  = self::absolute($rel);
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0770, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('Klasör oluşturulamadı: ' . dirname($rel));
        }
        $ok = $uploaded ? move_uploaded_file($from, $abs) : copy($from, $abs);
        if (!$ok) {
            throw new \RuntimeException('Dosya depoya yazılamadı: ' . $rel);
        }
        return $rel;
    }

    // Sunucuda üretilen içerik (imza resmi, mühürlü PDF) depoya yazılır; depoya göre yolu döner
    public static function putContent(string $bytes, string $dir, string $kind, string $ext): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qms');
        try {
            file_put_contents($tmp, $bytes);
            return self::put($tmp, $dir, $kind, $ext, false);
        } finally {
            @unlink($tmp);
        }
    }

    public static function absolute(string $rel): string
    {
        if (str_contains($rel, '..')) {
            throw new \InvalidArgumentException('Geçersiz dosya yolu.');
        }
        return self::root() . '/' . ltrim($rel, '/');
    }

    // Yarım kalan yüklemenin dosyaları (transaction geri alındığında)
    public static function delete(string $rel): void
    {
        $abs = self::absolute($rel);
        if (is_file($abs)) {
            unlink($abs);
        }
    }

    // Görseli (jpg/png) tek sayfalık A4 PDF'e çevirir; imza akışı PDF üzerinde yürür
    public static function imageToPdf(string $imagePath, string $pdfPath): void
    {
        $info = getimagesize($imagePath);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new \InvalidArgumentException('Görsel okunamadı.');
        }
        [$w, $h] = $info;
        $landscape = $w > $h;
        $pdf = new \FPDF($landscape ? 'L' : 'P', 'mm', 'A4');
        $pdf->SetMargins(0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pageW = $landscape ? 297 : 210;
        $pageH = $landscape ? 210 : 297;
        $scale = min(($pageW - 20) / $w, ($pageH - 20) / $h);
        $iw = $w * $scale;
        $ih = $h * $scale;
        $pdf->Image($imagePath, ($pageW - $iw) / 2, ($pageH - $ih) / 2, $iw, $ih, $info[2] === IMAGETYPE_PNG ? 'PNG' : 'JPG');
        $pdf->Output('F', $pdfPath);
    }
}
