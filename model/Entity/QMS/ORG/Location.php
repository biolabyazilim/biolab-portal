<?php
declare(strict_types=1);

namespace QMS\ORG;

use QMS\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Lokasyon (ör. Fabrika 1, Merkez Laboratuvar). Dağıtım kuralı lokasyona göre de verilebilir (SUBJECT_TYPE = LOCATION).
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_location')]
#[ORM\UniqueConstraint(name: 'UQ_qms_location_COMPANY_CODE', columns: ['COMPANY_ID', 'CODE'])]
class Location
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $locationId = null;               // LOCATION_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\Column(length: 20)]
    private string $code;                          // CODE

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $locationName;                  // LOCATION_NAME

    public function __construct(string $code, string $locationName)
    {
        $this->code         = $code;
        $this->locationName = $locationName;
    }

    public function getLocationId(): ?int          { return $this->locationId; }
    public function getCompany(): ?Company         { return $this->company; }
    public function setCompany(Company $v): self   { $this->company = $v; return $this; }
    public function getCode(): string              { return $this->code; }
    public function setCode(string $v): self      { $this->code = $v; return $this; }
    public function getLocationName(): string      { return $this->locationName; }
    public function setLocationName(string $v): self { $this->locationName = $v; return $this; }
}
