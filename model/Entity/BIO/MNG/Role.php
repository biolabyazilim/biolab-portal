<?php
declare(strict_types=1);

namespace BIO\MNG;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Rol (ör. MLYT_EXPERT maliyet uzmanı, MLYT_MANAGER yönetici, MLYT_LAB lab sorumlusu, HB_USER HB kullanıcısı).
 * Hangi ekranlara erişeceği mng_role_form'da, kimlere verildiği mng_user_role'de tutulur.
 * ROLE_CODE kodda rol kontrolü için kullanılır (ör. lab sorumlusu fiyat görmez).
 * APP_CODE rolün ait olduğu uygulamadır (apps/ altındaki klasör adı): kullanıcı bir uygulamaya ancak
 * o uygulamanın en az bir rolüne sahipse girebilir (FormAccess::canEnterApp).
 */
#[ORM\Entity]
#[ORM\Table(name: 'mng_role')]
class Role
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $roleId = null;                   // ROLE_ID

    #[ORM\Column(length: 50, unique: true)]
    private string $roleCode;                      // ROLE_CODE

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $roleName;                      // ROLE_NAME

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $roleNameEn = null;            // ROLE_NAME_EN

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $appCode = null;               // APP_CODE (ör. biolab-maliyet, hb-elektroforez)

    public function __construct(string $roleCode, string $roleName, ?string $roleNameEn = null, ?string $appCode = null)
    {
        $this->roleCode   = $roleCode;
        $this->roleName   = $roleName;
        $this->roleNameEn = $roleNameEn;
        $this->appCode    = $appCode;
    }

    public function getRoleId(): ?int            { return $this->roleId; }
    public function getRoleCode(): string        { return $this->roleCode; }
    public function getRoleName(): string        { return $this->roleName; }
    public function setRoleName(string $v): self { $this->roleName = $v; return $this; }
    public function getRoleNameEn(): ?string     { return $this->roleNameEn; }
    public function getAppCode(): ?string        { return $this->appCode; }
}
