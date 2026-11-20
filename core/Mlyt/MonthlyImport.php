<?php
declare(strict_types=1);

namespace Biolab\Core\Mlyt;

use BIO\MLYT\FireReport;
use BIO\MLYT\LabSample;
use BIO\MLYT\LabTestCount;
use BIO\MLYT\NetsisOutflow;
use BIO\MLYT\PeriodFile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aylık takip: yüklenen Excel'i okuyup PeriodFile'ın satırlarını yazar (eski satırlar silinir).
 * Kolonlar başlık adından bulunur (sıra değişse de okunur); kişisel kolonlar (ad, doğum tarihi, TC, dosya no, doktor)
 * hiç okunmaz. Dönem dışındaki tarihli satırlar atlanır: çok aylık Netsis dosyası her aya ayrı ayrı yüklenebilir.
 *
 *   $res = (new MonthlyImport($em))->import($file, $tmpPath);   // ['rows' => 3415, 'skipped' => 0, 'warnings' => [...]]
 *
 * Hata (zorunlu kolon yok, hiç satır yok…) \RuntimeException ile döner; mesaj kullanıcıya gösterilir.
 */
final class MonthlyImport
{
    /** Zorunlu / isteğe bağlı başlıklar: anahtar → kabul edilen başlık adları (normalize edilmiş) */
    private const NETSIS = [
        'date' => ['TARIH'], 'docNo' => ['FISNO'], 'code' => ['STOKKODU'], 'name' => ['STOKISMI', 'STOKADI'],
        'qty' => ['CIKISMIKTARI'], 'unit' => ['OLCUBR1'],
    ];
    private const NETSIS_OPT = [
        'qty2' => ['CIKISMIKTARI2'], 'unit2' => ['OLCUBR2'], 'note' => ['ACIKLAMA'],
        'from' => ['MALZEMECIKANDEPOKODU', 'CIKANDEPOKODU', 'CIKISDEPOKODU'], 'to' => ['MALZEMEGIRENDEPOKODU', 'GIRENDEPOKODU', 'GIRISDEPOKODU'],
    ];
    private const TEST_COUNT = ['code' => ['ENTEGRASYONKOD', 'ENTEGRASYONKODU', 'KOD'], 'name' => ['TESTADI', 'TEST'], 'qty' => ['TESTSAYISI'], 'repeat' => ['TEKRAR']];
    private const TEST_COUNT_OPT = ['group' => ['TESTGRUBU', 'UNITE']];
    private const SAMPLE = ['date' => ['TARIH'], 'sampleNo' => ['ORNEKNO'], 'barcodes' => ['BARKODLAR'], 'tests' => ['BEKLEYENTESTLER']];
    private const SAMPLE_OPT = ['accepted' => ['KABULTARIHI'], 'approved' => ['ONAYTARIHI', 'ONAYTARIH'], 'arrival' => ['DURUM'], 'institution' => ['BOLUM']];
    // Fire / zayi bildirim formu: şube formu (Şube / birim, Malzeme…) ya da PCR formu (Kit / malzeme, Run…). "Bildiren" okunmaz.
    private const FIRE = ['date' => ['TARIH'], 'qty' => ['MIKTAR'], 'reason' => ['SEBEP']];
    private const FIRE_OPT = [
        'material' => ['MALZEME', 'KITMALZEME'], 'stock' => ['STOKKODU'], 'unit' => ['SUBEBIRIM', 'SUBE'], 'measure' => ['OLCU'],
        'run' => ['RUNPLAKANO', 'RUN'], 'affected' => ['ETKILENENTESTSAYISI'], 'retested' => ['TESTLERTEKRARCALISILDIMI'],
        'lot' => ['BARKODLOTNO', 'LOTNO'], 'note' => ['ACIKLAMA'],
    ];

    private const BATCH = 500;

    /** Bilgi notu öneki (uyarı sayılmaz; ekranda soluk gösterilir) */
    public const INFO = 'ℹ ';

    public function __construct(private EntityManagerInterface $em) {}

