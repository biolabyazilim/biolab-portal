<?php
declare(strict_types=1);

namespace BIO\MNG;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Kullanıcının bağlı olduğu şirketler (D.Sys'teki UserCompany karşılığı).
 * Girişte DEFAULT_FL = 'Y' olan şirket oturuma yazılır (COMPANY_ID); yoksa ilk aktif şirket.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mng_user_company')]
#[ORM\UniqueConstraint(name: 'UQ_mng_user_company_USER_COMPANY', columns: ['USER_ID', 'COMPANY_ID'])]
class UserCompany
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $userCompanyId = null;          // USER_COMPANY_ID

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'USER_ID', referencedColumnName: 'USER_ID', nullable: false)]
    private AppUser $user;                       // USER_ID → mng_user

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private Company $company;                    // COMPANY_ID → mng_company

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $defaultFl = 'N';             // DEFAULT_FL

    public function __construct(AppUser $user, Company $company, bool $isDefault = false)
    {
        $this->user      = $user;
        $this->company   = $company;
        $this->defaultFl = $isDefault ? 'Y' : 'N';
    }

    public function getUserCompanyId(): ?int
    {
        return $this->userCompanyId !== null ? (int) $this->userCompanyId : null;
    }

    public function getUser(): AppUser       { return $this->user; }
    public function getCompany(): Company    { return $this->company; }
    public function isDefault(): bool        { return $this->defaultFl === 'Y'; }

    public function setDefault(bool $isDefault): self
    {
        $this->defaultFl = $isDefault ? 'Y' : 'N';
        return $this;
    }
}
