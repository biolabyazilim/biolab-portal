<?php
declare(strict_types=1);

namespace QMS\LOG;

use QMS\MNG\AppUser;
use QMS\MNG\Company;
use Doctrine\ORM\Mapping as ORM;

/**
 * Denetim kaydı: kim, ne zaman, neye, ne yaptı (yükleme, imzaya gönderme, imza, red, yayın, dağıtım değişikliği,
 * görüntüleme, indirme, kullanıcı ve yetki değişiklikleri …).
 * Yalnızca eklenir: readOnly + veritabanında UPDATE/DELETE engelleyen trigger (TR_qms_audit_log_NO_CHANGE).
 * RECORD_HASH = SHA-256(PREV_HASH + bu kaydın alanları); zincir kırılırsa kayıtla oynandığı anlaşılır.
 * ENTITY_NAME + ENTITY_ID işlemin yapıldığı kayıt (ör. qms_revision / 42); DETAIL işlemin ayrıntısı (JSON).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'qms_audit_log')]
#[ORM\Index(name: 'IX_qms_audit_log_ENTITY', columns: ['ENTITY_NAME', 'ENTITY_ID'])]
#[ORM\Index(name: 'IX_qms_audit_log_USER', columns: ['USER_ID', 'CREATED_AT'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $logId = null;                    // LOG_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: true)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'USER_ID', referencedColumnName: 'USER_ID', nullable: true)]
    private ?AppUser $user = null;                 // USER_ID → mng_user (sistem işlemlerinde boş)

    #[ORM\Column(length: 50)]
    private string $entityName;                    // ENTITY_NAME (tablo adı)

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $entityId = null;              // ENTITY_ID

    #[ORM\Column(length: 50)]
    private string $action;                        // ACTION (ör. UPLOAD, SUBMIT, SIGN, REJECT, PUBLISH, VIEW, DOWNLOAD)

    #[ORM\Column(type: 'text', nullable: true, options: ['collation' => 'Turkish_100_CI_AS_SC_UTF8'])]
    private ?string $detail = null;                // DETAIL (JSON)

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;             // IP_ADDRESS

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;         // CREATED_AT (sunucu saati)

    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $prevHash = null;              // PREV_HASH

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $recordHash;                    // RECORD_HASH

    public function __construct(
        ?Company $company,
        ?AppUser $user,
        string $entityName,
        ?int $entityId,
        string $action,
        ?string $detail,
        ?string $ipAddress,
        \DateTimeImmutable $createdAt,
        ?string $prevHash,
        string $recordHash,
    ) {
        $this->company    = $company;
        $this->user       = $user;
        $this->entityName = $entityName;
        $this->entityId   = $entityId !== null ? (string) $entityId : null;
        $this->action     = $action;
        $this->detail     = $detail;
        $this->ipAddress  = $ipAddress;
        $this->createdAt  = $createdAt;
        $this->prevHash   = $prevHash;
        $this->recordHash = $recordHash;
    }

    public function getLogId(): ?int
    {
        return $this->logId !== null ? (int) $this->logId : null;
    }

    public function getCompany(): ?Company         { return $this->company; }
    public function getUser(): ?AppUser            { return $this->user; }
    public function getEntityName(): string        { return $this->entityName; }
    public function getEntityId(): ?int            { return $this->entityId !== null ? (int) $this->entityId : null; }
    public function getAction(): string            { return $this->action; }
    public function getDetail(): ?string           { return $this->detail; }
    public function getIpAddress(): ?string        { return $this->ipAddress; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPrevHash(): ?string         { return $this->prevHash; }
    public function getRecordHash(): string        { return $this->recordHash; }
}
