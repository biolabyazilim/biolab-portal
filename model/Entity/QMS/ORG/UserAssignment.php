<?php
declare(strict_types=1);

namespace QMS\ORG;

use QMS\MNG\AppUser;
use QMS\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Kullanıcının şirketteki görevi: departman, pozisyon, lokasyon. Tarihlidir; görev değişince eski kaydın
 * VALID_TO'su doldurulur ve yeni kayıt eklenir (üzerine yazılmaz). Böylece "kişi o tarihte hangi pozisyondaydı,
 * hangi dokümanları görebiliyordu" sorusu geriye dönük cevaplanır.
 * Güncel görev: VALID_TO boş olan kayıt (kullanıcı + şirket başına bir tane).
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_user_assignment')]
#[ORM\Index(name: 'IX_qms_user_assignment_USER', columns: ['USER_ID', 'COMPANY_ID', 'VALID_TO'])]
#[ORM\Index(name: 'IX_qms_user_assignment_POSITION', columns: ['POSITION_ID', 'VALID_TO'])]
class UserAssignment
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $assignmentId = null;             // ASSIGNMENT_ID

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'USER_ID', referencedColumnName: 'USER_ID', nullable: false)]
    private AppUser $user;                         // USER_ID → mng_user

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'DEPARTMENT_ID', referencedColumnName: 'DEPARTMENT_ID', nullable: false)]
    private Department $department;                // DEPARTMENT_ID → qms_department

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(name: 'POSITION_ID', referencedColumnName: 'POSITION_ID', nullable: false)]
    private Position $position;                    // POSITION_ID → qms_position

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'LOCATION_ID', referencedColumnName: 'LOCATION_ID', nullable: true)]
    private ?Location $location = null;            // LOCATION_ID → qms_location

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $validFrom;         // VALID_FROM

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validTo = null;   // VALID_TO (boş: güncel görev)

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $reason = null;                // REASON (ör. "Kalite Şefi olarak atandı")

    public function __construct(AppUser $user, Department $department, Position $position, ?Location $location, \DateTimeImmutable $validFrom, ?string $reason = null)
    {
        $this->user       = $user;
        $this->department = $department;
        $this->position   = $position;
        $this->location   = $location;
        $this->validFrom  = $validFrom;
        $this->reason     = $reason;
    }

    public function getAssignmentId(): ?int
    {
        return $this->assignmentId !== null ? (int) $this->assignmentId : null;
    }

    public function getUser(): AppUser             { return $this->user; }
    public function getCompany(): ?Company         { return $this->company; }
    public function setCompany(Company $v): self   { $this->company = $v; return $this; }
    public function getDepartment(): Department    { return $this->department; }
    public function getPosition(): Position        { return $this->position; }
    public function getLocation(): ?Location       { return $this->location; }
    public function getValidFrom(): \DateTimeImmutable { return $this->validFrom; }
    public function getValidTo(): ?\DateTimeImmutable  { return $this->validTo; }
    public function getReason(): ?string           { return $this->reason; }
    public function isCurrent(): bool              { return $this->validTo === null; }

    // Görev sona erdi (yeni görev kaydı ayrıca eklenir)
    public function close(\DateTimeImmutable $validTo): self
    {
        $this->validTo = $validTo;
        return $this;
    }
}
