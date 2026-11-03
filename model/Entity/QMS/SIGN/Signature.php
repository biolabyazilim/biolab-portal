<?php
declare(strict_types=1);

namespace QMS\SIGN;

use QMS\DOC\Revision;
use QMS\MNG\AppUser;
use Doctrine\ORM\Mapping as ORM;

/**
 * Atılan imza. Yalnızca eklenir, hiçbir zaman güncellenmez ya da silinmez:
 *   - Doctrine'de readOnly (flush değişikliği yazmaz)
 *   - veritabanında UPDATE/DELETE engelleyen trigger (TR_qms_signature_NO_CHANGE, migration'da)
 *   - RECORD_HASH = SHA-256(önceki kaydın RECORD_HASH'i + bu kaydın alanları): araya kayıt sokulursa ya da bir kayıt
 *     değiştirilirse zincir kırılır (hesaplama: Biolab\Core\Qms\HashChain)
 * İmzacının adı, unvanı ve pozisyonu imza anındaki haliyle kopyalanır; kişi sonradan terfi alsa ya da ayrılsa da
 * eski imzada o günkü bilgi görünür. Zaman sunucu saatidir.
 * HASH_BEFORE: imzalanan PDF'in imzadan önceki SHA-256'sı; HASH_AFTER: imza basılıp mühürlendikten sonraki hali.
 * STROKES: çizimin vektör verisi (nokta + zaman, JSON); yapıştırılmış bir resimden ayırt edilmesini sağlar.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'qms_signature')]
#[ORM\UniqueConstraint(name: 'UQ_qms_signature_STEP', columns: ['SIGN_STEP_ID'])]
#[ORM\Index(name: 'IX_qms_signature_REVISION', columns: ['REVISION_ID'])]
class Signature
{
    public const AUTH_PASSWORD = 'PASSWORD';
    public const AUTH_SMS_OTP  = 'SMS_OTP';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $signatureId = null;              // SIGNATURE_ID

    #[ORM\ManyToOne(targetEntity: SignStep::class)]
    #[ORM\JoinColumn(name: 'SIGN_STEP_ID', referencedColumnName: 'SIGN_STEP_ID', nullable: false)]
    private SignStep $signStep;                    // SIGN_STEP_ID → qms_sign_step

    #[ORM\ManyToOne(targetEntity: Revision::class)]
    #[ORM\JoinColumn(name: 'REVISION_ID', referencedColumnName: 'REVISION_ID', nullable: false)]
    private Revision $revision;                    // REVISION_ID → qms_revision

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'USER_ID', referencedColumnName: 'USER_ID', nullable: false)]
    private AppUser $user;                         // USER_ID → mng_user

    #[ORM\Column(length: 100, options: ['collation' => 'Turkish_CI_AS'])]
    private string $signerName;                    // SIGNER_NAME (imza anındaki ad soyad)

    #[ORM\Column(length: 50, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $signerTitle = null;           // SIGNER_TITLE (imza anındaki unvan)

    #[ORM\Column(length: 150, options: ['collation' => 'Turkish_CI_AS'])]
    private string $signerPosition;                // SIGNER_POSITION (imza anındaki pozisyon)

    #[ORM\Column(length: 20)]
    private string $signRole;                      // SIGN_ROLE

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $signedAt;          // SIGNED_AT (sunucu saati)

    #[ORM\Column(length: 20)]
    private string $authMethod;                    // AUTH_METHOD (PASSWORD / SMS_OTP)

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $hashBefore;                    // HASH_BEFORE

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $hashAfter;                     // HASH_AFTER

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $imageHash;                     // IMAGE_HASH (imza resminin SHA-256'sı)

    #[ORM\Column(type: 'text')]
    private string $strokes;                       // STROKES (JSON)

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;             // IP_ADDRESS

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $userAgent = null;             // USER_AGENT

    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $prevHash = null;              // PREV_HASH (zincirdeki önceki kaydın RECORD_HASH'i; ilk kayıtta boş)

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $recordHash;                    // RECORD_HASH

    /**
     * @param array{name:string, title:?string, position:string} $signer imza anındaki bilgiler
     */
    public function __construct(
        SignStep $signStep,
        array $signer,
        \DateTimeImmutable $signedAt,
        string $authMethod,
        string $hashBefore,
        string $hashAfter,
        string $imageHash,
        string $strokes,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $prevHash,
        string $recordHash,
    ) {
        if (!in_array($authMethod, [self::AUTH_PASSWORD, self::AUTH_SMS_OTP], true)) {
            throw new \InvalidArgumentException('Geçersiz doğrulama yöntemi: ' . $authMethod);
        }
        $this->signStep       = $signStep;
        $this->revision       = $signStep->getRevision();
        $this->user           = $signStep->getUser();
        $this->signRole       = $signStep->getSignRole();
        $this->signerName     = $signer['name'];
        $this->signerTitle    = $signer['title'];
        $this->signerPosition = $signer['position'];
        $this->signedAt       = $signedAt;
        $this->authMethod     = $authMethod;
        $this->hashBefore     = $hashBefore;
        $this->hashAfter      = $hashAfter;
        $this->imageHash      = $imageHash;
        $this->strokes        = $strokes;
        $this->ipAddress      = $ipAddress;
        $this->userAgent      = $userAgent !== null ? mb_substr($userAgent, 0, 500) : null;
        $this->prevHash       = $prevHash;
        $this->recordHash     = $recordHash;
    }

    public function getSignatureId(): ?int
    {
        return $this->signatureId !== null ? (int) $this->signatureId : null;
    }

    public function getSignStep(): SignStep        { return $this->signStep; }
    public function getRevision(): Revision        { return $this->revision; }
    public function getUser(): AppUser             { return $this->user; }
    public function getSignerName(): string        { return $this->signerName; }
    public function getSignerTitle(): ?string      { return $this->signerTitle; }
    public function getSignerPosition(): string    { return $this->signerPosition; }
    public function getSignRole(): string          { return $this->signRole; }
    public function getSignedAt(): \DateTimeImmutable { return $this->signedAt; }
    public function getAuthMethod(): string        { return $this->authMethod; }
    public function getHashBefore(): string        { return $this->hashBefore; }
    public function getHashAfter(): string         { return $this->hashAfter; }
    public function getImageHash(): string         { return $this->imageHash; }
    public function getStrokes(): string           { return $this->strokes; }
    public function getIpAddress(): ?string        { return $this->ipAddress; }
    public function getUserAgent(): ?string        { return $this->userAgent; }
    public function getPrevHash(): ?string         { return $this->prevHash; }
    public function getRecordHash(): string        { return $this->recordHash; }
}
