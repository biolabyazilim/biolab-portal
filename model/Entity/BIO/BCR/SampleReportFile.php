<?php
declare(strict_types=1);

namespace BIO\BCR;

use Doctrine\ORM\Mapping as ORM;

/**
 * Onaylanan rapor revizyonunun PDF'i. Onay anında bir kez üretilir, sonra değişmez (arşiv).
 * SHA256 içeriğin sonradan değişmediğini doğrulamak içindir. Düzeltme yeni revizyon + yeni PDF demektir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sample_report_file')]
#[ORM\UniqueConstraint(name: 'UQ_sample_report_file_REPORT', columns: ['SAMPLE_REPORT_ID'])]
class SampleReportFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $sampleReportFileId = null;       // SAMPLE_REPORT_FILE_ID

    #[ORM\ManyToOne(targetEntity: SampleReport::class)]
    #[ORM\JoinColumn(name: 'SAMPLE_REPORT_ID', referencedColumnName: 'SAMPLE_REPORT_ID', nullable: false)]
    private SampleReport $sampleReport;            // SAMPLE_REPORT_ID → sample_report

    #[ORM\Column(length: 120)]
    private string $fileName;                      // FILE_NAME (ör. HB-11713441-rev1.pdf)

    #[ORM\Column(type: 'integer')]
    private int $fileSize;                         // FILE_SIZE (bayt)

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $sha256;                        // SHA256

    #[ORM\Column(type: 'blob')]
    private $content;                              // CONTENT (VARBINARY(MAX))

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;         // CREATED_AT

    public function __construct(SampleReport $report, string $fileName, string $bytes)
    {
        $this->sampleReport = $report;
        $this->fileName     = $fileName;
        $this->content      = $bytes;
        $this->fileSize     = strlen($bytes);
        $this->sha256       = hash('sha256', $bytes);
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getSampleReport(): SampleReport { return $this->sampleReport; }
    public function getFileName(): string           { return $this->fileName; }
    public function getFileSize(): int              { return $this->fileSize; }
    public function getSha256(): string             { return $this->sha256; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    // Doctrine 'blob' tipi okunurken akış (stream) döndürür
    public function getContent(): string
    {
        return is_resource($this->content) ? (string) stream_get_contents($this->content, -1, 0) : (string) $this->content;
    }

    // Arşivdeki dosya bozulmamış mı?
    public function isIntact(): bool
    {
        return hash('sha256', $this->getContent()) === $this->sha256;
    }
}
