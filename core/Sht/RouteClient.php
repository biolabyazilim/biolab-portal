<?php
declare(strict_types=1);

namespace Biolab\Core\Sht;

/**
 * Yol rotası (sürüş) istemcisi — OSRM HTTP API (https://project-osrm.org).
 * Personel "Yola çıktım" dediğinde konumundan firmaya rota bir kez hesaplanır, görevin kaydına yazılır
 * (sht_task.ROUTE_*); haritada gidilen / kalan kısım ve kalan süre buradan gösterilir.
 *
 * Adres config.php'de (yoksa OSRM demo sunucusu):
 *   'routing' => ['url' => 'https://router.project-osrm.org'],
 * DİKKAT: demo sunucusu yalnız deneme içindir (yoğun kullanım yasak, kesintiye uğrayabilir) ve personel konumu
 * şirket dışına gider. Canlıda kendi sunucumuzda OSRM (Türkiye haritasıyla, Docker) kurulup adres değiştirilmeli.
 *
 * Rota çizgisi Google "encoded polyline" (hassasiyet 5) biçiminde saklanır: kısa metin, tarayıcı çözer.
 */
final class RouteClient
{
    private const DEFAULT_URL = 'https://router.project-osrm.org';
    private const TIMEOUT_SECONDS = 8;

    public function __construct(private string $baseUrl) {}

    public static function fromConfig(): self
    {
        static $settings = null;
        $settings ??= require dirname(__DIR__, 2) . '/config.php';
        $url = trim((string) ($settings['routing']['url'] ?? ''));
        return new self(rtrim($url !== '' ? $url : self::DEFAULT_URL, '/'));
    }

    /**
     * İki nokta arası sürüş rotası. Ulaşılamazsa / rota yoksa null (çağıran düz çizgiye düşer).
     * @return array{polyline: string, distanceM: int, durationS: int}|null
     */
    public function route(float $fromLat, float $fromLon, float $toLat, float $toLon): ?array
    {
        $url = sprintf('%s/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=full&geometries=polyline&steps=false',
            $this->baseUrl, $fromLon, $fromLat, $toLon, $toLat);
        $context = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Accept: application/json\r\nUser-Agent: BiolabSahaTakip/1.0\r\n",
            'timeout'       => self::TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
        $data = json_decode((string) $body, true);
        $r = $data['routes'][0] ?? null;
        if (($data['code'] ?? '') !== 'Ok' || !is_array($r) || empty($r['geometry'])) {
            return null;
        }
        return [
            'polyline'  => (string) $r['geometry'],
            'distanceM' => (int) round((float) $r['distance']),
            'durationS' => (int) round((float) $r['duration']),
        ];
    }

    /** Encoded polyline → [[lat, lon], ...] */
    public static function decode(string $encoded): array
    {
        $points = [];
        $index = $lat = $lon = 0;
        $len = strlen($encoded);
        while ($index < $len) {
            foreach (['lat', 'lon'] as $axis) {
                $result = $shift = 0;
                do {
                    $b = ord($encoded[$index++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20 && $index < $len);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                $$axis += $delta;
            }
            $points[] = [$lat / 1e5, $lon / 1e5];
        }
        return $points;
    }

    /** Bir noktanın rota çizgisine en kısa uzaklığı (metre). Rotadan sapma kontrolü için. */
    public static function distanceToRouteM(float $lat, float $lon, array $points): float
    {
        if (count($points) < 2) {
            return INF;
        }
        // Küçük mesafelerde düzlem yaklaşımı yeterli: enlem/boylamı metreye çevir (nokta çevresinde)
        $kx = 111320 * cos(deg2rad($lat));
        $ky = 110540;
        $best = INF;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $ax = ($points[$i - 1][1] - $lon) * $kx; $ay = ($points[$i - 1][0] - $lat) * $ky;
            $bx = ($points[$i][1] - $lon) * $kx;     $by = ($points[$i][0] - $lat) * $ky;
            $dx = $bx - $ax; $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $px = $ax + $t * $dx; $py = $ay + $t * $dy;
            $best = min($best, sqrt($px * $px + $py * $py));
        }
        return $best;
    }
}
