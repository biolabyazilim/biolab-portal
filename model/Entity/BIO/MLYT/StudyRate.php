<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Maliyet çalışmasının döviz kurları. Excel'de dosya adında tutuluyordu ("$-55-€-62S-70").
 * TRY için kayıt açılmaz; kur çalışmanın öngörü katsayısıdır (Study::TRY_FACTOR).
 * Tedarikçinin kendi sözleşme kuru (ör. NUCLEUS EUR 44,7) ayrı tabloda: StudySupplierRate.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_study_rate')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_study_rate_CURRENCY', columns: ['STUDY_ID', 'CURRENCY'])]
class StudyRate
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const CURRENCY_TRY = 'TRY';
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_EUR = 'EUR';
    public const CURRENCY_GBP = 'GBP';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $studyRateId = null;              // STUDY_RATE_ID

    #[ORM\ManyToOne(targetEntity: Study::class)]
    #[ORM\JoinColumn(name: 'STUDY_ID', referencedColumnName: 'STUDY_ID', nullable: false)]
    private Study $study;                          // STUDY_ID → mlyt_study

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;                      // CURRENCY (USD / EUR / GBP)

    // decimal PHP'de string döner (yuvarlama hatası olmasın diye)
    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    private string $rate;                          // RATE (1 döviz = ? TL)

    public function __construct(Study $study, string $currency, string $rate)
    {
        $this->study    = $study;
        $this->currency = $currency;
        $this->rate     = $rate;
    }

    public function getStudyRateId(): ?int      { return $this->studyRateId; }
    public function getStudy(): Study           { return $this->study; }
    public function getCurrency(): string       { return $this->currency; }
    public function getRate(): string           { return $this->rate; }
    public function setRate(string $v): self    { $this->rate = $v; return $this; }
}
