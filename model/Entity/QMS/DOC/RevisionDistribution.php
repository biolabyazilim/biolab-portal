<?php
declare(strict_types=1);

namespace QMS\DOC;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Revizyonun dağıtımı: dokümanı kimler görür. Revizyon açılınca doküman tipinin varsayılanları kopyalanır
 * (DIST_SOURCE = DEFAULT); hazırlayan grup ekler (MANUAL), yayından sonra kalite ekleyebilir (AFTER_PUBLISH).
 * DIST_MODE = REMOVE: varsayılandan çıkarılan hedef; REASON zorunludur.
 * Dağıtım onayın parçasıdır: revizyon imzaya gönderildikten sonra yalnızca AFTER_PUBLISH kaydı eklenebilir.
 * SUBJECT_TYPE değerleri: QMS\DOC\Distribution
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_revision_distribution')]
#[ORM\UniqueConstraint(name: 'UQ_qms_revision_distribution', columns: ['REVISION_ID', 'SUBJECT_TYPE', 'SUBJECT_ID'])]
class RevisionDistribution
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const MODE_ADD    = 'ADD';
    public const MODE_REMOVE = 'REMOVE';

    public const SOURCE_DEFAULT       = 'DEFAULT';
    public const SOURCE_MANUAL        = 'MANUAL';
    public const SOURCE_AFTER_PUBLISH = 'AFTER_PUBLISH';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $revisionDistributionId = null;   // REVISION_DISTRIBUTION_ID

    #[ORM\ManyToOne(targetEntity: Revision::class)]
    #[ORM\JoinColumn(name: 'REVISION_ID', referencedColumnName: 'REVISION_ID', nullable: false)]
    private Revision $revision;                    // REVISION_ID → qms_revision

    #[ORM\Column(length: 20)]
    private string $subjectType;                   // SUBJECT_TYPE

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $subjectId = null;             // SUBJECT_ID

    #[ORM\Column(length: 10, options: ['default' => self::MODE_ADD])]
    private string $distMode = self::MODE_ADD;     // DIST_MODE

    #[ORM\Column(length: 20, options: ['default' => self::SOURCE_DEFAULT])]
    private string $distSource = self::SOURCE_DEFAULT;   // DIST_SOURCE

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $reason = null;                // REASON

    public function __construct(Revision $revision, string $subjectType, ?int $subjectId, string $distMode, string $distSource, ?string $reason = null)
    {
        Distribution::assertValid($subjectType, $subjectId);
        if (!in_array($distMode, [self::MODE_ADD, self::MODE_REMOVE], true)) {
            throw new \InvalidArgumentException('Geçersiz dağıtım işlemi: ' . $distMode);
        }
        if (!in_array($distSource, [self::SOURCE_DEFAULT, self::SOURCE_MANUAL, self::SOURCE_AFTER_PUBLISH], true)) {
            throw new \InvalidArgumentException('Geçersiz dağıtım kaynağı: ' . $distSource);
        }
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        if (($distMode === self::MODE_REMOVE || $distSource === self::SOURCE_AFTER_PUBLISH || $subjectType === Distribution::USER) && $reason === null) {
            throw new \InvalidArgumentException('Bu dağıtım değişikliği için gerekçe girilmeli.');
        }
        $this->revision    = $revision;
        $this->subjectType = $subjectType;
        $this->subjectId   = $subjectId !== null ? (string) $subjectId : null;
        $this->distMode    = $distMode;
        $this->distSource  = $distSource;
        $this->reason      = $reason;
    }

    public function getRevisionDistributionId(): ?int
    {
        return $this->revisionDistributionId !== null ? (int) $this->revisionDistributionId : null;
    }

    public function getRevision(): Revision        { return $this->revision; }
    public function getSubjectType(): string       { return $this->subjectType; }
    public function getSubjectId(): ?int           { return $this->subjectId !== null ? (int) $this->subjectId : null; }
    public function getDistMode(): string          { return $this->distMode; }
    public function isRemoval(): bool              { return $this->distMode === self::MODE_REMOVE; }
    public function getDistSource(): string        { return $this->distSource; }
    public function getReason(): ?string           { return $this->reason; }
}
