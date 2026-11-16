<?php
/**
 * Saha Takip — Canlı harita veri kaynağı
 *
 *   getLive   → şirketin aktif saha personeli + Traccar'daki son konumları (harita işaretleri ve soldaki liste)
 *   getRoute  → seçilen personelin seçilen gündeki rotası (Traccar konum geçmişi) ve özet (mesafe, ilk/son konum)
 *
 * Konum verisi SQL Server'a kopyalanmaz; her istekte sunucu tarafında Traccar API'sinden okunur (Biolab\Core\Sht\TraccarClient).
 * Tarayıcı Traccar'a bağlanmaz, Traccar anahtarı tarayıcıya gitmez.
 * Personel ↔ Traccar cihazı: önce sht_personel.TRACCAR_DEVICE_ID, yoksa PERSONEL_CODE = cihaz Identifier (uniqueId).
 * Saatler Türkiye saatiyle döner (Traccar UTC verir).
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use Biolab\Core\Sht\RouteClient;
use Biolab\Core\Sht\TaskHistory;
use Biolab\Core\Sht\TraccarClient;
use BIO\SHT\Personel;
use BIO\SHT\TaskLog;

$db = $dbm->getDB();
$resourceFile = "sht_harita/harita-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

const SHT_TZ = 'Europe/Istanbul';
const SHT_ONLINE_MINUTES = 10;   // son konumu bu kadar dakikadan yeni olan "çevrimiçi" sayılır (mobil 2 dk'da bir gönderir)
const SHT_TRAIL_MINUTES = 30;    // canlı haritadaki hareket izinin uzunluğu
const SHT_MOVE_METERS = 40;      // son iki nokta arası bundan fazlaysa "hareket halinde" (GPS sapması ±20 m)
const SHT_OFFROUTE_M = 300;      // personel yol rotasına bundan uzaksa "rotadan saptı" → rota yeniden hesaplanır
const SHT_REROUTE_SECONDS = 120; // yeniden hesaplama en sık bu aralıkla (rota servisini yormamak için)

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    case "getLive":
        $execType = ET_DEFAULT;
        try{
            $traccar = TraccarClient::fromConfig();
            if ($traccar === null) {
                $resCode = 1;
                $resMsg = $lang["ERR_TRACCAR_CONFIG"];
                break;
            }

            $personel = $db->createQuery("
                SELECT mt.personelId, mt.personelCode, mt.jobType, mt.traccarDeviceId, u.fullName
                  FROM BIO\SHT\Personel mt
                  JOIN mt.user u
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.recordFl > 0
                   AND mt.activeFl = 'Y'
                 ORDER BY u.fullName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();

            // Traccar'dan iki istek: cihazlar (uniqueId eşleştirmesi için) ve her cihazın son konumu
            $devicesById = array();
            $deviceIdByCode = array();
            foreach ($traccar->devices() as $d) {
                $devicesById[(int) $d["id"]] = $d;
                $deviceIdByCode[strtoupper((string) $d["uniqueId"])] = (int) $d["id"];
            }
            $posByDevice = array();
            foreach ($traccar->latestPositions() as $p) {
                $posByDevice[(int) $p["deviceId"]] = $p;
            }

            // Açık mesailer: personelId → başlangıç (mesaide olan / olmayan ayrımı)
            $openShifts = array();
            foreach ($db->createQuery("
                SELECT IDENTITY(s.personel) personelId, s.startedAt
                  FROM BIO\SHT\Shift s
                 WHERE IDENTITY(s.company) = :COMPANY_ID
                   AND s.endedAt IS NULL
                   AND s.recordFl > 0
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult() as $s) {
                $openShifts[(int) $s["personelId"]] = $s["startedAt"];
            }

            // Personelin şu anki görevi (Yolda / Firmada): Planlama'da "Görevde" grubu ve firmaya ok için.
            // Birden çoksa Firmada olan öne geçer.
            $activeTasks = array();
            foreach ($db->createQuery("
                SELECT IDENTITY(t.personel) personelId, t.taskId, t.status, t.title,
                       c.customerId, c.customerName, c.lat, c.lon,
                       t.routePolyline, t.routeDistanceM, t.routeDurationS, t.routeAt, t.routeCount
                  FROM BIO\SHT\Task t JOIN t.customer c
                 WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0
                   AND t.status IN ('ON_THE_WAY', 'ON_SITE')
                 ORDER BY t.status DESC
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult() as $t) {
                $pid = (int) $t["personelId"];
                if (!isset($activeTasks[$pid])) {   // ON_SITE > ON_THE_WAY (DESC sırası)
                    $activeTasks[$pid] = array(
                        "taskId"       => $t["taskId"],
                        "status"       => $t["status"],
                        "title"        => $t["title"],
                        "customerId"   => $t["customerId"],
                        "customerName" => $t["customerName"],
                        "lat"          => $t["lat"] !== null ? (float) $t["lat"] : null,
                        "lon"          => $t["lon"] !== null ? (float) $t["lon"] : null,
                        "route"        => $t["routePolyline"] === null ? null : array(
                            "polyline"  => $t["routePolyline"],
                            "distanceM" => $t["routeDistanceM"],
                            "durationS" => $t["routeDurationS"],
                            "count"     => $t["routeCount"],
                        ),
                        "_routeAt"     => $t["routeAt"],   // istemciye gitmez (aşağıda silinir)
                    );
                }
            }

            $now = new \DateTimeImmutable("now", new \DateTimeZone(SHT_TZ));
            $dtResponse = array();
            foreach ($personel as $r) {
                $deviceId = $r["traccarDeviceId"] ?: ($deviceIdByCode[strtoupper($r["personelCode"])] ?? null);
                $device   = $deviceId !== null ? ($devicesById[$deviceId] ?? null) : null;
                $pos      = $deviceId !== null ? ($posByDevice[$deviceId] ?? null) : null;

                $row = array(
                    "personelId"   => $r["personelId"],
                    "personelCode" => $r["personelCode"],
                    "fullName"     => $r["fullName"],
                    "jobType"      => $r["jobType"],
                    "deviceId"     => $device !== null ? $deviceId : null,
                    "status"       => $device === null ? "nodevice" : ($pos === null ? "nodata" : "offline"),
                    "lat"          => null,
                    "lon"          => null,
                    "speedKmh"     => null,
                    "accuracy"     => null,
                    "fixTime"      => null,
                    "minutesAgo"   => null,
                    "activeTask"   => $activeTasks[(int) $r["personelId"]] ?? null,
                    "onShift"      => isset($openShifts[$r["personelId"]]),
                    "shiftSince"   => isset($openShifts[$r["personelId"]])
                        ? $openShifts[$r["personelId"]]->setTimezone(new \DateTimeZone(SHT_TZ))->format("H:i") : null,
                );
                if ($pos !== null) {
                    $fix = shtLocal((string) $pos["fixTime"]);
                    $minutes = (int) floor(($now->getTimestamp() - $fix->getTimestamp()) / 60);
                    $row["lat"]        = (float) $pos["latitude"];
                    $row["lon"]        = (float) $pos["longitude"];
                    $row["speedKmh"]   = (int) round(((float) $pos["speed"]) * 1.852);   // Traccar hızı knot
                    $row["accuracy"]   = isset($pos["accuracy"]) ? (int) round((float) $pos["accuracy"]) : null;
                    $row["fixTime"]    = $fix->format("Y-m-d H:i:s");
                    $row["minutesAgo"] = max(0, $minutes);
                    $row["status"]     = $minutes <= SHT_ONLINE_MINUTES ? "online" : "offline";
                }

                // Hareket izi: mesaide ve konumu taze olanların son 30 dk'sı (haritada ince kesik çizgi).
                // Yön (heading): izin son iki ayrık noktasından (telefon pusula bilgisi göndermiyor).
                $row["trail"]   = array();
                $row["heading"] = null;
                $row["moving"]  = false;
                if ($row["onShift"] && $row["status"] === "online") {
                    foreach ($traccar->route((int) $deviceId, $now->modify("-" . SHT_TRAIL_MINUTES . " minutes"), $now) as $p) {
                        $lat = (float) $p["latitude"];
                        $lon = (float) $p["longitude"];
                        if (abs($lat) < 0.0001 && abs($lon) < 0.0001) { continue; }
                        $row["trail"][] = array(round($lat, 6), round($lon, 6));
                    }
                    $n = count($row["trail"]);
                    if ($n >= 2) {
                        [$aLat, $aLon] = $row["trail"][$n - 2];
                        [$bLat, $bLon] = $row["trail"][$n - 1];
                        $moved = shtDistanceM($aLat, $aLon, $bLat, $bLon);
                        // GPS sapması (±20 m) hareket sayılmasın: 40 m'den fazla yer değiştirdiyse ya da hız ≥ 5 km/sa
                        $row["moving"] = $moved > SHT_MOVE_METERS || ($row["speedKmh"] ?? 0) >= 5;
                        if ($row["moving"] && $moved > 1) {
                            $row["heading"] = (int) round(shtBearing($aLat, $aLon, $bLat, $bLon));
                        }
                    }
                }

                // Yolda ise yol rotası (yoksa / rotadan saptıysa hesaplanır)
                if ($row["activeTask"] !== null) {
                    $row["activeTask"] = shtEnsureRoute($db, $row["activeTask"], $row["lat"], $row["lon"], $now);
                }
                $dtResponse[] = $row;
            }

            $resObject = array("serverTime" => $now->format("Y-m-d H:i:s"), "onlineMinutes" => SHT_ONLINE_MINUTES);
            if (count($dtResponse) > 0) {
                $resCode = 0;
                $resMsg = "Ok";
            } else {
                $resCode = 21;
                $resMsg = $lang["NO_PERSONEL"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getMessage();
        }
        break;

    // Planlama: aktif firmalar (konumlu olanlar haritada) + her firmanın bitmemiş görevleri.
    // state: NONE (açık görev yok) · WAITING (atanmadı / atandı / yolda) · ON_SITE (personel şu an firmada)
    case "getCustomers":
        $execType = ET_DEFAULT;
        try{
            $customers = $db->createQuery("
                SELECT c.customerId, c.customerName, c.address, c.city, c.lat, c.lon,
                       c.contactName, c.phone, c.isgKatipNo
                  FROM BIO\SHT\Customer c
                 WHERE IDENTITY(c.company) = :COMPANY_ID AND c.recordFl > 0 AND c.activeFl = 'Y'
                 ORDER BY c.customerName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();

            $tasks = array();
            foreach ($db->createQuery("
                SELECT IDENTITY(t.customer) customerId, t.taskId, t.title, t.status, t.plannedAt, t.plannedEndAt, t.arrivedAt,
                       p.personelCode, u.fullName
                  FROM BIO\SHT\Task t LEFT JOIN t.personel p LEFT JOIN p.user u
                 WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0
                   AND t.status NOT IN ('DONE', 'CANCELLED')
                 ORDER BY t.plannedAt ASC
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult() as $t) {
                $tasks[(int) $t["customerId"]][] = array(
                    "taskId"    => $t["taskId"],
                    "title"     => $t["title"],
                    "status"    => $t["status"],
                    "plannedAt" => $t["plannedAt"]?->format("Y-m-d H:i"),
                    "plannedEndAt" => $t["plannedEndAt"]?->format("Y-m-d H:i"),
                    "arrivedAt" => $t["status"] === "ON_SITE" ? $t["arrivedAt"]?->format("Y-m-d H:i:s") : null,
                    "personel"  => $t["fullName"] ? $t["fullName"] . " (" . $t["personelCode"] . ")" : null,
                );
            }

            $dtResponse = array();
            foreach ($customers as $c) {
                $list = $tasks[(int) $c["customerId"]] ?? array();
                $statuses = array_column($list, "status");
                // Firmadaki personelin en erken "Vardım" saati (haritada "· 25 dk" için)
                $arrivals = array_filter(array_column($list, "arrivedAt"));
                $dtResponse[] = array(
                    "onSiteSince"  => $arrivals ? min($arrivals) : null,
                    "customerId"   => $c["customerId"],
                    "customerName" => $c["customerName"],
                    "address"      => $c["address"],
                    "city"         => $c["city"],
                    "lat"          => $c["lat"] !== null ? (float) $c["lat"] : null,
                    "lon"          => $c["lon"] !== null ? (float) $c["lon"] : null,
                    "contactName"  => $c["contactName"],
                    "phone"        => $c["phone"],
                    "isgKatipNo"   => $c["isgKatipNo"],
                    "tasks"        => $list,
                    "state"        => in_array("ON_SITE", $statuses, true) ? "ON_SITE" : ($list ? "WAITING" : "NONE"),
                );
            }
            $resCode = count($dtResponse) > 0 ? 0 : 21;
            $resMsg = $resCode === 0 ? "Ok" : $lang["NO_CUSTOMERS"];
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getMessage();
        }
        break;

    // Planlama → firma kartından hızlı görev atama: yeni görev açar ve personele atar
    case "setAssign":
        $execType = ET_SAVE;
        try{
            $customer = $db->getRepository(BIO\SHT\Customer::class)->findOneBy(array("customerId" => (int) ($_POST["CUSTOMER_ID"] ?? 0), "company" => $companyId));
            $personel = $db->getRepository(Personel::class)->findOneBy(array("personelId" => (int) ($_POST["PERSONEL_ID"] ?? 0), "company" => $companyId));
            $title = trim((string) ($_POST["TITLE"] ?? ""));
            $desc  = trim((string) ($_POST["DESCRIPTION"] ?? ""));
            $day   = \DateTimeImmutable::createFromFormat("!Y-m-d", (string) ($_POST["PLANNED_DATE"] ?? ""));
            $time  = trim((string) ($_POST["PLANNED_TIME"] ?? ""));       // firmada başlangıç (zorunlu)
            $end   = trim((string) ($_POST["PLANNED_END_TIME"] ?? ""));   // firmada bitiş (zorunlu)

            $error = null;
            if ($customer === null || $customer->isDeleted()) { $error = $lang["ERR_CUSTOMER"]; }
            elseif ($personel === null || $personel->isDeleted() || !$personel->isActive()) { $error = $lang["ERR_PERSONEL"]; }
            elseif ($title === "" || mb_strlen($title) > 200) { $error = $lang["ERR_TITLE"]; }
            elseif ($day === false) { $error = $lang["ERR_DATE"]; }
            elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) { $error = $lang["ERR_TIME"]; }
            elseif ($end <= $time) { $error = $lang["ERR_END_BEFORE_START"]; }
            if ($error !== null) { $resCode = 1; $resMsg = $error; break; }

            $task = new BIO\SHT\Task($customer, $title);
            $task->setDescription($desc !== "" ? mb_substr($desc, 0, 1000) : null)
                 ->setPlannedAt($day->modify($time))
                 ->setPlannedEndAt($day->modify($end))
                 ->assign($personel, new \DateTimeImmutable());
            $db->persist($task);
            TaskHistory::record($db, $task, TaskLog::CREATED, TaskLog::SRC_MAP, null, null);
            $db->flush();
            \Biolab\Core\Sht\PushService::taskAssigned($db, $task);   // telefona bildirim (Firebase kurulu değilse atlanır)

            $resObject = array("TASK_ID" => $task->getTaskId());
            $resCode = 0;
            $resMsg = sprintf($lang["INF_ASSIGNED"], $personel->getUser()->getFullName(), $customer->getCustomerName());
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getMessage();
        }
        break;

    // Planlama → firma kartındaki atanmamış (ya da henüz yola çıkılmamış) görevi seçili personele atar
    case "setAssignExisting":
        $execType = ET_SAVE;
        try{
            $task = $db->getRepository(BIO\SHT\Task::class)->findOneBy(array("taskId" => (int) ($_POST["TASK_ID"] ?? 0), "company" => $companyId));
            $personel = $db->getRepository(Personel::class)->findOneBy(array("personelId" => (int) ($_POST["PERSONEL_ID"] ?? 0), "company" => $companyId));
            if ($task === null || $task->isDeleted()) {
                $resCode = 21; $resMsg = $lang["NO_RECORDS_FOUND"];
            } elseif (!$task->isEditable()) {
                $resCode = 1; $resMsg = $lang["ERR_NOT_EDITABLE"];
            } elseif ($personel === null || $personel->isDeleted() || !$personel->isActive()) {
                $resCode = 1; $resMsg = $lang["ERR_PERSONEL"];
            } else {
                $prev = $task->getPersonel();
                $from = $task->getStatus();
                $task->assign($personel, new \DateTimeImmutable());
                if (($act = TaskHistory::assignmentAction($prev, $personel)) !== null) {
                    TaskHistory::record($db, $task, $act, TaskLog::SRC_MAP, $from, $prev);
                }
                $db->flush();
                if ($prev !== $personel) {
                    \Biolab\Core\Sht\PushService::taskAssigned($db, $task);
                    if ($prev !== null) { \Biolab\Core\Sht\PushService::taskWithdrawn($db, $task, $prev, false); }
                }
                $resCode = 0;
                $resMsg = sprintf($lang["INF_ASSIGNED"], $personel->getUser()->getFullName(), $task->getCustomer()->getCustomerName());
                $resObject = array("TASK_ID" => $task->getTaskId());
            }
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getMessage();
        }
        break;

    // Haritadan görev iptali (yanlış kişiye / firmaya atandıysa): firma panelindeki "İptal" ya da atama sonrası
    // mesajdaki "Görevi iptal et". Atanmış kişiye bildirim gider, görev telefonundaki listeden kalkar.
    case "setCancel":
        $execType = ET_SAVE;
        try{
            $task = $db->getRepository(BIO\SHT\Task::class)->findOneBy(array("taskId" => (int) ($_POST["TASK_ID"] ?? 0), "company" => $companyId));
            if ($task === null || $task->isDeleted()) {
                $resCode = 21; $resMsg = $lang["NO_RECORDS_FOUND"];
            } elseif ($task->isFinished()) {
                $resCode = 1; $resMsg = $lang["ERR_ALREADY_FINISHED"];
            } else {
                $prev = $task->getPersonel();
                $from = $task->getStatus();
                $task->cancel(new \DateTimeImmutable(), TaskHistory::currentUser($db));
                TaskHistory::record($db, $task, TaskLog::CANCELLED, TaskLog::SRC_MAP, $from, $prev);
                $db->flush();
                if ($prev !== null) { \Biolab\Core\Sht\PushService::taskWithdrawn($db, $task, $prev, true); }
                $resCode = 0;
                $resMsg = sprintf($lang["INF_CANCELLED"], $task->getTitle(), $task->getCustomer()->getCustomerName());
                $resObject = array("TASK_ID" => $task->getTaskId());
            }
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getMessage();
        }
        break;

    // Rota: seçilen günün 00:00–23:59 arası (Türkiye saati) bütün konumları. Hatalı (0,0) noktalar atlanır.
    case "getRoute":
        $execType = ET_DEFAULT;
        try{
            $traccar = TraccarClient::fromConfig();
            if ($traccar === null) {
                $resCode = 1;
                $resMsg = $lang["ERR_TRACCAR_CONFIG"];
                break;
            }

            $date = (string) ($_POST["DATE"] ?? "");
            $day  = \DateTimeImmutable::createFromFormat("!Y-m-d", $date, new \DateTimeZone(SHT_TZ));
            $personel = $db->getRepository(Personel::class)->findOneBy(array(
                "personelId" => (int) ($_POST["PERSONEL_ID"] ?? 0),
                "company"    => $companyId,
            ));

            if ($day === false) {
                $resCode = 1;
                $resMsg = $lang["ERR_DATE"];
                break;
            }
            if ($personel === null || $personel->isDeleted()) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
                break;
            }

            $deviceId = $personel->getTraccarDeviceId();
            if ($deviceId === null) {
                foreach ($traccar->devices() as $d) {
                    if (strtoupper((string) $d["uniqueId"]) === $personel->getPersonelCode()) { $deviceId = (int) $d["id"]; break; }
                }
            }
            if ($deviceId === null) {
                $resCode = 1;
                $resMsg = sprintf($lang["ERR_NO_DEVICE"], $personel->getPersonelCode());
                break;
            }

            $points = array();
            $distanceM = 0.0;
            $prev = null;
            foreach ($traccar->route($deviceId, $day, $day->modify("+1 day")) as $p) {
                $lat = (float) $p["latitude"];
                $lon = (float) $p["longitude"];
                if (abs($lat) < 0.0001 && abs($lon) < 0.0001) { continue; }
                if ($prev !== null) { $distanceM += shtDistanceM($prev[0], $prev[1], $lat, $lon); }
                $points[] = array(
                    "lat"      => $lat,
                    "lon"      => $lon,
                    "time"     => shtLocal((string) $p["fixTime"])->format("H:i"),
                    "speedKmh" => (int) round(((float) $p["speed"]) * 1.852),
                );
                $prev = array($lat, $lon);
            }

            $dtResponse = $points;
            $resObject = array(
                "pointCount" => count($points),
                "distanceKm" => round($distanceM / 1000, 1),
                "firstTime"  => $points ? $points[0]["time"] : null,
                "lastTime"   => $points ? $points[count($points) - 1]["time"] : null,
            );
            $resCode = 0;
            $resMsg = count($points) > 0 ? "Ok" : $lang["NO_ROUTE"];
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getMessage();
        }
        break;
}

/** Traccar UTC zamanını Türkiye saatine çevirir */
function shtLocal(string $iso): \DateTimeImmutable
{
    return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(SHT_TZ));
}

