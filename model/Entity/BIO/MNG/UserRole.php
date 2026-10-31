<?php
declare(strict_types=1);

namespace BIO\MNG;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Kullanıcıya şirket bazında verilen rol. Aynı kullanıcı farklı şirkette farklı role sahip olabilir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mng_user_role')]
#[ORM\UniqueConstraint(name: 'UQ_mng_user_role_USER_ROLE', columns: ['USER_ID', 'ROLE_ID', 'COMPANY_ID'])]
class UserRole
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $userRoleId = null;               // USER_ROLE_ID

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'USER_ID', referencedColumnName: 'USER_ID', nullable: false)]
    private AppUser $user;                         // USER_ID → mng_user

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'ROLE_ID', referencedColumnName: 'ROLE_ID', nullable: false)]
    private Role $role;                            // ROLE_ID → mng_role

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private Company $company;                      // COMPANY_ID → mng_company

    public function __construct(AppUser $user, Role $role, Company $company)
    {
        $this->user    = $user;
        $this->role    = $role;
        $this->company = $company;
    }

    public function getUserRoleId(): ?int
    {
        return $this->userRoleId !== null ? (int) $this->userRoleId : null;
    }

    public function getUser(): AppUser       { return $this->user; }
    public function getRole(): Role          { return $this->role; }
    public function getCompany(): Company    { return $this->company; }
}
