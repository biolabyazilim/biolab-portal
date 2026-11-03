<?php
declare(strict_types=1);

namespace QMS\SIGN;

use QMS\DOC\Revision;
use QMS\MNG\AppUser;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Okudum, anladım" onayı: yayımlanan revizyonu dağıtımdaki kişinin okuduğunun kaydı (ISO 45001 7.3 / 7.4).
 * Yalnızca eklenir; readOnly + veritabanında UPDATE/DELETE engelleyen trigger (TR_qms_read_ack_NO_CHANGE).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'qms_read_ack')]
#[ORM\UniqueConstraint(name: 'UQ_qms_read_ack_REVISION_USER', columns: ['REVISION_ID', 'USER_ID'])]
class ReadAck
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $readAckId = null;                // READ_ACK_ID

    #[ORM\ManyToOne(targetEntity: Revision::class)]
    #[ORM\JoinColumn(name: 'REVISION_ID', referencedColumnName: 'REVISION_ID', nullable: false)]
    private Revision $revision;                    // REVISION_ID → qms_revision

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'USER_ID', referencedColumnName: 'USER_ID', nullable: false)]
    private AppUser $user;                         // USER_ID → mng_user

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $ackedAt;           // ACKED_AT (sunucu saati)

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;             // IP_ADDRESS

    public function __construct(Revision $revision, AppUser $user, \DateTimeImmutable $ackedAt, ?string $ipAddress)
    {
        $this->revision  = $revision;
        $this->user      = $user;
        $this->ackedAt   = $ackedAt;
        $this->ipAddress = $ipAddress;
    }

    public function getReadAckId(): ?int
    {
        return $this->readAckId !== null ? (int) $this->readAckId : null;
    }

    public function getRevision(): Revision        { return $this->revision; }
    public function getUser(): AppUser             { return $this->user; }
    public function getAckedAt(): \DateTimeImmutable { return $this->ackedAt; }
    public function getIpAddress(): ?string        { return $this->ipAddress; }
}
