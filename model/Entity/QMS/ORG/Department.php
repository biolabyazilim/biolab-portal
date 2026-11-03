<?php
declare(strict_types=1);

namespace QMS\ORG;

use QMS\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Departman (ör. Laboratuvar, Üretim, İSG Birimi). Şirket bazında tanımlanır.
 * Doküman sahibi (qms_document.OWNER_DEPARTMENT_ID) ve dağıtım kuralları (SUBJECT_TYPE = DEPARTMENT) buna bağlanır.
 * Kullanılmış departman silinmez, pasife alınır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_department')]
#[ORM\UniqueConstraint(name: 'UQ_qms_department_COMPANY_CODE', columns: ['COMPANY_ID', 'CODE'])]
class Department
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $departmentId = null;             // DEPARTMENT_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company (boşsa QmsAuditListener oturumdakini yazar)

    #[ORM\Column(length: 20)]
    private string $code;                          // CODE (ör. LAB, ISG)

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $departmentName;                // DEPARTMENT_NAME

    public function __construct(string $code, string $departmentName)
    {
        $this->code           = $code;
        $this->departmentName = $departmentName;
    }

    public function getDepartmentId(): ?int        { return $this->departmentId; }
    public function getCompany(): ?Company         { return $this->company; }
    public function setCompany(Company $v): self   { $this->company = $v; return $this; }
    public function getCode(): string              { return $this->code; }
    public function setCode(string $v): self      { $this->code = $v; return $this; }
    public function getDepartmentName(): string    { return $this->departmentName; }
    public function setDepartmentName(string $v): self { $this->departmentName = $v; return $this; }
}
