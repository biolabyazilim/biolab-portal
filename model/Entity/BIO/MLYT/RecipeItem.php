<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Testin reçetesindeki bir malzeme: "PAT09 için 2 adet doku takip kaseti, hasta başına".
 * Reçete teste bağlıdır, maliyet çalışmasına değil: aynı reçete her çalışmanın kendi fiyatlarıyla hesaplanır
 * (Biolab\Core\Mlyt\CostCalculator). Miktar malzemenin kullanım biriminde (USAGE_UNIT) yazılır.
 *
 * CONSUMPTION — miktar neye göre:
 *   PATIENT → hasta başına (şimdilik hesaplanan tek tip)
 *   RUN / DAY / WEEK / MONTH → çalışma / gün / hafta / ay başına (kontrol, kalibratör); test hacmi gelince hesaplanacak
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_recipe_item')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_recipe_item', columns: ['TEST_ID', 'MATERIAL_ID', 'CONSUMPTION'])]
class RecipeItem
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const PER_PATIENT = 'PATIENT';
    public const PER_RUN     = 'RUN';
    public const PER_DAY     = 'DAY';
    public const PER_WEEK    = 'WEEK';
    public const PER_MONTH   = 'MONTH';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $recipeItemId = null;             // RECIPE_ITEM_ID

    #[ORM\ManyToOne(targetEntity: Test::class)]
    #[ORM\JoinColumn(name: 'TEST_ID', referencedColumnName: 'TEST_ID', nullable: false)]
    private Test $test;                            // TEST_ID → mlyt_test

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'MATERIAL_ID', referencedColumnName: 'MATERIAL_ID', nullable: false)]
    private Material $material;                    // MATERIAL_ID → mlyt_material

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    private string $quantity;                      // QUANTITY (0,5 adet; 1,5 ml...)

    #[ORM\Column(length: 10, options: ['default' => self::PER_PATIENT])]
    private string $consumption = self::PER_PATIENT;   // CONSUMPTION

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortNo = 0;                       // SORT_NO

    #[ORM\Column(length: 300, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                  // NOTE

    public function __construct(Test $test, Material $material, string $quantity, string $consumption = self::PER_PATIENT, int $sortNo = 0)
    {
        $this->test        = $test;
        $this->material    = $material;
        $this->quantity    = $quantity;
        $this->consumption = $consumption;
        $this->sortNo      = $sortNo;
    }

    public function getRecipeItemId(): ?int         { return $this->recipeItemId; }
    public function getTest(): Test                 { return $this->test; }
    public function getMaterial(): Material         { return $this->material; }
    public function getQuantity(): string           { return $this->quantity; }
    public function setQuantity(string $v): self    { $this->quantity = $v; return $this; }
    public function getConsumption(): string        { return $this->consumption; }
    public function getSortNo(): int                { return $this->sortNo; }
    public function setSortNo(int $v): self         { $this->sortNo = $v; return $this; }
    public function getNote(): ?string              { return $this->note; }
    public function setNote(?string $v): self       { $this->note = $v; return $this; }
}
