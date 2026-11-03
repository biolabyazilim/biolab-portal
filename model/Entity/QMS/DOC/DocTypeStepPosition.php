<?php
declare(strict_types=1);

namespace QMS\DOC;

use QMS\ORG\Position;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Yetki matrisi: bu imza adımında imza atabilecek pozisyon (ör. İSG Talimatı / Hazırlayan → İSG Uzmanı, Lab Şefi).
 * İmzacı seçim listesi yalnızca bu pozisyonlardaki aktif kullanıcıları gösterir; tek kişi varsa otomatik atanır.
 * Denetçinin "onaylayanın yetkisi var mıydı" sorusunun cevabı bu tablodur.
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_doc_type_step_position')]
#[ORM\UniqueConstraint(name: 'UQ_qms_doc_type_step_position', columns: ['DOC_TYPE_STEP_ID', 'POSITION_ID'])]
class DocTypeStepPosition
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $docTypeStepPositionId = null;    // DOC_TYPE_STEP_POSITION_ID

    #[ORM\ManyToOne(targetEntity: DocTypeStep::class)]
    #[ORM\JoinColumn(name: 'DOC_TYPE_STEP_ID', referencedColumnName: 'DOC_TYPE_STEP_ID', nullable: false)]
    private DocTypeStep $step;                     // DOC_TYPE_STEP_ID → qms_doc_type_step

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(name: 'POSITION_ID', referencedColumnName: 'POSITION_ID', nullable: false)]
    private Position $position;                    // POSITION_ID → qms_position

    public function __construct(DocTypeStep $step, Position $position)
    {
        $this->step     = $step;
        $this->position = $position;
    }

    public function getDocTypeStepPositionId(): ?int { return $this->docTypeStepPositionId; }
    public function getStep(): DocTypeStep         { return $this->step; }
    public function getPosition(): Position        { return $this->position; }
}
