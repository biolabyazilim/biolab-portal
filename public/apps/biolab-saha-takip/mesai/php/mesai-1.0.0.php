<?php
/**
 * Saha Takip — Mesailer veri kaynağı (salt okunur; mesailer mobil uygulamadan gelir)
 *
 *   getPERSONEL_ID  → personel süzgeci (şirketteki saha personeli)
 *   getGridList     → mesai listesi (jqGrid). Süzgeç: DATE_FROM, DATE_TO (gg.aa.yyyy ya da yyyy-aa-gg),
 *                     PERSONEL_ID, STATUS (OPEN = açık, CLOSED = kapalı). Süre dakika olarak hesaplanır
 *                     (açık mesaide şu ana kadar).
 *
 * Saatler telefonun gerçek saatidir (STARTED_AT / ENDED_AT); internet yokken başlatılan mesai sonradan gelse de
 * doğru saatte görünür. Bütün kayıtlar oturumdaki şirkete göredir.
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

$db = $dbm->getDB();
$resourceFile = "sht_mesai/mesai-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    case "getPERSONEL_ID":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = $db->createQuery("
                SELECT mt.personelId, mt.activeFl, u.fullName, mt.personelCode
                  FROM BIO\SHT\Personel mt
                  JOIN mt.user u
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.recordFl > 0
                 ORDER BY u.fullName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            foreach ($dtResponse as &$r) {
                $r["personelText"] = $r["fullName"] . " (" . $r["personelCode"] . ")";
                $r["activeFl"] = "Y";   // pasif personelin geçmiş mesaileri de seçilebilsin
            }
            unset($r);

            if (count($dtResponse) > 0) {
                $resCode = 0;
                $resMsg = "Ok";
            } else {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $whereClause = "";
            $params = array("COMPANY_ID" => $companyId);

            $from = shtDate($_POST["DATE_FROM"] ?? "");
            $to   = shtDate($_POST["DATE_TO"] ?? "");
            if ($from !== null) {
                $whereClause .= " AND mt.startedAt >= :DATE_FROM";
                $params["DATE_FROM"] = $from;
            }
            if ($to !== null) {
                $whereClause .= " AND mt.startedAt < :DATE_TO";
                $params["DATE_TO"] = $to->modify("+1 day");
            }
            if ((int) ($_POST["PERSONEL_ID"] ?? 0) > 0) {
                $whereClause .= " AND p.personelId = :PERSONEL_ID";
                $params["PERSONEL_ID"] = (int) $_POST["PERSONEL_ID"];
            }
            $status = (string) ($_POST["STATUS"] ?? "");
            if ($status === "OPEN") {
                $whereClause .= " AND mt.endedAt IS NULL";
            } elseif ($status === "CLOSED") {
                $whereClause .= " AND mt.endedAt IS NOT NULL";
            }

            $fromSql = "
                  FROM BIO\SHT\Shift mt
                  JOIN mt.personel p
                  JOIN p.user u
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.recordFl > 0
            ";

            $totalRecords = (int) $db->createQuery("SELECT COUNT(mt.shiftId) " . $fromSql . $whereClause)
                ->setParameters($params)->getSingleScalarResult();

            // Sıralama alanı istemciden gelir; DQL'e eklenmeden önce izin listesiyle kontrol edilir
            $sortable  = array("mt.startedAt", "mt.endedAt", "p.personelCode", "u.fullName");
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "mt.startedAt";
            $direction = strtoupper($_POST["sord"] ?? "") === "ASC" ? "ASC" : "DESC";

            $rows = max(1, (int) ($_POST["rows"] ?? 25));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $list = $db->createQuery("
                SELECT mt.shiftId, mt.startedAt, mt.endedAt, mt.endReason,
                       mt.startLat, mt.startLon, mt.endLat, mt.endLon,
                       p.personelId, p.personelCode, u.fullName
            " . $fromSql . $whereClause . " ORDER BY " . $sortBy . " " . $direction . ", mt.shiftId DESC")
                ->setParameters($params)
                ->setMaxResults($rows)
                ->setFirstResult(($page - 1) * $rows)
                ->getArrayResult();

            $now = new \DateTimeImmutable();
            $dtResponse = array();
            foreach ($list as $r) {
                /** @var \DateTimeImmutable $start */
                $start = $r["startedAt"];
                $end   = $r["endedAt"];
                $dtResponse[] = array(
                    "shiftId"      => $r["shiftId"],
                    "personelId"   => $r["personelId"],
                    "personelCode" => $r["personelCode"],
                    "fullName"     => $r["fullName"],
                    "day"          => $start->format("Y-m-d"),
                    "startedAt"    => $start->format("Y-m-d H:i:s"),
                    "endedAt"      => $end?->format("Y-m-d H:i:s"),
                    "minutes"      => (int) floor((($end ?? $now)->getTimestamp() - $start->getTimestamp()) / 60),
                    "minutesRaw"   => (int) floor((($end ?? $now)->getTimestamp() - $start->getTimestamp()) / 60),   // toplam için (gizli sütun)
                    "isOpen"       => $end === null ? "Y" : "N",
                    "endReason"    => $r["endReason"],
                    "startLoc"     => $r["startLat"] !== null ? $r["startLat"] . "," . $r["startLon"] : null,
                    "endLoc"       => $r["endLat"] !== null ? $r["endLat"] . "," . $r["endLon"] : null,
                );
            }

            if (count($dtResponse) > 0) {
                $resCode = 0;
                $resMsg = "Ok";
            } else {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

/** "12.11.2026" ya da "2026-11-12" → günün başı; geçersizse null */
function shtDate(string $raw): ?\DateTimeImmutable
{
    $raw = trim($raw);
    foreach (array("!d.m.Y", "!Y-m-d") as $fmt) {
        $d = \DateTimeImmutable::createFromFormat($fmt, $raw);
        if ($d !== false) {
            return $d;
        }
    }
    return null;
}
