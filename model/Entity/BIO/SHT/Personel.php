<?php
declare(strict_types=1);

namespace BIO\SHT;

use BIO\MNG\AppUser;
use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Saha takip uygulamasını kullanan personel. Her personel bir portal kullanıcısına (mng_user) bağlıdır;
 * ayrı şifre tutulmaz, mobil uygulamaya portal kullanıcı adı / şifresiyle girilir.
 *
 * PERSONEL_CODE: Traccar'daki cihazın Identifier (uniqueId) değeriyle aynıdır (ör. P002). Telefon konumu
 * bu kodla gönderir. Traccar'da tekil olduğu için burada da şirketten bağımsız tekildir.
 * TRACCAR_DEVICE_ID: Traccar'ın kendi iç numarası (portal Traccar API'siyle cihaz açınca yazılır).
 * ACTIVE_FL = 'N' olan personel mobil uygulamaya giremez.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sht_personel')]
class Personel
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $personelId = null;               // PERSONEL_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company (boşsa AuditListener oturumdakini yazar)

    #[ORM\OneToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'USER_ID', referencedColumnName: 'USER_ID', nullable: false, unique: true)]
    private AppUser $user;                         // USER_ID → mng_user (bir kullanıcı tek personel kaydı)

    #[ORM\Column(length: 20, unique: true)]
    private string $personelCode;                  // PERSONEL_CODE (= Traccar Identifier, ör. P002)

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $traccarDeviceId = null;          // TRACCAR_DEVICE_ID

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;                 // PHONE

    #[ORM\Column(length: 50, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $jobType = null;               // JOB_TYPE (ör. İSG uzmanı, işyeri hekimi, teknisyen)

    public function __construct(AppUser $user, string $personelCode)
    {
        $this->user = $user;
        $this->setPersonelCode($personelCode);
    }

    public function getPersonelId(): ?int              { return $this->personelId; }
    public function getCompany(): ?Company             { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }
    public function getUser(): AppUser                 { return $this->user; }
    public function getPersonelCode(): string          { return $this->personelCode; }
    public function setPersonelCode(string $v): self   { $this->personelCode = strtoupper(trim($v)); return $this; }
    public function getTraccarDeviceId(): ?int         { return $this->traccarDeviceId; }
    public function setTraccarDeviceId(?int $v): self  { $this->traccarDeviceId = $v; return $this; }
    public function getPhone(): ?string                { return $this->phone; }
    public function setPhone(?string $v): self         { $this->phone = $v; return $this; }
    public function getJobType(): ?string              { return $this->jobType; }
    public function setJobType(?string $v): self       { $this->jobType = $v; return $this; }
}
