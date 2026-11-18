<?php
declare(strict_types=1);

namespace BIO\MLYT;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Aylık fire / kayıp / tekrar takibinin dönemi (ör. 2026-10). Yıllık maliyet çalışmasından ayrıdır:
 * miktarlar o ayın yüklenen dosyalarından (Netsis, LabAsistan) gelir; TL karşılığı STUDY_ID'deki
 * çalışmanın birim maliyetleriyle hesaplanır (Biolab\Core\Mlyt\MonthlyReport). Şirket başına ay tektir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_period')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_period', columns: ['COMPANY_ID', 'PERIOD_YM'])]
class MonthlyPeriod
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $periodId = null;                 // PERIOD_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\Column(length: 7, options: ['fixed' => true])]
    private string $periodYm;                      // PERIOD_YM ("2026-10")

    #[ORM\ManyToOne(targetEntity: Study::class)]
    #[ORM\JoinColumn(name: 'STUDY_ID', referencedColumnName: 'STUDY_ID', nullable: true)]
    private ?Study $study = null;                  // STUDY_ID: TL karşılığı için birim maliyetlerin alındığı çalışma

    #[ORM\Column(length: 1000, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                  // NOTE

    public function __construct(Company $company, string $periodYm, ?Study $study = null)
    {
        $this->company  = $company;
        $this->periodYm = $periodYm;
        $this->study    = $study;
    }

    public function getPeriodId(): ?int          { return $this->periodId; }
    public function getCompany(): ?Company       { return $this->company; }
    public function getPeriodYm(): string        { return $this->periodYm; }
    public function getStudy(): ?Study           { return $this->study; }
    public function setStudy(?Study $v): self    { $this->study = $v; return $this; }
    public function getNote(): ?string           { return $this->note; }
    public function setNote(?string $v): self    { $this->note = $v; return $this; }

    /** Dönemin ilk ve son günü */
    public function firstDay(): \DateTimeImmutable { return new \DateTimeImmutable($this->periodYm . '-01'); }
    public function lastDay(): \DateTimeImmutable  { return $this->firstDay()->modify('last day of this month'); }
}
