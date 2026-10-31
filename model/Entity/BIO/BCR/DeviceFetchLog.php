<?php
declare(strict_types=1);

namespace BIO\BCR;

use Doctrine\ORM\Mapping as ORM;

/**
 * Cihazdan her çekme denemesinin kaydı.
 * - Ekrandaki "D-10 bağlantısı · son çekme" şeridi buradan beslenir.
 * - Aynı anda iki ekranın çekme yapmasını önlemek için son kayda bakılır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'device_fetch_log')]
class DeviceFetchLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $deviceFetchLogId = null;         // DEVICE_FETCH_LOG_ID

    #[ORM\Column(length: 20)]
    private string $testCode;                      // TEST_CODE

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFrom;          // DATE_FROM

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateTo;            // DATE_TO

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;         // STARTED_AT

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null; // FINISHED_AT (boşsa çekme sürüyor)

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $successFl = 'N';               // SUCCESS_FL

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $listCount = 0;                    // LIST_COUNT (cihaz listesindeki satır)

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $newCount = 0;                     // NEW_COUNT (yeni eklenen çalışma)

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $message = null;               // MESSAGE (hata mesajı)

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $userId = null;                   // USER_ID (çekmeyi başlatan; otomatikte de ekranı açık olan)

    public function __construct(string $testCode, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, ?int $userId)
    {
        $this->testCode  = $testCode;
        $this->dateFrom  = $dateFrom;
        $this->dateTo    = $dateTo;
        $this->userId    = $userId;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function finish(bool $success, int $listCount, int $newCount, ?string $message = null): self
    {
        $this->finishedAt = new \DateTimeImmutable();
        $this->successFl  = $success ? 'Y' : 'N';
        $this->listCount  = $listCount;
        $this->newCount   = $newCount;
        $this->message    = $message !== null ? mb_substr($message, 0, 500) : null;
        return $this;
    }

    public function getDeviceFetchLogId(): ?int        { return $this->deviceFetchLogId !== null ? (int) $this->deviceFetchLogId : null; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function isSuccess(): bool                  { return $this->successFl === 'Y'; }
    public function getNewCount(): int                 { return $this->newCount; }
    public function getMessage(): ?string              { return $this->message; }
}
