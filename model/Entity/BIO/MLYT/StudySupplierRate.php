<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Tedarikçinin çalışma dönemi için kendi aralarında belirlenen sözleşme kuru (ör. NUCLEUS EUR 44,7; her yıl yeniden belirlenir).
 * Tedarikçisi bu olan malzemenin fiyatı bu kurla hesaplanır; kaydı yoksa çalışmanın genel kuru (StudyRate) kullanılır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_study_supplier_rate')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_study_supplier_rate', columns: ['STUDY_ID', 'SUPPLIER_ID', 'CURRENCY'])]
class StudySupplierRate
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $studySupplierRateId = null;      // STUDY_SUPPLIER_RATE_ID

    #[ORM\ManyToOne(targetEntity: Study::class)]
    #[ORM\JoinColumn(name: 'STUDY_ID', referencedColumnName: 'STUDY_ID', nullable: false)]
    private Study $study;                          // STUDY_ID → mlyt_study

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'SUPPLIER_ID', referencedColumnName: 'SUPPLIER_ID', nullable: false)]
    private Supplier $supplier;                    // SUPPLIER_ID → mlyt_supplier

    // Collation açıkça: mlyt_material_price.CURRENCY ile karşılaştırılır; sunucunun varsayılanı (Turkish_CI_AS) farklı
    #[ORM\Column(length: 3, options: ['fixed' => true, 'collation' => 'Latin1_General_100_CI_AS_SC_UTF8'])]
    private string $currency;                      // CURRENCY (USD / EUR / GBP)

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    private string $rate;                          // RATE (1 döviz = ? TL)

    public function __construct(Study $study, Supplier $supplier, string $currency, string $rate)
    {
        $this->study    = $study;
        $this->supplier = $supplier;
        $this->currency = $currency;
        $this->rate     = $rate;
    }

    public function getStudySupplierRateId(): ?int { return $this->studySupplierRateId; }
    public function getStudy(): Study              { return $this->study; }
    public function getSupplier(): Supplier        { return $this->supplier; }
    public function getCurrency(): string          { return $this->currency; }
    public function getRate(): string              { return $this->rate; }
    public function setRate(string $v): self       { $this->rate = $v; return $this; }
}
