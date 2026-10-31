<?php
declare(strict_types=1);

namespace BIO\BCR;

use Doctrine\ORM\Mapping as ORM;

/**
 * Cihaz raporundaki pik tablosunun bir satırı (A1a, A1b, F, LA1c/CHb-1, A1c, P3, A0, A2, Unknown…).
 * Değerler raporda yazdığı gibi saklanır; "Area %" metin ("< 0.8 *" olabilir).
 */
#[ORM\Entity]
#[ORM\Table(name: 'device_result_peak')]
#[ORM\Index(name: 'IX_device_result_peak_RESULT', columns: ['DEVICE_RESULT_ID'])]
class DeviceResultPeak
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $deviceResultPeakId = null;       // DEVICE_RESULT_PEAK_ID

    #[ORM\ManyToOne(targetEntity: DeviceResult::class)]
    #[ORM\JoinColumn(name: 'DEVICE_RESULT_ID', referencedColumnName: 'DEVICE_RESULT_ID', nullable: false, onDelete: 'CASCADE')]
    private DeviceResult $deviceResult;            // DEVICE_RESULT_ID → device_result

    #[ORM\Column(type: 'smallint')]
    private int $sortNo;                           // SORT_NO (rapordaki sıra)

    #[ORM\Column(length: 50)]
    private string $peakName;                      // PEAK_NAME

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $retentionTime = null;         // RETENTION_TIME (dk, ör. "3.17")

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $height = null;                   // HEIGHT

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $area = null;                     // AREA

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $areaPct = null;               // AREA_PCT

    public function __construct(DeviceResult $deviceResult, int $sortNo, string $peakName,
                                ?string $retentionTime, ?int $height, ?int $area, ?string $areaPct)
    {
        $this->deviceResult  = $deviceResult;
        $this->sortNo        = $sortNo;
        $this->peakName      = $peakName;
        $this->retentionTime = $retentionTime;
        $this->height        = $height;
        $this->area          = $area;
        $this->areaPct       = $areaPct;
    }

    public function getDeviceResult(): DeviceResult { return $this->deviceResult; }
    public function getSortNo(): int                 { return $this->sortNo; }
    public function getPeakName(): string            { return $this->peakName; }
    public function getRetentionTime(): ?string      { return $this->retentionTime; }
    public function getHeight(): ?int                { return $this->height !== null ? (int) $this->height : null; }
    public function getArea(): ?int                  { return $this->area !== null ? (int) $this->area : null; }
    public function getAreaPct(): ?string            { return $this->areaPct; }
}
