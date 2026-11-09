<?php
declare(strict_types=1);

namespace BIO\MLYT;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Malzeme kataloğu. Kategorisi mlyt_material_category'den gelir (Kit, Sarf, Besiyeri...).
 * Fiyat burada tutulmaz; her maliyet çalışmasının kendi fiyatı vardır (MaterialPrice).
 * Reçetede miktar USAGE_UNIT cinsinden yazılır (ör. 0,5 adet kanlı agar, 1,5 ml kristal viyole).
 * Mükerrer kayıt silinmez; MERGED_INTO_MATERIAL_ID ile asıl kayda bağlanır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_material')]
#[ORM\Index(name: 'IX_mlyt_material_NAME', columns: ['COMPANY_ID', 'MATERIAL_NAME'])]
class Material
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const UNIT_PIECE = 'PIECE';   // adet
    public const UNIT_TEST  = 'TEST';
    public const UNIT_ML    = 'ML';
    public const UNIT_GR    = 'GR';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $materialId = null;               // MATERIAL_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\Column(length: 250, options: ['collation' => 'Turkish_CI_AS'])]
    private string $materialName;                  // MATERIAL_NAME

    #[ORM\ManyToOne(targetEntity: MaterialCategory::class)]
    #[ORM\JoinColumn(name: 'CATEGORY_ID', referencedColumnName: 'CATEGORY_ID', nullable: false)]
    private MaterialCategory $category;            // CATEGORY_ID → mlyt_material_category

    #[ORM\Column(length: 10, options: ['default' => self::UNIT_PIECE])]
    private string $usageUnit = self::UNIT_PIECE;  // USAGE_UNIT

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'SUPPLIER_ID', referencedColumnName: 'SUPPLIER_ID', nullable: true)]
    private ?Supplier $supplier = null;            // SUPPLIER_ID → mlyt_supplier

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $sourceRowNo = null;              // SOURCE_ROW_NO (Excel MALZEME LİSTESİ "SIRA")

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'MERGED_INTO_MATERIAL_ID', referencedColumnName: 'MATERIAL_ID', nullable: true)]
    private ?Material $mergedInto = null;          // MERGED_INTO_MATERIAL_ID (mükerrerse asıl kayıt)

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                  // NOTE

    // Kısa kod: patolojide malzeme notlarında geçen kısaltma (TK = takip kaseti, L = lam, LM = lamel, NSİ, SPS, BYK).
    // Kısa kodu olan malzemeler Test tanımları'nda patoloji testine adetle seçilebilir.
    #[ORM\Column(length: 10, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $shortCode = null;             // SHORT_CODE

    // Katalog numarası (tedarikçinin ürün kodu, ör. Roche 3183688122); aynı kit birden çok satırda gelirse tek kayıt olur
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $catalogNo = null;             // CATALOG_NO

    // Bedelli malzeme (kategori BILLABLE): bir kere tanımlanan sabit bedel, TL / kullanım birimi. Doktorlara ürün bazlı
    // fiyatlanan malzeme (numune kabı vb.) maliyet çalışmasından bağımsız bu bedelle değerlendirilir.
    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $billablePrice = null;         // BILLABLE_PRICE

    public function __construct(string $materialName, MaterialCategory $category, string $usageUnit = self::UNIT_PIECE)
    {
        $this->materialName = $materialName;
        $this->category     = $category;
        $this->usageUnit    = $usageUnit;
    }

    public function getMaterialId(): ?int               { return $this->materialId; }
    public function getCompany(): ?Company              { return $this->company; }
    public function setCompany(Company $company): self  { $this->company = $company; return $this; }

    public function getMaterialName(): string           { return $this->materialName; }
    public function setMaterialName(string $v): self    { $this->materialName = $v; return $this; }
    public function getCategory(): MaterialCategory     { return $this->category; }
    public function setCategory(MaterialCategory $v): self { $this->category = $v; return $this; }
    public function getUsageUnit(): string              { return $this->usageUnit; }
    public function setUsageUnit(string $v): self       { $this->usageUnit = $v; return $this; }

    public function getSupplier(): ?Supplier            { return $this->supplier; }
    public function setSupplier(?Supplier $v): self     { $this->supplier = $v; return $this; }
    public function getSourceRowNo(): ?int              { return $this->sourceRowNo; }
    public function setSourceRowNo(?int $v): self       { $this->sourceRowNo = $v; return $this; }
    public function getMergedInto(): ?Material          { return $this->mergedInto; }
    public function setMergedInto(?Material $v): self   { $this->mergedInto = $v; return $this; }
    public function getNote(): ?string                  { return $this->note; }
    public function setNote(?string $v): self           { $this->note = $v; return $this; }
    public function getShortCode(): ?string             { return $this->shortCode; }
    public function setShortCode(?string $v): self      { $this->shortCode = $v; return $this; }
    public function getCatalogNo(): ?string             { return $this->catalogNo; }
    public function setCatalogNo(?string $v): self      { $this->catalogNo = $v; return $this; }
    public function getBillablePrice(): ?string         { return $this->billablePrice; }
    public function setBillablePrice(?string $v): self  { $this->billablePrice = $v; return $this; }
    public function isBillable(): bool                  { return $this->category->getCategoryCode() === MaterialCategory::CODE_BILLABLE; }
}
