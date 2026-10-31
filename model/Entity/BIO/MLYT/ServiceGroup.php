<?php
declare(strict_types=1);

namespace BIO\MLYT;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Testin hizmet grubu (Biyokimya, Hormon, Patoloji Raporu...). Tenay test listesindeki "Hizmet Grubu".
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_service_group')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_service_group_NAME', columns: ['COMPANY_ID', 'SERVICE_GROUP_NAME'])]
class ServiceGroup
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $serviceGroupId = null;           // SERVICE_GROUP_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $serviceGroupName;              // SERVICE_GROUP_NAME

    public function __construct(string $serviceGroupName)
    {
        $this->serviceGroupName = $serviceGroupName;
    }

    public function getServiceGroupId(): ?int            { return $this->serviceGroupId; }
    public function getCompany(): ?Company               { return $this->company; }
    public function setCompany(Company $company): self   { $this->company = $company; return $this; }
    public function getServiceGroupName(): string        { return $this->serviceGroupName; }
    public function setServiceGroupName(string $v): self { $this->serviceGroupName = $v; return $this; }
}
