<?php
declare(strict_types=1);

/**
 * Biolab Saha Takip — mobil uygulama API'si
 *   POST ?action=login   { username, password, deviceInfo?, appVersion? }  → { ok, token, expiresAt, personel }
 *   GET  ?action=me      Authorization: Bearer <token>                     → { ok, personel }
 *   POST ?action=logout  Authorization: Bearer <token>                     → { ok }
 *   POST ?action=shiftStart  { clientUuid, startedAt(unix sn), lat?, lon? }  → { ok, shiftId, startedAt, endedAt }
 *   POST ?action=shiftEnd    { clientUuid, startedAt, endedAt, lat?, lon?, endLat?, endLon? } → aynı
 *        Telefon mesai olaylarını kuyrukta biriktirip internet gelince gönderir: aynı olay tekrar gelebilir
 *        (clientUuid ile tek kayıt), bitiş başlangıçtan önce ulaşabilir (bitiş gelince mesai açılıp kapatılır).
 *   GET  ?action=taskList                                                  → { ok, tasks: [...] }
 *        Personelin bitmemiş görevleri + bugün tamamladıkları (firma adı, adres, telefon, konum ile)
 *   POST ?action=taskStatus  { taskId, status: ON_THE_WAY|ON_SITE|DONE, at(unix sn), lat?, lon?, note?, earlyReason? } → { ok, task }
 *        Aynı adım tekrar gelirse değişiklik yapmadan ok döner (kuyruk). İptal edilmiş görev → 409 (telefon bırakır).
 *        Saat aralığı (plannedAt–plannedEndAt) olan görevde: ON_SITE başlangıçtan önce → 409; DONE bitişten önce
 *        yalnız earlyReason (en az 5 karakter) ile → erken bitti olarak kaydedilir, yoksa 409. 2 dk tolerans.
 *   POST ?action=pushToken   { pushToken }  → { ok }
 *        Telefonun Firebase bildirim adresi bu oturuma kaydedilir (boş → bildirim kapalı). Görev atanınca buraya
 *        bildirim gider (Biolab\Core\Sht\PushService). Aynı adres başka (eski) oturumlarda varsa oradan silinir.
 *
 * Web uygulamalarından farkı: PHP oturumu (çerez) ve CSRF YOK. Telefon girişte aldığı token'ı her istekte
 * "Authorization: Bearer …" başlığıyla gönderir. Token geçersizse 401 döner → telefon giriş ekranına döner.
 * Giriş için kullanıcının bu uygulamaya ait rolü (mng_role.APP_CODE = biolab-saha-takip) ve
 * sht_personel kaydı olmalı. Şifre kontrolü / kilitleme AuthService ile web girişiyle aynıdır.
 */

use Biolab\Core\Auth\AuthService;
use Biolab\Core\Http\JsonResponse;
use Biolab\Core\Sht\DeviceTokenService;
use BIO\SHT\DeviceToken;
use BIO\SHT\Personel;

const SHT_APP_CODE = 'biolab-saha-takip';

$root = dirname(__DIR__, 2);   // public/api → proje kökü
require $root . '/vendor/autoload.php';

$action = $_GET['action'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/** Telefona dönen personel bilgisi */
function shtPersonelInfo(Personel $p, DeviceToken|\BIO\MNG\Company $ctx): array
{
    $company = $ctx instanceof DeviceToken ? $ctx->getCompany() : $ctx;
    return [
        'personelCode' => $p->getPersonelCode(),
        'fullName'     => $p->getUser()->getFullName(),
        'username'     => $p->getUser()->getUsername(),
        'jobType'      => $p->getJobType(),
        'companyName'  => $company?->getCompanyName(),
    ];
}

/**
 * AuditListener C_BY / U_BY ve COMPANY_ID'yi $_SESSION'dan okur. Bu API oturum açmaz (session_start yok);
 * dizi yalnız bu isteğin belleğinde durur, çerez / dosya oluşmaz.
 */
function shtActAs(int $userId, int $companyId): void
{
    $_SESSION = ['USER_ID' => $userId, 'COMPANY_ID' => $companyId];
}

/**
 * Telefonun gönderdiği zaman (Unix saniye). Telefon olayları kuyrukta bekletip geç gönderebilir; bu yüzden
 * olayın portala ulaştığı an değil telefondaki an kullanılır. Gelecekten (telefon saati ileri) 10 dk'dan fazla
 * ya da 7 günden eski zamanlar güvenilmez sayılır → portal saati.
 */
function shtTime(mixed $raw): ?\DateTimeImmutable
{
    if ($raw === null || $raw === '' || !ctype_digit((string) $raw)) {
        return null;
    }
    $now = new \DateTimeImmutable();
    $t   = (new \DateTimeImmutable('@' . (int) $raw))->setTimezone($now->getTimezone());
    $diff = $t->getTimestamp() - $now->getTimestamp();
    return ($diff > 600 || $diff < -7 * 86400) ? $now : $t;
}

/** Enlem / boylam; geçersizse null */
function shtCoord(mixed $raw, int $limit): ?float
{
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return null;
    }
    $v = (float) $raw;
    return abs($v) <= $limit ? $v : null;
}

