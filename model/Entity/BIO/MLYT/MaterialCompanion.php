<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Kitle birlikte kullanılan malzeme: "ALB2 kitinin her testinde 1 adet pipet ucu, 1 adet küvet".
 * Kit bir testin reçetesindeyse bu malzemeler o testin maliyetine kendiliğinden eklenir:
 *   miktar = reçetedeki kit miktarı × QUANTITY   (Biolab\Core\Mlyt\CostCalculator, satır türü COMPANION)
 * Reçeteye ayrıca yazılmaz; liste değişince kitin bağlı olduğu bütün testlere yansır. Tek düzey: birlikte kullanılan
 * malzemenin kendi listesi hesaba katılmaz.
 *
 * BASIS miktarın neye göre yazıldığını söyler:
 *   TEST: QUANTITY birlikte kullanılan malzemenin kullanım biriminde, kitin bir testi başına (pipet ucu 4 adet)
 *   KIT : QUANTITY kitin bir kutusu başına kaç ambalaj kullanıldığı (Vitamin C kiti başına 3 kalibratör).
 *         Test başına = QUANTITY × malzemenin ambalaj içeriği ÷ kitin test (hasta) sayısı; ikisi de seçili çalışmadaki fiyattan.
 *         Çarpan elle hesaplanmaz; kitin ambalajı değişince (200 / 250 test) kendiliğinden doğru bölünür.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_material_companion')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_material_companion', columns: ['KIT_MATERIAL_ID', 'MATERIAL_ID'])]
class MaterialCompanion
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const BASIS_TEST = 'TEST';   // kitin bir testi başına (kullanım biriminde)
    public const BASIS_KIT  = 'KIT';    // kitin bir kutusu başına (ambalaj adedi)
    public const BASES = [self::BASIS_TEST, self::BASIS_KIT];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $materialCompanionId = null;      // MATERIAL_COMPANION_ID

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'KIT_MATERIAL_ID', referencedColumnName: 'MATERIAL_ID', nullable: false)]
    private Material $kit;                         // KIT_MATERIAL_ID → mlyt_material (kit)

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'MATERIAL_ID', referencedColumnName: 'MATERIAL_ID', nullable: false)]
    private Material $material;                    // MATERIAL_ID → mlyt_material (pipet ucu, küvet…)

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    private string $quantity;                      // QUANTITY (BASIS'e göre: kitin bir testi ya da bir kutusu başına)

    #[ORM\Column(length: 10, options: ['default' => self::BASIS_TEST])]
    private string $basis = self::BASIS_TEST;      // BASIS (TEST / KIT)

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortNo = 0;                       // SORT_NO

    public function __construct(Material $kit, Material $material, string $quantity, int $sortNo = 0, string $basis = self::BASIS_TEST)
    {
        $this->kit      = $kit;
        $this->material = $material;
        $this->quantity = $quantity;
        $this->sortNo   = $sortNo;
        $this->basis    = $basis;
    }

    public function getMaterialCompanionId(): ?int  { return $this->materialCompanionId; }
    public function getKit(): Material              { return $this->kit; }
    public function getMaterial(): Material         { return $this->material; }
    public function getQuantity(): string           { return $this->quantity; }
    public function setQuantity(string $v): self    { $this->quantity = $v; return $this; }
    public function getBasis(): string              { return $this->basis; }
    public function setBasis(string $v): self       { $this->basis = $v; return $this; }
    public function getSortNo(): int                { return $this->sortNo; }
    public function setSortNo(int $v): self         { $this->sortNo = $v; return $this; }
}
