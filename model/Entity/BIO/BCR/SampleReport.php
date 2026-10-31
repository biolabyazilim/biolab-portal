<?php
declare(strict_types=1);

namespace BIO\BCR;

use BIO\MNG\AppUser;
use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Uzman onayı = raporun bir revizyonu. Onaylanan revizyon değiştirilmez;
 * düzeltme yeni revizyon olarak yazılır, eskisi SUPERSEDED_AT ile kapanır (gerekçesi yenisinde durur).
 * Geçerli rapor: SUPERSEDED_AT boş olan revizyon. 5. adımdaki rapor PDF'i bundan üretilir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sample_report')]
#[ORM\UniqueConstraint(name: 'UQ_sample_report_REVISION', columns: ['SAMPLE_ACCEPTANCE_ID', 'REVISION_NO'])]
class SampleReport
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $sampleReportId = null;           // SAMPLE_REPORT_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: SampleAcceptance::class)]
    #[ORM\JoinColumn(name: 'SAMPLE_ACCEPTANCE_ID', referencedColumnName: 'SAMPLE_ACCEPTANCE_ID', nullable: false)]
    private SampleAcceptance $sampleAcceptance;    // SAMPLE_ACCEPTANCE_ID

    #[ORM\ManyToOne(targetEntity: DeviceResult::class)]
    #[ORM\JoinColumn(name: 'DEVICE_RESULT_ID', referencedColumnName: 'DEVICE_RESULT_ID', nullable: false)]
    private DeviceResult $deviceResult;            // DEVICE_RESULT_ID (uzmanın seçtiği çalışma)

    #[ORM\Column(type: 'smallint')]
    private int $revisionNo;                       // REVISION_NO (1, 2, …)

    #[ORM\Column(length: 300, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $revisionReason = null;        // REVISION_REASON (2. revizyondan itibaren zorunlu)

    #[ORM\Column(length: 1, options: ['fixed' => true])]
    private string $ageGroup;                      // AGE_GROUP (A / B / C)

    #[ORM\Column(type: 'smallint')]
    private int $ageMonths;                        // AGE_MONTHS (kabul tarihindeki yaş)

    // Raporlanan değerler
    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    private ?string $hba2Value = null;             // HBA2_VALUE

    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    private ?string $hbfValue = null;              // HBF_VALUE (cihaz "< 0.8" verdiyse 0.8)

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $hbfDeviceValue = null;        // HBF_DEVICE_VALUE (cihazın ham değeri)

    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    private ?string $hbaValue = null;              // HBA_VALUE (raporlanan)

    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    private ?string $hbaCalculated = null;         // HBA_CALCULATED (100 − HbA2 − HbF)

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $hbaManualFl = 'N';             // HBA_MANUAL_FL: Y = anormal pik ≥ %5, değeri uzman girdi

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $a0Value = null;               // A0_VALUE (cihazdaki A0 %, kontrol için)

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $hbsHbdText;                    // HBS_HBD_TEXT (SAPTANMADI / DOĞRULAMA GEREKLİ / uzman metni)

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $suggestedBlocks = null;       // SUGGESTED_BLOCKS (yazılımın önerisi, ör. "C3+C3N+V1")

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $selectedBlocks = null;        // SELECTED_BLOCKS (uzmanın seçtiği)

    // VARCHAR(MAX) + UTF-8 harmanlama: yorumda β, δ, α, ≥, µ var
    #[ORM\Column(type: 'text', options: ['collation' => 'Turkish_100_CI_AS_SC_UTF8'])]
    private string $commentText;                   // COMMENT_TEXT (algoritma yorumu: seçilen blokların metni, onay anındaki hali)

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $commentEditedFl = 'N';         // COMMENT_EDITED_FL: Y = uzman blok metnini değiştirdi (algoritma taslağından farklı)

    // Uzmanın algoritma yorumuna eklediği not; raporda yorumun altında ayrı kutuda, onaylayanın adıyla
    #[ORM\Column(type: 'text', nullable: true, options: ['collation' => 'Turkish_100_CI_AS_SC_UTF8'])]
    private ?string $expertNote = null;            // EXPERT_NOTE

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $warningsJson = null;          // WARNINGS_JSON (onayda gösterilen uyarılar)

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'APPROVED_BY', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $approvedBy = null;           // APPROVED_BY → mng_user

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $approvedAt;        // APPROVED_AT

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $supersededAt = null; // SUPERSEDED_AT (yeni revizyon gelince)

    public function __construct(SampleAcceptance $sa, DeviceResult $run, int $revisionNo, ?AppUser $approvedBy)
    {
        $this->sampleAcceptance = $sa;
        $this->deviceResult     = $run;
        $this->revisionNo       = $revisionNo;
        $this->approvedBy       = $approvedBy;
        $this->approvedAt       = new \DateTimeImmutable();
    }

    public function getSampleReportId(): ?int
    {
        return $this->sampleReportId !== null ? (int) $this->sampleReportId : null;
    }

    public function getCompany(): ?Company                 { return $this->company; }
    public function setCompany(Company $company): self     { $this->company = $company; return $this; }

    public function getSampleAcceptance(): SampleAcceptance { return $this->sampleAcceptance; }
    public function getDeviceResult(): DeviceResult         { return $this->deviceResult; }
    public function getRevisionNo(): int                    { return $this->revisionNo; }
    public function getRevisionReason(): ?string            { return $this->revisionReason; }
    public function setRevisionReason(?string $v): self     { $this->revisionReason = $v; return $this; }

    public function getAgeGroup(): string                   { return $this->ageGroup; }
    public function getAgeMonths(): int                     { return $this->ageMonths; }
    public function setAge(string $group, int $months): self { $this->ageGroup = $group; $this->ageMonths = $months; return $this; }

    public function setValues(?float $hba2, ?float $hbf, ?string $hbfDevice, ?float $hba, ?float $hbaCalc, bool $hbaManual, ?string $a0): self
    {
        $this->hba2Value      = $hba2    !== null ? number_format($hba2, 1, '.', '') : null;
        $this->hbfValue       = $hbf     !== null ? number_format($hbf, 1, '.', '') : null;
        $this->hbfDeviceValue = $hbfDevice;
        $this->hbaValue       = $hba     !== null ? number_format($hba, 1, '.', '') : null;
        $this->hbaCalculated  = $hbaCalc !== null ? number_format($hbaCalc, 1, '.', '') : null;
        $this->hbaManualFl    = $hbaManual ? 'Y' : 'N';
        $this->a0Value        = $a0;
        return $this;
    }

    public function getHba2Value(): ?string                 { return $this->hba2Value; }
    public function getHbfValue(): ?string                  { return $this->hbfValue; }
    public function getHbfDeviceValue(): ?string            { return $this->hbfDeviceValue; }
    public function getHbaValue(): ?string                  { return $this->hbaValue; }
    public function getHbaCalculated(): ?string             { return $this->hbaCalculated; }
    public function isHbaManual(): bool                     { return $this->hbaManualFl === 'Y'; }
    public function getA0Value(): ?string                   { return $this->a0Value; }

    public function getHbsHbdText(): string                 { return $this->hbsHbdText; }
    public function setHbsHbdText(string $v): self          { $this->hbsHbdText = $v; return $this; }

    public function getSuggestedBlocks(): ?string           { return $this->suggestedBlocks; }
    public function getSelectedBlocks(): ?string            { return $this->selectedBlocks; }
    public function setBlocks(?string $suggested, ?string $selected): self
    {
        $this->suggestedBlocks = $suggested;
        $this->selectedBlocks  = $selected;
        return $this;
    }

    public function getCommentText(): string                { return $this->commentText; }
    public function setCommentText(string $v): self         { $this->commentText = $v; return $this; }

    public function isCommentEdited(): bool                 { return $this->commentEditedFl === 'Y'; }
    public function setCommentEdited(bool $v): self         { $this->commentEditedFl = $v ? 'Y' : 'N'; return $this; }

    public function getExpertNote(): ?string                { return $this->expertNote; }
    public function setExpertNote(?string $v): self         { $this->expertNote = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getWarnings(): array                    { return $this->warningsJson ? (json_decode($this->warningsJson, true) ?: []) : []; }
    public function setWarnings(array $w): self             { $this->warningsJson = json_encode($w, JSON_UNESCAPED_UNICODE); return $this; }

    public function getApprovedBy(): ?AppUser               { return $this->approvedBy; }
    public function getApprovedAt(): \DateTimeImmutable     { return $this->approvedAt; }

    public function isCurrent(): bool                       { return $this->supersededAt === null; }
    public function getSupersededAt(): ?\DateTimeImmutable  { return $this->supersededAt; }
    public function supersede(): self                       { $this->supersededAt = new \DateTimeImmutable(); return $this; }
}