/**
 * CLIENT_UUID ile mesaiyi bulur, yoksa açar. Aynı olay tekrar gelirse aynı kayıt döner (çift kayıt yok).
 * Yeni mesai açılırken personelin açık kalmış başka mesaisi varsa, yeninin başlangıç saatiyle kapatılır.
 */
function shtUpsertShift(\Doctrine\ORM\EntityManagerInterface $em, DeviceToken $token, string $uuid,
                        \DateTimeImmutable $startedAt, ?float $lat, ?float $lon): \BIO\SHT\Shift
{
    $repo  = $em->getRepository(\BIO\SHT\Shift::class);
    $shift = $repo->findOneBy(['clientUuid' => $uuid]);
    if ($shift !== null) {
        if ($shift->getPersonel() !== $token->getPersonel()) {
            JsonResponse::send(['ok' => false, 'message' => 'Mesai başka bir personele ait.'], 403);
        }
        return $shift;
    }

    foreach ($repo->findBy(['personel' => $token->getPersonel(), 'endedAt' => null]) as $open) {
        if (!$open->isDeleted()) {
            $open->close($startedAt, \BIO\SHT\Shift::END_REPLACED);
        }
    }

    $shift = new \BIO\SHT\Shift($token->getPersonel(), $token->getCompany(), $uuid, $startedAt);
    $shift->setStartLocation($lat, $lon);
    $em->persist($shift);
    return $shift;
}

/** Telefona giden görev bilgisi */
function shtTaskInfo(\BIO\SHT\Task $t): array
{
    $c = $t->getCustomer();
    $iso = fn (?\DateTimeImmutable $d) => $d?->format(DATE_ATOM);
    return [
        'taskId'      => $t->getTaskId(),
        'title'       => $t->getTitle(),
        'description' => $t->getDescription(),
        'plannedAt'   => $iso($t->getPlannedAt()),      // başlangıç: "Vardım" bundan önce basılamaz
        'plannedEndAt' => $iso($t->getPlannedEndAt()),  // bitiş: "Tamamladım" bundan önce yalnız gerekçeyle (erken bitir)
        'doneEarly'   => $t->isDoneEarly(),
        'earlyReason' => $t->getEarlyReason(),
        'status'      => $t->getStatus(),
        'onTheWayAt'  => $iso($t->getOnTheWayAt()),
        'arrivedAt'   => $iso($t->getArrivedAt()),
        'doneAt'      => $iso($t->getDoneAt()),
        'customer'    => [
            'name'        => $c->getCustomerName(),
            'address'     => $c->getAddress(),
            'city'        => $c->getCity(),
            'phone'       => $c->getPhone(),
            'contactName' => $c->getContactName(),
            'lat'         => $c->getLat(),
            'lon'         => $c->getLon(),
        ],
    ];
}

