<?php
declare(strict_types=1);

namespace QMS\DOC;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Doküman tipinin imza akışı şablonundaki adım (ör. 1 Hazırlayan → 2 Kontrol eden → 3 Onaylayan).
 * Bu adımda imza atabilecek pozisyonlar: qms_doc_type_step_position (yetki matrisi).
 * Revizyon imzaya gönderilince adımlar qms_sign_step'e kopyalanır; şablon sonradan değişse de eski revizyonların
 * akışı değişmez. Aynı STEP_ORDER'daki adımlar paralel imzalanır.
 * SIGN_ROLE, Word şablonundaki yer tutucu grubunu belirler: PREPARED → ${prepared_*}, CHECKED → ${checked_*},
 * APPROVED → ${approved_*}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_doc_type_step')]
#[ORM\UniqueConstraint(name: 'UQ_qms_doc_type_step_TYPE_ROLE', columns: ['DOC_TYPE_ID', 'SIGN_ROLE'])]
class DocTypeStep
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const ROLE_PREPARED = 'PREPARED';   // Hazırlayan
    public const ROLE_CHECKED  = 'CHECKED';    // Kontrol eden
    public const ROLE_APPROVED = 'APPROVED';   // Onaylayan
    public const ROLES = [self::ROLE_PREPARED, self::ROLE_CHECKED, self::ROLE_APPROVED];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $docTypeStepId = null;            // DOC_TYPE_STEP_ID

    #[ORM\ManyToOne(targetEntity: DocType::class)]
    #[ORM\JoinColumn(name: 'DOC_TYPE_ID', referencedColumnName: 'DOC_TYPE_ID', nullable: false)]
    private DocType $docType;                      // DOC_TYPE_ID → qms_doc_type

    #[ORM\Column(type: 'smallint')]
    private int $stepOrder;                        // STEP_ORDER

    #[ORM\Column(length: 20)]
    private string $signRole;                      // SIGN_ROLE (PREPARED / CHECKED / APPROVED)

    public function __construct(DocType $docType, int $stepOrder, string $signRole)
    {
        if (!in_array($signRole, self::ROLES, true)) {
            throw new \InvalidArgumentException('Geçersiz imza rolü: ' . $signRole);
        }
        $this->docType   = $docType;
        $this->stepOrder = $stepOrder;
        $this->signRole  = $signRole;
    }

    public function getDocTypeStepId(): ?int       { return $this->docTypeStepId; }
    public function getDocType(): DocType          { return $this->docType; }
    public function getStepOrder(): int            { return $this->stepOrder; }
    public function setStepOrder(int $v): self     { $this->stepOrder = $v; return $this; }
    public function getSignRole(): string          { return $this->signRole; }
}
