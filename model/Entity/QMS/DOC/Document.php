<?php
declare(strict_types=1);

namespace QMS\DOC;

use QMS\MNG\Company;
use QMS\ORG\Department;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Dokümanın kimliği (ör. TL14 Kimyasal dökülme talimatı). Kod ve varyant verildikten sonra değişmez;
 * içerik, imza, dağıtım ve okuma kayıtları revizyona bağlıdır (qms_revision).
 * VARIANT_NAME: aynı kodun alt dokümanı (ör. FR06 altında "Numune alma", "İK"…; paylaşımlı klasörde FR06 bir klasördür).
 *   Her varyant ayrı dokümandır: imzacıları ve revizyonu kendine aittir. Tek dosyalı dokümanda boştur ('').
 * DOC_CODE: isteğe bağlı (NULL). ISO 45001 7.5.2 tanımlamayı ister, kodu şart koşmaz: kodu olmayan doküman
 *   başlık + varyantla tanımlanır (yüklemede aynı başlık yeni revizyon sayılır). Kod verilirse tipin kalıbına uymalıdır.
 * CURRENT_REVISION_ID: yürürlükteki revizyon (ilk yayından önce boş).
 * CONFIDENTIALITY: PUBLIC (dağıtıma göre), CONFIDENTIAL (yalnızca dağıtımda adı geçen kullanıcılar).
 * FILLABLE_FL: 'Y' doldurulacak form; yürürlükteyken Form doldur listesinde çıkar (varsayılanı tipten, yüklemede değiştirilebilir).
 * DOC_STATUS: ACTIVE ya da CANCELLED (gözden geçirmede iptal kararı; revizyonlar arşivde kalır).
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_document')]
#[ORM\UniqueConstraint(name: 'UQ_qms_document_COMPANY_CODE', columns: ['COMPANY_ID', 'DOC_CODE', 'VARIANT_NAME'])]
#[ORM\Index(name: 'IX_qms_document_TYPE', columns: ['DOC_TYPE_ID'])]
class Document
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const STATUS_ACTIVE    = 'ACTIVE';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const CONF_PUBLIC       = 'PUBLIC';
    public const CONF_CONFIDENTIAL = 'CONFIDENTIAL';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $documentId = null;               // DOCUMENT_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: DocType::class)]
    #[ORM\JoinColumn(name: 'DOC_TYPE_ID', referencedColumnName: 'DOC_TYPE_ID', nullable: false)]
    private DocType $docType;                      // DOC_TYPE_ID → qms_doc_type

    #[ORM\Column(length: 30, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $docCode;                      // DOC_CODE (ör. TL14; dosya adından okunur)

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS', 'default' => ''])]
    private string $variantName = '';              // VARIANT_NAME

    #[ORM\Column(length: 250, options: ['collation' => 'Turkish_CI_AS'])]
    private string $docTitle;                      // DOC_TITLE

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'OWNER_DEPARTMENT_ID', referencedColumnName: 'DEPARTMENT_ID', nullable: false)]
    private Department $ownerDepartment;           // OWNER_DEPARTMENT_ID → qms_department

    #[ORM\Column(length: 20, options: ['default' => self::CONF_PUBLIC])]
    private string $confidentiality = self::CONF_PUBLIC;   // CONFIDENTIALITY

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstPublishDate = null;  // FIRST_PUBLISH_DATE (antetteki "İlk Yayın Tarihi")

    #[ORM\ManyToOne(targetEntity: Revision::class)]
    #[ORM\JoinColumn(name: 'CURRENT_REVISION_ID', referencedColumnName: 'REVISION_ID', nullable: true)]
    private ?Revision $currentRevision = null;     // CURRENT_REVISION_ID → qms_revision

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $docStatus = self::STATUS_ACTIVE;   // DOC_STATUS

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $fillableFl = 'N';              // FILLABLE_FL

    public function __construct(DocType $docType, ?string $docCode, string $docTitle, Department $ownerDepartment, string $variantName = '')
    {
        $docCode = ($docCode === null || trim($docCode) === '') ? null : trim($docCode);
        if ($docCode !== null && !$docType->matchesCode($docCode)) {
            throw new \InvalidArgumentException('Doküman kodu, doküman tipinin kod kalıbına uymuyor: ' . $docCode);
        }
        $this->docType         = $docType;
        $this->docCode         = $docCode;
        $this->docTitle        = $docTitle;
        $this->ownerDepartment = $ownerDepartment;
        $this->variantName     = trim($variantName);
    }

    public function getDocumentId(): ?int
    {
        return $this->documentId !== null ? (int) $this->documentId : null;
    }

    public function getCompany(): ?Company         { return $this->company; }
    public function setCompany(Company $v): self   { $this->company = $v; return $this; }
    public function getDocType(): DocType          { return $this->docType; }
    public function getDocCode(): ?string          { return $this->docCode; }   // kod ve varyant değişmez: setter yok
    public function getVariantName(): string       { return $this->variantName; }
    public function getDocTitle(): string          { return $this->docTitle; }
    public function setDocTitle(string $v): self   { $this->docTitle = $v; return $this; }
    public function getOwnerDepartment(): Department { return $this->ownerDepartment; }
    public function setOwnerDepartment(Department $v): self { $this->ownerDepartment = $v; return $this; }
    public function getConfidentiality(): string   { return $this->confidentiality; }
    public function isConfidential(): bool         { return $this->confidentiality === self::CONF_CONFIDENTIAL; }

    public function setConfidentiality(string $v): self
    {
        if (!in_array($v, [self::CONF_PUBLIC, self::CONF_CONFIDENTIAL], true)) {
            throw new \InvalidArgumentException('Geçersiz gizlilik: ' . $v);
        }
        $this->confidentiality = $v;
        return $this;
    }

    public function getFirstPublishDate(): ?\DateTimeImmutable { return $this->firstPublishDate; }
    public function getCurrentRevision(): ?Revision { return $this->currentRevision; }
    public function getDocStatus(): string         { return $this->docStatus; }
    public function isFillable(): bool             { return $this->fillableFl === 'Y'; }
    public function setFillable(bool $v): self     { $this->fillableFl = $v ? 'Y' : 'N'; return $this; }
    public function isCancelled(): bool            { return $this->docStatus === self::STATUS_CANCELLED; }

    // Revizyon yürürlüğe girince çağrılır; ilk yayın tarihi yalnızca bir kez yazılır
    public function publish(Revision $revision, \DateTimeImmutable $effectiveDate): self
    {
        $this->currentRevision    = $revision;
        $this->firstPublishDate ??= $effectiveDate;
        return $this;
    }

    public function cancel(): self
    {
        $this->docStatus = self::STATUS_CANCELLED;
        return $this;
    }
}
