<?php
/**
 * Saha Takip — Görevler veri kaynağı
 *
 *   getCUSTOMER_ID  → firma seçimi (aktif firmalar)
 *   getPERSONEL_ID  → personel seçimi. CUSTOMER_ID verilirse firmaya EN YAKINDAN uzağa sıralanır:
 *                     mesaide olanlar önce, sonra uzaklık (Traccar'daki son konuma göre). Metin: "Ad (P002) · Mesaide · 2,3 km"
 *   getGridList     → görev listesi (jqGrid). Süzgeç: DATE_FROM / DATE_TO (planlanan gün), STATUS, PERSONEL_ID, Q
 *   getSelectedId   → seçilen görev (formu doldurur)
 *   setSave / setAdd → görev aç / güncelle. Yola çıkılmış görevin firması, günü ve personeli değiştirilemez.
 *   setCancel       → görevi iptal eder (bitmemişse); iptal eden CANCELLED_BY'a yazılır
 *   getHistory      → görev geçmişi (sht_task_log): her işlem kim / ne zaman / nereden. Bütün işlemler
 *                     Biolab\Core\Sht\TaskHistory::record ile yazılır (Görevler, Harita, mobil API).
 *
 * Görev akışı (BIO\SHT\Task): NEW → ASSIGNED → ON_THE_WAY → ON_SITE → DONE; ya da CANCELLED.
 * Personel adımları mobil uygulamadan ilerletir (api/saha.php?action=taskStatus).
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use Biolab\Core\Sht\TaskHistory;
use Biolab\Core\Sht\TraccarClient;
use BIO\SHT\Customer;
use BIO\SHT\Personel;
use BIO\SHT\Task;
use BIO\SHT\TaskLog;

$db = $dbm->getDB();
$resourceFile = "sht_gorev/gorev-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

const SHT_ARRIVE_WARN_M = 300;   // "Vardım" firmaya bundan uzaksa listede uyarı (kural netleşince değişebilir)

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    case "getCUSTOMER_ID":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = $db->createQuery("
                SELECT mt.customerId, mt.customerName, mt.city, mt.activeFl
                  FROM BIO\SHT\Customer mt
                 WHERE IDENTITY(mt.company) = :COMPANY_ID AND mt.recordFl > 0
                 ORDER BY mt.customerName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            foreach ($dtResponse as &$r) {
                $r["customerText"] = $r["customerName"] . ($r["city"] ? " · " . $r["city"] : "");
            }
            unset($r);
            $resCode = count($dtResponse) > 0 ? 0 : 21;
            $resMsg = $resCode === 0 ? "Ok" : $lang["NO_RECORDS_FOUND"];
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Personel seçimi; firma verilirse en yakından uzağa (Aşama 3'ün ilk adımı: en yakın boştaki personel)
    case "getPERSONEL_ID":
        $execType = ET_DEFAULT;
        try{
            $people = $db->createQuery("
                SELECT mt.personelId, mt.personelCode, mt.traccarDeviceId, mt.activeFl, u.fullName
                  FROM BIO\SHT\Personel mt JOIN mt.user u
                 WHERE IDENTITY(mt.company) = :COMPANY_ID AND mt.recordFl > 0
                 ORDER BY u.fullName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();

            $onShift = array();
            foreach ($db->createQuery("
                SELECT IDENTITY(s.personel) pid FROM BIO\SHT\Shift s
                 WHERE IDENTITY(s.company) = :COMPANY_ID AND s.endedAt IS NULL AND s.recordFl > 0
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult() as $s) {
                $onShift[(int) $s["pid"]] = true;
            }
            // Personelin şu an üstündeki (bitmemiş, yola çıkılmış) görev sayısı: "boşta mı"
            $busy = array();
            foreach ($db->createQuery("
                SELECT IDENTITY(t.personel) pid, COUNT(t.taskId) n FROM BIO\SHT\Task t
                 WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0 AND t.status IN ('ON_THE_WAY', 'ON_SITE')
                 GROUP BY t.personel
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult() as $b) {
                $busy[(int) $b["pid"]] = (int) $b["n"];
            }

            $customer = (int) ($_POST["CUSTOMER_ID"] ?? 0) > 0
                ? $db->getRepository(Customer::class)->findOneBy(array("customerId" => (int) $_POST["CUSTOMER_ID"], "company" => $companyId))
                : null;
            $positions = array();
            if ($customer !== null && $customer->getLat() !== null && ($traccar = TraccarClient::fromConfig()) !== null) {
                $byCode = array();
                foreach ($traccar->devices() as $d) { $byCode[strtoupper((string) $d["uniqueId"])] = (int) $d["id"]; }
                $latest = array();
                foreach ($traccar->latestPositions() as $p) { $latest[(int) $p["deviceId"]] = $p; }
                foreach ($people as $r) {
                    $dev = $r["traccarDeviceId"] ?: ($byCode[strtoupper($r["personelCode"])] ?? null);
                    if ($dev !== null && isset($latest[$dev])) {
                        $positions[$r["personelId"]] = shtDistanceM($customer->getLat(), $customer->getLon(),
                            (float) $latest[$dev]["latitude"], (float) $latest[$dev]["longitude"]);
                    }
                }
            }

            foreach ($people as &$r) {
                $pid = (int) $r["personelId"];
                $r["onShift"] = isset($onShift[$pid]);
                $r["busy"] = $busy[$pid] ?? 0;
                $r["distanceM"] = isset($positions[$pid]) ? (int) round($positions[$pid]) : null;
                $parts = array($r["onShift"] ? $lang["ON_SHIFT"] : $lang["OFF_SHIFT"]);
                if ($r["busy"] > 0) { $parts[] = $lang["BUSY"]; }
                if ($r["distanceM"] !== null) { $parts[] = shtKm($r["distanceM"]); }
                $r["personelText"] = $r["fullName"] . " (" . $r["personelCode"] . ") · " . implode(" · ", $parts);
            }
            unset($r);
            if ($customer !== null) {
                // Önce mesaide ve boşta olanlar, sonra uzaklık
                usort($people, function ($a, $b) {
                    $ka = array($a["onShift"] ? 0 : 1, $a["busy"] > 0 ? 1 : 0, $a["distanceM"] ?? PHP_INT_MAX);
                    $kb = array($b["onShift"] ? 0 : 1, $b["busy"] > 0 ? 1 : 0, $b["distanceM"] ?? PHP_INT_MAX);
                    return $ka <=> $kb;
                });
            }
            $dtResponse = $people;
            $resCode = count($dtResponse) > 0 ? 0 : 21;
            $resMsg = $resCode === 0 ? "Ok" : $lang["NO_RECORDS_FOUND"];
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getMessage();
        }
        break;

    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $where = "";
            $params = array("COMPANY_ID" => $companyId);
            $from = shtDay($_POST["DATE_FROM"] ?? "");
            $to   = shtDay($_POST["DATE_TO"] ?? "");
            if ($from !== null) { $where .= " AND mt.plannedAt >= :DATE_FROM"; $params["DATE_FROM"] = $from; }
            if ($to !== null)   { $where .= " AND mt.plannedAt < :DATE_TO";   $params["DATE_TO"] = $to->modify("+1 day"); }
            if ((int) ($_POST["PERSONEL_ID"] ?? 0) > 0) { $where .= " AND p.personelId = :PERSONEL_ID"; $params["PERSONEL_ID"] = (int) $_POST["PERSONEL_ID"]; }
            $status = (string) ($_POST["STATUS"] ?? "ALL");
            if ($status === "OPEN") {
                $where .= " AND mt.status NOT IN ('DONE', 'CANCELLED')";
            } elseif (in_array($status, array(Task::NEW, Task::ASSIGNED, Task::ON_THE_WAY, Task::ON_SITE, Task::DONE, Task::CANCELLED), true)) {
                $where .= " AND mt.status = :STATUS";
                $params["STATUS"] = $status;
            }
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") { $where .= " AND (c.customerName LIKE :Q OR mt.title LIKE :Q)"; $params["Q"] = "%" . $q . "%"; }

            $fromSql = "
                  FROM BIO\SHT\Task mt
                  JOIN mt.customer c
                  LEFT JOIN mt.personel p
                  LEFT JOIN p.user u
                  LEFT JOIN mt.cancelledBy cb
                 WHERE IDENTITY(mt.company) = :COMPANY_ID AND mt.recordFl > 0
            ";
            $totalRecords = (int) $db->createQuery("SELECT COUNT(mt.taskId) " . $fromSql . $where)->setParameters($params)->getSingleScalarResult();

            $sortable  = array("mt.plannedAt", "c.customerName", "mt.title", "u.fullName", "mt.status");
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "mt.plannedAt";
            $direction = strtoupper($_POST["sord"] ?? "") === "ASC" ? "DESC" : "ASC";
            $rows = max(1, (int) ($_POST["rows"] ?? 50));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $list = $db->createQuery("
                SELECT mt.taskId, mt.title, mt.plannedAt, mt.plannedEndAt, mt.doneEarly, mt.earlyReason,
                       mt.status, mt.onTheWayAt, mt.arrivedAt, mt.arriveDistanceM,
                       mt.doneAt, mt.doneNote, mt.cancelledAt, cb.fullName cancelledByName,
                       c.customerName, c.city, p.personelId, p.personelCode, u.fullName
            " . $fromSql . $where . " ORDER BY " . $sortBy . " " . $direction . ", mt.taskId DESC")
                ->setParameters($params)->setMaxResults($rows)->setFirstResult(($page - 1) * $rows)->getArrayResult();

            $hm = function (?\DateTimeImmutable $t) { return $t?->format("H:i"); };
            $dtResponse = array();
            foreach ($list as $r) {
                $dtResponse[] = array(
                    "taskId"       => $r["taskId"],
                    "plannedAt"    => $r["plannedAt"]?->format("Y-m-d H:i"),
                    "plannedRange" => shtRange($r["plannedAt"], $r["plannedEndAt"]),   // "13.11.2026 10:00–12:00"
                    "doneEarly"    => $r["doneEarly"],
                    "earlyReason"  => $r["earlyReason"],
                    "customerName" => $r["customerName"] . ($r["city"] ? " · " . $r["city"] : ""),
                    "title"        => $r["title"],
                    "personel"     => $r["fullName"] ? $r["fullName"] . " (" . $r["personelCode"] . ")" : null,
                    "personelId"   => $r["personelId"],
                    "status"       => $r["status"],
                    "onTheWayAt"   => $hm($r["onTheWayAt"]),
                    "arrivedAt"    => $hm($r["arrivedAt"]),
                    "arriveDistanceM" => $r["arriveDistanceM"],
                    "arriveWarn"   => $r["arriveDistanceM"] !== null && $r["arriveDistanceM"] > SHT_ARRIVE_WARN_M ? "Y" : "N",
                    "doneAt"       => $hm($r["doneAt"]),
                    "visitMinutes" => ($r["arrivedAt"] && $r["doneAt"]) ? (int) floor(($r["doneAt"]->getTimestamp() - $r["arrivedAt"]->getTimestamp()) / 60) : null,
                    "doneNote"     => $r["doneNote"],
                    "day"          => $r["plannedAt"]?->format("Y-m-d"),
                    // İptal: ne zaman · kim (listede durumun altında)
                    "cancelInfo"   => $r["status"] === Task::CANCELLED
                        ? implode(" · ", array_filter(array($r["cancelledAt"]?->format("d.m.Y H:i"), $r["cancelledByName"])))
                        : null,
                );
            }
            $resCode = count($dtResponse) > 0 ? 0 : 21;
            $resMsg = $resCode === 0 ? "Ok" : $lang["NO_RECORDS_FOUND"];
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getSelectedId":
        $execType = ET_DEFAULT;
        try{
            $r = $db->createQuery("
                SELECT mt.taskId, mt.title, mt.description, mt.plannedAt, mt.plannedEndAt, mt.doneEarly, mt.earlyReason,
                       mt.status, IDENTITY(mt.customer) customerId,
                       IDENTITY(mt.personel) personelId, mt.cancelledAt, cb.fullName cancelledByName
                  FROM BIO\SHT\Task mt
                  LEFT JOIN mt.cancelledBy cb
                 WHERE IDENTITY(mt.company) = :COMPANY_ID AND mt.taskId = :TASK_ID AND mt.recordFl > 0
            ")->setParameters(array("COMPANY_ID" => $companyId, "TASK_ID" => (int) ($_POST["SELECTED_ID"] ?? 0)))->getArrayResult();
            if ($r) {
                $r[0]["plannedDate"] = $r[0]["plannedAt"]?->format("Y-m-d");
                $r[0]["plannedTime"] = $r[0]["plannedAt"] && $r[0]["plannedAt"]->format("H:i") !== "00:00" ? $r[0]["plannedAt"]->format("H:i") : "";
                $r[0]["plannedEndTime"] = $r[0]["plannedEndAt"]?->format("H:i") ?? "";
                if ($r[0]["plannedEndAt"] !== null) { $r[0]["plannedTime"] = $r[0]["plannedAt"]?->format("H:i"); }   // 00:00 başlangıç da geçerli
                unset($r[0]["plannedAt"], $r[0]["plannedEndAt"]);
                $r[0]["cancelledAt"] = $r[0]["cancelledAt"]?->format("d.m.Y H:i");
                $r[0]["editable"] = in_array($r[0]["status"], array(Task::NEW, Task::ASSIGNED), true) ? "Y" : "N";
            }
            $dtResponse = $r;
            $resCode = count($dtResponse) > 0 ? 0 : 21;
            $resMsg = $resCode === 0 ? "Ok" : $lang["NO_RECORDS_FOUND"];
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Görev geçmişi (sht_task_log): kim, ne zaman, ne yaptı, nereden — eskiden yeniye
    case "getHistory":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = TaskHistory::list($db, (int) ($_POST["TASK_ID"] ?? 0), $companyId);
            $resCode = 0;   // boş geçmiş hata değil (bu özellikten önce açılmış görev)
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getMessage();
        }
        break;

    case "setAdd":
        $_POST["TASK_ID"] = "";

    case "setSave":
        $execType = ET_SAVE;
        try{
            $taskId   = (int) ($_POST["TASK_ID"] ?? 0);
            $title    = trim((string) ($_POST["TITLE"] ?? ""));
            $desc     = trim((string) ($_POST["DESCRIPTION"] ?? ""));
            $day      = shtDay($_POST["PLANNED_DATE"] ?? "");
            $time     = trim((string) ($_POST["PLANNED_TIME"] ?? ""));       // başlangıç (zorunlu)
            $endTime  = trim((string) ($_POST["PLANNED_END_TIME"] ?? ""));   // bitiş (zorunlu, başlangıçtan sonra)
            $task     = $taskId > 0 ? $db->getRepository(Task::class)->findOneBy(array("taskId" => $taskId, "company" => $companyId)) : null;
            $customer = $db->getRepository(Customer::class)->findOneBy(array("customerId" => (int) ($_POST["CUSTOMER_ID"] ?? 0), "company" => $companyId));
            $pid      = (int) ($_POST["PERSONEL_ID"] ?? 0);
            $personel = $pid > 0 ? $db->getRepository(Personel::class)->findOneBy(array("personelId" => $pid, "company" => $companyId)) : null;

            $error = null;
            if ($taskId > 0 && ($task === null || $task->isDeleted())) { $error = $lang["NO_RECORDS_FOUND"]; }
            elseif ($task !== null && !$task->isEditable()) { $error = $lang["ERR_NOT_EDITABLE"]; }
            elseif ($customer === null || $customer->isDeleted()) { $error = $lang["ERR_CUSTOMER_ID"]; }
            elseif ($title === "" || mb_strlen($title) > 200) { $error = $lang["ERR_TITLE"]; }
            elseif ($day === null) { $error = $lang["ERR_PLANNED_DATE"]; }
            elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) { $error = $lang["ERR_PLANNED_TIME"]; }
            elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $endTime)) { $error = $lang["ERR_PLANNED_END_TIME"]; }
            elseif ($endTime <= $time) { $error = $lang["ERR_END_BEFORE_START"]; }
            elseif ($pid > 0 && ($personel === null || $personel->isDeleted() || !$personel->isActive())) { $error = $lang["ERR_PERSONEL_ID"]; }
            if ($error !== null) { $resCode = 1; $resMsg = $error; break; }

            $now = new \DateTimeImmutable();
            $isNew = $task === null;
            $plannedAt = $day->modify($time);
            $plannedEndAt = $day->modify($endTime);
            $descVal = $desc !== "" ? mb_substr($desc, 0, 1000) : null;
            $changed = array();   // geçmişte "Güncellendi: görev adı, gün" notu
            if ($isNew) {
                $task = new Task($customer, $title);
                $db->persist($task);
                $resMsg = $lang["RECORD_IS_ADDED"];
            } else {
                if ($task->getCustomer() !== $customer) { $changed[] = $lang["CUSTOMER_ID"] . ": " . $task->getCustomer()->getCustomerName() . " → " . $customer->getCustomerName(); }
                if ($task->getTitle() !== $title) { $changed[] = $lang["TITLE"] . ": " . $task->getTitle() . " → " . $title; }
                $oldRange = shtRange($task->getPlannedAt(), $task->getPlannedEndAt());
                $newRange = shtRange($plannedAt, $plannedEndAt);
                if ($oldRange !== $newRange) { $changed[] = $lang["PLANNED_AT"] . ": " . ($oldRange ?: "–") . " → " . $newRange; }
                if ($task->getDescription() !== $descVal) { $changed[] = $lang["DESCRIPTION"]; }
                $task->setCustomer($customer)->setTitle($title);
                $resMsg = $lang["RECORD_IS_CHANGED"];
            }
            $fromStatus = $isNew ? null : $task->getStatus();
            $task->setDescription($descVal)->setPlannedAt($plannedAt)->setPlannedEndAt($plannedEndAt);
            $prev = $task->getPersonel();
            if ($prev !== $personel) {
                $task->assign($personel, $now);
            }
            // Görev geçmişi: yeni görev / değişen alanlar / kişi değişikliği
            if ($isNew) {
                TaskHistory::record($db, $task, TaskLog::CREATED, TaskLog::SRC_TASKS, null, null);
            } else {
                if ($changed) {
                    TaskHistory::record($db, $task, TaskLog::UPDATED, TaskLog::SRC_TASKS, $fromStatus, $prev, $now, implode(" · ", $changed));
                }
                if (($act = TaskHistory::assignmentAction($prev, $personel)) !== null) {
                    TaskHistory::record($db, $task, $act, TaskLog::SRC_TASKS, $fromStatus, $prev);
                }
            }
            $db->flush();
            // Telefona bildirim (Firebase kurulu değilse atlanır): yeni kişiye "Yeni görev", eskisine "başkasına verildi"
            if ($prev !== $personel) {
                if ($personel !== null) { \Biolab\Core\Sht\PushService::taskAssigned($db, $task); }
                if ($prev !== null) { \Biolab\Core\Sht\PushService::taskWithdrawn($db, $task, $prev, false); }
            }

            $resObject = array("TASK_ID" => $task->getTaskId());
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setCancel":
        $execType = ET_SAVE;
        try{
            $task = $db->getRepository(Task::class)->findOneBy(array("taskId" => (int) ($_POST["TASK_ID"] ?? 0), "company" => $companyId));
            if ($task === null || $task->isDeleted()) {
                $resCode = 21; $resMsg = $lang["NO_RECORDS_FOUND"];
            } elseif ($task->isFinished()) {
                $resCode = 1; $resMsg = $lang["ERR_ALREADY_FINISHED"];
            } else {
                $prev = $task->getPersonel();
                $from = $task->getStatus();
                $task->cancel(new \DateTimeImmutable(), TaskHistory::currentUser($db));
                TaskHistory::record($db, $task, TaskLog::CANCELLED, TaskLog::SRC_TASKS, $from, $prev);
                $db->flush();
                if ($prev !== null) { \Biolab\Core\Sht\PushService::taskWithdrawn($db, $task, $prev, true); }
                $resCode = 0; $resMsg = $lang["INF_CANCELLED"];
                $resObject = array("TASK_ID" => $task->getTaskId());
            }
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

/** Görev saat aralığı yazısı: "13.11.2026 10:00–12:00" (bitiş yoksa eski görev: "13.11.2026 10:00" ya da yalnız gün) */
function shtRange(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): string
{
    if ($start === null) { return ""; }
    $s = $start->format("d.m.Y") . ($start->format("H:i") !== "00:00" || $end !== null ? " " . $start->format("H:i") : "");
    return $end !== null ? $s . "–" . $end->format("H:i") : $s;
}

/** "2026-11-12" ya da "12.11.2026" → günün başı; geçersizse null */
function shtDay(string $raw): ?\DateTimeImmutable
{
    foreach (array("!Y-m-d", "!d.m.Y") as $fmt) {
        $d = \DateTimeImmutable::createFromFormat($fmt, trim($raw));
        if ($d !== false) { return $d; }
    }
    return null;
}

/** 850 → "850 m", 2340 → "2,3 km" */
function shtKm(int $m): string
{
    return $m < 1000 ? $m . " m" : str_replace(".", ",", (string) round($m / 1000, 1)) . " km";
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
