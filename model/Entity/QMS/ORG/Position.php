<?php
declare(strict_types=1);

namespace QMS\ORG;

use QMS\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Pozisyon (ör. İSG Uzmanı, Kalite Şefi, İSG Müdürü). İmza yetki matrisi pozisyona göre kurulur
 * (qms_doc_type_step_position): kim hangi doküman tipinde hazırlayan / kontrol eden / onaylayan olabilir.
 * DEPARTMENT_ID boş olabilir: şirket geneli pozisyonlar (ör. Genel Müdür).
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_position')]
#[ORM\UniqueConstraint(name: 'UQ_qms_position_COMPANY_CODE', columns: ['COMPANY_ID', 'CODE'])]
class Position
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $positionId = null;               // POSITION_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'DEPARTMENT_ID', referencedColumnName: 'DEPARTMENT_ID', nullable: true)]
    private ?Department $department = null;        // DEPARTMENT_ID → qms_department

    #[ORM\Column(length: 20)]
    private string $code;                          // CODE (ör. ISG_UZM)

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $positionName;                  // POSITION_NAME

    public function __construct(string $code, string $positionName, ?Department $department = null)
    {
        $this->code         = $code;
        $this->positionName = $positionName;
        $this->department   = $department;
    }

    public function getPositionId(): ?int          { return $this->positionId; }
    public function getCompany(): ?Company         { return $this->company; }
    public function setCompany(Company $v): self   { $this->company = $v; return $this; }
    public function getDepartment(): ?Department   { return $this->department; }
    public function setDepartment(?Department $v): self { $this->department = $v; return $this; }
    public function getCode(): string              { return $this->code; }
    public function setCode(string $v): self      { $this->code = $v; return $this; }
    public function getPositionName(): string      { return $this->positionName; }
    public function setPositionName(string $v): self { $this->positionName = $v; return $this; }
}
