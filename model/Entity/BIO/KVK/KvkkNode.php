<?php
declare(strict_types=1);

namespace BIO\KVK;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * KVKK sayfasındaki başlık ağacı (Politikalar, Aydınlatma Metinleri → İnsan Kaynakları Birimi …).
 * Üst başlığı boş olan kayıt kök başlıktır; seviye sınırı yoktur.
 * Belgeler (PDF) bu başlıklara bağlanır (kvk_document). Kayıt silinmez, RECORD_FL = 0 yapılır.
 * Yayın sitesi yalnızca aktif (ACTIVE_FL = 'Y') ve silinmemiş başlıkları gösterir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kvk_node')]
#[ORM\Index(name: 'IX_kvk_node_PARENT', columns: ['PARENT_NODE_ID', 'SORT_NO'])]
class KvkkNode
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $nodeId = null;                   // NODE_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company (boşsa AuditListener oturumdakini yazar)

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'PARENT_NODE_ID', referencedColumnName: 'NODE_ID', nullable: true)]
    private ?KvkkNode $parent = null;              // PARENT_NODE_ID → kvk_node (kök başlıkta boş)

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $nodeName;                      // NODE_NAME (ör. "Aydınlatma Metinleri")

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $nodeDesc = null;              // NODE_DESC (yayında başlığın yanında gri yazı, ör. "İş başvuru alanı değildir")

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortNo = 0;                       // SORT_NO (aynı üst başlık altındaki sıra)

    public function __construct(string $nodeName, ?KvkkNode $parent = null, int $sortNo = 0)
    {
        $this->nodeName = $nodeName;
        $this->parent   = $parent;
        $this->sortNo   = $sortNo;
    }

    public function getNodeId(): ?int                  { return $this->nodeId; }
    public function getCompany(): ?Company             { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }
    public function getParent(): ?KvkkNode             { return $this->parent; }
    public function setParent(?KvkkNode $v): self      { $this->parent = $v; return $this; }
    public function getNodeName(): string              { return $this->nodeName; }
    public function setNodeName(string $v): self       { $this->nodeName = $v; return $this; }
    public function getNodeDesc(): ?string             { return $this->nodeDesc; }
    public function setNodeDesc(?string $v): self      { $this->nodeDesc = $v; return $this; }
    public function getSortNo(): int                   { return $this->sortNo; }
    public function setSortNo(int $v): self            { $this->sortNo = $v; return $this; }
}
