<?php
/**
 * HbRapor — Dashboard veri kaynağı
 *
 * D.Sys düzeni: ekranın JS'i "dashboard-1.0.0@<query>" ile istek atar, buradaki switch ilgili case'i çalıştırır.
 *   getSummary → sayaç kartları, son 14 günün çalışma/onay grafiği, yorum bloğu dağılımı,
 *                ortalama sonuç süresi, D-10 son çekme ve son onaylanan raporlar
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse;

use BIO\BCR\SampleAcceptance;

$db = $dbm->getDB();
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

switch ($query) {

    case "getSummary":
        $execType = ET_DEFAULT;
        try{
            $conn      = $db->getConnection();
            $companyId = (int) $_SESSION["COMPANY_ID"];
            $today     = new DateTimeImmutable("today");
            $since30   = $today->modify("-30 days")->format("Y-m-d");
            $since14   = $today->modify("-13 days");

            // --- Sayaç kartları (iş listesinin sekmeleriyle aynı anlam; son 30 gün) ---
            $statusCounts = array();
            foreach ($conn->fetchAllAssociative("
                SELECT STATUS, COUNT(*) AS N FROM sample_acceptance
                 WHERE COMPANY_ID = ? AND RECORD_FL > 0 AND ACTIVE_FL = 'Y' AND ACCEPTED_AT >= ?
                 GROUP BY STATUS", array($companyId, $since30)) as $r) {
                $statusCounts[$r["STATUS"]] = (int) $r["N"];
            }
            $unmatched = (int) $conn->fetchOne("
                SELECT COUNT(*) FROM device_result
                 WHERE COMPANY_ID = ? AND RECORD_FL > 0 AND SAMPLE_ACCEPTANCE_ID IS NULL AND INJECTION_DATE >= ?",
                array($companyId, $since30));
            $approvedToday = (int) $conn->fetchOne("
                SELECT COUNT(*) FROM sample_report
                 WHERE COMPANY_ID = ? AND RECORD_FL > 0 AND APPROVED_AT >= ?",
                array($companyId, $today->format("Y-m-d")));

            // --- Son 14 gün: günlük cihaz çalışması ve onaylanan rapor ---
            $runsByDay = $conn->fetchAllKeyValue("
                SELECT CONVERT(varchar(10), INJECTION_DATE, 23), COUNT(*) FROM device_result
                 WHERE COMPANY_ID = ? AND RECORD_FL > 0 AND INJECTION_DATE >= ?
                 GROUP BY CONVERT(varchar(10), INJECTION_DATE, 23)",
                array($companyId, $since14->format("Y-m-d")));
            $reportsByDay = $conn->fetchAllKeyValue("
                SELECT CONVERT(varchar(10), APPROVED_AT, 23), COUNT(*) FROM sample_report
                 WHERE COMPANY_ID = ? AND RECORD_FL > 0 AND REVISION_NO = 1 AND APPROVED_AT >= ?
                 GROUP BY CONVERT(varchar(10), APPROVED_AT, 23)",
                array($companyId, $since14->format("Y-m-d")));
            $days = array();
            for ($d = $since14; $d <= $today; $d = $d->modify("+1 day")) {
                $key = $d->format("Y-m-d");
                $days[] = array("date" => $key, "runs" => (int) ($runsByDay[$key] ?? 0), "reports" => (int) ($reportsByDay[$key] ?? 0));
            }

            // --- Yorum bloğu dağılımı (son 30 gün, geçerli revizyonlar; ilk blok = yaş bloğu) ---
            $blocks = array();
            foreach ($conn->fetchFirstColumn("
                SELECT SELECTED_BLOCKS FROM sample_report
                 WHERE COMPANY_ID = ? AND RECORD_FL > 0 AND SUPERSEDED_AT IS NULL AND APPROVED_AT >= ?",
                array($companyId, $since30)) as $text) {
                $code = strtoupper(trim((string) preg_split('/[\s+,·]+/u', (string) $text)[0]));
                $code = $code !== "" ? $code : "—";
                $blocks[$code] = ($blocks[$code] ?? 0) + 1;
            }
            arsort($blocks);

            // --- Ortalama sonuç süresi: cihaz çalışması → ilk onay (son 30 gün, saat) ---
            $tatMinutes = $conn->fetchOne("
                SELECT AVG(CAST(DATEDIFF(minute, r.INJECTED_AT, sr.APPROVED_AT) AS float))
                  FROM sample_report sr
                  JOIN device_result r ON r.DEVICE_RESULT_ID = sr.DEVICE_RESULT_ID
                 WHERE sr.COMPANY_ID = ? AND sr.RECORD_FL > 0 AND sr.REVISION_NO = 1
                   AND sr.APPROVED_AT >= ? AND r.INJECTED_AT IS NOT NULL AND sr.APPROVED_AT >= r.INJECTED_AT",
                array($companyId, $since30));
            $reports30 = (int) $conn->fetchOne("
                SELECT COUNT(*) FROM sample_report
                 WHERE COMPANY_ID = ? AND RECORD_FL > 0 AND REVISION_NO = 1 AND APPROVED_AT >= ?",
                array($companyId, $since30));

            // --- D-10 son çekme ---
            $lastFetch = $conn->fetchAssociative("
                SELECT TOP 1 STARTED_AT, FINISHED_AT, SUCCESS_FL, MESSAGE FROM device_fetch_log ORDER BY DEVICE_FETCH_LOG_ID DESC");

            // --- Son onaylanan raporlar ---
            $recent = array();
            $rows = $db->createQuery("
                SELECT sr, sa FROM BIO\BCR\SampleReport sr
                  JOIN sr.sampleAcceptance sa
                 WHERE IDENTITY(sa.company) = :COMPANY_ID AND sr.recordFl > 0 AND sr.supersededAt IS NULL
                 ORDER BY sr.approvedAt DESC
            ")->setParameter("COMPANY_ID", $companyId)->setMaxResults(6)->getResult();
            foreach ($rows as $sr) {
                $sa = $sr->getSampleAcceptance();
                $recent[] = array(
                    "sampleAcceptanceId" => $sa->getSampleAcceptanceId(),
                    "barcode"            => $sa->getBarcode(),
                    "patientName"        => $sa->getPatientNameSurname(),
                    "approvedAt"         => $sr->getApprovedAt()->format("Y-m-d H:i"),
                    "blocks"             => $sr->getSelectedBlocks(),
                    "revisionNo"         => $sr->getRevisionNo(),
                    "approvedBy"         => $sr->getApprovedBy()?->getDisplayName(),
                );
            }

            $dtResponse = array(
                "counts" => array(
                    "unmatched"     => $unmatched,
                    "pending"       => ($statusCounts[SampleAcceptance::STATUS_RESULTED] ?? 0) + ($statusCounts[SampleAcceptance::STATUS_RERUN] ?? 0),
                    "waiting"       => $statusCounts[SampleAcceptance::STATUS_WAITING] ?? 0,
                    "approvedToday" => $approvedToday,
                ),
                "days"       => $days,
                "blocks"     => $blocks,
                "tatHours"   => $tatMinutes !== null && $tatMinutes !== false ? round((float) $tatMinutes / 60, 1) : null,
                "reports30"  => $reports30,
                "lastFetch"  => $lastFetch ? array(
                    "at"      => substr((string) ($lastFetch["FINISHED_AT"] ?? $lastFetch["STARTED_AT"]), 0, 16),
                    "ok"      => $lastFetch["SUCCESS_FL"] === "Y",
                    "message" => $lastFetch["MESSAGE"],
                ) : null,
                "recent"     => $recent,
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    default:
        $resCode = 9001;
        $resMsg = "Unknown query";
        break;
}
