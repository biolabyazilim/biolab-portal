<?php
declare(strict_types=1);

namespace BIO\MNG;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Uygulama menüsü (ağaç). Her uygulamanın bir kök menüsü vardır; kökün MENU_CODE'u uygulama klasörünün adıdır
 * (ör. biolab-maliyet). Altındaki kayıtlar ya grup (FORM_ID boş) ya da ekrandır (FORM_ID dolu).
 * Kullanıcı yalnızca rolünün erişebildiği ekranları görür; altında görünür ekran kalmayan grup gizlenir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mng_menu')]
#[ORM\Index(name: 'IX_mng_menu_PARENT', columns: ['PARENT_MENU_ID', 'MENU_ORDER'])]
class Menu
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $menuId = null;                   // MENU_ID

    // Yalnızca kök menüde dolu: uygulama kodu
    #[ORM\Column(length: 50, nullable: true, unique: true)]
    private ?string $menuCode = null;              // MENU_CODE

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'PARENT_MENU_ID', referencedColumnName: 'MENU_ID', nullable: true)]
    private ?Menu $parent = null;                  // PARENT_MENU_ID → mng_menu

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(name: 'FORM_ID', referencedColumnName: 'FORM_ID', nullable: true)]
    private ?Form $form = null;                    // FORM_ID → mng_form (grupta boş)

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $menuName;                      // MENU_NAME

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $menuNameEn = null;            // MENU_NAME_EN

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $menuIcon = null;              // MENU_ICON (Font Awesome sınıfı, ör. "fas fa-boxes")

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $menuOrder = 0;                    // MENU_ORDER

    public function __construct(string $menuName, ?Menu $parent = null, ?Form $form = null, int $menuOrder = 0)
    {
        $this->menuName  = $menuName;
        $this->parent    = $parent;
        $this->form      = $form;
        $this->menuOrder = $menuOrder;
    }

    public function getMenuId(): ?int            { return $this->menuId; }
    public function getMenuCode(): ?string       { return $this->menuCode; }
    public function setMenuCode(?string $v): self { $this->menuCode = $v; return $this; }
    public function getParent(): ?Menu           { return $this->parent; }
    public function setParent(?Menu $v): self    { $this->parent = $v; return $this; }
    public function getForm(): ?Form             { return $this->form; }
    public function setForm(?Form $v): self      { $this->form = $v; return $this; }
    public function getMenuName(): string        { return $this->menuName; }
    public function setMenuName(string $v): self { $this->menuName = $v; return $this; }
    public function getMenuNameEn(): ?string     { return $this->menuNameEn; }
    public function setMenuNameEn(?string $v): self { $this->menuNameEn = $v; return $this; }
    public function getMenuIcon(): ?string       { return $this->menuIcon; }
    public function setMenuIcon(?string $v): self { $this->menuIcon = $v; return $this; }
    public function getMenuOrder(): int          { return $this->menuOrder; }
    public function setMenuOrder(int $v): self   { $this->menuOrder = $v; return $this; }
}
