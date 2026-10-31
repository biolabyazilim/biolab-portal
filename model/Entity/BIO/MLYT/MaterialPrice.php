<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Malzemenin bir maliyet çalışmasındaki fiyatı.
 * Birim maliyet (TL / USAGE_UNIT) = PACKAGE_PRICE × kur ÷ PACKAGE_QTY ; hesap Biolab\Core\Mlyt tarafında yapılır.
 * Örnek: 24 testlik kit 480 USD, kur 55 → 480 × 55 ÷ 24 = 1.100 TL / test.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_material_price')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_material_price_MATERIAL', columns: ['STUDY_ID', 'MATERIAL_ID'])]
class MaterialPrice
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $materialPriceId = null;          // MATERIAL_PRICE_ID

    #[ORM\ManyToOne(targetEntity: Study::class)]
    #[ORM\JoinColumn(name: 'STUDY_ID', referencedColumnName: 'STUDY_ID', nullable: false)]
    private Study $study;                          // STUDY_ID → mlyt_study

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'MATERIAL_ID', referencedColumnName: 'MATERIAL_ID', nullable: false)]
    private Material $material;                    // MATERIAL_ID → mlyt_material

    #[ORM\Column(length: 100, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $packageDesc = null;           // PACKAGE_DESC (ör. "1 kutu = 10 paket x 96")

    // Ambalajda kaç USAGE_UNIT var (24 test, 960 adet, 500 ml)
    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    private string $packageQty;                    // PACKAGE_QTY

    // Bir ambalajdan çıkan hasta sayısı (kit: 96 testlik kitten 70 hasta; ICP-MS standardı: şişe kaç hastaya yeter).
    // Doluysa birim maliyet hasta başıdır: ambalaj fiyatı × kur ÷ hasta sayısı (ambalaj içeriği yerine).
    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, nullable: true)]
    private ?string $patientQty = null;            // PATIENT_QTY

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;                      // CURRENCY (TRY / USD / EUR / GBP)

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $packagePrice;                  // PACKAGE_PRICE (ambalaj fiyatı, dövizinde)

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $vatIncludedFl = 'N';           // VAT_INCLUDED_FL (Y / N)

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                  // NOTE

    public function __construct(Study $study, Material $material, string $packageQty, string $currency, string $packagePrice)
    {
        $this->study        = $study;
        $this->material     = $material;
        $this->packageQty   = $packageQty;
        $this->currency     = $currency;
        $this->packagePrice = $packagePrice;
    }

    public function getMaterialPriceId(): ?int          { return $this->materialPriceId; }
    public function getStudy(): Study                   { return $this->study; }
    public function getMaterial(): Material             { return $this->material; }

    public function getPackageDesc(): ?string           { return $this->packageDesc; }
    public function setPackageDesc(?string $v): self    { $this->packageDesc = $v; return $this; }
    public function getPackageQty(): string             { return $this->packageQty; }
    public function setPackageQty(string $v): self      { $this->packageQty = $v; return $this; }
    public function getPatientQty(): ?string            { return $this->patientQty; }
    public function setPatientQty(?string $v): self     { $this->patientQty = $v; return $this; }
    public function getCurrency(): string               { return $this->currency; }
    public function setCurrency(string $v): self        { $this->currency = $v; return $this; }
    public function getPackagePrice(): string           { return $this->packagePrice; }
    public function setPackagePrice(string $v): self    { $this->packagePrice = $v; return $this; }
    public function isVatIncluded(): bool               { return $this->vatIncludedFl === 'Y'; }
    public function setVatIncluded(bool $v): self       { $this->vatIncludedFl = $v ? 'Y' : 'N'; return $this; }
    public function getNote(): ?string                  { return $this->note; }
    public function setNote(?string $v): self           { $this->note = $v; return $this; }
}
