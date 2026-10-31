<?php
declare(strict_types=1);

namespace BIO\KVK;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Bir başlığa bağlı KVKK belgesi (ör. "Çalışan Adayı Aydınlatma Metni").
 * PDF dosyası ayrı tabloda durur (kvk_document_file); her yüklemede yeni versiyon eklenir, eskisi arşivde kalır.
 * Yayın sitesi belgenin son versiyonunu gösterir.
 *
 * PUBLIC_KEY: yayın sitesindeki bağlantı (doc.php?k=…). Sıra numarası (ID) dışarıya verilmez, tahmin edilemez.
 * Belge güncellense de anahtar değişmez; biolabltd.com.tr'ye verilen bağlantı bozulmaz.
 * PUBLISH_FL: 'Y' ise yayın sitesinde görünür. Yüklenmiş dosyası olmayan belge yayınlanmaz (ekran kontrol eder).
 */
#[ORM\Entity]
#[ORM\Table(name: 'kvk_document')]
#[ORM\Index(name: 'IX_kvk_document_NODE', columns: ['NODE_ID', 'SORT_NO'])]
class KvkkDocument
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $documentId = null;               // DOCUMENT_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company (boşsa AuditListener oturumdakini yazar)

    #[ORM\ManyToOne(targetEntity: KvkkNode::class)]
    #[ORM\JoinColumn(name: 'NODE_ID', referencedColumnName: 'NODE_ID', nullable: false)]
    private KvkkNode $node;                        // NODE_ID → kvk_node

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $documentName;                  // DOCUMENT_NAME (yayında görünen ad)

    #[ORM\Column(length: 32, unique: true, options: ['fixed' => true])]
    private string $publicKey;                     // PUBLIC_KEY (32 haneli rastgele anahtar)

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortNo = 0;                       // SORT_NO (başlık altındaki sıra)

    #[ORM\Column(length: 1, options: ['fixed' => true, 'default' => 'N'])]
    private string $publishFl = 'N';               // PUBLISH_FL: Y = yayında

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;   // PUBLISHED_AT (son yayına alınma zamanı)

    public function __construct(KvkkNode $node, string $documentName, int $sortNo = 0)
    {
        $this->node         = $node;
        $this->documentName = $documentName;
        $this->sortNo       = $sortNo;
        $this->publicKey    = bin2hex(random_bytes(16));
    }

    public function getDocumentId(): ?int              { return $this->documentId; }
    public function getCompany(): ?Company             { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }
    public function getNode(): KvkkNode                { return $this->node; }
    public function setNode(KvkkNode $v): self         { $this->node = $v; return $this; }
    public function getDocumentName(): string          { return $this->documentName; }
    public function setDocumentName(string $v): self   { $this->documentName = $v; return $this; }
    public function getPublicKey(): string             { return $this->publicKey; }
    public function getSortNo(): int                   { return $this->sortNo; }
    public function setSortNo(int $v): self            { $this->sortNo = $v; return $this; }
    public function isPublished(): bool                { return $this->publishFl === 'Y'; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }

    // Yayına alınırken zaman yazılır; yayından kaldırılınca son yayın zamanı bilgi olarak kalır
    public function setPublished(bool $publish): self
    {
        if ($publish && $this->publishFl !== 'Y') {
            $this->publishedAt = new \DateTimeImmutable();
        }
        $this->publishFl = $publish ? 'Y' : 'N';
        return $this;
    }
}
