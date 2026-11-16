<?php
declare(strict_types=1);

namespace BIO\SHT;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Saha personelinin mesaisi. Mobil uygulamada "Mesaiye Başla" / "Mesaiyi Bitir" ile oluşur (api/saha.php).
 *
 * CLIENT_UUID: telefonda mesai başlarken üretilir. Telefon internet yokken olayları kuyrukta biriktirip
 * sonra gönderdiği için aynı olay iki kez gelebilir; bu anahtar sayesinde çift kayıt oluşmaz.
 * STARTED_AT / ENDED_AT: telefondaki GERÇEK saat (olayın portala ulaştığı saat değil).
 * ENDED_AT boşsa mesai açıktır. Bir personelin aynı anda tek açık mesaisi olur:
 * yeni mesai gelirken açık kalmış eski mesai, yeninin başlangıç saatiyle kapatılır (END_REASON = REPLACED).
 * END_REASON: MANUAL (personel bitirdi), REPLACED (yeni mesai başladı, eskisi kapanmamıştı), AUTO (kural ile, ileride).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sht_shift')]
#[ORM\Index(name: 'IX_sht_shift_PERSONEL_START', columns: ['PERSONEL_ID', 'STARTED_AT'])]
#[ORM\Index(name: 'IX_sht_shift_COMPANY_START', columns: ['COMPANY_ID', 'STARTED_AT'])]
class Shift
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const END_MANUAL   = 'MANUAL';
    public const END_REPLACED = 'REPLACED';
    public const END_AUTO     = 'AUTO';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $shiftId = null;                  // SHIFT_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: Personel::class)]
    #[ORM\JoinColumn(name: 'PERSONEL_ID', referencedColumnName: 'PERSONEL_ID', nullable: false)]
    private Personel $personel;                    // PERSONEL_ID → sht_personel

    #[ORM\Column(length: 36, unique: true, options: ['fixed' => true])]
    private string $clientUuid;                    // CLIENT_UUID

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;         // STARTED_AT

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $startLat = null;              // START_LAT

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $startLon = null;              // START_LON

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;   // ENDED_AT (boş = mesai açık)

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $endLat = null;                // END_LAT

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $endLon = null;                // END_LON

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $endReason = null;             // END_REASON

    public function __construct(Personel $personel, Company $company, string $clientUuid, \DateTimeImmutable $startedAt)
    {
        $this->personel   = $personel;
        $this->company    = $company;
        $this->clientUuid = $clientUuid;
        $this->startedAt  = $startedAt;
    }

    public function isOpen(): bool { return $this->endedAt === null; }

    public function setStartLocation(?float $lat, ?float $lon): self
    {
        $this->startLat = $lat !== null ? number_format($lat, 6, '.', '') : null;
        $this->startLon = $lon !== null ? number_format($lon, 6, '.', '') : null;
        return $this;
    }

    /** Mesaiyi kapatır. Bitiş başlangıçtan önce olamaz (telefon saati bozuksa başlangıca çekilir). */
    public function close(\DateTimeImmutable $endedAt, string $reason, ?float $lat = null, ?float $lon = null): self
    {
        $this->endedAt   = $endedAt < $this->startedAt ? $this->startedAt : $endedAt;
        $this->endReason = $reason;
        $this->endLat    = $lat !== null ? number_format($lat, 6, '.', '') : null;
        $this->endLon    = $lon !== null ? number_format($lon, 6, '.', '') : null;
        return $this;
    }

    public function getShiftId(): ?int                  { return $this->shiftId; }
    public function getCompany(): ?Company              { return $this->company; }
    public function setCompany(Company $company): self  { $this->company = $company; return $this; }
    public function getPersonel(): Personel             { return $this->personel; }
    public function getClientUuid(): string             { return $this->clientUuid; }
    public function getStartedAt(): \DateTimeImmutable  { return $this->startedAt; }
    public function getEndedAt(): ?\DateTimeImmutable   { return $this->endedAt; }
    public function getEndReason(): ?string             { return $this->endReason; }
    public function getStartLat(): ?string              { return $this->startLat; }
    public function getStartLon(): ?string              { return $this->startLon; }
    public function getEndLat(): ?string                { return $this->endLat; }
    public function getEndLon(): ?string                { return $this->endLon; }
}
