<?php
declare(strict_types=1);

namespace QMS\DOC;

use QMS\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Doküman klasörü (kategori): kullanıcının ortak klasörde alıştığı ağaç, ör.
 *   KALİTE DOKÜMANLARI › TALİMATLAR / PROSEDÜRLER / FORMLAR / LİSTELER …
 * Yalnızca gruplama ve görünüm içindir; kurallar (kod kalıbı, şablon, imza akışı, dağıtım) doküman tipindedir.
 * Her doküman tipi bir klasöre bağlıdır, doküman da klasörünü tipinden alır (ayrıca seçilmez).
 * Klasörler sanaldır: adı değişse de dosya taşınmaz, bağlantı bozulmaz.
 * PARENT_ID boşsa kök klasördür; SORT_NO aynı seviyedeki sırayı belirler.
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_doc_category')]
#[ORM\UniqueConstraint(name: 'UQ_qms_doc_category_COMPANY_CODE', columns: ['COMPANY_ID', 'CODE'])]
#[ORM\Index(name: 'IX_qms_doc_category_PARENT', columns: ['PARENT_ID', 'SORT_NO'])]
class DocCategory
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $categoryId = null;               // CATEGORY_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'PARENT_ID', referencedColumnName: 'CATEGORY_ID', nullable: true)]
    private ?DocCategory $parent = null;           // PARENT_ID → qms_doc_category (kökte boş)

    #[ORM\Column(length: 30)]
    private string $code;                          // CODE (ör. TALIMAT)

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $categoryName;                  // CATEGORY_NAME (ekranda görünen klasör adı, ör. TALİMATLAR)

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortNo = 0;                       // SORT_NO

    public function __construct(string $code, string $categoryName, ?DocCategory $parent = null, int $sortNo = 0)
    {
        $this->code         = $code;
        $this->categoryName = $categoryName;
        $this->parent       = $parent;
        $this->sortNo       = $sortNo;
    }

    public function getCategoryId(): ?int          { return $this->categoryId; }
    public function getCompany(): ?Company         { return $this->company; }
    public function setCompany(Company $v): self   { $this->company = $v; return $this; }
    public function getParent(): ?DocCategory      { return $this->parent; }
    public function setParent(?DocCategory $v): self { $this->parent = $v; return $this; }
    public function getCode(): string              { return $this->code; }
    public function setCode(string $v): self       { $this->code = $v; return $this; }
    public function getCategoryName(): string      { return $this->categoryName; }
    public function setCategoryName(string $v): self { $this->categoryName = $v; return $this; }
    public function getSortNo(): int               { return $this->sortNo; }
    public function setSortNo(int $v): self        { $this->sortNo = $v; return $this; }
}
