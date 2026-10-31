<?php
declare(strict_types=1);

namespace BIO\BCR;

use Doctrine\ORM\Mapping as ORM;

/**
 * Cihaz raporundan saklanan dosyalar:
 *   DEVICE_PDF       → cihazın ürettiği orijinal PDF, değiştirilmeden (denetimde kaynak belge)
 *   CHROMATOGRAM_PNG → PDF'teki kromatogram resmi (yeni raporda kullanılır)
 * Her çalışmanın her türden tek dosyası olur (benzersiz kısıt).
 */
#[ORM\Entity]
#[ORM\Table(name: 'device_result_file')]
#[ORM\UniqueConstraint(name: 'UQ_device_result_file_TYPE', columns: ['DEVICE_RESULT_ID', 'FILE_TYPE'])]
class DeviceResultFile
{
    public const TYPE_DEVICE_PDF       = 'DEVICE_PDF';
    public const TYPE_CHROMATOGRAM_PNG = 'CHROMATOGRAM_PNG';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $deviceResultFileId = null;       // DEVICE_RESULT_FILE_ID

    #[ORM\ManyToOne(targetEntity: DeviceResult::class)]
    #[ORM\JoinColumn(name: 'DEVICE_RESULT_ID', referencedColumnName: 'DEVICE_RESULT_ID', nullable: false, onDelete: 'CASCADE')]
    private DeviceResult $deviceResult;            // DEVICE_RESULT_ID → device_result

    #[ORM\Column(length: 30)]
    private string $fileType;                      // FILE_TYPE

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fileName = null;              // FILE_NAME (cihazdaki ad, ör. 20261020112739930DW.pdf)

    #[ORM\Column(length: 50)]
    private string $mimeType;                      // MIME_TYPE

    #[ORM\Column(type: 'integer')]
    private int $fileSize;                         // FILE_SIZE (bayt)

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $sha256;                        // SHA256 (içeriğin değişmediğini doğrulamak için)

    #[ORM\Column(type: 'blob')]
    private $content;                              // CONTENT (VARBINARY(MAX))

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;         // CREATED_AT

    public function __construct(DeviceResult $deviceResult, string $fileType, string $mimeType, string $bytes, ?string $fileName = null)
    {
        $this->deviceResult = $deviceResult;
        $this->fileType     = $fileType;
        $this->mimeType     = $mimeType;
        $this->fileName     = $fileName;
        $this->content      = $bytes;
        $this->fileSize     = strlen($bytes);
        $this->sha256       = hash('sha256', $bytes);
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getDeviceResult(): DeviceResult { return $this->deviceResult; }
    public function getFileType(): string            { return $this->fileType; }
    public function getFileName(): ?string           { return $this->fileName; }
    public function getMimeType(): string            { return $this->mimeType; }
    public function getFileSize(): int               { return $this->fileSize; }
    public function getSha256(): string              { return $this->sha256; }

    // Doctrine 'blob' tipi okunurken akış (stream) döndürür
    public function getContent(): string
    {
        return is_resource($this->content) ? (string) stream_get_contents($this->content, -1, 0) : (string) $this->content;
    }
}
