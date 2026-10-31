<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Hesaplamalı testin bileşeni. Testin maliyeti = Σ bileşen maliyeti × QUANTITY × APPLY_RATE / 100.
 * Örnek: PAT316 = 910220 + 909560 + 909820 + 911160 + 909760 × 2 (birleşik patoloji);
 *        kültürde "üreme olursa antibiyogram" → APPLY_RATE ile (ör. %30).
 * Bileşim yıldan yıla değişmediği için maliyet çalışmasına bağlı değildir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_test_component')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_test_component', columns: ['TEST_ID', 'COMPONENT_TEST_ID'])]
class TestComponent
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $testComponentId = null;          // TEST_COMPONENT_ID

    #[ORM\ManyToOne(targetEntity: Test::class)]
    #[ORM\JoinColumn(name: 'TEST_ID', referencedColumnName: 'TEST_ID', nullable: false)]
    private Test $test;                            // TEST_ID → mlyt_test (birleşik / hesaplamalı test)

    #[ORM\ManyToOne(targetEntity: Test::class)]
    #[ORM\JoinColumn(name: 'COMPONENT_TEST_ID', referencedColumnName: 'TEST_ID', nullable: false)]
    private Test $componentTest;                   // COMPONENT_TEST_ID → mlyt_test

    #[ORM\Column(type: 'decimal', precision: 9, scale: 3, options: ['default' => 1])]
    private string $quantity = '1';                // QUANTITY (X2 → 2)

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, options: ['default' => 100])]
    private string $applyRate = '100';             // APPLY_RATE (% uygulanma oranı)

    public function __construct(Test $test, Test $componentTest, string $quantity = '1', string $applyRate = '100')
    {
        $this->test          = $test;
        $this->componentTest = $componentTest;
        $this->quantity      = $quantity;
        $this->applyRate     = $applyRate;
    }

    public function getTestComponentId(): ?int      { return $this->testComponentId; }
    public function getTest(): Test                 { return $this->test; }
    public function getComponentTest(): Test        { return $this->componentTest; }
    public function getQuantity(): string           { return $this->quantity; }
    public function setQuantity(string $v): self    { $this->quantity = $v; return $this; }
    public function getApplyRate(): string          { return $this->applyRate; }
    public function setApplyRate(string $v): self   { $this->applyRate = $v; return $this; }
}
