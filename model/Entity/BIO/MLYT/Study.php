<?php
declare(strict_types=1);

namespace BIO\MLYT;

use BIO\MNG\AppUser;
use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Maliyet çalışması (ör. "2026 Yıl Sonu Maliyet Çalışması").
 * Kurlar, malzeme fiyatları ve reçeteler çalışmaya bağlıdır; yeni çalışma bir öncekinden kopyalanır.
 * Onaylanan çalışma salt okunurdur, böylece yıllar birbirini etkilemeden karşılaştırılır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_study')]
class Study
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const STATUS_DRAFT    = 'DRAFT';      // taslak, düzenlenebilir
    public const STATUS_REVIEW   = 'REVIEW';     // yönetici incelemesinde
    public const STATUS_APPROVED = 'APPROVED';   // onaylandı, salt okunur
    public const STATUS_ARCHIVED = 'ARCHIVED';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $studyId = null;                  // STUDY_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $studyName;                     // STUDY_NAME

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $rateDate;          // RATE_DATE (kurların alındığı gün)

    #[ORM\Column(length: 10, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;   // STATUS

    // "Günlük 6 test kontrol" gibi bildirimleri aylığa çevirirken kullanılır
    #[ORM\Column(type: 'smallint', options: ['default' => 26])]
    private int $workDaysPerMonth = 26;            // WORK_DAYS_PER_MONTH

    // TL öngörü katsayısı: TL fiyatlar bununla çarpılır (Excel'de TL kuru 1,5: "mutlaka öngörülü çalış"). TL'nin kuru gibidir.
    #[ORM\Column(type: 'decimal', precision: 8, scale: 4, options: ['default' => 1])]
    private string $tryFactor = '1';               // TRY_FACTOR

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'SOURCE_STUDY_ID', referencedColumnName: 'STUDY_ID', nullable: true)]
    private ?Study $sourceStudy = null;            // SOURCE_STUDY_ID (hangi çalışmadan kopyalandı)

    #[ORM\Column(length: 1000, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                  // NOTE

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'APPROVED_BY', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $approvedBy = null;           // APPROVED_BY → mng_user

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null; // APPROVED_AT

    public function __construct(string $studyName, \DateTimeImmutable $rateDate, ?Study $sourceStudy = null)
    {
        $this->studyName   = $studyName;
        $this->rateDate    = $rateDate;
        $this->sourceStudy = $sourceStudy;
    }

    public function getStudyId(): ?int                  { return $this->studyId; }
    public function getCompany(): ?Company              { return $this->company; }
    public function setCompany(Company $company): self  { $this->company = $company; return $this; }

    public function getStudyName(): string              { return $this->studyName; }
    public function setStudyName(string $v): self       { $this->studyName = $v; return $this; }
    public function getRateDate(): \DateTimeImmutable   { return $this->rateDate; }
    public function setRateDate(\DateTimeImmutable $v): self { $this->rateDate = $v; return $this; }
    public function getStatus(): string                 { return $this->status; }
    public function setStatus(string $v): self          { $this->status = $v; return $this; }
    public function isEditable(): bool                  { return $this->status === self::STATUS_DRAFT; }
    public function getWorkDaysPerMonth(): int          { return $this->workDaysPerMonth; }
    public function setWorkDaysPerMonth(int $v): self   { $this->workDaysPerMonth = $v; return $this; }
    public function getTryFactor(): string              { return $this->tryFactor; }
    public function setTryFactor(string $v): self       { $this->tryFactor = $v; return $this; }
    public function getSourceStudy(): ?Study            { return $this->sourceStudy; }
    public function getNote(): ?string                  { return $this->note; }
    public function setNote(?string $v): self           { $this->note = $v; return $this; }

    public function approve(AppUser $by): self
    {
        $this->status     = self::STATUS_APPROVED;
        $this->approvedBy = $by;
        $this->approvedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getApprovedBy(): ?AppUser           { return $this->approvedBy; }
    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
}
