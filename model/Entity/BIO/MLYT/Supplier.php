<?php
declare(strict_types=1);

namespace BIO\MLYT;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Tedarikçi / cihaz platformu (NUCLEUS, Y-EUROIMMUN, DEKİM MEDİKAL MAGLUMİ, OSGB...).
 * Maliyet Excel'indeki "Tedarik" kolonundan gelir; "HESAPLAMALI TEST", "MANUEL TESTLER" gibi
 * yöntem bildiren değerler buraya değil testin maliyet yöntemine yazılır.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mlyt_supplier')]
#[ORM\UniqueConstraint(name: 'UQ_mlyt_supplier_NAME', columns: ['COMPANY_ID', 'SUPPLIER_NAME'])]
class Supplier
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $supplierId = null;               // SUPPLIER_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $supplierName;                  // SUPPLIER_NAME

    public function __construct(string $supplierName)
    {
        $this->supplierName = $supplierName;
    }

    public function getSupplierId(): ?int               { return $this->supplierId; }
    public function getCompany(): ?Company              { return $this->company; }
    public function setCompany(Company $company): self  { $this->company = $company; return $this; }
    public function getSupplierName(): string           { return $this->supplierName; }
    public function setSupplierName(string $v): self    { $this->supplierName = $v; return $this; }
}
