<?php
declare(strict_types=1);

namespace QMS\MNG;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

#[ORM\Entity]
#[ORM\Table(name: 'mng_company')]
class Company
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $companyId = null;          // COMPANY_ID

    #[ORM\Column(length: 20, unique: true)]
    private string $code;                       // CODE  (örn. BIOLAB)

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $companyName;                       // COMPANY_NAME

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taxNumber = null;          // TAX_NUMBER

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(name: 'LANGUAGE_ID', referencedColumnName: 'LANGUAGE_ID', nullable: false)]
    private Language $language;                     // LANGUAGE_ID → mng_language

    public function getLanguage(): Language { return $this->language; }
    public function setLanguage(Language $language): self { $this->language = $language; return $this; }

    public function __construct(string $code, string $companyName,  Language $language)
    {
        $this->code      = $code;
        $this->companyName      = $companyName;
        $this->language = $language;
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId !== null ? (int) $this->companyId : null;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): self
    {
        $this->companyName = $companyName;
        return $this;
    }

    public function getTaxNumber(): ?string
    {
        return $this->taxNumber;
    }

    public function setTaxNumber(?string $taxNumber): self
    {
        $this->taxNumber = $taxNumber;
        return $this;
    }
}
