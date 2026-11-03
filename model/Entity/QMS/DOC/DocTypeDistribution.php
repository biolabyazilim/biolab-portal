<?php
declare(strict_types=1);

namespace QMS\DOC;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Doküman tipinin varsayılan dağıtımı (ör. İSG Talimatı → sahip departman + İSG Birimi; Acil durum planı → herkes).
 * Yeni revizyonda qms_revision_distribution'a kopyalanır; hazırlayan ekleme yapabilir, çıkarma gerekçe ister.
 * SUBJECT_TYPE değerleri: QMS\DOC\Distribution
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_doc_type_distribution')]
#[ORM\UniqueConstraint(name: 'UQ_qms_doc_type_distribution', columns: ['DOC_TYPE_ID', 'SUBJECT_TYPE', 'SUBJECT_ID'])]
class DocTypeDistribution
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $docTypeDistributionId = null;    // DOC_TYPE_DISTRIBUTION_ID

    #[ORM\ManyToOne(targetEntity: DocType::class)]
    #[ORM\JoinColumn(name: 'DOC_TYPE_ID', referencedColumnName: 'DOC_TYPE_ID', nullable: false)]
    private DocType $docType;                      // DOC_TYPE_ID → qms_doc_type

    #[ORM\Column(length: 20)]
    private string $subjectType;                   // SUBJECT_TYPE

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $subjectId = null;             // SUBJECT_ID (türüne göre departman / pozisyon / lokasyon / kullanıcı)

    public function __construct(DocType $docType, string $subjectType, ?int $subjectId = null)
    {
        Distribution::assertValid($subjectType, $subjectId);
        $this->docType     = $docType;
        $this->subjectType = $subjectType;
        $this->subjectId   = $subjectId !== null ? (string) $subjectId : null;
    }

    public function getDocTypeDistributionId(): ?int { return $this->docTypeDistributionId; }
    public function getDocType(): DocType          { return $this->docType; }
    public function getSubjectType(): string       { return $this->subjectType; }
    public function getSubjectId(): ?int           { return $this->subjectId !== null ? (int) $this->subjectId : null; }
}
