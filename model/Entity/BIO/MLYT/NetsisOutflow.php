<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;

/**
 * Netsis "depodan çıkan malzeme listesi" satırı. Açıklama kolonu "çıkış deposu-giriş deposu" biçimindedir
 * (1-131 = Merkez → Alsancak şube); giriş 5000 ve üzeri ise cari (kurum / doktor, e-irsaliye).
 * Depo adları mlyt_depot'tadır. Yükleme satırıdır, değiştirilmez (dosya yeniden yüklenince silinir): denetim kolonu yok.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_netsis_outflow')]
#[ORM\Index(name: 'IX_mlyt_netsis_outflow_file', columns: ['FILE_ID'])]
class NetsisOutflow
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $outflowId = null;                // OUTFLOW_ID

    #[ORM\ManyToOne(targetEntity: PeriodFile::class)]
    #[ORM\JoinColumn(name: 'FILE_ID', referencedColumnName: 'FILE_ID', nullable: false)]
    private PeriodFile $file;                      // FILE_ID → mlyt_period_file

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $docDate;           // DOC_DATE (Tarih)

    #[ORM\Column(length: 30)]
    private string $docNo;                         // DOC_NO (Fiş No: DAT… depo transferi, EIR… e-irsaliye)

    #[ORM\Column(length: 30)]
    private string $stockCode;                     // STOCK_CODE (büyük harfe çevrilmiş)

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $stockName;                     // STOCK_NAME

    #[ORM\Column(type: 'decimal', precision: 14, scale: 3)]
    private string $quantity;                      // QUANTITY (Çıkış Miktarı, 1. ölçü birimi: ör. 1.200 AD)

    #[ORM\Column(length: 10)]
    private string $unit;                          // UNIT (Ölçü Br-1: AD, KT, LT…)

    #[ORM\Column(type: 'decimal', precision: 14, scale: 3, nullable: true)]
    private ?string $quantity2 = null;             // QUANTITY2 (Çıkış Miktarı-2: ör. 4 KT)

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $unit2 = null;                 // UNIT2

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fromDepot = null;                // FROM_DEPOT (1 Merkez, 11 Soğukhava)

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $toCode = null;                   // TO_CODE (depo kodu; 5000+ cari kodu; İADE vb. ise null)

    #[ORM\Column(length: 30, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                  // NOTE (Açıklama kolonu ham hâli)

    public function __construct(PeriodFile $file, \DateTimeImmutable $docDate, string $docNo, string $stockCode, string $stockName,
                                string $quantity, string $unit, ?string $quantity2, ?string $unit2, ?int $fromDepot, ?int $toCode, ?string $note)
    {
        $this->file      = $file;
        $this->docDate   = $docDate;
        $this->docNo     = $docNo;
        $this->stockCode = $stockCode;
        $this->stockName = $stockName;
        $this->quantity  = $quantity;
        $this->unit      = $unit;
        $this->quantity2 = $quantity2;
        $this->unit2     = $unit2;
        $this->fromDepot = $fromDepot;
        $this->toCode    = $toCode;
        $this->note      = $note;
    }

    public function getOutflowId(): ?int           { return $this->outflowId; }
    public function getFile(): PeriodFile          { return $this->file; }
    public function getDocDate(): \DateTimeImmutable { return $this->docDate; }
    public function getStockCode(): string         { return $this->stockCode; }
    public function getStockName(): string         { return $this->stockName; }
    public function getQuantity(): string          { return $this->quantity; }
    public function getUnit(): string              { return $this->unit; }
    public function getToCode(): ?int              { return $this->toCode; }
}
