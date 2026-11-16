<?php
declare(strict_types=1);

namespace Biolab\Core\Sht;

use BIO\SHT\DeviceToken;
use BIO\SHT\Personel;
use BIO\SHT\Task;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Telefona bildirim (Firebase Cloud Messaging, HTTP v1). Uygulama kapalıyken de bildirim Android'in kendisi gösterir.
 *
 * Kurulum (bir kez, ayrıntı: saha_takip/docs/bildirim.md):
 *   Firebase konsolu → Proje ayarları → Hizmet hesapları → "Yeni özel anahtar oluştur" → inen JSON dosyası
 *   storage/secrets/firebase-service-account.json olarak kaydedilir (git'e girmez, web'den erişilemez).
 *   Farklı yer istenirse config.php:  'fcm' => ['credentials' => 'C:/…/dosya.json'],
 *
 * Dosya yoksa bildirim gönderilmez, görev ataması normal çalışır (hata vermez, error_log'a bir satır yazar).
 * Gönderim hatası atamayı asla bozmaz: bütün hatalar yakalanır ve loglanır.
 *
 * Firebase'e her istekte kısa ömürlü (1 saat) erişim anahtarı gerekir: hizmet hesabının özel anahtarıyla imzalı
 * JWT → Google OAuth → access_token. Anahtar storage/secrets içinde önbelleğe alınır (her atamada yeniden alınmaz).
 * curl gerekmez (Apache'deki PHP'de yok); openssl eklentisi gerekir.
 */
final class PushService
{
    private const TIMEOUT_SECONDS = 6;
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Android bildirim kanalı (uygulamada aynı adla oluşturulur: lib/services/push_service.dart) */
    public const CHANNEL_TASKS = 'gorevler';

    /** @param array{client_email:string, private_key:string, project_id:string, token_uri?:string} $account */
    private function __construct(private EntityManagerInterface $em, private array $account, private string $cacheFile) {}

    /** Hizmet hesabı dosyası varsa servis, yoksa null */
    public static function fromConfig(EntityManagerInterface $em): ?self
    {
        static $settings = null;
        $root = dirname(__DIR__, 2);
        $settings ??= require $root . '/config.php';
        $file = trim((string) ($settings['fcm']['credentials'] ?? ''));
        if ($file === '') {
            $file = $root . '/storage/secrets/firebase-service-account.json';
        }
        if (!is_file($file)) {
            error_log('[Saha bildirim] Firebase hizmet hesabı dosyası yok, bildirim gönderilmedi: ' . $file);
            return null;
        }
        $account = json_decode((string) file_get_contents($file), true);
        if (!is_array($account) || empty($account['client_email']) || empty($account['private_key']) || empty($account['project_id'])) {
            error_log('[Saha bildirim] Firebase hizmet hesabı dosyası okunamadı: ' . $file);
            return null;
        }
        return new self($em, $account, $root . '/storage/secrets/fcm-access-token.json');
    }

    // ---------- Görev olayları ----------

    /** Görev personele atandı: "Yeni görev · ABC Tekstil" */
    public static function taskAssigned(EntityManagerInterface $em, Task $task): void
    {
        $p = $task->getPersonel();
        if ($p === null) {
            return;
        }
        $c = $task->getCustomer();
        $when = $task->getPlannedAt();
        $end = $task->getPlannedEndAt();
        // "Bugün 10:00–12:00" / "14.11.2026 10:00–12:00"
        $whenText = $when === null ? '' : (
            $when->format('Y-m-d') === date('Y-m-d') ? 'Bugün' : $when->format('d.m.Y')
        ) . ($when->format('H:i') !== '00:00' || $end !== null ? ' ' . $when->format('H:i') : '')
          . ($end !== null ? '–' . $end->format('H:i') : '');
        $body = $task->getTitle() . ($whenText !== '' ? ' · ' . $whenText : '')
              . ($c->getAddress() ? "\n" . $c->getAddress() : '');
        self::fromConfig($em)?->notifyPersonel($p, 'Yeni görev · ' . $c->getCustomerName(), $body, [
            'type'   => 'task_assigned',
            'taskId' => (string) $task->getTaskId(),
        ], 'task-' . $task->getTaskId());
    }

    /** Görev kişiden alındı (iptal ya da başkasına atandı): telefondaki liste yenilensin */
    public static function taskWithdrawn(EntityManagerInterface $em, Task $task, Personel $from, bool $cancelled): void
    {
        self::fromConfig($em)?->notifyPersonel($from,
            ($cancelled ? 'Görev iptal edildi · ' : 'Görev başkasına verildi · ') . $task->getCustomer()->getCustomerName(),
            $task->getTitle(),
            ['type' => 'task_withdrawn', 'taskId' => (string) $task->getTaskId()],
            'task-' . $task->getTaskId());   // aynı etiket: telefondaki "Yeni görev" bildiriminin yerine geçer
    }

    // ---------- Gönderim ----------

    /**
     * Personelin geçerli bütün telefon oturumlarına (bildirim adresi olanlara) gönderir.
     * @param array<string, string> $data uygulamaya giden ek bilgi (dokununca hangi ekran açılacak)
     * @return int başarılı gönderim sayısı
     */
    public function notifyPersonel(Personel $p, string $title, string $body, array $data = [], ?string $tag = null): int
    {
        try {
            $now = new \DateTimeImmutable();
            /** @var DeviceToken[] $tokens */
            $tokens = $this->em->createQuery("
                SELECT t FROM BIO\SHT\DeviceToken t
                 WHERE t.personel = :P AND t.pushToken IS NOT NULL AND t.revokedAt IS NULL AND t.expiresAt > :NOW
                 ORDER BY t.pushTokenAt DESC
            ")->setParameters(['P' => $p, 'NOW' => $now])->getResult();

            $sent = 0;
            $seen = [];
            $dirty = false;
            foreach ($tokens as $t) {
                $addr = (string) $t->getPushToken();
                if (isset($seen[$addr]) || !$t->isUsable($now)) {
                    continue;   // aynı telefon birden çok oturumda kayıtlıysa bir kez
                }
                $seen[$addr] = true;
                $res = $this->send($addr, $title, $body, $data, $tag);
                if ($res === true) {
                    $sent++;
                } elseif ($res === 'invalid') {
                    $t->setPushToken(null, $now);   // uygulama silinmiş / adres yenilenmiş
                    $dirty = true;
                }
            }
            if ($dirty) {
                $this->em->flush();
            }
            return $sent;
        } catch (\Throwable $e) {
            error_log('[Saha bildirim] ' . $e->getMessage());
            return 0;
        }
    }

    /** @return true|'invalid'|false  invalid: Firebase bu adresi artık tanımıyor */
    private function send(string $address, string $title, string $body, array $data, ?string $tag): bool|string
    {
        $android = [
            'priority'     => 'HIGH',
            'notification' => array_filter([
                'channel_id' => self::CHANNEL_TASKS,
                'sound'      => 'default',
                'tag'        => $tag,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ]),
        ];
        $message = ['message' => [
            'token'        => $address,
            'notification' => ['title' => $title, 'body' => $body],
            'data'         => array_map('strval', $data) + ['title' => $title, 'body' => $body],
            'android'      => $android,
        ]];

        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($this->account['project_id']) . '/messages:send';
        [$status, $resp] = $this->http('POST', $url, json_encode($message, JSON_UNESCAPED_UNICODE),
            "Content-Type: application/json; charset=utf-8\r\nAuthorization: Bearer " . $this->accessToken() . "\r\n");

        if ($status === 200) {
            return true;
        }
        $err = json_decode((string) $resp, true)['error'] ?? [];
        $codes = array_column($err['details'] ?? [], 'errorCode');
        if ($status === 404 || in_array('UNREGISTERED', $codes, true)
            || ($status === 400 && str_contains((string) ($err['message'] ?? ''), 'registration token'))) {
            return 'invalid';
        }
        if ($status === 401) {
            @unlink($this->cacheFile);   // erişim anahtarı reddedildi: bir dahakine yenisi alınsın
        }
        error_log('[Saha bildirim] FCM HTTP ' . $status . ' ' . mb_substr((string) $resp, 0, 300));
        return false;
    }

    /** Google OAuth erişim anahtarı (önbellekte varsa ve 5 dk'dan fazla ömrü kaldıysa onu kullanır) */
    private function accessToken(): string
    {
        $cached = is_file($this->cacheFile) ? json_decode((string) @file_get_contents($this->cacheFile), true) : null;
        if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 300 && ($cached['sub'] ?? '') === $this->account['client_email']) {
            return (string) $cached['token'];
        }

        $now = time();
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $tokenUri = $this->account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
            'iss'   => $this->account['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $tokenUri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $key = openssl_pkey_get_private($this->account['private_key']);
        if ($key === false || !openssl_sign($unsigned, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Firebase hizmet hesabı anahtarı ile imza atılamadı.');
        }
        [$status, $resp] = $this->http('POST', $tokenUri, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . $b64($sig),
        ]), "Content-Type: application/x-www-form-urlencoded\r\n");
        $json = json_decode((string) $resp, true);
        if ($status !== 200 || empty($json['access_token'])) {
            throw new \RuntimeException('Google erişim anahtarı alınamadı (HTTP ' . $status . '): ' . mb_substr((string) $resp, 0, 200));
        }
        @file_put_contents($this->cacheFile, json_encode([
            'token' => $json['access_token'],
            'exp'   => $now + (int) ($json['expires_in'] ?? 3600),
            'sub'   => $this->account['client_email'],
        ]), LOCK_EX);
        return (string) $json['access_token'];
    }

    /** @return array{0:int, 1:string|false} [HTTP durum kodu, gövde] */
    private function http(string $method, string $url, string $body, string $headers): array
    {
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => $headers,
            'content'       => $body,
            'timeout'       => self::TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            throw new \RuntimeException('Firebase / Google sunucusuna ulaşılamadı.');
        }
        $statusLine = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers()[0] ?? '') : '';
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m) ? (int) $m[1] : 0;
        return [$status, $resp];
    }
}
