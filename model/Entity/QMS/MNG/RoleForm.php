<?php
declare(strict_types=1);

namespace QMS\MNG;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Rolün erişebildiği ekran.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mng_role_form')]
#[ORM\UniqueConstraint(name: 'UQ_mng_role_form_ROLE_FORM', columns: ['ROLE_ID', 'FORM_ID'])]
class RoleForm
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $roleFormId = null;               // ROLE_FORM_ID

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'ROLE_ID', referencedColumnName: 'ROLE_ID', nullable: false)]
    private Role $role;                            // ROLE_ID → mng_role

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(name: 'FORM_ID', referencedColumnName: 'FORM_ID', nullable: false)]
    private Form $form;                            // FORM_ID → mng_form

    public function __construct(Role $role, Form $form)
    {
        $this->role = $role;
        $this->form = $form;
    }

    public function getRoleFormId(): ?int  { return $this->roleFormId; }
    public function getRole(): Role        { return $this->role; }
    public function getForm(): Form        { return $this->form; }
}
