<?php
declare(strict_types=1);

namespace BIO\SHT;

use BIO\MNG\Company;
use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Mobil uygulama oturumu. Telefon girişte bir kez token alır, sonraki her istekte
 * "Authorization: Bearer <token>" başlığıyla gönderir (portal oturumu + CSRF yerine).
 *
 * TOKEN_HASH: token'ın SHA-256 özeti. Token'ın kendisi saklanmaz; veritabanı ele geçse de oturum çalınamaz.
 * COMPANY_ID: girişte seçilen şirket (AuthService'in giriş şirketi). İstekler bu şirket adına çalışır.
 * REVOKED_AT: çıkış yapılınca ya da yönetici "telefondan çıkar" deyince dolar; bu token bir daha geçmez.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sht_device_token')]
#[ORM\Index(name: 'IX_sht_device_token_PERSONEL', columns: ['PERSONEL_ID', 'REVOKED_AT'])]
class DeviceToken
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $tokenId = null;                  // TOKEN_ID

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'COMPANY_ID', referencedColumnName: 'COMPANY_ID', nullable: false)]
    private ?Company $company = null;              // COMPANY_ID → mng_company

    #[ORM\ManyToOne(targetEntity: Personel::class)]
    #[ORM\JoinColumn(name: 'PERSONEL_ID', referencedColumnName: 'PERSONEL_ID', nullable: false)]
    private Personel $personel;                    // PERSONEL_ID → sht_personel

    #[ORM\Column(length: 64, unique: true, options: ['fixed' => true])]
    private string $tokenHash;                     // TOKEN_HASH (SHA-256, 64 hane)

    #[ORM\Column(length: 200, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $deviceInfo = null;            // DEVICE_INFO (telefon modeli, Android sürümü)

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $appVersion = null;            // APP_VERSION

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;    // LAST_SEEN_AT (son API isteği)

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;             // EXPIRES_AT

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;     // REVOKED_AT

    /**
     * PUSH_TOKEN: telefonun Firebase (FCM) bildirim adresi. Uygulama girişten sonra ve Firebase adresi
     * değişince gönderir (action=pushToken). Görev atanınca bu adrese bildirim gider (PushService).
     * Firebase "artık geçersiz" derse boşaltılır.
     */
    #[ORM\Column(length: 400, nullable: true)]
    private ?string $pushToken = null;                 // PUSH_TOKEN

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pushTokenAt = null;   // PUSH_TOKEN_AT (son kayıt)

    public function __construct(Personel $personel, Company $company, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->personel  = $personel;
        $this->company   = $company;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
    }

    /** Token şu an kullanılabilir mi (iptal edilmemiş, süresi dolmamış, silinmemiş) */
    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $this->expiresAt > $now && !$this->isDeleted() && $this->isActive();
    }

    public function revoke(\DateTimeImmutable $now): self { $this->revokedAt ??= $now; return $this; }

    public function getTokenId(): ?int                       { return $this->tokenId; }
    public function getCompany(): ?Company                   { return $this->company; }
    public function setCompany(Company $company): self       { $this->company = $company; return $this; }
    public function getPersonel(): Personel                  { return $this->personel; }
    public function getTokenHash(): string                   { return $this->tokenHash; }
    public function getDeviceInfo(): ?string                 { return $this->deviceInfo; }
    public function setDeviceInfo(?string $v): self          { $this->deviceInfo = $v !== null ? mb_substr($v, 0, 200) : null; return $this; }
    public function getAppVersion(): ?string                 { return $this->appVersion; }
    public function setAppVersion(?string $v): self          { $this->appVersion = $v !== null ? mb_substr($v, 0, 20) : null; return $this; }
    public function getLastSeenAt(): ?\DateTimeImmutable     { return $this->lastSeenAt; }
    public function setLastSeenAt(\DateTimeImmutable $v): self { $this->lastSeenAt = $v; return $this; }
    public function getExpiresAt(): \DateTimeImmutable       { return $this->expiresAt; }
    public function getRevokedAt(): ?\DateTimeImmutable      { return $this->revokedAt; }
    public function getPushToken(): ?string                  { return $this->pushToken; }
    public function getPushTokenAt(): ?\DateTimeImmutable    { return $this->pushTokenAt; }

    /** Bildirim adresini kaydet (null / boş → bildirim kapalı) */
    public function setPushToken(?string $v, \DateTimeImmutable $now): self
    {
        $v = $v !== null ? trim($v) : null;
        $this->pushToken   = ($v === null || $v === '') ? null : mb_substr($v, 0, 400);
        $this->pushTokenAt = $now;
        return $this;
    }
}
