<?php
declare(strict_types=1);

namespace Biolab\Core\Sht;

use BIO\MNG\Company;
use BIO\SHT\DeviceToken;
use BIO\SHT\Personel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saha takip mobil uygulamasının token (Bearer) oturumları.
 *   issue()        girişte yeni token üretir; telefona ham token BİR KEZ verilir, veritabanına özeti yazılır
 *   authenticate() isteğin "Authorization: Bearer …" başlığını doğrular
 *   revoke()       çıkışta token'ı iptal eder
 */
final class DeviceTokenService
{
    public const TTL_DAYS = 90;                 // token ömrü (kurallar netleşince değişebilir)
    private const SEEN_UPDATE_SECONDS = 300;    // LAST_SEEN_AT her istekte değil, 5 dk'da bir yazılır

    public function __construct(private EntityManagerInterface $em) {}

    /** @return array{token: string, entity: DeviceToken} */
    public function issue(Personel $personel, Company $company, ?string $deviceInfo, ?string $appVersion): array
    {
        $raw = bin2hex(random_bytes(32));   // 64 hane
        $now = new \DateTimeImmutable();

        $token = (new DeviceToken($personel, $company, self::hash($raw), $now->modify('+' . self::TTL_DAYS . ' days')))
            ->setDeviceInfo($deviceInfo)
            ->setAppVersion($appVersion)
            ->setLastSeenAt($now);
        $this->em->persist($token);

        return ['token' => $raw, 'entity' => $token];
    }

    /**
     * Geçerli token'ı döner; token yoksa, iptal edilmiş / süresi dolmuşsa ya da personel / kullanıcı pasifse null.
     */
    public function authenticate(?string $raw): ?DeviceToken
    {
        if ($raw === null || !preg_match('/^[0-9a-f]{64}$/', $raw)) {
            return null;
        }
        $token = $this->em->getRepository(DeviceToken::class)->findOneBy(['tokenHash' => self::hash($raw)]);
        $now   = new \DateTimeImmutable();
        if ($token === null || !$token->isUsable($now)) {
            return null;
        }

        $personel = $token->getPersonel();
        $user     = $personel->getUser();
        if ($personel->isDeleted() || !$personel->isActive() || $user->isDeleted() || !$user->isActive()) {
            return null;
        }

        $seen = $token->getLastSeenAt();
        if ($seen === null || $now->getTimestamp() - $seen->getTimestamp() >= self::SEEN_UPDATE_SECONDS) {
            $token->setLastSeenAt($now);
            $this->em->flush();
        }
        return $token;
    }

    public function revoke(DeviceToken $token): void
    {
        $token->revoke(new \DateTimeImmutable());
        $this->em->flush();
    }

    /** İstekten Bearer token'ı okur (Apache / IIS / FastCGI farklı yerlere koyabilir) */
    public static function bearerFromRequest(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('getallheaders') ? (array_change_key_case(getallheaders())['authorization'] ?? null) : null);

        if (!is_string($header) || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return null;
        }
        return $m[1];
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
