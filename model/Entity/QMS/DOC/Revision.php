<?php
declare(strict_types=1);

namespace QMS\DOC;

use QMS\MNG\AppUser;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Dokümanın bir revizyonu. İmza akışı (qms_sign_step), dağıtım (qms_revision_distribution) ve okuma onayları
 * (qms_read_ack) revizyona bağlıdır; Rev 02'nin imzaları Rev 03'ünkilere karışmaz.
 *
 * Durumlar (REV_STATUS):
 *   DRAFT      taslak; dosya ve akış değiştirilebilir (red sonrası da buraya döner, SIGN_ROUND bir artar)
 *   SIGNING    imzada; PDF kilitli, PDF_HASH sabit
 *   EFFECTIVE  yürürlükte; SIGNED_FILE_PATH son mühürlü PDF
 *   OBSOLETE   yeni revizyon yürürlüğe girdi; yalnızca kalite ve denetçi görür
 *   CANCELLED  doküman iptal edildi ya da taslak vazgeçildi
 *
 * Dosyalar web kökü dışındaki depoda durur; burada depoya göre yolları tutulur:
 *   SOURCE_FILE_PATH  yüklenen Word/Excel (antet yer tutucuları doldurulmadan önce)
 *   PDF_FILE_PATH     imzaya giden PDF (yer tutucular dolu); PDF_HASH bu dosyanın SHA-256'sı
 *   SIGNED_FILE_PATH  her imzadan sonra güncellenen mühürlü PDF; SIGNED_HASH son halinin SHA-256'sı
 * UPLOADED_BY dosyayı sisteme koyan kişidir; hazırlayan imza akışındaki PREPARED adımıdır, aynı kişi olmak zorunda değil.
 * LEGACY_FL = 'Y': sisteme geçişten önce ıslak imzayla onaylanmış revizyonun taraması (imza akışı yoktur).
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_revision')]
#[ORM\UniqueConstraint(name: 'UQ_qms_revision_DOC_NO', columns: ['DOCUMENT_ID', 'REVISION_NO'])]
#[ORM\Index(name: 'IX_qms_revision_STATUS', columns: ['REV_STATUS', 'NEXT_REVIEW_DATE'])]
class Revision
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const DRAFT     = 'DRAFT';
    public const SIGNING   = 'SIGNING';
    public const EFFECTIVE = 'EFFECTIVE';
    public const OBSOLETE  = 'OBSOLETE';
    public const CANCELLED = 'CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $revisionId = null;               // REVISION_ID

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'DOCUMENT_ID', referencedColumnName: 'DOCUMENT_ID', nullable: false)]
    private Document $document;                    // DOCUMENT_ID → qms_document

    #[ORM\Column(type: 'smallint')]
    private int $revisionNo;                       // REVISION_NO (00, 01, 02 …)

    #[ORM\Column(length: 20, options: ['default' => self::DRAFT])]
    private string $revStatus = self::DRAFT;       // REV_STATUS

    #[ORM\Column(type: 'text', options: ['collation' => 'Turkish_100_CI_AS_SC_UTF8'])]
    private string $changeReason;                  // CHANGE_REASON (zorunlu; "güncellendi" yeterli değil)

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'UPLOADED_BY', referencedColumnName: 'USER_ID', nullable: false)]
    private AppUser $uploadedBy;                   // UPLOADED_BY → mng_user (oturumdaki kullanıcı)

    #[ORM\Column(length: 255, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $sourceFileName = null;        // SOURCE_FILE_NAME (yüklenen dosyanın özgün adı)

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $sourceFilePath = null;        // SOURCE_FILE_PATH

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $pdfFilePath = null;           // PDF_FILE_PATH

    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $pdfHash = null;               // PDF_HASH (SHA-256, hex)

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $signedFilePath = null;        // SIGNED_FILE_PATH

    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $signedHash = null;            // SIGNED_HASH (SHA-256, hex)

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $signRound = 1;                    // SIGN_ROUND (her red sonrası yeni imza turu)

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $revisionDate = null;      // REVISION_DATE (antetteki "Revizyon Tarihi")

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $effectiveDate = null;     // EFFECTIVE_DATE (yürürlük)

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextReviewDate = null;    // NEXT_REVIEW_DATE

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $obsoleteDate = null;      // OBSOLETE_DATE (geçersiz olduğu tarih; saklama süresi buradan)

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $legacyFl = 'N';                // LEGACY_FL

    public function __construct(Document $document, int $revisionNo, string $changeReason, AppUser $uploadedBy)
    {
        if (trim($changeReason) === '') {
            throw new \InvalidArgumentException('Değişiklik nedeni girilmeli.');
        }
        $this->document     = $document;
        $this->revisionNo   = $revisionNo;
        $this->changeReason = trim($changeReason);
        $this->uploadedBy   = $uploadedBy;
    }

    public function getRevisionId(): ?int
    {
        return $this->revisionId !== null ? (int) $this->revisionId : null;
    }

    public function getDocument(): Document        { return $this->document; }
    public function getRevisionNo(): int           { return $this->revisionNo; }
    public function getRevisionLabel(): string     { return str_pad((string) $this->revisionNo, 2, '0', STR_PAD_LEFT); }
    public function getRevStatus(): string         { return $this->revStatus; }
    public function getChangeReason(): string      { return $this->changeReason; }
    public function getUploadedBy(): AppUser       { return $this->uploadedBy; }
    public function getSourceFileName(): ?string   { return $this->sourceFileName; }
    public function getSourceFilePath(): ?string   { return $this->sourceFilePath; }
    public function getPdfFilePath(): ?string      { return $this->pdfFilePath; }
    public function getPdfHash(): ?string          { return $this->pdfHash; }
    public function getSignedFilePath(): ?string   { return $this->signedFilePath; }
    public function getSignedHash(): ?string       { return $this->signedHash; }
    public function getSignRound(): int            { return $this->signRound; }
    public function getRevisionDate(): ?\DateTimeImmutable   { return $this->revisionDate; }
    public function getEffectiveDate(): ?\DateTimeImmutable  { return $this->effectiveDate; }
    public function getNextReviewDate(): ?\DateTimeImmutable { return $this->nextReviewDate; }
    public function getObsoleteDate(): ?\DateTimeImmutable   { return $this->obsoleteDate; }
    public function isLegacy(): bool               { return $this->legacyFl === 'Y'; }
    public function isEditable(): bool             { return $this->revStatus === self::DRAFT; }

    // Taslakta dosya yüklendi / değiştirildi
    public function setSourceFile(string $originalName, string $path): self
    {
        $this->assertStatus(self::DRAFT);
        $this->sourceFileName = $originalName;
        $this->sourceFilePath = $path;
        return $this;
    }

    // Antet doldurulup PDF'e çevrildi
    public function setPdf(string $path, string $sha256): self
    {
        $this->assertStatus(self::DRAFT);
        $this->pdfFilePath = $path;
        $this->pdfHash     = $sha256;
        return $this;
    }

    // Hazırlayan "imzaya gönder" dedi: PDF artık değişmez
    public function submitForSigning(): self
    {
        $this->assertStatus(self::DRAFT);
        if ($this->pdfHash === null) {
            throw new \LogicException('İmzaya göndermeden önce PDF oluşturulmalı.');
        }
        $this->revStatus = self::SIGNING;
        return $this;
    }

    // Her imzadan sonra mühürlü PDF'in son hali
    public function setSigned(string $path, string $sha256): self
    {
        $this->assertStatus(self::SIGNING);
        $this->signedFilePath = $path;
        $this->signedHash     = $sha256;
        return $this;
    }

    // İmzacı reddetti: taslağa döner, yeni imza turu açılır
    public function returnToDraft(): self
    {
        $this->assertStatus(self::SIGNING);
        $this->revStatus      = self::DRAFT;
        $this->signRound++;
        $this->signedFilePath = null;
        $this->signedHash     = null;
        return $this;
    }

    // Son imza atıldı: yürürlüğe girer
    public function makeEffective(\DateTimeImmutable $effectiveDate, int $reviewMonths): self
    {
        $this->assertStatus(self::SIGNING);
        $this->revStatus      = self::EFFECTIVE;
        $this->revisionDate ??= $effectiveDate;
        $this->effectiveDate  = $effectiveDate;
        $this->nextReviewDate = $effectiveDate->modify("+{$reviewMonths} months");
        return $this;
    }

    // Gözden geçirmede "geçerli" kararı: içerik aynı, yalnızca tarih ileri alınır
    public function extendReview(\DateTimeImmutable $nextReviewDate): self
    {
        $this->assertStatus(self::EFFECTIVE);
        $this->nextReviewDate = $nextReviewDate;
        return $this;
    }

    // Yeni revizyon yürürlüğe girdi
    public function makeObsolete(\DateTimeImmutable $date): self
    {
        $this->assertStatus(self::EFFECTIVE);
        $this->revStatus    = self::OBSOLETE;
        $this->obsoleteDate = $date;
        return $this;
    }

    public function cancel(\DateTimeImmutable $date): self
    {
        if (!in_array($this->revStatus, [self::DRAFT, self::EFFECTIVE], true)) {
            throw new \LogicException('Bu durumdaki revizyon iptal edilemez: ' . $this->revStatus);
        }
        $this->revStatus    = self::CANCELLED;
        $this->obsoleteDate = $date;
        return $this;
    }

    // Sisteme geçiş: ıslak imzalı mevcut revizyonun taraması doğrudan yürürlükte kaydedilir
    public function importLegacy(string $scanPath, string $sha256, \DateTimeImmutable $revisionDate, \DateTimeImmutable $effectiveDate, int $reviewMonths): self
    {
        $this->assertStatus(self::DRAFT);
        $this->legacyFl       = 'Y';
        $this->signedFilePath = $scanPath;
        $this->signedHash     = $sha256;
        $this->revisionDate   = $revisionDate;
        $this->effectiveDate  = $effectiveDate;
        $this->nextReviewDate = $effectiveDate->modify("+{$reviewMonths} months");
        $this->revStatus      = self::EFFECTIVE;
        return $this;
    }

    private function assertStatus(string $expected): void
    {
        if ($this->revStatus !== $expected) {
            throw new \LogicException("Revizyon durumu {$this->revStatus}; bu işlem için {$expected} olmalı.");
        }
    }
}
