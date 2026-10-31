<?php
declare(strict_types=1);

namespace BIO\KVK;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Belgenin PDF dosyası; her yükleme yeni bir versiyondur (1, 2, 3 …). Yüklenen dosya sonradan değişmez.
 * Eski versiyonlar silinmez: "şu tarihte yayında hangi metin vardı" sorusunun cevabı buradadır.
 * SHA256 içeriğin sonradan değişmediğini doğrulamak içindir (BCR\SampleReportFile ile aynı yöntem).
 * Dosya listesi çekilirken içerik (CONTENT) belleğe alınmasın diye belge bilgisinden ayrı tablodadır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kvk_document_file')]
#[ORM\UniqueConstraint(name: 'UQ_kvk_document_file_VERSION', columns: ['DOCUMENT_ID', 'VERSION_NO'])]
class KvkkDocumentFile
{
    use AuditTrait;   // C_BY = yükleyen, C_AT = yükleme zamanı

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $documentFileId = null;           // DOCUMENT_FILE_ID

    #[ORM\ManyToOne(targetEntity: KvkkDocument::class)]
    #[ORM\JoinColumn(name: 'DOCUMENT_ID', referencedColumnName: 'DOCUMENT_ID', nullable: false)]
    private KvkkDocument $document;                // DOCUMENT_ID → kvk_document

    #[ORM\Column(type: 'smallint')]
    private int $versionNo;                        // VERSION_NO

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $fileName;                      // FILE_NAME (yüklenen dosyanın adı)

    #[ORM\Column(type: 'integer')]
    private int $fileSize;                         // FILE_SIZE (bayt)

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $sha256;                        // SHA256

    #[ORM\Column(type: 'blob')]
    private $content;                              // CONTENT (VARBINARY(MAX))

    public function __construct(KvkkDocument $document, int $versionNo, string $fileName, string $bytes)
    {
        $this->document  = $document;
        $this->versionNo = $versionNo;
        $this->fileName  = $fileName;
        $this->content   = $bytes;
        $this->fileSize  = strlen($bytes);
        $this->sha256    = hash('sha256', $bytes);
    }

    public function getDocumentFileId(): ?int     { return $this->documentFileId; }
    public function getDocument(): KvkkDocument   { return $this->document; }
    public function getVersionNo(): int           { return $this->versionNo; }
    public function getFileName(): string         { return $this->fileName; }
    public function getFileSize(): int            { return $this->fileSize; }
    public function getSha256(): string           { return $this->sha256; }

    // Doctrine 'blob' tipi okunurken akış (stream) döndürür
    public function getContent(): string
    {
        return is_resource($this->content) ? (string) stream_get_contents($this->content, -1, 0) : (string) $this->content;
    }
}
