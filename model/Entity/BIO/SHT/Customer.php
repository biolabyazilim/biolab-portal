<?php
declare(strict_types=1);

namespace BIO\SHT;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Saha personelinin ziyaret ettiği firma (müşteri işyeri). Görevler (sht_task) bir firmaya açılır.
 *
 * LAT / LON: firmanın haritadaki yeri (web'de haritaya tıklanarak ya da koordinat yapıştırılarak girilir).
 * Personel "Vardım" dediğinde bu noktaya uzaklığı ölçülür; "en yakın boştaki personel" de buradan hesaplanır.
 * ISG_KATIP_NO: İSG-KATİP'teki sözleşme / işyeri numarası (kurallar netleşince kullanımı genişleyecek).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sht_customer')]
#[ORM\Index(name: 'IX_sht_customer_NAME', columns: ['COMPANY_ID', 'CUSTOMER_NAME'])]
class Customer
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $customerId = null;               // CUSTOMER_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company (boşsa AuditListener oturumdakini yazar)

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $customerName;                  // CUSTOMER_NAME

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $address = null;               // ADDRESS

    #[ORM\Column(length: 50, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $city = null;                  // CITY (il / ilçe)

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $lat = null;                   // LAT

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $lon = null;                   // LON

    #[ORM\Column(length: 100, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $contactName = null;           // CONTACT_NAME (firmadaki yetkili)

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;                 // PHONE

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $isgKatipNo = null;            // ISG_KATIP_NO

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $note = null;                  // NOTE

    public function __construct(string $customerName)
    {
        $this->customerName = $customerName;
    }

    public function getCustomerId(): ?int              { return $this->customerId; }
    public function getCompany(): ?Company             { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }
    public function getCustomerName(): string          { return $this->customerName; }
    public function setCustomerName(string $v): self   { $this->customerName = $v; return $this; }
    public function getAddress(): ?string              { return $this->address; }
    public function setAddress(?string $v): self       { $this->address = $v; return $this; }
    public function getCity(): ?string                 { return $this->city; }
    public function setCity(?string $v): self          { $this->city = $v; return $this; }
    public function getLat(): ?float                   { return $this->lat !== null ? (float) $this->lat : null; }
    public function getLon(): ?float                   { return $this->lon !== null ? (float) $this->lon : null; }
    public function setLocation(?float $lat, ?float $lon): self
    {
        $this->lat = $lat !== null ? number_format($lat, 6, '.', '') : null;
        $this->lon = $lon !== null ? number_format($lon, 6, '.', '') : null;
        return $this;
    }
    public function getContactName(): ?string          { return $this->contactName; }
    public function setContactName(?string $v): self   { $this->contactName = $v; return $this; }
    public function getPhone(): ?string                { return $this->phone; }
    public function setPhone(?string $v): self         { $this->phone = $v; return $this; }
    public function getIsgKatipNo(): ?string           { return $this->isgKatipNo; }
    public function setIsgKatipNo(?string $v): self    { $this->isgKatipNo = $v; return $this; }
    public function getNote(): ?string                 { return $this->note; }
    public function setNote(?string $v): self          { $this->note = $v; return $this; }
}
