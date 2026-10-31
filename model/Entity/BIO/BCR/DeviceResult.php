<?php
declare(strict_types=1);

namespace BIO\BCR;

use BIO\MNG\AppUser;
use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * D-10 cihazındaki bir çalışma (enjeksiyon). diaweb.cgi sonuç listesinin bir satırı.
 * Aynı numune birden fazla çalışılabilir; her çalışma ayrı satırdır.
 * Kabulde barkod bulunursa SAMPLE_ACCEPTANCE_ID bağlanır; bulunmazsa "kabulü olmayan sonuç" olarak kalır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'device_result')]
#[ORM\UniqueConstraint(name: 'UQ_device_result_INJECTION', columns: ['INJECTION_DATE', 'INJECTION_NO'])]
#[ORM\Index(name: 'IX_device_result_SAMPLE_ID', columns: ['SAMPLE_ID'])]
class DeviceResult
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $deviceResultId = null;           // DEVICE_RESULT_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: SampleAcceptance::class)]
    #[ORM\JoinColumn(name: 'SAMPLE_ACCEPTANCE_ID', referencedColumnName: 'SAMPLE_ACCEPTANCE_ID', nullable: true)]
    private ?SampleAcceptance $sampleAcceptance = null; // eşleşen kabul (yoksa boş)

    #[ORM\Column(length: 50)]
    private string $sampleId;                      // SAMPLE_ID (cihazdaki barkod)

    #[ORM\Column(length: 20)]
    private string $testCode;                      // TEST_CODE (ör. BTHAL)

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $injectionDate;     // INJECTION_DATE

    #[ORM\Column(type: 'smallint')]
    private int $injectionNo;                      // INJECTION_NO (günün enjeksiyon sırası, listedeki INJ #)

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $rackPosition = null;             // RACK_POSITION

    #[ORM\Column(length: 100)]
    private string $deviceKey;                     // DEVICE_KEY (cihaza rapor isteğinde gönderilen değer)

    // Değerler cihazdan geldiği gibi metin saklanır ("< 0.8" gibi)
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $fValue = null;                // F_VALUE

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $a1cValue = null;              // A1C_VALUE

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $a2Value = null;               // A2_VALUE

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $deviceComment = null;         // DEVICE_COMMENT ("Out of range" vb.)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $injectedAt = null; // INJECTED_AT (saat dahil; cihaz raporundan gelir)

    // Cihaz raporundan (page=pdf) gelen bilgiler. Rapor her çalışma için bir kez alınır.
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $method = null;                // METHOD (ör. HbA2/F)

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $totalArea = null;                // TOTAL_AREA

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reportFetchedAt = null; // REPORT_FETCHED_AT (boşsa rapor henüz alınmadı)

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $reportAttempts = 0;               // REPORT_ATTEMPTS (başarısız denemeler; sınırı aşınca otomatik denenmez)

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $reportError = null;           // REPORT_ERROR (son hata)

    // Kabulle eşleştirmenin izi: AUTO = barkod aynı, MANUAL = kullanıcı bağladı (ör. barkod yazım hatası)
    public const MATCH_AUTO   = 'AUTO';
    public const MATCH_MANUAL = 'MANUAL';

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $matchType = null;             // MATCH_TYPE

    #[ORM\Column(length: 200, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $matchNote = null;             // MATCH_NOTE (elle eşleştirmede gerekçe)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $matchedAt = null; // MATCHED_AT

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'MATCHED_BY', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $matchedBy = null;            // MATCHED_BY → mng_user

    public function __construct(
        Company $company,
        string $sampleId,
        string $testCode,
        \DateTimeImmutable $injectionDate,
        int $injectionNo,
        string $deviceKey,
    ) {
        $this->company       = $company;
        $this->sampleId      = $sampleId;
        $this->testCode      = $testCode;
        $this->injectionDate = $injectionDate;
        $this->injectionNo   = $injectionNo;
        $this->deviceKey     = $deviceKey;
    }

    public function getDeviceResultId(): ?int
    {
        return $this->deviceResultId !== null ? (int) $this->deviceResultId : null;
    }

    public function getCompany(): ?Company                    { return $this->company; }
    public function setCompany(Company $company): self        { $this->company = $company; return $this; }

    /**
     * Sonucu kabule bağlar ve izini yazar. Kabul "sonuç bekleniyor" / "tekrar çalışma" ise "onay bekliyor" olur.
     */
    public function linkTo(SampleAcceptance $sa, string $matchType, ?AppUser $by = null, ?string $note = null): self
    {
        $this->sampleAcceptance = $sa;
        $this->matchType        = $matchType;
        $this->matchNote        = $note;
        $this->matchedAt        = new \DateTimeImmutable();
        $this->matchedBy        = $by;

        if (in_array($sa->getStatus(), [SampleAcceptance::STATUS_WAITING, SampleAcceptance::STATUS_RERUN], true)) {
            $sa->setStatus(SampleAcceptance::STATUS_RESULTED);
        }
        return $this;
    }

    /* --- Cihaz raporu --- */

    public function setReportData(?\DateTimeImmutable $injectedAt, ?string $method, ?int $totalArea): self
    {
        $this->injectedAt      = $injectedAt;
        $this->method          = $method;
        $this->totalArea       = $totalArea;
        $this->reportFetchedAt = new \DateTimeImmutable();
        $this->reportError     = null;
        return $this;
    }

    public function markReportFailed(string $error): self
    {
        $this->reportAttempts++;
        $this->reportError = mb_substr($error, 0, 300);
        return $this;
    }

    public function isReportFetched(): bool                   { return $this->reportFetchedAt !== null; }
    public function getReportFetchedAt(): ?\DateTimeImmutable { return $this->reportFetchedAt; }
    public function getReportAttempts(): int                  { return $this->reportAttempts; }
    public function getReportError(): ?string                 { return $this->reportError; }
    public function getMethod(): ?string                      { return $this->method; }
    public function getTotalArea(): ?int                      { return $this->totalArea !== null ? (int) $this->totalArea : null; }

    public function getMatchType(): ?string                   { return $this->matchType; }
    public function getMatchNote(): ?string                   { return $this->matchNote; }
    public function getMatchedAt(): ?\DateTimeImmutable       { return $this->matchedAt; }

    public function getSampleAcceptance(): ?SampleAcceptance  { return $this->sampleAcceptance; }
    public function setSampleAcceptance(?SampleAcceptance $v): self { $this->sampleAcceptance = $v; return $this; }

    public function getSampleId(): string                     { return $this->sampleId; }
    public function getTestCode(): string                     { return $this->testCode; }
    public function getInjectionDate(): \DateTimeImmutable    { return $this->injectionDate; }
    public function getInjectionNo(): int                     { return $this->injectionNo; }
    public function getDeviceKey(): string                    { return $this->deviceKey; }

    public function getRackPosition(): ?int                   { return $this->rackPosition; }
    public function setRackPosition(?int $v): self            { $this->rackPosition = $v; return $this; }

    public function getFValue(): ?string                      { return $this->fValue; }
    public function getA1cValue(): ?string                    { return $this->a1cValue; }
    public function getA2Value(): ?string                     { return $this->a2Value; }
    public function getDeviceComment(): ?string               { return $this->deviceComment; }

    public function setValues(?string $f, ?string $a1c, ?string $a2, ?string $comment): self
    {
        $this->fValue        = $f;
        $this->a1cValue      = $a1c;
        $this->a2Value       = $a2;
        $this->deviceComment = $comment;
        return $this;
    }

    public function getInjectedAt(): ?\DateTimeImmutable      { return $this->injectedAt; }
    public function setInjectedAt(?\DateTimeImmutable $v): self { $this->injectedAt = $v; return $this; }
}
