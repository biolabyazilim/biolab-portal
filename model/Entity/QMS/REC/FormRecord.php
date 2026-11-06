<?php
declare(strict_types=1);

namespace QMS\REC;

use QMS\DOC\Document;
use QMS\DOC\Revision;
use QMS\MNG\AppUser;
use QMS\MNG\Company;
use Doctrine\ORM\Mapping as ORM;

/**
 * Doldurulmuş form (kayıt, ISO 45001 7.5.3). Yürürlükteki form revizyonunun PDF'i üzerine ekranda yazılan, çizilen
 * ve işaretlenenler sunucuda PDF'in kopyasına basılır; onaylı şablon değişmez.
 * Yalnızca eklenir: readOnly + veritabanında UPDATE/DELETE engelleyen trigger (TR_qms_form_record_NO_CHANGE).
 * Düzeltme gerekirse yeni kayıt açılır; hangi revizyondan doldurulduğu (REVISION_ID) kayıtta kalır.
 *
 * FILE_PATH: depoda rec/{doküman}/r{revizyon}/rec-….pdf · FILE_HASH: bu dosyanın SHA-256'sı
 * MARKS: ekrandaki işaretlerin vektör verisi (JSON; sayfa, tür, sayfaya göre 0–1 koordinat, metin)
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'qms_form_record')]
#[ORM\Index(name: 'IX_qms_form_record_REVISION', columns: ['REVISION_ID'])]
#[ORM\Index(name: 'IX_qms_form_record_DOCUMENT', columns: ['DOCUMENT_ID', 'FILLED_AT'])]
class FormRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $formRecordId = null;             // FORM_RECORD_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private Company $company;                      // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'DOCUMENT_ID', referencedColumnName: 'DOCUMENT_ID', nullable: false)]
    private Document $document;                    // DOCUMENT_ID → qms_document

    #[ORM\ManyToOne(targetEntity: Revision::class)]
    #[ORM\JoinColumn(name: 'REVISION_ID', referencedColumnName: 'REVISION_ID', nullable: false)]
    private Revision $revision;                    // REVISION_ID → qms_revision (doldurulan form revizyonu)

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'USER_ID', referencedColumnName: 'USER_ID', nullable: false)]
    private AppUser $user;                         // USER_ID → mng_user (dolduran)

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $filledAt;          // FILLED_AT (sunucu saati)

    #[ORM\Column(length: 200, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $recordTitle = null;           // RECORD_TITLE (bulmak için kısa açıklama, ör. hasta / cihaz / tarih)

    #[ORM\Column(type: 'smallint')]
    private int $pageCount;                        // PAGE_COUNT

    #[ORM\Column(length: 300)]
    private string $filePath;                      // FILE_PATH

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $fileHash;                      // FILE_HASH (SHA-256, hex)

    #[ORM\Column(type: 'text', options: ['collation' => 'Turkish_100_CI_AS_SC_UTF8'])]
    private string $marks;                         // MARKS (JSON)

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;             // IP_ADDRESS

    public function __construct(Company $company, Revision $revision, AppUser $user, \DateTimeImmutable $filledAt, ?string $recordTitle,
                                int $pageCount, string $filePath, string $fileHash, string $marks, ?string $ipAddress)
    {
        if ($revision->getRevStatus() !== Revision::EFFECTIVE) {
            throw new \LogicException('Yalnızca yürürlükteki form revizyonu doldurulabilir.');
        }
        $this->company     = $company;
        $this->document    = $revision->getDocument();
        $this->revision    = $revision;
        $this->user        = $user;
        $this->filledAt    = $filledAt;
        $this->recordTitle = $recordTitle !== null && trim($recordTitle) !== '' ? trim($recordTitle) : null;
        $this->pageCount   = $pageCount;
        $this->filePath    = $filePath;
        $this->fileHash    = $fileHash;
        $this->marks       = $marks;
        $this->ipAddress   = $ipAddress;
    }

    public function getFormRecordId(): ?int
    {
        return $this->formRecordId !== null ? (int) $this->formRecordId : null;
    }

    public function getDocument(): Document        { return $this->document; }
    public function getRevision(): Revision        { return $this->revision; }
    public function getUser(): AppUser             { return $this->user; }
    public function getFilledAt(): \DateTimeImmutable { return $this->filledAt; }
    public function getRecordTitle(): ?string      { return $this->recordTitle; }
    public function getPageCount(): int            { return $this->pageCount; }
    public function getFilePath(): string          { return $this->filePath; }
    public function getFileHash(): string          { return $this->fileHash; }
    public function getMarks(): string             { return $this->marks; }
}
