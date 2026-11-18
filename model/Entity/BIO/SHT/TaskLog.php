<?php
declare(strict_types=1);

namespace BIO\SHT;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Görev geçmişi: görevde olan HER işlem bir satır (yalnız eklenir, değiştirilmez / silinmez).
 * Kim → C_BY (işlemi yapan portal kullanıcısı; mobil adımlarda personelin kendi kullanıcısı)
 * Ne zaman → EVENT_AT (olayın zamanı; mobilde telefondaki an) ve C_AT (portala yazıldığı an)
 * Ne → ACTION, durum STATUS_FROM → STATUS_TO, kişi PERSONEL_FROM → PERSONEL_TO
 * Nereden → SOURCE: WEB_MAP (Harita), WEB_TASKS (Görevler ekranı), MOBILE (telefon)
 *
 * Kayıt Biolab\Core\Sht\TaskHistory::record() ile, görevi değiştiren işlemle aynı flush içinde yazılır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sht_task_log')]
#[ORM\Index(name: 'IX_sht_task_log_TASK', columns: ['TASK_ID', 'EVENT_AT'])]
#[ORM\Index(name: 'IX_sht_task_log_COMPANY_ACTION', columns: ['COMPANY_ID', 'ACTION', 'EVENT_AT'])]
class TaskLog
{
    use AuditTrait;   // C_BY (kim), C_AT (yazıldığı an), …

    public const CREATED    = 'CREATED';      // görev açıldı (atandıysa PERSONEL_TO dolu)
    public const ASSIGNED   = 'ASSIGNED';     // atanmamış göreve kişi atandı
    public const REASSIGNED = 'REASSIGNED';   // kişi değişti (PERSONEL_FROM → PERSONEL_TO)
    public const UNASSIGNED = 'UNASSIGNED';   // kişi kaldırıldı
    public const UPDATED    = 'UPDATED';      // görev adı / gün / firma / açıklama değişti (NOTE: hangileri)
    public const ON_THE_WAY = 'ON_THE_WAY';   // telefondan "Yola çıktım"
    public const WAY_UNDONE = 'WAY_UNDONE';   // telefondan "Yola çıktım" geri alındı (Yolda → Atandı)
    public const ON_SITE    = 'ON_SITE';      // telefondan "Vardım"
    public const DONE       = 'DONE';         // telefondan "Tamamladım" (NOTE: personelin notu)
    public const CANCELLED  = 'CANCELLED';    // iptal edildi

    public const SRC_MAP    = 'WEB_MAP';
    public const SRC_TASKS  = 'WEB_TASKS';
    public const SRC_MOBILE = 'MOBILE';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $logId = null;                     // LOG_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;               // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'TASK_ID', referencedColumnName: 'TASK_ID', nullable: false)]
    private Task $task;                             // TASK_ID → sht_task

    #[ORM\Column(length: 20)]
    private string $action;                         // ACTION

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $statusFrom = null;             // STATUS_FROM (önceki durum; yeni görevde boş)

    #[ORM\Column(length: 15)]
    private string $statusTo;                       // STATUS_TO (işlemden sonraki durum)

    #[ORM\ManyToOne(targetEntity: Personel::class)]
    #[ORM\JoinColumn(name: 'PERSONEL_FROM_ID', referencedColumnName: 'PERSONEL_ID', nullable: true)]
    private ?Personel $personelFrom = null;         // PERSONEL_FROM_ID (önceki atanan)

    #[ORM\ManyToOne(targetEntity: Personel::class)]
    #[ORM\JoinColumn(name: 'PERSONEL_TO_ID', referencedColumnName: 'PERSONEL_ID', nullable: true)]
    private ?Personel $personelTo = null;           // PERSONEL_TO_ID (işlemden sonraki atanan)

    #[ORM\Column(length: 10)]
    private string $source;                         // SOURCE

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $eventAt;            // EVENT_AT

    #[ORM\Column(length: 1000, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                   // NOTE

    public function __construct(Task $task, string $action, string $source, ?string $statusFrom,
                                ?Personel $personelFrom, \DateTimeImmutable $eventAt, ?string $note = null)
    {
        $this->task         = $task;
        $this->company      = $task->getCompany();
        $this->action       = $action;
        $this->source       = $source;
        $this->statusFrom   = $statusFrom;
        $this->statusTo     = $task->getStatus();
        $this->personelFrom = $personelFrom;
        $this->personelTo   = $task->getPersonel();
        $this->eventAt      = $eventAt;
        $this->note         = $note !== null && $note !== '' ? mb_substr($note, 0, 1000) : null;
    }

    public function getLogId(): ?int                     { return $this->logId; }
    public function getCompany(): ?Company               { return $this->company; }
    public function setCompany(Company $company): self   { $this->company = $company; return $this; }
    public function getTask(): Task                      { return $this->task; }
    public function getAction(): string                  { return $this->action; }
    public function getStatusFrom(): ?string             { return $this->statusFrom; }
    public function getStatusTo(): string                { return $this->statusTo; }
    public function getPersonelFrom(): ?Personel         { return $this->personelFrom; }
    public function getPersonelTo(): ?Personel           { return $this->personelTo; }
    public function getSource(): string                  { return $this->source; }
    public function getEventAt(): \DateTimeImmutable     { return $this->eventAt; }
    public function getNote(): ?string                   { return $this->note; }
}
