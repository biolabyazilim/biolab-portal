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
 * malzemenin kendi listesi hesaba katılmaz. QUANTITY birlikte kullanılan malzemenin kullanım biriminde, kitin bir testi başına.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_material_companion')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_material_companion', columns: ['KIT_MATERIAL_ID', 'MATERIAL_ID'])]
class MaterialCompanion
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

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
    private string $quantity;                      // QUANTITY (kitin bir testi başına)

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortNo = 0;                       // SORT_NO

    public function __construct(Material $kit, Material $material, string $quantity, int $sortNo = 0)
    {
        $this->kit      = $kit;
        $this->material = $material;
        $this->quantity = $quantity;
        $this->sortNo   = $sortNo;
    }

    public function getMaterialCompanionId(): ?int  { return $this->materialCompanionId; }
    public function getKit(): Material              { return $this->kit; }
    public function getMaterial(): Material         { return $this->material; }
    public function getQuantity(): string           { return $this->quantity; }
    public function setQuantity(string $v): self    { $this->quantity = $v; return $this; }
    public function getSortNo(): int                { return $this->sortNo; }
    public function setSortNo(int $v): self         { $this->sortNo = $v; return $this; }
}
