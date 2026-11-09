<?php
declare(strict_types=1);

namespace BIO\MNG;

use Biolab\Model\Common\AuditTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Şirket tipi (ör. Merkez, Şube). mng_company.COMPANY_TYPE_ID bu tabloya bağlanır.
 * CODE sabittir; kod içinde tip kontrolü gerekirse (ör. şube üst şirket ister) kodla yapılır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mng_company_type')]
class CompanyType
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const HEADQUARTERS = 'MERKEZ';
    public const BRANCH       = 'SUBE';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $companyTypeId = null;         // COMPANY_TYPE_ID

    #[ORM\Column(length: 20, unique: true)]
    private string $code;                       // CODE (MERKEZ, SUBE)

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $companyTypeName;            // COMPANY_TYPE_NAME

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $companyTypeNameEn = null;  // COMPANY_TYPE_NAME_EN

    public function __construct(string $code, string $companyTypeName, ?string $companyTypeNameEn = null)
    {
        $this->code              = $code;
        $this->companyTypeName   = $companyTypeName;
        $this->companyTypeNameEn = $companyTypeNameEn;
    }

    public function getCompanyTypeId(): ?int          { return $this->companyTypeId; }
    public function getCode(): string                 { return $this->code; }
    public function setCode(string $v): self          { $this->code = $v; return $this; }
    public function getCompanyTypeName(): string      { return $this->companyTypeName; }
    public function setCompanyTypeName(string $v): self { $this->companyTypeName = $v; return $this; }
    public function getCompanyTypeNameEn(): ?string   { return $this->companyTypeNameEn; }
    public function setCompanyTypeNameEn(?string $v): self { $this->companyTypeNameEn = $v; return $this; }
    public function isBranch(): bool                  { return $this->code === self::BRANCH; }
}