    /** @return array{rows:int, skipped:int, warnings:list<string>} */
    public function import(PeriodFile $file, string $path): array
    {
        $reader = new XlsxReader($path);
        $names = $reader->sheetNames();
        if (!$names) { throw new \RuntimeException('Excel dosyasında sayfa yok.'); }
        $rows = $reader->sheet($names[0]);   // ilk sayfa (Netsis dosyasındaki "depo kodları" sayfası okunmaz)

        $this->deleteRows($file);
        $res = match ($file->getSourceCode()) {
            PeriodFile::NETSIS_OUT     => $this->netsis($file, $rows),
            PeriodFile::LAB_TEST_COUNT => $this->testCount($file, $rows),
            PeriodFile::FIRE_REPORT    => $this->fire($file, $rows),
            default                    => $this->samples($file, $rows),
        };
        $file->setRowCount($res['rows'])->setWarnings($res['warnings'] ? implode("\n", $res['warnings']) : null);
        $this->em->flush();
        return $res;
    }

    /** Dosyanın satırlarını siler (yeniden yükleme ve dosya silme) */
    public function deleteRows(PeriodFile $file): void
    {
        if ($file->getFileId() === null) { return; }
        $class = match ($file->getSourceCode()) {
            PeriodFile::NETSIS_OUT     => NetsisOutflow::class,
            PeriodFile::LAB_TEST_COUNT => LabTestCount::class,
            PeriodFile::FIRE_REPORT    => FireReport::class,
            default                    => LabSample::class,
        };
        $this->em->createQuery("DELETE FROM $class x WHERE x.file = :FILE")->setParameter('FILE', $file)->execute();
    }

    /* ---------- Kaynaklar ---------- */

    private function netsis(PeriodFile $file, array $rows): array
    {
        [$hdr, $col] = $this->header($rows, self::NETSIS, self::NETSIS_OPT, 'Netsis çıkış listesi');
        $hasDepots = isset($col['note']) || isset($col['to']);
        if (!$hasDepots) { throw new \RuntimeException('Netsis dosyasında "Açıklama" ya da "malzeme giren depo kodu" kolonu yok: malzemenin hangi birime gittiği okunamaz.'); }

        [$from, $to] = [$file->getPeriod()->firstDay(), $file->getPeriod()->lastDay()];
        $n = $skipped = $bad = $noDest = 0; $min = $max = null; $fileRef = $this->em->getReference(PeriodFile::class, $file->getFileId());
        foreach ($rows as $no => $r) {
            if ($no <= $hdr) { continue; }
            $code = mb_strtoupper(trim((string) ($r[$col['code']] ?? '')));
            if ($code === '') { continue; }
            $date = self::date($r[$col['date']] ?? null);
            if ($date === null) { $bad++; continue; }
            if ($date < $from || $date > $to) { $skipped++; continue; }

            // Çıkış-giriş deposu: Açıklama "1-131" ya da ayrı kolonlar ("1 MERKEZ", "131 ALSANCAK ŞUBE")
            $note = isset($col['note']) ? trim((string) ($r[$col['note']] ?? '')) : '';
            $fromDepot = $toCode = null;
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $note, $m)) { $fromDepot = (int) $m[1]; $toCode = (int) $m[2]; }
            if ($toCode === null && isset($col['to'])) { $toCode = self::leadingInt($r[$col['to']] ?? null); }
            if ($fromDepot === null && isset($col['from'])) { $fromDepot = self::leadingInt($r[$col['from']] ?? null); }
            if ($toCode === null) { $noDest++; }

