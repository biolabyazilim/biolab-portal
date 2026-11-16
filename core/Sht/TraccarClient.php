<?php
declare(strict_types=1);

namespace Biolab\Core\Sht;

/**
 * Traccar REST API istemcisi (sunucu tarafı). Tarayıcı Traccar'a hiç bağlanmaz; harita verisi portal üzerinden gelir.
 *
 * Bağlantı bilgisi config.php'de (git'e girmez):
 *   'traccar' => [
 *       'url'   => 'http://127.0.0.1:8082',   // Traccar web/API adresi (portal ile aynı makinedeyse 127.0.0.1)
 *       'token' => '…',                       // Traccar → Ayarlar → Hesap → Token (yönetici hesabıyla üretilir)
 *   ],
 *
 * Traccar zamanları UTC'dir ("2026-11-11T07:15:00.000+00:00"); bu sınıf dönüşüm yapmaz, çağıran yerel saate çevirir.
 */
final class TraccarClient
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(private string $baseUrl, private string $token) {}

    /** config.php'deki 'traccar' ayarıyla istemci; ayar yoksa null (ekran anlaşılır mesaj gösterir) */
    public static function fromConfig(): ?self
    {
        static $settings = null;
        $settings ??= require dirname(__DIR__, 2) . '/config.php';
        $url   = trim((string) ($settings['traccar']['url'] ?? ''));
        $token = trim((string) ($settings['traccar']['token'] ?? ''));
        return ($url === '' || $token === '') ? null : new self(rtrim($url, '/'), $token);
    }

    /** @return list<array<string, mixed>> Kullanıcının gördüğü bütün cihazlar (id, name, uniqueId, status, lastUpdate…) */
    public function devices(): array
    {
        return $this->get('/api/devices');
    }

    /** @return list<array<string, mixed>> Her cihazın son konumu (deviceId, latitude, longitude, speed[knot], fixTime…) */
    public function latestPositions(): array
    {
        return $this->get('/api/positions');
    }

    /**
     * Bir cihazın iki zaman arasındaki bütün konumları (rota), eskiden yeniye.
     * @return list<array<string, mixed>>
     */
    public function route(int $deviceId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->get('/api/positions', [
            'deviceId' => $deviceId,
            'from'     => self::utc($from),
            'to'       => self::utc($to),
        ]);
    }

    /**
     * PHP'nin kendi HTTP desteğiyle (curl eklentisi gerekmez; Apache'deki PHP'de curl yüklü değil).
     * @param array<string, scalar> $query
     */
    private function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $context = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Accept: application/json\r\nAuthorization: Bearer " . $this->token . "\r\n",
            'timeout'       => self::TIMEOUT_SECONDS,
            'ignore_errors' => true,   // 4xx/5xx'te de gövdeyi ve durum kodunu al
        ]]);
        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new \RuntimeException('Traccar sunucusuna ulaşılamadı (' . $this->baseUrl . ').');
        }
        // İlk başlık satırı: "HTTP/1.1 200 OK"
        $statusLine = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers()[0] ?? '') : '';
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m) ? (int) $m[1] : 200;
        if ($status === 401) {
            throw new \RuntimeException('Traccar anahtarı (token) geçersiz. config.php → traccar.token kontrol edin.');
        }
        if ($status >= 400) {
            throw new \RuntimeException('Traccar hata döndü (HTTP ' . $status . ').');
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Traccar cevabı okunamadı.');
        }
        return $data;
    }

    private static function utc(\DateTimeInterface $t): string
    {
        return \DateTimeImmutable::createFromInterface($t)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
