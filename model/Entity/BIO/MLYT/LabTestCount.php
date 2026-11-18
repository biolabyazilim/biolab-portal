<?php
declare(strict_types=1);

namespace BIO\MLYT;

use Doctrine\ORM\Mapping as ORM;

/**
 * LabAsistan › İstatistik › Test Sayıları (Tekrarları Göster işaretli) satırı: dönemde çalışılan test ve tekrar sayısı.
 * TEST_CODE mlyt_test.TEST_CODE ile eşleşir (Entegrasyon Kod). LabAsistan tekrarı parametre bazında sayar
 * (1 hemogram tekrarı ~22 satır), bu yüzden toplam tekrar adedi tek başına yorumlanmaz.
 * Yükleme satırıdır, değiştirilmez: denetim kolonu yok.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_lab_test_count')]
#[ORM\Index(name: 'IX_mlyt_lab_test_count_file', columns: ['FILE_ID'])]
class LabTestCount
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $testCountId = null;              // TEST_COUNT_ID

    #[ORM\ManyToOne(targetEntity: PeriodFile::class)]
    #[ORM\JoinColumn(name: 'FILE_ID', referencedColumnName: 'FILE_ID', nullable: false)]
    private PeriodFile $file;                      // FILE_ID → mlyt_period_file

    #[ORM\Column(length: 100, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $testGroup = null;             // TEST_GROUP

    #[ORM\Column(length: 30)]
    private string $testCode;                      // TEST_CODE (büyük harf)

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $testName;                      // TEST_NAME

    #[ORM\Column(type: 'integer')]
    private int $testQty;                          // TEST_QTY (Test Sayısı)

    #[ORM\Column(type: 'integer')]
    private int $repeatQty;                        // REPEAT_QTY (Tekrar)

    public function __construct(PeriodFile $file, ?string $testGroup, string $testCode, string $testName, int $testQty, int $repeatQty)
    {
        $this->file      = $file;
        $this->testGroup = $testGroup;
        $this->testCode  = $testCode;
        $this->testName  = $testName;
        $this->testQty   = $testQty;
        $this->repeatQty = $repeatQty;
    }

    public function getTestCountId(): ?int { return $this->testCountId; }
    public function getTestGroup(): ?string { return $this->testGroup; }
    public function getTestCode(): string  { return $this->testCode; }
    public function getTestName(): string  { return $this->testName; }
    public function getTestQty(): int      { return $this->testQty; }
    public function getRepeatQty(): int    { return $this->repeatQty; }
}