/** Personelin bitmemiş görevleri + bugün tamamladıkları. Sıra: firmada, yolda, sonra planlanan saate göre. */
function shtTaskList(\Doctrine\ORM\EntityManagerInterface $em, Personel $p): array
{
    $tasks = $em->createQuery("
        SELECT t, c FROM BIO\SHT\Task t JOIN t.customer c
         WHERE t.personel = :P AND t.recordFl > 0
           AND (t.status IN ('ASSIGNED', 'ON_THE_WAY', 'ON_SITE') OR (t.status = 'DONE' AND t.doneAt >= :TODAY))
    ")->setParameters(['P' => $p, 'TODAY' => new \DateTimeImmutable('today')])->getResult();

    $rank = ['ON_SITE' => 0, 'ON_THE_WAY' => 1, 'ASSIGNED' => 2, 'DONE' => 3];
    usort($tasks, function (\BIO\SHT\Task $a, \BIO\SHT\Task $b) use ($rank) {
        return [$rank[$a->getStatus()] ?? 9, $a->getPlannedAt()?->getTimestamp() ?? PHP_INT_MAX, $a->getTaskId()]
           <=> [$rank[$b->getStatus()] ?? 9, $b->getPlannedAt()?->getTimestamp() ?? PHP_INT_MAX, $b->getTaskId()];
    });
    return array_map('shtTaskInfo', $tasks);
}

/** İki nokta arası kuş uçuşu mesafe (metre) */
function shtDistanceM(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

try {
    /** @var \Doctrine\ORM\EntityManagerInterface $em */
    $em     = require $root . '/bootstrap.php';
    $tokens = new DeviceTokenService($em);

    switch ($action) {
        case 'login':
            if (!$isPost) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz istek.'], 405);
            }
            $check = (new AuthService($em))->checkCredentials(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                SHT_APP_CODE
            );
            if (is_string($check)) {
                JsonResponse::send(['ok' => false, 'message' => $check], 401);
            }
            ['user' => $user, 'company' => $company] = $check;

            $personel = $em->getRepository(Personel::class)->findOneBy(['user' => $user]);
            if ($personel === null || $personel->isDeleted() || !$personel->isActive()) {
                JsonResponse::send([
                    'ok'      => false,
                    'message' => 'Saha takip için personel kaydınız yok. Yöneticinize başvurun.',
                ], 403);
            }

            shtActAs($user->getUserId(), $company->getCompanyId());
            $issued = $tokens->issue(
                $personel,
                $company,
                isset($_POST['deviceInfo']) ? (string) $_POST['deviceInfo'] : null,
                isset($_POST['appVersion']) ? (string) $_POST['appVersion'] : null
            );
            $user->markLogin();
            $em->flush();

            JsonResponse::send([
                'ok'        => true,
                'token'     => $issued['token'],
                'expiresAt' => $issued['entity']->getExpiresAt()->format(DATE_ATOM),
                'personel'  => shtPersonelInfo($personel, $company),
            ]);

        case 'me':
        case 'logout':
        case 'shiftStart':
        case 'shiftEnd':
        case 'taskList':
        case 'taskStatus':
        case 'pushToken':
            $token = $tokens->authenticate(DeviceTokenService::bearerFromRequest());
            if ($token === null) {
                JsonResponse::send(['ok' => false, 'message' => 'Oturumunuz sona erdi. Tekrar giriş yapın.'], 401);
            }
            shtActAs($token->getPersonel()->getUser()->getUserId(), $token->getCompany()->getCompanyId());

            if ($action === 'me') {
                JsonResponse::send(['ok' => true, 'personel' => shtPersonelInfo($token->getPersonel(), $token)]);
            }
            if ($action === 'taskList') {
                JsonResponse::send(['ok' => true, 'tasks' => shtTaskList($em, $token->getPersonel())]);
            }
            if (!$isPost) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz istek.'], 405);
            }
            if ($action === 'logout') {
                $tokens->revoke($token);
                JsonResponse::send(['ok' => true]);
            }

            // --- Bildirim adresi (Firebase) ---
            if ($action === 'pushToken') {
                $addr = trim((string) ($_POST['pushToken'] ?? ''));
                if (strlen($addr) > 400 || ($addr !== '' && !preg_match('/^[A-Za-z0-9_:\-\.]+$/', $addr))) {
                    JsonResponse::send(['ok' => false, 'message' => 'Geçersiz bildirim adresi.'], 400);
                }
                $now = new \DateTimeImmutable();
                if ($addr !== '') {
                    // Aynı telefon eski bir oturumda da kayıtlıysa (ör. çıkış yapmadan yeniden giriş) oradan sil:
                    // bir telefona aynı bildirim iki kez gitmesin, başka personelin görevi bu telefona düşmesin.
                    foreach ($em->getRepository(DeviceToken::class)->findBy(['pushToken' => $addr]) as $other) {
                        if ($other !== $token) { $other->setPushToken(null, $now); }
                    }
                }
                $token->setPushToken($addr !== '' ? $addr : null, $now);
                $em->flush();
                JsonResponse::send(['ok' => true]);
            }

            // --- Görev adımı (Yola çıktım / Vardım / Tamamladım) ---
            if ($action === 'taskStatus') {
                $task = $em->getRepository(\BIO\SHT\Task::class)->find((int) ($_POST['taskId'] ?? 0));
                if ($task === null || $task->isDeleted() || $task->getPersonel() !== $token->getPersonel()) {
                    JsonResponse::send(['ok' => false, 'message' => 'Görev bulunamadı ya da size atanmamış.'], 404);
                }
                if ($task->getStatus() === \BIO\SHT\Task::CANCELLED) {
                    JsonResponse::send(['ok' => false, 'message' => 'Bu görev iptal edildi.'], 409);
                }
                $new = (string) ($_POST['status'] ?? '');
                $newIdx = \BIO\SHT\Task::flowIndex($new);
                if ($newIdx < 1) {   // ASSIGNED (0) telefondan gönderilmez
                    JsonResponse::send(['ok' => false, 'message' => 'Geçersiz görev adımı.'], 400);
                }
                // Aynı ya da geride kalan adım tekrar geldiyse (kuyruktan): değişiklik yok
                if ($newIdx > \BIO\SHT\Task::flowIndex($task->getStatus())) {
                    $at  = shtTime($_POST['at'] ?? null) ?? new \DateTimeImmutable();

                    // Saat aralığı kuralı (telefon da uygular; burada telefon saati / eski sürüm için). 2 dk tolerans.
                    $earlyReason = null;
                    if ($task->hasTimeWindow()) {
                        $grace = new \DateInterval('PT2M');
                        if ($new === \BIO\SHT\Task::ON_SITE && $at < $task->getPlannedAt()->sub($grace)) {
                            JsonResponse::send(['ok' => false, 'message' => 'Görev saati gelmedi. "Vardım" '
                                . $task->getPlannedAt()->format('d.m.Y H:i') . ' itibarıyla açılır.'], 409);
                        }
                        if ($new === \BIO\SHT\Task::DONE && $at < $task->getPlannedEndAt()->sub($grace)) {
                            $reason = trim((string) ($_POST['earlyReason'] ?? ''));
                            if (mb_strlen($reason) < 5) {
                                JsonResponse::send(['ok' => false, 'message' => 'Görev bitiş saati '
                                    . $task->getPlannedEndAt()->format('H:i') . '. Daha önce bitirmek için "Erken bitir" ile gerekçe yazın.'], 409);
                            }
                            $earlyReason = $reason;
                        }
                    }
                    $lat = shtCoord($_POST['lat'] ?? null, 90);
                    $lon = shtCoord($_POST['lon'] ?? null, 180);
                    $fromStatus = $task->getStatus();
                    if ($new === \BIO\SHT\Task::ON_THE_WAY) {
                        $task->markOnTheWay($at);
                    } elseif ($new === \BIO\SHT\Task::ON_SITE) {
                        $c = $task->getCustomer();
                        $dist = ($lat !== null && $c->getLat() !== null)
                            ? (int) round(shtDistanceM($lat, $lon, $c->getLat(), $c->getLon())) : null;
                        $task->markArrived($at, $lat, $lon, $dist);
                    } else {
                        $task->markDone($at, $lat, $lon, isset($_POST['note']) ? trim((string) $_POST['note']) : null, $earlyReason);
                    }
                    // Görev geçmişi: olayın telefondaki zamanı (kuyruktan geç gelse de), kim = personelin kullanıcısı
                    $logNote = $new === \BIO\SHT\Task::ON_SITE && $task->getArriveDistanceM() !== null
                        ? 'Firmaya uzaklık: ' . $task->getArriveDistanceM() . ' m'
                        : ($new === \BIO\SHT\Task::DONE
                            ? implode(' · ', array_filter([
                                $earlyReason !== null ? 'ERKEN BİTİRDİ (bitiş ' . $task->getPlannedEndAt()->format('H:i') . '): ' . $earlyReason : null,
                                $task->getDoneNote(),
                            ]))
                            : null);
                    \Biolab\Core\Sht\TaskHistory::record($em, $task, $new, \BIO\SHT\TaskLog::SRC_MOBILE,
                        $fromStatus, $task->getPersonel(), $at, $logNote);
                    $em->flush();
                }
                JsonResponse::send(['ok' => true, 'task' => shtTaskInfo($task)]);
            }

            // --- Mesai olayları ---
            $uuid = strtolower(trim((string) ($_POST['clientUuid'] ?? '')));
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
                JsonResponse::send(['ok' => false, 'message' => 'Geçersiz mesai anahtarı.'], 400);
            }
            $startedAt = shtTime($_POST['startedAt'] ?? null);
            if ($startedAt === null) {
                JsonResponse::send(['ok' => false, 'message' => 'Mesai başlangıç saati eksik.'], 400);
            }
            $shift = shtUpsertShift($em, $token, $uuid, $startedAt, shtCoord($_POST['lat'] ?? null, 90), shtCoord($_POST['lon'] ?? null, 180));

            if ($action === 'shiftEnd' && $shift->isOpen()) {
                $endedAt = shtTime($_POST['endedAt'] ?? null) ?? new \DateTimeImmutable();
                $shift->close($endedAt, \BIO\SHT\Shift::END_MANUAL,
                    shtCoord($_POST['endLat'] ?? null, 90), shtCoord($_POST['endLon'] ?? null, 180));
            }
            $em->flush();

            JsonResponse::send([
                'ok'        => true,
                'shiftId'   => $shift->getShiftId(),
                'startedAt' => $shift->getStartedAt()->format(DATE_ATOM),
                'endedAt'   => $shift->getEndedAt()?->format(DATE_ATOM),
            ]);

        default:
            JsonResponse::send(['ok' => false, 'message' => 'Bilinmeyen işlem.'], 400);
    }
} catch (\Throwable $e) {
    error_log('[Saha API] ' . $e);
    JsonResponse::send(['ok' => false, 'message' => 'Beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.'], 500);
}
