<?php
declare(strict_types=1);

namespace BIO\BCR;

use BIO\MNG\AppUser;
use BIO\MNG\Company;
use BIO\MNG\Gender;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Numune kabul kaydı. Barkod (D-10'daki Sample ID) cihaz sonucuyla eşleşmenin anahtarıdır.
 * Kabul zamanı ACCEPTED_AT'tir (iş tarihi); C_AT sadece kaydın veritabanına yazıldığı anı tutar.
 * Şirket boş bırakılırsa AuditListener oturumdaki COMPANY_ID'yi yazar.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sample_acceptance')]
#[ORM\Index(name: 'IX_sample_acceptance_COMPANY_ACCEPTED_AT', columns: ['COMPANY_ID', 'ACCEPTED_AT'])]
class SampleAcceptance
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    // Numunenin durumu (STATUS)
    public const STATUS_WAITING  = 'WAITING';    // Sonuç bekleniyor
    public const STATUS_RESULTED = 'RESULTED';   // Sonuç geldi, onay bekliyor
    public const STATUS_RERUN    = 'RERUN';      // Tekrar çalışma istendi
    public const STATUS_APPROVED = 'APPROVED';   // Uzman onayladı
    public const STATUS_REPORTED = 'REPORTED';   // Rapor üretildi
    public const STATUS_REJECTED = 'REJECTED';   // Numune reddedildi / iptal

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $sampleAcceptanceId = null;      // SAMPLE_ACCEPTANCE_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;             // COMPANY_ID → mng_company

    #[ORM\Column(length: 50, unique: true)]
    private string $barcode;                      // BARCODE (tek benzersiz alan)

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $barcodeManualFl = 'N';        // BARCODE_MANUAL_FL: Y = elle girildi

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $patientNameSurname;           // PATIENT_NAME_SURNAME

    #[ORM\Column(length: 50)]
    private string $fileNo;                       // FILE_NO

    #[ORM\ManyToOne(targetEntity: Gender::class)]
    #[ORM\JoinColumn(name: 'GENDER_ID', referencedColumnName: 'GENDER_ID', nullable: false)]
    private Gender $gender;                       // GENDER_ID → mng_gender

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $birthDate;        // BIRTH_DATE

    #[ORM\Column(length: 150, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $institution = null;          // INSTITUTION (kurum / bölüm)

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_WAITING])]
    private string $status = self::STATUS_WAITING; // STATUS

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $acceptedAt;       // ACCEPTED_AT (numunenin kabul edildiği an)

    // Numune reddi (hemolizli, pıhtılı, yetersiz…): kim, ne zaman, neden
    #[ORM\Column(length: 200, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $rejectReason = null;         // REJECT_REASON

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rejectedAt = null; // REJECTED_AT

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'REJECTED_BY', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $rejectedBy = null;          // REJECTED_BY → mng_user

    // Uzmanın tekrar çalışma isteği: son istek (yeni çalışma gelince durum yeniden "onay bekliyor" olur)
    #[ORM\Column(length: 200, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $rerunReason = null;          // RERUN_REASON

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rerunAt = null;  // RERUN_AT

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'RERUN_BY', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $rerunBy = null;             // RERUN_BY → mng_user

    public function __construct(
        string $barcode,
        string $patientNameSurname,
        string $fileNo,
        Gender $gender,
        \DateTimeImmutable $birthDate,
        bool $barcodeManual = false,
        ?\DateTimeImmutable $acceptedAt = null,   // verilmezse şimdi
    ) {
        $this->acceptedAt         = $acceptedAt ?? new \DateTimeImmutable();
        $this->barcode            = $barcode;
        $this->patientNameSurname = $patientNameSurname;
        $this->fileNo             = $fileNo;
        $this->gender             = $gender;
        $this->birthDate          = $birthDate;
        $this->barcodeManualFl    = $barcodeManual ? 'Y' : 'N';
    }

    public function getSampleAcceptanceId(): ?int
    {
        return $this->sampleAcceptanceId !== null ? (int) $this->sampleAcceptanceId : null;
    }

    // AuditListener yeni kayıtta şirket boşsa oturumdaki COMPANY_ID'yi buradan yazar
    public function getCompany(): ?Company                 { return $this->company; }
    public function setCompany(Company $company): self     { $this->company = $company; return $this; }

    public function getBarcode(): string                   { return $this->barcode; }
    public function isBarcodeManual(): bool                { return $this->barcodeManualFl === 'Y'; }

    public function getPatientNameSurname(): string        { return $this->patientNameSurname; }
    public function setPatientNameSurname(string $v): self { $this->patientNameSurname = $v; return $this; }

    public function getFileNo(): string                    { return $this->fileNo; }
    public function setFileNo(string $v): self             { $this->fileNo = $v; return $this; }

    public function getGender(): Gender                    { return $this->gender; }
    public function setGender(Gender $v): self             { $this->gender = $v; return $this; }

    public function getBirthDate(): \DateTimeImmutable     { return $this->birthDate; }
    public function setBirthDate(\DateTimeImmutable $v): self { $this->birthDate = $v; return $this; }

    public function getInstitution(): ?string              { return $this->institution; }
    public function setInstitution(?string $v): self       { $this->institution = $v; return $this; }

    public function getStatus(): string                    { return $this->status; }
    public function setStatus(string $status): self        { $this->status = $status; return $this; }

    // Kabul zamanı (ACCEPTED_AT). Sonradan girilen kayıtta gerçek kabul saati setAcceptedAt ile verilir.
    public function getAcceptedAt(): \DateTimeImmutable    { return $this->acceptedAt; }
    public function setAcceptedAt(\DateTimeImmutable $v): self { $this->acceptedAt = $v; return $this; }

    // Reddedilebilir mi? Onaylanmış/raporlanmış numune reddedilmez (o aşamada revizyon yapılır)
    public function canReject(): bool
    {
        return in_array($this->status, [self::STATUS_WAITING, self::STATUS_RESULTED, self::STATUS_RERUN], true);
    }

    public function reject(string $reason, ?AppUser $by): self
    {
        $this->status       = self::STATUS_REJECTED;
        $this->rejectReason = $reason;
        $this->rejectedAt   = new \DateTimeImmutable();
        $this->rejectedBy   = $by;
        return $this;
    }

    public function getRejectReason(): ?string             { return $this->rejectReason; }
    public function getRejectedAt(): ?\DateTimeImmutable   { return $this->rejectedAt; }
    public function getRejectedBy(): ?AppUser              { return $this->rejectedBy; }

    /* --- Uzman onayı --- */

    // İlk onay: sonucu gelmiş (ya da tekrar çalışması istenmiş) numune onaylanır
    public function canApprove(): bool
    {
        return in_array($this->status, [self::STATUS_RESULTED, self::STATUS_RERUN], true);
    }

    // Onaylı/raporlu numunede düzeltme yeni revizyon olarak yapılır
    public function canRevise(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REPORTED], true);
    }

    public function approve(): self
    {
        if (!$this->canApprove()) {
            throw new \DomainException('Bu numune onaylanamaz (durum: ' . $this->status . ').');
        }
        $this->status = self::STATUS_APPROVED;
        return $this;
    }

    // Onaylanan raporun PDF'i üretilip arşivlendi
    public function markReported(): self
    {
        if (!in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REPORTED], true)) {
            throw new \DomainException('Onaylanmamış numune raporlanamaz (durum: ' . $this->status . ').');
        }
        $this->status = self::STATUS_REPORTED;
        return $this;
    }

    public function canRequestRerun(): bool
    {
        return in_array($this->status, [self::STATUS_RESULTED, self::STATUS_RERUN], true);
    }

    public function requestRerun(string $reason, ?AppUser $by): self
    {
        if (!$this->canRequestRerun()) {
            throw new \DomainException('Bu numune için tekrar çalışma istenemez (durum: ' . $this->status . ').');
        }
        $this->status      = self::STATUS_RERUN;
        $this->rerunReason = $reason;
        $this->rerunAt     = new \DateTimeImmutable();
        $this->rerunBy     = $by;
        return $this;
    }

    public function getRerunReason(): ?string              { return $this->rerunReason; }
    public function getRerunAt(): ?\DateTimeImmutable      { return $this->rerunAt; }

    // Kabul tarihindeki yaş (ay olarak); algoritma grubu bundan hesaplanır
    public function getAgeInMonths(?\DateTimeImmutable $at = null): int
    {
        $diff = $this->birthDate->diff($at ?? $this->acceptedAt);
        return $diff->y * 12 + $diff->m;
    }

    // Kabul tarihindeki yaş (gün); ilk ayın HbF aralığı (0–30 gün) için
    public function getAgeInDays(?\DateTimeImmutable $at = null): int
    {
        return (int) $this->birthDate->diff($at ?? $this->acceptedAt)->days;
    }
}
