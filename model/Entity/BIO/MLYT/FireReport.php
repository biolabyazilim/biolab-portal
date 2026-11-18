<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;

/**
 * Fire bildirim formundan (şube formu ya da PCR formu) okunan bir satır: son kullanma, soğuk zincir, açık kit stabilitesi,
 * kırılma, kit kusuru; PCR'da kontaminasyon, geçersiz run, ekstraksiyon hatası… Dosya birim başına yüklenir (PeriodFile
 * UNIT_NAME: "131 ALSANCAK ŞUBE", "PCR"). "Bildiren" kolonu (kişi adı) okunmaz.
 * Yükleme satırıdır, değiştirilmez (dosya yeniden yüklenince silinir): denetim kolonu yok.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_fire_report')]
#[ORM\Index(name: 'IX_mlyt_fire_report_file', columns: ['FILE_ID'])]
class FireReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $fireId = null;                   // FIRE_ID

    #[ORM\ManyToOne(targetEntity: PeriodFile::class)]
    #[ORM\JoinColumn(name: 'FILE_ID', referencedColumnName: 'FILE_ID', nullable: false)]
    private PeriodFile $file;                      // FILE_ID → mlyt_period_file

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $eventDate;         // EVENT_DATE (firenin olduğu gün)

    #[ORM\Column(length: 60, options: ['collation' => 'Turkish_CI_AS'])]
    private string $unitName;                      // UNIT_NAME (satırdaki şube; PCR formunda dosyanın birimi)

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $depotCode = null;                // DEPOT_CODE (Netsis depo kodu: "131 ALSANCAK ŞUBE" → 131)

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $stockCode = null;             // STOCK_CODE (Netsis stok kodu; yoksa null)

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $materialText;                  // MATERIAL_TEXT (formdaki malzeme / kit adı)

    #[ORM\Column(type: 'decimal', precision: 14, scale: 3)]
    private string $quantity;                      // QUANTITY

    #[ORM\Column(length: 10, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $measure = null;               // MEASURE (Adet, Kutu, Test, Kit, ml)

    #[ORM\Column(length: 60, options: ['collation' => 'Turkish_CI_AS'])]
    private string $reason;                        // REASON (formdaki sebep listesi)

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $runNo = null;                 // RUN_NO (PCR: run / plaka no)

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $affectedTests = null;            // AFFECTED_TESTS (PCR: boşa giden hasta testi / kuyucuk)

    #[ORM\Column(length: 1, nullable: true, options: ['fixed' => true])]
    private ?string $retestedFl = null;            // RETESTED_FL (PCR: testler tekrar çalışıldı mı; Y / N)

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $lotNo = null;                 // LOT_NO (barkod / lot no)

    #[ORM\Column(length: 300, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                  // NOTE (açıklama)

    public function __construct(PeriodFile $file, \DateTimeImmutable $eventDate, string $unitName, ?int $depotCode, ?string $stockCode,
                                string $materialText, string $quantity, ?string $measure, string $reason, ?string $runNo, ?int $affectedTests,
                                ?bool $retested, ?string $lotNo, ?string $note)
    {
        $this->file          = $file;
        $this->eventDate     = $eventDate;
        $this->unitName      = mb_substr($unitName, 0, 60);
        $this->depotCode     = $depotCode;
        $this->stockCode     = $stockCode === null ? null : mb_substr($stockCode, 0, 30);
        $this->materialText  = mb_substr($materialText, 0, 200);
        $this->quantity      = $quantity;
        $this->measure       = $measure === null ? null : mb_substr($measure, 0, 10);
        $this->reason        = mb_substr($reason, 0, 60);
        $this->runNo         = $runNo === null ? null : mb_substr($runNo, 0, 40);
        $this->affectedTests = $affectedTests;
        $this->retestedFl    = $retested === null ? null : ($retested ? 'Y' : 'N');
        $this->lotNo         = $lotNo === null ? null : mb_substr($lotNo, 0, 60);
        $this->note          = $note === null ? null : mb_substr($note, 0, 300);
    }

    public function getFireId(): ?int { return $this->fireId; }
}
