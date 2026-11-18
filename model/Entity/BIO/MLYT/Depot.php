<?php
declare(strict_types=1);

namespace BIO\MLYT;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Netsis depo / birim kodu (satın alma listesi): 1 Merkez, 11 Soğukhava, 131 Alsancak şube, 21 Biyokimya…
 * Netsis Açıklama kolonundaki "çıkış-giriş" kodları buna göre adlandırılır. Listede olmayan 5000+ kodlar caridir
 * (kurum / doktor). DEPOT_TYPE raporda gruplamak içindir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_depot')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_depot', columns: ['COMPANY_ID', 'DEPOT_CODE'])]
class Depot
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const TYPE_STORE  = 'STORE';    // ana depo (Merkez, Soğukhava)
    public const TYPE_BRANCH = 'BRANCH';   // şube
    public const TYPE_OSGB   = 'OSGB';     // OSGB merkez / saha
    public const TYPE_LAB    = 'LAB';      // Kahramanlar laboratuvar bölümü, kan alma
    public const TYPE_OTHER  = 'OTHER';    // idari, mutfak, pazarlama, ödünç…
    /** Listede olmayan 5000+ kod (rapor grubu; tabloda kaydı yok) */
    public const TYPE_CUSTOMER = 'CUSTOMER';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $depotId = null;                  // DEPOT_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\Column(type: 'integer')]
    private int $depotCode;                        // DEPOT_CODE

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $depotName;                     // DEPOT_NAME

    #[ORM\Column(length: 10)]
    private string $depotType;                     // DEPOT_TYPE

    public function __construct(Company $company, int $depotCode, string $depotName, string $depotType)
    {
        $this->company   = $company;
        $this->depotCode = $depotCode;
        $this->depotName = $depotName;
        $this->depotType = $depotType;
    }

    public function getDepotId(): ?int        { return $this->depotId; }
    public function getDepotCode(): int       { return $this->depotCode; }
    public function getDepotName(): string    { return $this->depotName; }
    public function getDepotType(): string    { return $this->depotType; }
}
