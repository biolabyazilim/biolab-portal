<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;

/**
 * LabAsistan › Hastalar listesindeki bir numune (kabul edilmeyen ya da reddedilen; liste türü dosyanın SOURCE_CODE'u).
 * Yalnız maliyet için gereken kolonlar tutulur; hasta adı, doğum tarihi, yaş, TC, dosya no ve doktor okunmaz.
 * INSTITUTION, LabAsistan "Bölüm" kolonudur (kurum adı); kişi adı (Dr. …) içeriyorsa boş bırakılır.
 * Aynı numune iki listede de olabilir; rapor Örnek No ile tekilleştirir. Yükleme satırıdır: denetim kolonu yok.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_lab_sample')]
#[ORM\Index(name: 'IX_mlyt_lab_sample_file', columns: ['FILE_ID'])]
#[ORM\Index(name: 'IX_mlyt_lab_sample_no', columns: ['SAMPLE_NO'])]
class LabSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $labSampleId = null;              // LAB_SAMPLE_ID

    #[ORM\ManyToOne(targetEntity: PeriodFile::class)]
    #[ORM\JoinColumn(name: 'FILE_ID', referencedColumnName: 'FILE_ID', nullable: false)]
    private PeriodFile $file;                      // FILE_ID → mlyt_period_file

    #[ORM\Column(length: 30)]
    private string $sampleNo;                      // SAMPLE_NO (Örnek No)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null; // REGISTERED_AT (Tarih = kayıt tarihi)

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $acceptedFl = 'N';              // ACCEPTED_FL (Kabul Tarihi doluysa Y: kabulden sonra reddedilen)

    // Onay Tarih doluysa testler çalışılıp onaylanmıştır: kabul kaydı eksik olsa da numune kaybolmamıştır
    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $approvedFl = 'N';              // APPROVED_FL

    #[ORM\Column(length: 40, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $arrivalType = null;           // ARRIVAL_TYPE (Durum: DISLAB, AYAKTAN, GIRIS, EV KAN ALMA…)

    #[ORM\Column(length: 150, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $institution = null;           // INSTITUTION (Bölüm: kurum adı)

    #[ORM\Column(type: 'smallint')]
    private int $tubeQty;                          // TUBE_QTY (Barkodlar kolonundaki barkod sayısı)

    #[ORM\Column(type: 'smallint')]
    private int $testQty;                          // TEST_QTY ("Bekleyen Testler" başındaki sayı)

    #[ORM\Column(length: 2000, nullable: true)]
    private ?string $testShortNames = null;        // TEST_SHORT_NAMES (kısa adlar, boşlukla)

    public function __construct(PeriodFile $file, string $sampleNo, ?\DateTimeImmutable $registeredAt, bool $accepted, bool $approved,
                                ?string $arrivalType, ?string $institution, int $tubeQty, int $testQty, ?string $testShortNames)
    {
        $this->file           = $file;
        $this->sampleNo       = $sampleNo;
        $this->registeredAt   = $registeredAt;
        $this->acceptedFl     = $accepted ? 'Y' : 'N';
        $this->approvedFl     = $approved ? 'Y' : 'N';
        $this->arrivalType    = $arrivalType;
        $this->institution    = $institution;
        $this->tubeQty        = $tubeQty;
        $this->testQty        = $testQty;
        $this->testShortNames = $testShortNames === null ? null : mb_substr($testShortNames, 0, 2000);
    }

    public function getLabSampleId(): ?int { return $this->labSampleId; }
    public function getSampleNo(): string  { return $this->sampleNo; }
    public function isAccepted(): bool     { return $this->acceptedFl === 'Y'; }
    public function isApproved(): bool     { return $this->approvedFl === 'Y'; }
    public function getArrivalType(): ?string { return $this->arrivalType; }
    public function getInstitution(): ?string { return $this->institution; }
    public function getTubeQty(): int      { return $this->tubeQty; }
    public function getTestQty(): int      { return $this->testQty; }
}
