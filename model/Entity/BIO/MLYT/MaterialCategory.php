<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Malzeme kategorisi (Kit, Sarf, Besiyeri, Boya / kimyasal, Kontrol / kalibratör, Hizmet, Diğer).
 * Ekrandaki kategori listeleri buradan gelir; yeni kategori için kod değişmez, satır eklenir.
 * CATEGORY_CODE kodda kategoriye göre davranış gerekirse kullanılır (ör. KIT reçetede efektif hasta ister).
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_material_category')]
class MaterialCategory
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const CODE_KIT = 'KIT';
    public const CODE_OTHER = 'OTHER';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $categoryId = null;               // CATEGORY_ID

    #[ORM\Column(length: 20, unique: true)]
    private string $categoryCode;                  // CATEGORY_CODE (ör. KIT, CONSUMABLE)

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $categoryName;                  // CATEGORY_NAME

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categoryNameEn = null;        // CATEGORY_NAME_EN

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortNo = 0;                       // SORT_NO (listedeki sıra)

    public function __construct(string $categoryCode, string $categoryName, ?string $categoryNameEn = null, int $sortNo = 0)
    {
        $this->categoryCode   = $categoryCode;
        $this->categoryName   = $categoryName;
        $this->categoryNameEn = $categoryNameEn;
        $this->sortNo         = $sortNo;
    }

    public function getCategoryId(): ?int           { return $this->categoryId; }
    public function getCategoryCode(): string       { return $this->categoryCode; }
    public function getCategoryName(): string       { return $this->categoryName; }
    public function setCategoryName(string $v): self { $this->categoryName = $v; return $this; }
    public function getCategoryNameEn(): ?string    { return $this->categoryNameEn; }
    public function setCategoryNameEn(?string $v): self { $this->categoryNameEn = $v; return $this; }
    public function getSortNo(): int                { return $this->sortNo; }
    public function setSortNo(int $v): self         { $this->sortNo = $v; return $this; }

    /** Oturum diline göre ad (en ise İngilizce varsa onu) */
    public function getName(string $lang): string
    {
        return ($lang === 'en' && $this->categoryNameEn) ? $this->categoryNameEn : $this->categoryName;
    }
}
