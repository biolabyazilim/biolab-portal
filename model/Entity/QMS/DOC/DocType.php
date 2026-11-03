<?php
declare(strict_types=1);

namespace QMS\DOC;

use QMS\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Doküman tipi (ör. İSG Talimatı, Prosedür, Risk Değerlendirmesi). Kalite bir kez tanımlar, sistemin geri kalanı
 * bu tanımdan çalışır:
 *   CODE_PATTERN     yeni doküman kodu bu kalıba uymalı (regex, ör. ^İSG-TL-\d{3}$); öneri için CODE_PREFIX kullanılır
 *   TEMPLATE_FILE    şirketin antetli Word şablonu (yer tutucular: ${doc_no}, ${rev_no}, ${approved_sign} …)
 *   REVIEW_MONTHS    yürürlüğe girişten kaç ay sonra gözden geçirilecek
 *   RETENTION_YEARS  geçersiz olan revizyon kaç yıl saklanacak
 *   READ_ACK_FL      yayımlanınca dağıtımdakilerden "okudum, anladım" onayı istensin mi
 * İmza akışı şablonu: qms_doc_type_step (+ yetkili pozisyonlar qms_doc_type_step_position)
 * Varsayılan dağıtım: qms_doc_type_distribution
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_doc_type')]
#[ORM\UniqueConstraint(name: 'UQ_qms_doc_type_COMPANY_PREFIX', columns: ['COMPANY_ID', 'CODE_PREFIX'])]
class DocType
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $docTypeId = null;                // DOC_TYPE_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\Column(length: 20, options: ['collation' => 'Turkish_CI_AS'])]
    private string $codePrefix;                    // CODE_PREFIX (ör. İSG-TL; öneri: İSG-TL-015)

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $docTypeName;                   // DOC_TYPE_NAME

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $standardCode = null;          // STANDARD_CODE (ör. ISO45001, ISO9001)

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $codePattern;                   // CODE_PATTERN (regex)

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $templateFile = null;          // TEMPLATE_FILE (şablon deposundaki dosya yolu)

    #[ORM\Column(type: 'smallint', options: ['default' => 24])]
    private int $reviewMonths = 24;                // REVIEW_MONTHS

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $retentionYears = 10;              // RETENTION_YEARS

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'Y'])]
    private string $readAckFl = 'Y';               // READ_ACK_FL

    public function __construct(string $codePrefix, string $docTypeName, string $codePattern)
    {
        $this->codePrefix  = $codePrefix;
        $this->docTypeName = $docTypeName;
        $this->codePattern = $codePattern;
    }

    public function getDocTypeId(): ?int           { return $this->docTypeId; }
    public function getCompany(): ?Company         { return $this->company; }
    public function setCompany(Company $v): self   { $this->company = $v; return $this; }
    public function getCodePrefix(): string        { return $this->codePrefix; }
    public function getDocTypeName(): string       { return $this->docTypeName; }
    public function setDocTypeName(string $v): self { $this->docTypeName = $v; return $this; }
    public function getStandardCode(): ?string     { return $this->standardCode; }
    public function setStandardCode(?string $v): self { $this->standardCode = $v; return $this; }
    public function getCodePattern(): string       { return $this->codePattern; }
    public function setCodePattern(string $v): self { $this->codePattern = $v; return $this; }
    public function getTemplateFile(): ?string     { return $this->templateFile; }
    public function setTemplateFile(?string $v): self { $this->templateFile = $v; return $this; }
    public function getReviewMonths(): int         { return $this->reviewMonths; }
    public function setReviewMonths(int $v): self  { $this->reviewMonths = $v; return $this; }
    public function getRetentionYears(): int       { return $this->retentionYears; }
    public function setRetentionYears(int $v): self { $this->retentionYears = $v; return $this; }
    public function requiresReadAck(): bool        { return $this->readAckFl === 'Y'; }
    public function setReadAck(bool $v): self      { $this->readAckFl = $v ? 'Y' : 'N'; return $this; }

    // Kod kalıba uyuyor mu (Türkçe karakterler için u bayrağı)
    public function matchesCode(string $docCode): bool
    {
        return preg_match('/' . str_replace('/', '\/', $this->codePattern) . '/u', $docCode) === 1;
    }
}
