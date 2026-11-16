<?php
declare(strict_types=1);

namespace BIO\SHT;

use BIO\MNG\AppUser;
use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Saha görevi: bir firmaya (sht_customer) yapılacak ziyaret. Yönetici web'den açar ve personele atar,
 * personel mobil uygulamadan adım adım ilerletir:
 *
 *   NEW (atanmadı) → ASSIGNED ──Yola çıktım──► ON_THE_WAY ──Vardım──► ON_SITE ──Tamamladım──► DONE
 *   (her aşamada yönetici CANCELLED yapabilir; DONE / CANCELLED son durumdur)
 *
 * Her adımın telefondaki saati ve konumu saklanır. Telefon adımları internet yokken kuyrukta biriktirip
 * sonra gönderebilir; bu yüzden zamanlar olayın portala ulaştığı an değil, telefondaki andır.
 * ARRIVE_DISTANCE_M: "Vardım"daki konumun firmaya uzaklığı (firma konumu girilmişse). Web'de uyarı için.
 * Bir görev = bir ziyaret (ayrı sht_visit tablosu gerekirse sonra ayrılır).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sht_task')]
#[ORM\Index(name: 'IX_sht_task_PERSONEL_STATUS', columns: ['PERSONEL_ID', 'STATUS'])]
#[ORM\Index(name: 'IX_sht_task_COMPANY_PLANNED', columns: ['COMPANY_ID', 'PLANNED_AT'])]
class Task
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const NEW        = 'NEW';
    public const ASSIGNED   = 'ASSIGNED';
    public const ON_THE_WAY = 'ON_THE_WAY';
    public const ON_SITE    = 'ON_SITE';
    public const DONE       = 'DONE';
    public const CANCELLED  = 'CANCELLED';

    /** Personelin ilerletebildiği sıra (mobil) */
    public const FLOW = [self::ASSIGNED, self::ON_THE_WAY, self::ON_SITE, self::DONE];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $taskId = null;                   // TASK_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'CUSTOMER_ID', referencedColumnName: 'CUSTOMER_ID', nullable: false)]
    private Customer $customer;                    // CUSTOMER_ID → sht_customer

    #[ORM\ManyToOne(targetEntity: Personel::class)]
    #[ORM\JoinColumn(name: 'PERSONEL_ID', referencedColumnName: 'PERSONEL_ID', nullable: true)]
    private ?Personel $personel = null;            // PERSONEL_ID → sht_personel (boş = atanmadı)

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $title;                         // TITLE (ör. "Periyodik ölçüm", "İşyeri hekimi ziyareti")

    #[ORM\Column(length: 1000, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $description = null;           // DESCRIPTION

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $plannedAt = null; // PLANNED_AT (planlanan gün + BAŞLANGIÇ saati)

    /**
     * Firmada kalınacak aralığın sonu (aynı gün). Kural (bitiş saati olan görevlerde; eski görevlerde boş → kural yok):
     *   "Vardım"    PLANNED_AT'ten önce basılamaz  (Yola çıktım serbest: vaktinde varabilsin)
     *   "Tamamladım" PLANNED_END_AT'ten önce basılamaz; yalnız gerekçe yazılarak "Erken bitir" (DONE_EARLY, EARLY_REASON)
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $plannedEndAt = null;  // PLANNED_END_AT

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $doneEarly = 'N';                   // DONE_EARLY (Y: bitiş saatinden önce, gerekçeyle bitirildi)

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $earlyReason = null;               // EARLY_REASON (erken bitirme gerekçesi, zorunlu)

    #[ORM\Column(length: 15, options: ['default' => 'NEW'])]
    private string $status = self::NEW;            // STATUS

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;    // ASSIGNED_AT

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $onTheWayAt = null;    // ON_THE_WAY_AT (Yola çıktım)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $arrivedAt = null;     // ARRIVED_AT (Vardım)

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $arriveLat = null;             // ARRIVE_LAT

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $arriveLon = null;             // ARRIVE_LON

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $arriveDistanceM = null;          // ARRIVE_DISTANCE_M (firmaya uzaklık, metre)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;    // DONE_AT (Tamamladım)

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $doneLat = null;               // DONE_LAT

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $doneLon = null;               // DONE_LON

    #[ORM\Column(length: 1000, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $doneNote = null;              // DONE_NOTE (personelin notu)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;   // CANCELLED_AT

    /** İptal eden portal kullanıcısı (U_BY sonraki değişikliklerde ezilir; bu alan kalır). Ayrıntı: sht_task_log */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'CANCELLED_BY', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $cancelledBy = null;              // CANCELLED_BY → mng_user

    // --- Yol rotası (Yolda iken; Biolab\Core\Sht\RouteClient / OSRM) ---
    // Personelin konumundan firmaya sürüş rotası. Rotadan 300 m'den fazla saparsa yeniden hesaplanır.

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $routePolyline = null;             // ROUTE_POLYLINE (encoded polyline, hassasiyet 5)

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $routeDistanceM = null;               // ROUTE_DISTANCE_M (rota uzunluğu)

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $routeDurationS = null;               // ROUTE_DURATION_S (tahmini sürüş süresi, saniye)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $routeAt = null;       // ROUTE_AT (son hesaplama)

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $routeCount = 0;                       // ROUTE_COUNT (kaç kez hesaplandı; 1'den fazlası = rotadan sapma)

    public function __construct(Customer $customer, string $title)
    {
        $this->customer = $customer;
        $this->title    = $title;
    }

    /** Personeli atar / değiştirir / kaldırır. Yalnız henüz yola çıkılmamış görevde. */
    public function assign(?Personel $p, \DateTimeImmutable $now): self
    {
        $this->personel = $p;
        if (in_array($this->status, [self::NEW, self::ASSIGNED], true)) {
            $this->status     = $p === null ? self::NEW : self::ASSIGNED;
            $this->assignedAt = $p === null ? null : $now;
        }
        return $this;
    }

    public function isEditable(): bool { return in_array($this->status, [self::NEW, self::ASSIGNED], true); }
    public function isFinished(): bool { return in_array($this->status, [self::DONE, self::CANCELLED], true); }

    /** Sıradaki adım mı / zaten geçilmiş mi (telefon aynı adımı tekrar gönderebilir) */
    public static function flowIndex(string $status): int
    {
        $i = array_search($status, self::FLOW, true);
        return $i === false ? -1 : $i;
    }

    public function markOnTheWay(\DateTimeImmutable $at): self
    {
        $this->status = self::ON_THE_WAY;
        $this->onTheWayAt = $at;
        return $this;
    }

    public function markArrived(\DateTimeImmutable $at, ?float $lat, ?float $lon, ?int $distanceM): self
    {
        $this->status = self::ON_SITE;
        $this->onTheWayAt ??= $at;   // "Yola çıktım" atlandıysa
        $this->arrivedAt = $at;
        $this->arriveLat = $lat !== null ? number_format($lat, 6, '.', '') : null;
        $this->arriveLon = $lon !== null ? number_format($lon, 6, '.', '') : null;
        $this->arriveDistanceM = $distanceM;
        return $this;
    }

    /** Bitiş saatinden önce tamamlanıyorsa erken bitirme gerekçesi (yoksa null) */
    public function markDone(\DateTimeImmutable $at, ?float $lat, ?float $lon, ?string $note, ?string $earlyReason = null): self
    {
        $this->doneEarly   = $earlyReason !== null ? 'Y' : 'N';
        $this->earlyReason = $earlyReason !== null ? mb_substr($earlyReason, 0, 500) : null;
        $this->status = self::DONE;
        $this->arrivedAt ??= $at;
        $this->doneAt = $at < ($this->arrivedAt ?? $at) ? $this->arrivedAt : $at;
        $this->doneLat = $lat !== null ? number_format($lat, 6, '.', '') : null;
        $this->doneLon = $lon !== null ? number_format($lon, 6, '.', '') : null;
        $this->doneNote = $note !== null && $note !== '' ? mb_substr($note, 0, 1000) : null;
        return $this;
    }

    public function cancel(\DateTimeImmutable $now, ?AppUser $by): self
    {
        $this->status = self::CANCELLED;
        $this->cancelledAt = $now;
        $this->cancelledBy = $by;
        return $this;
    }

    public function getTaskId(): ?int                     { return $this->taskId; }
    public function getCompany(): ?Company                { return $this->company; }
    public function setCompany(Company $company): self    { $this->company = $company; return $this; }
    public function getCustomer(): Customer               { return $this->customer; }
    public function setCustomer(Customer $c): self        { $this->customer = $c; return $this; }
    public function getPersonel(): ?Personel              { return $this->personel; }
    public function getTitle(): string                    { return $this->title; }
    public function setTitle(string $v): self             { $this->title = $v; return $this; }
    public function getDescription(): ?string             { return $this->description; }
    public function setDescription(?string $v): self      { $this->description = $v; return $this; }
    public function getPlannedAt(): ?\DateTimeImmutable   { return $this->plannedAt; }
    public function setPlannedAt(?\DateTimeImmutable $v): self { $this->plannedAt = $v; return $this; }
    public function getPlannedEndAt(): ?\DateTimeImmutable { return $this->plannedEndAt; }
    public function setPlannedEndAt(?\DateTimeImmutable $v): self { $this->plannedEndAt = $v; return $this; }
    public function isDoneEarly(): bool                   { return $this->doneEarly === 'Y'; }
    public function getEarlyReason(): ?string             { return $this->earlyReason; }

    /** Saat aralığı kuralı uygulanır mı (eski, bitiş saati olmayan görevlerde hayır) */
    public function hasTimeWindow(): bool                 { return $this->plannedAt !== null && $this->plannedEndAt !== null; }

    public function getStatus(): string                   { return $this->status; }
    public function getAssignedAt(): ?\DateTimeImmutable  { return $this->assignedAt; }
    public function getOnTheWayAt(): ?\DateTimeImmutable  { return $this->onTheWayAt; }
    public function getArrivedAt(): ?\DateTimeImmutable   { return $this->arrivedAt; }
    public function getArriveDistanceM(): ?int            { return $this->arriveDistanceM; }
    public function getDoneAt(): ?\DateTimeImmutable      { return $this->doneAt; }
    public function getDoneNote(): ?string                { return $this->doneNote; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function getCancelledBy(): ?AppUser            { return $this->cancelledBy; }

    /** @param array{polyline: string, distanceM: int, durationS: int} $route */
    public function setRoute(array $route, \DateTimeImmutable $now): self
    {
        $this->routePolyline  = $route['polyline'];
        $this->routeDistanceM = $route['distanceM'];
        $this->routeDurationS = $route['durationS'];
        $this->routeAt        = $now;
        $this->routeCount++;
        return $this;
    }

    public function getRoutePolyline(): ?string        { return $this->routePolyline; }
    public function getRouteDistanceM(): ?int          { return $this->routeDistanceM; }
    public function getRouteDurationS(): ?int          { return $this->routeDurationS; }
    public function getRouteAt(): ?\DateTimeImmutable  { return $this->routeAt; }
    public function getRouteCount(): int               { return $this->routeCount; }
}
