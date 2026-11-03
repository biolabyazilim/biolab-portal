<?php
declare(strict_types=1);

namespace QMS\MNG;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\Qms\QmsAuditTrait;

/**
 * QMS kullanıcısı (Biolab_QMS.mng_user). Portalın kullanıcılarından bağımsızdır; QMS'e elle tanımlanır.
 * Departman / pozisyon / lokasyon görevleri qms_user_assignment'ta tarihli tutulur.
 * PHONE: imza anında SMS ile tek kullanımlık kod gönderilecek numara.
 * Kullanıcı silinmez, pasife alınır (ACTIVE_FL = 'N'): geçmiş imzaları sahipsiz kalmamalı.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mng_user')]
class AppUser
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $userId = null;                 // USER_ID

    #[ORM\Column(length: 50, unique: true)]
    private string $username;                    // USERNAME

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $fullName;                    // FULL_NAME

    #[ORM\Column(length: 255)]
    private string $passwordHash;                // PASSWORD_HASH

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $email = null;              // → EMAIL

    #[ORM\Column(length: 50, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $title = null;               // TITLE (unvan, ör. "Uzm. Dr."; raporda onaylayanın adının önüne yazılır)

    #[ORM\Column(length: 20, nullable: true, unique: true)]
    private ?string $registryNo = null;          // REGISTRY_NO (sicil no)

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;               // PHONE (imza SMS kodu için, ör. 905321234567)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;   // LAST_LOGIN_AT

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $failedLoginCount = 0;           // FAILED_LOGIN_COUNT

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;   // LOCKED_UNTIL

    public function __construct(string $username, string $fullName, string $plainPassword)
    {
        $this->username     = $username;
        $this->fullName     = $fullName;
        $this->passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    }

    public function getUserId(): ?int
    {
        return $this->userId !== null ? (int) $this->userId : null;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): self
    {
        $this->fullName = $fullName;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title !== null && trim($title) !== '' ? trim($title) : null;
        return $this;
    }

    public function getRegistryNo(): ?string     { return $this->registryNo; }
    public function setRegistryNo(?string $v): self { $this->registryNo = $v !== null && trim($v) !== '' ? trim($v) : null; return $this; }
    public function getPhone(): ?string          { return $this->phone; }
    public function setPhone(?string $v): self   { $this->phone = $v !== null && trim($v) !== '' ? trim($v) : null; return $this; }
    public function getEmail(): ?string          { return $this->email; }
    public function setEmail(?string $v): self   { $this->email = $v !== null && trim($v) !== '' ? trim($v) : null; return $this; }

    // Raporda imza satırı: "Uzm. Dr. Ad Soyad"
    public function getDisplayName(): string
    {
        return trim(($this->title ? $this->title . ' ' : '') . $this->fullName);
    }

    // Şifre: setPasswordHash yok, her zaman hash'lenerek değişir
    public function changePassword(string $plainPassword): self
    {
        $this->passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
        return $this;
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    // Başarılı girişte çağrılır; hatalı deneme sayacı ve kilit sıfırlanır
    public function markLogin(): self
    {
        $this->lastLoginAt      = new \DateTimeImmutable();
        $this->failedLoginCount = 0;
        $this->lockedUntil      = null;
        return $this;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function isLocked(\DateTimeImmutable $now): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > $now;
    }

    // Hatalı şifrede çağrılır; $maxAttempts'e ulaşınca hesap $lockMinutes dakika kilitlenir
    public function registerFailedLogin(int $maxAttempts, int $lockMinutes, \DateTimeImmutable $now): self
    {
        // Süresi dolmuş kilitten sonra sayaç baştan başlar
        if ($this->lockedUntil !== null && $this->lockedUntil <= $now) {
            $this->lockedUntil      = null;
            $this->failedLoginCount = 0;
        }

        $this->failedLoginCount++;
        if ($this->failedLoginCount >= $maxAttempts) {
            $this->lockedUntil      = $now->modify("+{$lockMinutes} minutes");
            $this->failedLoginCount = 0;
        }
        return $this;
    }
}