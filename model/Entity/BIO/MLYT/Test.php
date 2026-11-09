<?php
declare(strict_types=1);

namespace BIO\MLYT;

use BIO\MNG\AppUser;
use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Test kataloğu. TEST_CODE Tenay test kodudur; maliyet Tenay'a bu kodla gider, bu yüzden ekranda değiştirilmez.
 * COST_METHOD testin maliyetinin nasıl hesaplanacağını belirler:
 *   KIT        → kit / cihaz testi (kit testi + kontrol / kalibratör + sarf)
 *   RECIPE     → reçete (mikrobiyoloji, manuel, tekil patoloji)
 *   SERVICE    → hizmet alımı (OSGB, dış laboratuvar)
 *   CALCULATED → bileşen testlerden (mlyt_test_component; birleşik patoloji, oran testleri)
 *   UNDEFINED  → henüz belirlenmedi (Excel'de tedarik boş ya da belirsiz)
 * SOURCE_* kolonları Excel'den geldiği gibi saklanır; ekranda bilgi olarak görünür, değiştirilmez.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_test')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_test_CODE', columns: ['COMPANY_ID', 'TEST_CODE'])]
#[ORM\Index(name: 'IX_mlyt_test_SUT', columns: ['SUT_CODE'])]
class Test
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const METHOD_KIT        = 'KIT';
    public const METHOD_RECIPE     = 'RECIPE';
    public const METHOD_SERVICE    = 'SERVICE';
    public const METHOD_CALCULATED = 'CALCULATED';
    public const METHOD_UNDEFINED  = 'UNDEFINED';

    public const UNIT_BIOLAB    = 'BIOLAB';      // Excel "Dışlab"
    public const UNIT_PATHOLOGY = 'PATHOLOGY';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $testId = null;                   // TEST_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\Column(length: 20)]
    private string $testCode;                      // TEST_CODE (Tenay kodu: PAT09, M08, B20)

    #[ORM\Column(length: 500, options: ['collation' => 'Turkish_CI_AS'])]
    private string $testName;                      // TEST_NAME

    #[ORM\ManyToOne(targetEntity: ServiceGroup::class)]
    #[ORM\JoinColumn(name: 'SERVICE_GROUP_ID', referencedColumnName: 'SERVICE_GROUP_ID', nullable: false)]
    private ServiceGroup $serviceGroup;            // SERVICE_GROUP_ID → mlyt_service_group

    #[ORM\Column(length: 20, options: ['default' => self::UNIT_BIOLAB])]
    private string $labUnit = self::UNIT_BIOLAB;   // LAB_UNIT

    #[ORM\Column(length: 20, options: ['default' => self::METHOD_UNDEFINED])]
    private string $costMethod = self::METHOD_UNDEFINED;   // COST_METHOD

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'SUPPLIER_ID', referencedColumnName: 'SUPPLIER_ID', nullable: true)]
    private ?Supplier $supplier = null;            // SUPPLIER_ID → mlyt_supplier (cihaz / tedarikçi)

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $pathologyLevel = null;           // PATHOLOGY_LEVEL (1–4)

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $sutCode = null;               // SUT_CODE (patoloji: 909410)

    // Numune varyantı: KREATİNİN (BOS) → KREATİNİN
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'PARENT_TEST_ID', referencedColumnName: 'TEST_ID', nullable: true)]
    private ?Test $parentTest = null;              // PARENT_TEST_ID

    #[ORM\Column(length: 50, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $sampleType = null;            // SAMPLE_TYPE (BOS, MAYİ, SPOT İDRAR...)

    // Malzeme bildirimini yapan lab sorumlusu; portal kullanıcısı olunca RESPONSIBLE_USER_ID dolar
    #[ORM\Column(length: 100, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $responsibleName = null;       // RESPONSIBLE_NAME

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'RESPONSIBLE_USER_ID', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $responsibleUser = null;      // RESPONSIBLE_USER_ID → mng_user

    #[ORM\Column(length: 150, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $sourceSupplierText = null;    // SOURCE_SUPPLIER_TEXT (Excel "Tedarik")

    #[ORM\Column(length: 50, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $sourceLevelText = null;       // SOURCE_LEVEL_TEXT (Excel "DÜZEY")

    #[ORM\Column(length: 300, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $sourceMaterialText = null;    // SOURCE_MATERIAL_TEXT (PATOLOJİ "HANGİ MALZEME": 2 TK, 2L, 2 LM, NSİ)

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                  // NOTE

    // Uzman hesap notu: maliyet Excel'inde testin nasıl hesaplandığı ("KANLI+EMB HESAPLAMASI", "2* ALP MALİYETİ + peg").
    // Reçete girilirken yol gösterir; hesaba girmez.
    #[ORM\Column(length: 2000, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $costNote = null;              // COST_NOTE

    public function __construct(string $testCode, string $testName, ServiceGroup $serviceGroup)
    {
        $this->testCode     = $testCode;
        $this->testName     = $testName;
        $this->serviceGroup = $serviceGroup;
    }

    public function getTestId(): ?int                     { return $this->testId; }
    public function getCompany(): ?Company                { return $this->company; }
    public function setCompany(Company $company): self    { $this->company = $company; return $this; }
    public function getTestCode(): string                 { return $this->testCode; }

    public function getTestName(): string                 { return $this->testName; }
    public function setTestName(string $v): self          { $this->testName = $v; return $this; }
    public function getServiceGroup(): ServiceGroup       { return $this->serviceGroup; }
    public function setServiceGroup(ServiceGroup $v): self { $this->serviceGroup = $v; return $this; }
    public function getLabUnit(): string                  { return $this->labUnit; }
    public function setLabUnit(string $v): self           { $this->labUnit = $v; return $this; }
    public function getCostMethod(): string               { return $this->costMethod; }
    public function setCostMethod(string $v): self        { $this->costMethod = $v; return $this; }
    public function getSupplier(): ?Supplier              { return $this->supplier; }
    public function setSupplier(?Supplier $v): self       { $this->supplier = $v; return $this; }
    public function getPathologyLevel(): ?int             { return $this->pathologyLevel; }
    public function setPathologyLevel(?int $v): self      { $this->pathologyLevel = $v; return $this; }
    public function getSutCode(): ?string                 { return $this->sutCode; }
    public function setSutCode(?string $v): self          { $this->sutCode = $v; return $this; }
    public function getParentTest(): ?Test                { return $this->parentTest; }
    public function setParentTest(?Test $v): self         { $this->parentTest = $v; return $this; }
    public function getSampleType(): ?string              { return $this->sampleType; }
    public function setSampleType(?string $v): self       { $this->sampleType = $v; return $this; }
    public function getResponsibleName(): ?string         { return $this->responsibleName; }
    public function setResponsibleName(?string $v): self  { $this->responsibleName = $v; return $this; }
    public function getResponsibleUser(): ?AppUser        { return $this->responsibleUser; }
    public function setResponsibleUser(?AppUser $v): self { $this->responsibleUser = $v; return $this; }
    public function getNote(): ?string                    { return $this->note; }
    public function setNote(?string $v): self             { $this->note = $v; return $this; }
    public function getCostNote(): ?string                { return $this->costNote; }
    public function setCostNote(?string $v): self         { $this->costNote = $v; return $this; }

    public function getSourceSupplierText(): ?string      { return $this->sourceSupplierText; }
    public function getSourceLevelText(): ?string         { return $this->sourceLevelText; }
    public function getSourceMaterialText(): ?string      { return $this->sourceMaterialText; }

    /** Excel'den gelen ham değerler (yalnızca import yazar) */
    public function setSource(?string $supplierText, ?string $levelText, ?string $materialText): self
    {
        $this->sourceSupplierText = $supplierText;
        $this->sourceLevelText    = $levelText;
        $this->sourceMaterialText = $materialText;
        return $this;
    }
}