/**
 * "Yolda" görevin yol rotası: rota yoksa ya da personel rotadan SHT_OFFROUTE_M'den fazla saptıysa (en sık
 * SHT_REROUTE_SECONDS'ta bir) OSRM'den personelin şu anki konumundan firmaya rota alınır ve göreve yazılır.
 * Rota servisi cevap vermezse sessizce geçilir (haritada düz ok kalır).
 * Döner: activeTask + route.offRouteM (personelin rotaya uzaklığı) ; iç alan _routeAt silinir.
 */
function shtEnsureRoute($db, array $task, ?float $lat, ?float $lon, \DateTimeImmutable $now): array
{
    $routeAt = $task["_routeAt"] ?? null;
    unset($task["_routeAt"]);
    if ($task["status"] !== "ON_THE_WAY" || $lat === null || $task["lat"] === null) {
        return $task;
    }

    $offM = null;
    if ($task["route"] !== null) {
        $offM = (int) round(RouteClient::distanceToRouteM($lat, $lon, RouteClient::decode($task["route"]["polyline"])));
        $task["route"]["offRouteM"] = $offM;
    }
    $stale = $routeAt === null || ($now->getTimestamp() - $routeAt->getTimestamp()) >= SHT_REROUTE_SECONDS;
    if ($task["route"] !== null && !($offM > SHT_OFFROUTE_M && $stale)) {
        return $task;   // rota var ve personel üstünde (ya da az önce yeniden hesaplandı)
    }

    try {
        $r = RouteClient::fromConfig()->route($lat, $lon, $task["lat"], $task["lon"]);
        if ($r === null) { return $task; }
        $entity = $db->find(BIO\SHT\Task::class, $task["taskId"]);
        if ($entity === null) { return $task; }
        $entity->setRoute($r, $now);
        $db->flush();
        $task["route"] = array(
            "polyline"  => $r["polyline"],
            "distanceM" => $r["distanceM"],
            "durationS" => $r["durationS"],
            "count"     => $entity->getRouteCount(),
            "offRouteM" => 0,
        );
    } catch (\Throwable $e) {
        error_log("[Saha harita] rota hesaplanamadı: " . $e->getMessage());
    }
    return $task;
}

/** A'dan B'ye yön (derece, 0 = kuzey, saat yönünde) */
function shtBearing(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dl = deg2rad($lon2 - $lon1);
    $y = sin($dl) * cos($p2);
    $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}

/** İki nokta arası kuş uçuşu mesafe (metre, haversine) */
function shtDistanceM(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}
