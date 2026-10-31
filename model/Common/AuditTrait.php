<?php
declare(strict_types=1);

namespace Biolab\Model\Common;

use BIO\MNG\AppUser;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\ORM\Mapping as ORM;

trait AuditTrait
{
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'C_BY', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $cBy = null;

    // SQL ile elle eklenen kayıtlarda da dolsun: DEFAULT GETDATE()
    #[ORM\Column(name: 'C_AT', type: 'datetime_immutable', nullable: true, options: ['default' => new CurrentTimestamp()])]
    private ?\DateTimeImmutable $cAt = null;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'U_BY', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $uBy = null;

    #[ORM\Column(name: 'U_AT', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $uAt = null;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'D_BY', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $dBy = null;

    #[ORM\Column(name: 'D_AT', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dAt = null;

    #[ORM\Column(name: 'ACTIVE_FL', length: 1, options: ['fixed' => true, 'default' => 'Y'])]
    private string $activeFl = 'Y';

    #[ORM\Column(name: 'RECORD_FL', type: 'smallint', options: ['default' => 1])]
    private int $recordFl = 1;

    public function auditCreate(?AppUser $user, \DateTimeImmutable $now): void
    {
        $this->cAt ??= $now;
        $this->cBy ??= $user;
    }

    public function auditUpdate(?AppUser $user, \DateTimeImmutable $now): void
    {
        if ($this->recordFl === 0 && $this->dAt === null) {   // silindi olarak işaretlendi
            $this->dAt = $now;
            $this->dBy = $user;
            return;
        }
        $this->uAt = $now;
        $this->uBy = $user;
    }

    public function softDelete(): void          { $this->recordFl = 0; }
    public function isDeleted(): bool           { return $this->recordFl === 0; }
    public function isActive(): bool            { return $this->activeFl === 'Y'; }
    public function setActive(bool $a): void    { $this->activeFl = $a ? 'Y' : 'N'; }

    public function getCBy(): ?AppUser               { return $this->cBy; }
    public function getCAt(): ?\DateTimeImmutable    { return $this->cAt; }
    public function getUBy(): ?AppUser               { return $this->uBy; }
    public function getUAt(): ?\DateTimeImmutable    { return $this->uAt; }
    public function getDBy(): ?AppUser               { return $this->dBy; }
    public function getDAt(): ?\DateTimeImmutable    { return $this->dAt; }
}