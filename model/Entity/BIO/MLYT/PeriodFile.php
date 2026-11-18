<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Döneme yüklenen bir kaynak dosya. Satırları kaynağa göre ayrı tablodadır:
 *   NETSIS_OUT       → mlyt_netsis_outflow  (Netsis depodan çıkan malzeme listesi)
 *   LAB_TEST_COUNT   → mlyt_lab_test_count  (LabAsistan › İstatistik › Test Sayıları, tekrarlı)
 *   LAB_NOT_ACCEPTED → mlyt_lab_sample      (LabAsistan › Hastalar, Kabul Edilmeyen; birim seçilerek)
 *   LAB_REJECTED     → mlyt_lab_sample      (LabAsistan › Hastalar, Reddedilen; birim seçilerek)
 *   FIRE_REPORT      → mlyt_fire_report     (fire bildirim formu; UNIT_NAME = şube / PCR, birim başına bir dosya)
 * Dönem + kaynak + birim tektir: aynı dosya yeniden yüklenirse eski satırlar silinip yenileri yazılır.
 * Kişisel veri (ad, doğum tarihi, TC, dosya no, doktor) okunmaz ve saklanmaz.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_period_file')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_period_file', columns: ['PERIOD_ID', 'SOURCE_CODE', 'UNIT_NAME'])]
class PeriodFile
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const NETSIS_OUT       = 'NETSIS_OUT';
    public const LAB_TEST_COUNT   = 'LAB_TEST_COUNT';
    public const LAB_NOT_ACCEPTED = 'LAB_NOT_ACCEPTED';
    public const LAB_REJECTED     = 'LAB_REJECTED';
    public const FIRE_REPORT      = 'FIRE_REPORT';   // fire bildirim formu (şube ya da PCR) → mlyt_fire_report
    public const SOURCES = [self::NETSIS_OUT, self::LAB_TEST_COUNT, self::LAB_NOT_ACCEPTED, self::LAB_REJECTED, self::FIRE_REPORT];
    /** LabAsistan birimi seçilerek yüklenen numune listeleri (birim başına bir dosya) */
    public const PER_UNIT = [self::LAB_NOT_ACCEPTED, self::LAB_REJECTED];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $fileId = null;                   // FILE_ID

    #[ORM\ManyToOne(targetEntity: MonthlyPeriod::class)]
    #[ORM\JoinColumn(name: 'PERIOD_ID', referencedColumnName: 'PERIOD_ID', nullable: false)]
    private MonthlyPeriod $period;                 // PERIOD_ID → mlyt_period

    #[ORM\Column(length: 20)]
    private string $sourceCode;                    // SOURCE_CODE

    // LabAsistan listelerinde seçilen birim (KAHRAMANLAR, BAYRAKLI…; birim boş bırakıldıysa "TÜM BİRİMLER"); diğer kaynaklarda '-'
    #[ORM\Column(length: 60, options: ['collation' => 'Turkish_CI_AS', 'default' => '-'])]
    private string $unitName = '-';                // UNIT_NAME

    #[ORM\Column(length: 255, options: ['collation' => 'Turkish_CI_AS'])]
    private string $fileName;                      // FILE_NAME (yüklenen dosyanın adı)

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $rowCount = 0;                     // ROW_COUNT (okunan veri satırı)

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateFrom = null;  // DATE_FROM (dosyadaki en erken tarih; test sayılarında yok)

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateTo = null;    // DATE_TO

    // Yüklemede bulunan uyarılar (dönem dışı satır, eşleşmeyen kod…), satır satır
    #[ORM\Column(length: 2000, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $warnings = null;              // WARNINGS

    public function __construct(MonthlyPeriod $period, string $sourceCode, string $unitName, string $fileName)
    {
        $this->period     = $period;
        $this->sourceCode = $sourceCode;
        $this->unitName   = $unitName;
        $this->fileName   = $fileName;
    }

    public function getFileId(): ?int                 { return $this->fileId; }
    public function getPeriod(): MonthlyPeriod        { return $this->period; }
    public function getSourceCode(): string           { return $this->sourceCode; }
    public function getUnitName(): string             { return $this->unitName; }
    public function getFileName(): string             { return $this->fileName; }
    public function setFileName(string $v): self      { $this->fileName = $v; return $this; }
    public function getRowCount(): int                { return $this->rowCount; }
    public function setRowCount(int $v): self         { $this->rowCount = $v; return $this; }
    public function getDateFrom(): ?\DateTimeImmutable { return $this->dateFrom; }
    public function getDateTo(): ?\DateTimeImmutable   { return $this->dateTo; }
    public function setDates(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): self { $this->dateFrom = $from; $this->dateTo = $to; return $this; }
    public function getWarnings(): ?string            { return $this->warnings; }
    public function setWarnings(?string $v): self     { $this->warnings = $v === null ? null : mb_substr($v, 0, 2000); return $this; }
}
