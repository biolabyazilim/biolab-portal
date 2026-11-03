<?php
declare(strict_types=1);

namespace QMS\SIGN;

use QMS\DOC\DocTypeStep;
use QMS\DOC\Revision;
use QMS\MNG\AppUser;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * Revizyonun imza akışındaki adım: kimin, hangi rolde, hangi sırada imzalayacağı. Atılan imza ayrı tablodadır
 * (qms_signature); bu tablo "kimin imzalaması gerekiyor" bilgisidir.
 *
 * Durumlar (STEP_STATUS):
 *   WAITING   sırası gelmedi
 *   ACTIVE    sırası geldi; yalnızca atanmış kullanıcı imzalayabilir
 *   SIGNED    imzalandı
 *   REJECTED  imzacı reddetti (REJECT_NOTE zorunlu); revizyon taslağa döner
 *   VOID      tur kapandı (başka adım reddetti) ya da adım devredildi
 * Red sonrası eski turun adımları silinmez; yeni tur (SIGN_ROUND + 1) yeni satırlarla açılır.
 * Devir: atanan kişi değişirse eski adım VOID olur, yeni adım REASSIGN_REASON ile eklenir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'qms_sign_step')]
#[ORM\Index(name: 'IX_qms_sign_step_REVISION', columns: ['REVISION_ID', 'SIGN_ROUND', 'STEP_ORDER'])]
#[ORM\Index(name: 'IX_qms_sign_step_USER', columns: ['USER_ID', 'STEP_STATUS'])]
class SignStep
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const WAITING  = 'WAITING';
    public const ACTIVE   = 'ACTIVE';
    public const SIGNED   = 'SIGNED';
    public const REJECTED = 'REJECTED';
    public const VOID     = 'VOID';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $signStepId = null;               // SIGN_STEP_ID

    #[ORM\ManyToOne(targetEntity: Revision::class)]
    #[ORM\JoinColumn(name: 'REVISION_ID', referencedColumnName: 'REVISION_ID', nullable: false)]
    private Revision $revision;                    // REVISION_ID → qms_revision

    #[ORM\Column(type: 'smallint')]
    private int $signRound;                        // SIGN_ROUND

    #[ORM\Column(type: 'smallint')]
    private int $stepOrder;                        // STEP_ORDER

    #[ORM\Column(length: 20)]
    private string $signRole;                      // SIGN_ROLE (PREPARED / CHECKED / APPROVED)

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'USER_ID', referencedColumnName: 'USER_ID', nullable: false)]
    private AppUser $user;                         // USER_ID → mng_user (atanan imzacı)

    #[ORM\Column(length: 20, options: ['default' => self::WAITING])]
    private string $stepStatus = self::WAITING;    // STEP_STATUS

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;   // ACTIVATED_AT (sırası geldiği an; hatırlatma süresi buradan)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;   // COMPLETED_AT

    #[ORM\Column(length: 1000, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $rejectNote = null;            // REJECT_NOTE

    #[ORM\Column(length: 500, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $reassignReason = null;        // REASSIGN_REASON

    public function __construct(Revision $revision, int $stepOrder, string $signRole, AppUser $user, ?string $reassignReason = null)
    {
        if (!in_array($signRole, DocTypeStep::ROLES, true)) {
            throw new \InvalidArgumentException('Geçersiz imza rolü: ' . $signRole);
        }
        $this->revision       = $revision;
        $this->signRound      = $revision->getSignRound();
        $this->stepOrder      = $stepOrder;
        $this->signRole       = $signRole;
        $this->user           = $user;
        $this->reassignReason = $reassignReason;
    }

    public function getSignStepId(): ?int
    {
        return $this->signStepId !== null ? (int) $this->signStepId : null;
    }

    public function getRevision(): Revision        { return $this->revision; }
    public function getSignRound(): int            { return $this->signRound; }
    public function getStepOrder(): int            { return $this->stepOrder; }
    public function getSignRole(): string          { return $this->signRole; }
    public function getUser(): AppUser             { return $this->user; }
    public function getStepStatus(): string        { return $this->stepStatus; }
    public function getActivatedAt(): ?\DateTimeImmutable { return $this->activatedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getRejectNote(): ?string       { return $this->rejectNote; }
    public function getReassignReason(): ?string   { return $this->reassignReason; }
    public function isActive(): bool               { return $this->stepStatus === self::ACTIVE; }

    public function activate(\DateTimeImmutable $now): self
    {
        $this->assertStatus(self::WAITING);
        $this->stepStatus  = self::ACTIVE;
        $this->activatedAt = $now;
        return $this;
    }

    public function markSigned(\DateTimeImmutable $now): self
    {
        $this->assertStatus(self::ACTIVE);
        $this->stepStatus  = self::SIGNED;
        $this->completedAt = $now;
        return $this;
    }

    public function reject(string $note, \DateTimeImmutable $now): self
    {
        $this->assertStatus(self::ACTIVE);
        if (trim($note) === '') {
            throw new \InvalidArgumentException('Red gerekçesi girilmeli.');
        }
        $this->stepStatus  = self::REJECTED;
        $this->rejectNote  = trim($note);
        $this->completedAt = $now;
        return $this;
    }

    // Tur kapandı ya da adım başka kişiye devredildi
    public function void(\DateTimeImmutable $now): self
    {
        if (!in_array($this->stepStatus, [self::WAITING, self::ACTIVE], true)) {
            throw new \LogicException('Tamamlanmış adım iptal edilemez.');
        }
        $this->stepStatus  = self::VOID;
        $this->completedAt = $now;
        return $this;
    }

    private function assertStatus(string $expected): void
    {
        if ($this->stepStatus !== $expected) {
            throw new \LogicException("İmza adımı durumu {$this->stepStatus}; bu işlem için {$expected} olmalı.");
        }
    }
}
