<?php
/**
 * Maliyet — Özet (dashboard)
 *
 *   Seçim kutuları (lib.js $.fn.slb):
 *   getSTUDY_ID  → maliyet çalışmaları (en yeni üstte)
 *   getPERIOD_ID → aylık takip dönemleri (en yeni üstte)
 *   getLAB_UNIT  → birim filtresi (Biolab / Patoloji)
 *
 *   getDashboard → bütün kartların verisi tek istekte (Biolab\Core\Mlyt\Dashboard): veri tamlığı, yapılacaklar,
 *                  maliyet yapısı, kur payı, dönemin malzeme maliyeti (Pareto), dönemler boyunca aylık takip
 *
 * Salt okunur: hiçbir kayıt değiştirmez.
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use Biolab\Core\Mlyt\Dashboard;
use BIO\MLYT\MonthlyPeriod;
use BIO\MLYT\Study;
use BIO\MLYT\Test;

$db = $dbm->getDB();
$resourceFile = "mlyt_dashboard/dashboard-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

const DB_MONTHS = array(1 => "Ocak", "Şubat", "Mart", "Nisan", "Mayıs", "Haziran", "Temmuz", "Ağustos", "Eylül", "Ekim", "Kasım", "Aralık");

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    /* ---------- Seçim kutuları ---------- */

    case "getSTUDY_ID":
        $execType = ET_DEFAULT;
        try{
            $statusText = array(Study::STATUS_DRAFT => "Taslak", Study::STATUS_REVIEW => "İncelemede",
                                Study::STATUS_APPROVED => "Onaylandı", Study::STATUS_ARCHIVED => "Arşiv");
            $rows = $db->createQuery("
                SELECT s.studyId, s.studyName, s.status, s.activeFl
                  FROM BIO\MLYT\Study s
                 WHERE IDENTITY(s.company) = :COMPANY_ID AND s.recordFl > 0
                 ORDER BY s.rateDate DESC, s.studyId DESC
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            foreach ($rows as &$r) { $r["studyText"] = $r["studyName"] . " · " . ($statusText[$r["status"]] ?? $r["status"]); }
            unset($r);
            $dtResponse = $rows;
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getPERIOD_ID":
        $execType = ET_DEFAULT;
        try{
            $rows = $db->createQuery("
                SELECT p.periodId, p.periodYm FROM BIO\MLYT\MonthlyPeriod p
                 WHERE IDENTITY(p.company) = :COMPANY_ID AND p.recordFl > 0
                 ORDER BY p.periodYm DESC
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            foreach ($rows as &$r) { $r["periodText"] = dbPeriodText($r["periodYm"]); $r["activeFl"] = "Y"; }
            unset($r);
            $dtResponse = $rows;
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getLAB_UNIT":
        $execType = ET_DEFAULT;
        $dtResponse = array(
            array("labUnit" => Test::UNIT_BIOLAB, "labUnitName" => "Biolab", "activeFl" => "Y"),
            array("labUnit" => Test::UNIT_PATHOLOGY, "labUnitName" => "Patoloji", "activeFl" => "Y"),
        );
        $resCode = 0;
        $resMsg = "Ok";
        break;

    /* ---------- Özet ---------- */

    // STUDY_ID (zorunlu), PERIOD_ID (isteğe bağlı: yapılacaklar sıralaması ve dönemin malzeme maliyeti), LAB_UNIT (isteğe bağlı)
    case "getDashboard":
        $execType = ET_CUSTOM;
        try{
            $study = $db->getRepository(Study::class)->findOneBy(array("studyId" => (int) ($_POST["STUDY_ID"] ?? 0), "company" => $companyId));
            if ($study === null || $study->isDeleted()) { $resCode = 20; $resMsg = "Maliyet çalışması seçin."; break; }
            $period = $db->getRepository(MonthlyPeriod::class)->findOneBy(array("periodId" => (int) ($_POST["PERIOD_ID"] ?? 0), "company" => $companyId));
            if ($period !== null && $period->isDeleted()) { $period = null; }
            $unit = in_array($_POST["LAB_UNIT"] ?? "", array(Test::UNIT_BIOLAB, Test::UNIT_PATHOLOGY), true) ? $_POST["LAB_UNIT"] : null;

            $d = new Dashboard($db, $companyId, $study, $unit);
            $resObject = array(
                "study"        => array("studyId" => $study->getStudyId(), "studyName" => $study->getStudyName(), "status" => $study->getStatus()),
                "period"       => $period ? array("periodId" => $period->getPeriodId(), "periodYm" => $period->getPeriodYm(), "periodText" => dbPeriodText($period->getPeriodYm())) : null,
                "completeness" => $d->completeness(),
                "todo"         => $d->todo($period),
                "structure"    => $d->structure(),
                "monthly"      => $period ? $d->monthlyCost($period) : array("hasData" => false),
                "trend"        => $d->trend(),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    default:
        $resCode = 9000;
        $resMsg = "Bilinmeyen işlem: " . $query;
}

/** "2026-10" → "Ekim 2026" */
function dbPeriodText(string $ym): string
{
    [$y, $m] = array_map("intval", explode("-", $ym));
    return (DB_MONTHS[$m] ?? $ym) . " " . $y;
}