            $qty2 = isset($col['qty2']) ? self::decimal($r[$col['qty2']] ?? null) : null;
            $this->em->persist(new NetsisOutflow($fileRef, $date, mb_substr(trim((string) ($r[$col['docNo']] ?? '')), 0, 30), mb_substr($code, 0, 30),
                mb_substr(trim((string) ($r[$col['name']] ?? '')), 0, 200), self::decimal($r[$col['qty']] ?? null) ?? '0',
                mb_substr(mb_strtoupper(trim((string) ($r[$col['unit']] ?? ''))), 0, 10),
                $qty2, isset($col['unit2']) ? (mb_substr(mb_strtoupper(trim((string) ($r[$col['unit2']] ?? ''))), 0, 10) ?: null) : null,
                $fromDepot, $toCode, $note !== '' ? mb_substr($note, 0, 30) : null));
            $min = $min === null || $date < $min ? $date : $min;
            $max = $max === null || $date > $max ? $date : $max;
            if (++$n % self::BATCH === 0) { $this->em->flush(); }
        }
        $file->setDates($min, $max);

        $w = [];
        if ($skipped) { $w[] = self::INFO . number_format($skipped, 0, ',', '.') . ' satır dönem dışında olduğu için alınmadı.'; }
        if ($bad)     { $w[] = $bad . ' satırın tarihi okunamadı.'; }
        if ($noDest)  { $w[] = self::INFO . $noDest . ' satırda giriş deposu yok (ör. İADE).'; }
        if ($n && $max !== null && $max < $to->modify('-3 days')) { $w[] = 'Dosyadaki son tarih ' . $max->format('d.m.Y') . ': ay sonuna kadar olmayabilir.'; }
        if ($n === 0) { throw new \RuntimeException('Dosyada bu döneme (' . $from->format('m.Y') . ') ait satır yok.' . ($skipped ? " ($skipped satır başka aylara ait.)" : '')); }
        return ['rows' => $n, 'skipped' => $skipped, 'warnings' => $w];
    }

    private function testCount(PeriodFile $file, array $rows): array
    {
        try {
            [$hdr, $col] = $this->header($rows, self::TEST_COUNT, self::TEST_COUNT_OPT, 'LabAsistan test sayıları');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage() . ' Raporu "Tekrarları Göster" işaretli alın.');
        }
        $n = 0; $seen = []; $dup = 0; $fileRef = $this->em->getReference(PeriodFile::class, $file->getFileId());
        foreach ($rows as $no => $r) {
            if ($no <= $hdr) { continue; }
            $code = mb_strtoupper(trim((string) ($r[$col['code']] ?? '')));
            if ($code === '' || isset($seen[$code])) { $dup += $code !== '' ? 1 : 0; continue; }
            $seen[$code] = true;
            $this->em->persist(new LabTestCount($fileRef, isset($col['group']) ? (mb_substr(trim((string) ($r[$col['group']] ?? '')), 0, 100) ?: null) : null,
                mb_substr($code, 0, 30), mb_substr(trim((string) ($r[$col['name']] ?? '')), 0, 200),
                (int) round((float) (self::decimal($r[$col['qty']] ?? null) ?? 0)), (int) round((float) (self::decimal($r[$col['repeat']] ?? null) ?? 0))));
            if (++$n % self::BATCH === 0) { $this->em->flush(); }
        }
        if ($n === 0) { throw new \RuntimeException('Dosyada test satırı yok.'); }
        $file->setDates(null, null);
        $w = [self::INFO . 'Rapor tarih aralığı dosyada yazmaz: LabAsistan\'da Kayıt Tarihi ' . $file->getPeriod()->firstDay()->format('d.m.Y') . ' – ' . $file->getPeriod()->lastDay()->format('d.m.Y') . ' seçildiğinden emin olun.'];
        if ($dup) { $w[] = $dup . ' tekrarlanan test kodu satırı alınmadı.'; }
        return ['rows' => $n, 'skipped' => 0, 'warnings' => $w];
    }

    private function samples(PeriodFile $file, array $rows): array
    {
        [$hdr, $col] = $this->header($rows, self::SAMPLE, self::SAMPLE_OPT, 'LabAsistan numune listesi');
        [$from, $to] = [$file->getPeriod()->firstDay(), $file->getPeriod()->lastDay()->setTime(23, 59, 59)];
        $n = $skipped = $nApproved = 0; $seen = []; $min = $max = null; $fileRef = $this->em->getReference(PeriodFile::class, $file->getFileId());
        foreach ($rows as $no => $r) {
            if ($no <= $hdr) { continue; }
            $sampleNo = trim((string) ($r[$col['sampleNo']] ?? ''));
            if ($sampleNo === '' || isset($seen[$sampleNo])) { continue; }
            $at = self::dateTime($r[$col['date']] ?? null);
            if ($at !== null && ($at < $from || $at > $to)) { $skipped++; continue; }
            $seen[$sampleNo] = true;

            $tubes = count(array_filter(array_map('trim', explode(',', (string) ($r[$col['barcodes']] ?? ''))), 'strlen'));
            $tests = trim((string) ($r[$col['tests']] ?? ''));
            $testQty = preg_match('/^\s*(\d+)\s*:/', $tests, $m) ? (int) $m[1] : 0;
            $short = trim((string) preg_replace('/^\s*\d+\s*:\s*/', '', $tests));
            $inst = isset($col['institution']) ? trim((string) ($r[$col['institution']] ?? '')) : '';
            if ($inst !== '' && preg_match('/^(DR|DOÇ|DOC|PROF|UZM|OP)\b\.?/iu', $inst)) { $inst = ''; }   // kişi adı saklanmaz
            $accepted = isset($col['accepted']) && trim((string) ($r[$col['accepted']] ?? '')) !== '';
            // Onay Tarih dolu: testler onaylanmış (LabAsistan'da yeşil, "Bekleyen Testler" 0:) → kabul kaydı eksik, numune kayıp değil
            $approved = isset($col['approved']) && trim((string) ($r[$col['approved']] ?? '')) !== '';
            if ($approved) { $nApproved++; }
            $arrival = isset($col['arrival']) ? mb_strtoupper(trim((string) ($r[$col['arrival']] ?? ''))) : '';

            $this->em->persist(new LabSample($fileRef, mb_substr($sampleNo, 0, 30), $at, $accepted, $approved, $arrival !== '' ? mb_substr($arrival, 0, 40) : null,
                $inst !== '' ? mb_substr($inst, 0, 150) : null, min($tubes, 32000), min($testQty, 32000), $short !== '' ? $short : null));
            if ($at !== null) { $min = $min === null || $at < $min ? $at : $min; $max = $max === null || $at > $max ? $at : $max; }
            if (++$n % self::BATCH === 0) { $this->em->flush(); }
        }
        $file->setDates($min?->setTime(0, 0), $max?->setTime(0, 0));
        $w = [];
        if ($skipped) { $w[] = self::INFO . $skipped . ' numune dönem dışında olduğu için alınmadı.'; }
        if ($nApproved) { $w[] = self::INFO . $nApproved . ' numunenin testleri onaylanmış (Onay Tarih dolu): kabul kaydı eksik, kayıp sayılmaz.'; }
        if (!isset($col['approved'])) { $w[] = '"Onay Tarih" kolonu yok: testleri onaylanmış numuneler ayrılamaz.'; }
        if (!isset($col['arrival'])) { $w[] = '"Durum" kolonu yok: geliş tipine göre dağılım çıkmaz.'; }
        if (!isset($col['institution'])) { $w[] = '"Bölüm" kolonu yok: kuruma göre dağılım çıkmaz.'; }
        if ($n === 0 && !$skipped) { $w[] = 'Listede numune yok.'; }
        return ['rows' => $n, 'skipped' => $skipped, 'warnings' => $w];
    }

    /**
     * Fire / zayi bildirim formu. Boş form "bu ay bildirim yok" demektir: hata değildir, 0 satır olarak yüklenir.
     * Şube formunda birim satırdan ("131 ALSANCAK ŞUBE"), PCR formunda dosyanın biriminden alınır.
     */
    private function fire(PeriodFile $file, array $rows): array
    {
        [$hdr, $col] = $this->header($rows, self::FIRE, self::FIRE_OPT, 'fire / zayi bildirim formu');
        if (!isset($col['material'])) { throw new \RuntimeException('Formda "Malzeme" ya da "Kit / malzeme" kolonu yok.'); }
        // PCR formu (Run kolonu var, Şube kolonu yok) yalnız PCR birimine; şube formu PCR'a yüklenmez
        $isPcrForm = isset($col['run']) && !isset($col['unit']);
        if ($isPcrForm !== ($file->getUnitName() === MonthlyReport::FIRE_UNIT_PCR)) {
            throw new \RuntimeException($isPcrForm
                ? 'Bu dosya PCR formu; birim olarak "PCR" seçin.'
                : 'Bu dosya şube formu; birim olarak PCR değil, formun ait olduğu şubeyi seçin.');
        }
        [$from, $to] = [$file->getPeriod()->firstDay(), $file->getPeriod()->lastDay()];
        $n = $skipped = 0; $bad = []; $otherUnits = []; $fileRef = $this->em->getReference(PeriodFile::class, $file->getFileId());
        $cell = fn (array $r, string $k) => isset($col[$k]) ? trim((string) ($r[$col[$k]] ?? '')) : '';
        foreach ($rows as $no => $r) {
            if ($no <= $hdr) { continue; }
            $material = $cell($r, 'material'); $qtyRaw = $cell($r, 'qty'); $reason = $cell($r, 'reason');
            if ($material === '' && $qtyRaw === '' && $reason === '') { continue; }   // boş satır (şablonda 300 satır hazır)
            $date = self::date($cell($r, 'date'));
            $qty = self::decimal($qtyRaw);
            if ($date === null || $material === '' || $qty === null || (float) $qty <= 0 || $reason === '') {
                $bad[] = $no; continue;
            }
            if ($date < $from || $date > $to) { $skipped++; continue; }

            // Birleştirilmiş formda şube satırdan gelir ve zorunludur; tek birim formunda boşsa dosyanın birimi
            $merged = $file->getUnitName() === MonthlyReport::FIRE_UNIT_ALL;
            $unit = $cell($r, 'unit');
            if ($merged && $unit === '') { $bad[] = $no; continue; }
            $unit = $unit ?: $file->getUnitName();
            if (!$merged && $unit !== $file->getUnitName()) { $otherUnits[$unit] = true; }
            $depot = self::leadingInt($unit);
            // Stok kodu: kolondan (formül sonucu ya da elle) ya da "S737 – BESİYERLİ SWAB" biçiminden
            $stock = mb_strtoupper($cell($r, 'stock'));
            if ($stock === '' && preg_match('/^(\S+)\s+[–-]\s+/u', $material, $m)) { $stock = mb_strtoupper($m[1]); }
            $retested = $cell($r, 'retested');
            $affected = $cell($r, 'affected');

            $this->em->persist(new FireReport($fileRef, $date, $unit, $depot, $stock !== '' ? $stock : null, $material, $qty,
                $cell($r, 'measure') ?: null, $reason, $cell($r, 'run') ?: null, $affected !== '' && is_numeric($affected) ? (int) $affected : null,
                $retested === '' ? null : mb_strtoupper(mb_substr($retested, 0, 1)) === 'E', $cell($r, 'lot') ?: null, $cell($r, 'note') ?: null));
            if (++$n % self::BATCH === 0) { $this->em->flush(); }
        }
        $file->setDates(null, null);
        $w = [];
        if ($n === 0 && !$bad) { $w[] = self::INFO . 'Bu ay bildirim yok (form boş).'; }
        if ($skipped) { $w[] = self::INFO . $skipped . ' satır dönem dışında olduğu için alınmadı.'; }
        if ($bad) { $w[] = count($bad) . ' satır eksik ya da hatalı olduğu için alınmadı (tarih, malzeme, miktar, sebep' . ($file->getUnitName() === MonthlyReport::FIRE_UNIT_ALL ? ', şube' : '') . ' zorunlu): Excel satır ' . implode(', ', array_slice($bad, 0, 10)) . (count($bad) > 10 ? '…' : '') . '.'; }
        if ($otherUnits) { $w[] = 'Dosyada seçilen birimden (' . $file->getUnitName() . ') farklı birimler var: ' . implode(', ', array_keys($otherUnits)) . '.'; }
        return ['rows' => $n, 'skipped' => $skipped, 'warnings' => $w];
    }

    /* ---------- Yardımcılar ---------- */

    /**
     * İlk 15 satırda zorunlu başlıkların hepsinin bulunduğu satırı arar.
     * @return array{0:int, 1:array<string,int>} başlık satır no, alan → kolon no
     */
    private function header(array $rows, array $required, array $optional, string $what): array
    {
        $i = 0;
        foreach ($rows as $no => $r) {
            if (++$i > 15) { break; }
            $norm = [];
            foreach ($r as $c => $v) { $k = self::norm($v); if ($k !== '' && !isset($norm[$k])) { $norm[$k] = $c; } }
            $col = [];
            foreach ($required as $key => $names) { foreach ($names as $nm) { if (isset($norm[$nm])) { $col[$key] = $norm[$nm]; break; } } }
            if (count($col) < count($required)) { continue; }
            foreach ($optional as $key => $names) {
                foreach ($names as $nm) {
                    // "malzeme giren depo kodu" gibi uzun başlıklar: tam eşleşme yoksa başlıkla başlayan ilk kolon
                    if (isset($norm[$nm])) { $col[$key] = $norm[$nm]; break; }
                    foreach ($norm as $h => $c) { if (str_starts_with($h, $nm)) { $col[$key] = $c; break 2; } }
                }
            }
            return [$no, $col];
        }
        $need = array_map(fn ($n) => $n[0], $required);
        throw new \RuntimeException("Dosya $what biçiminde değil: başlık satırı bulunamadı (gereken kolonlar: " . implode(', ', $need) . ').');
    }

    /** Başlık karşılaştırması için: büyük harf, Türkçe harfler sadeleşir, harf / rakam dışı atılır ("Ölçü Br-1" → OLCUBR1) */
    public static function norm($v): string
    {
        $v = mb_strtoupper(trim((string) $v), 'UTF-8');
        $v = strtr($v, ['İ' => 'I', 'I' => 'I', 'Ş' => 'S', 'Ğ' => 'G', 'Ü' => 'U', 'Ö' => 'O', 'Ç' => 'C', 'Â' => 'A', 'Î' => 'I', 'Û' => 'U']);
        return (string) preg_replace('/[^A-Z0-9]/', '', $v);
    }

    /** "1.200" / "1200" / "1,5" / Excel sayısı → "1200" ; boşsa null */
    public static function decimal($v): ?string
    {
        $v = str_replace([' ', "\u{00A0}"], '', trim((string) $v));
        if ($v === '') { return null; }
        if (is_numeric($v)) { return (string) (0 + $v); }   // Excel sayı hücresi ham değerdir ("1200", "2.125")
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $v)) { $v = str_replace(['.', ','], ['', '.'], $v); }   // metin "1.200,5"
        elseif (str_contains($v, ',')) { $v = str_replace(',', '.', str_replace('.', '', $v)); }
        return is_numeric($v) ? (string) (0 + $v) : null;
    }

    private static function leadingInt($v): ?int
    {
        return preg_match('/^\s*(\d+)/', (string) $v, $m) ? (int) $m[1] : null;
    }

    /** Excel tarih sayısı ya da "dd.mm.yyyy" → gün */
    public static function date($v): ?\DateTimeImmutable
    {
        $d = self::dateTime($v);
        return $d?->setTime(0, 0);
    }

    /** Excel tarih sayısı ya da "dd.mm.yyyy[ hh:mm[:ss]]" / "yyyy-mm-dd" → zaman */
    public static function dateTime($v): ?\DateTimeImmutable
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        if (is_numeric($v)) {
            $f = (float) $v;
            if ($f < 20000 || $f > 80000) { return null; }
            // Excel gün sayısı: 1899-12-30 + gün (+ günün kesri saat)
            return (new \DateTimeImmutable('1899-12-30 00:00:00'))->modify('+' . (int) round($f * 86400) . ' seconds');
        }
        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $v);
            if ($d !== false) { return $d; }
        }
        return null;
    }
}
