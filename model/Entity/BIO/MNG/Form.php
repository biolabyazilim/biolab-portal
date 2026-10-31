<?php
declare(strict_types=1);

namespace BIO\MNG;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Ekran (form) tanımı. Rol → ekran yetkisi (mng_role_form) ve menü (mng_menu) bu kayda bağlanır.
 * FORM_PATH public klasörüne göre ekranın html yoludur (ör. apps/biolab-maliyet/material/material-1.0.0.html);
 * proxy, ekranın PHP'si çağrıldığında yetkiyi bu yoldan bulur (Biolab\Core\Auth\FormAccess).
 */
#[ORM\Entity]
#[ORM\Table(name: 'mng_form')]
class Form
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $formId = null;                   // FORM_ID

    #[ORM\Column(length: 50, unique: true)]
    private string $formCode;                      // FORM_CODE (ör. MLYT_MATERIAL)

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $formName;                      // FORM_NAME

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $formNameEn = null;            // FORM_NAME_EN

    #[ORM\Column(length: 300, unique: true)]
    private string $formPath;                      // FORM_PATH

    public function __construct(string $formCode, string $formName, string $formPath, ?string $formNameEn = null)
    {
        $this->formCode   = $formCode;
        $this->formName   = $formName;
        $this->formPath   = $formPath;
        $this->formNameEn = $formNameEn;
    }

    public function getFormId(): ?int           { return $this->formId; }
    public function getFormCode(): string       { return $this->formCode; }
    public function getFormName(): string       { return $this->formName; }
    public function setFormName(string $v): self { $this->formName = $v; return $this; }
    public function getFormNameEn(): ?string    { return $this->formNameEn; }
    public function getFormPath(): string       { return $this->formPath; }
    public function setFormPath(string $v): self { $this->formPath = $v; return $this; }
}
