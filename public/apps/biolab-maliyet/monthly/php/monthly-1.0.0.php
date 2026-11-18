<?php
/**
 * Maliyet — Aylık takip (fire / kayıp / tekrar, Aşama 1)
 *
 * Yıllık maliyet çalışmasından ayrıdır: her ay Netsis ve LabAsistan Excel'leri yüklenir, rapor bunlardan hesaplanır.
 * TL karşılığı dönemin seçili maliyet çalışmasının birim maliyetleriyledir (CostCalculator).
 *
 *   Seçim kutuları (lib.js $.fn.slb):
 *   getPERIOD_ID  → dönemler (en yeni üstte)
 *   getSTUDY_ID   → maliyet çalışmaları (dönemin TL karşılığı için)
 *   getUNIT_NAME  → LabAsistan birimleri (numune listeleri birim seçilerek yüklenir)
 *   getDEPOT_TYPE → Netsis malzeme filtresi (kurum / OSGB / şube / lab / diğer)
 *
 *   setPeriod      → yeni dönem (PERIOD_YM "2026-10", STUDY_ID); varsa onu döner
 *   setPeriodStudy → dönemin maliyet çalışmasını değiştirir
 *   getFiles       → dönemin dosyaları (kaynak kartları)
 *   setUpload      → dosya yükler (PERIOD_ID, SOURCE_CODE, UNIT_NAME, FILE): Biolab\Core\Mlyt\MonthlyImport
 *   setDeleteFile  → dosyayı ve satırlarını siler
 *   getSummary     → özet kartları + numune (geliş tipi / kurum / birim) + Netsis birim tabloları
 *   getRepeatList  → tekrar listesi (jqGrid; FILTER: ALL / PRESENT / RATE / PRICE / NOCOST, Q)
 *   getNetsisList  → Netsis malzeme listesi (jqGrid; DEPOT_TYPE, Q)
 *   getFIRE_UNIT   → fire / zayi formunun birimi (şube, OSGB, lab, ana depo, PCR)
 *   getFireList    → fire / zayi bildirim satırları (jqGrid; CATEGORY: FIRE / ZAYI / SUPPLIER / OTHER, Q)
 *   getExcel       → aylık rapor Excel'i; indirme adresi döner (/api/pdf.php)
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use Biolab\Core\Mlyt\MonthlyImport;
use Biolab\Core\Mlyt\MonthlyReport;
use BIO\MLYT\Depot;
use BIO\MLYT\MonthlyPeriod;
use BIO\MLYT\PeriodFile;
use BIO\MLYT\Study;
use BIO\MNG\Company;

$db = $dbm->getDB();
$resourceFile = "monthly/monthly-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

const MT_MONTHS = array(1 => "Ocak", "Şubat", "Mart", "Nisan", "Mayıs", "Haziran", "Temmuz", "Ağustos", "Eylül", "Ekim", "Kasım", "Aralık");
// LabAsistan › Hastalar ekranındaki Birim listesi (yazımı LabAsistan'daki gibi). Birim seçilmeden indirilen liste
// bütün birimleri içerir: MT_ALL_UNITS olarak yüklenir. Bir dönemde ikisi karışmaz (aynı numune iki kez sayılmasın).
const MT_ALL_UNITS = "TÜM BİRİMLER";
const MT_UNITS = array("AYDIN", "BAYRAKLI", "BIOLAB", "CIGLI", "HAKAN", "KAHRAMANLAR", "KARSIYAKA", "MOBILAB", "PATOLOJI");

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    /* ---------- Seçim kutuları ---------- */

    case "getPERIOD_ID":
        $execType = ET_DEFAULT;
        try{
            $rows = $db->createQuery("
                SELECT p.periodId, p.periodYm, IDENTITY(p.study) AS studyId
                  FROM BIO\MLYT\MonthlyPeriod p
                 WHERE IDENTITY(p.company) = :COMPANY_ID AND p.recordFl > 0
                 ORDER BY p.periodYm DESC
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            foreach ($rows as &$r) { $r["periodText"] = mtPeriodText($r["periodYm"]); $r["activeFl"] = "Y"; }
            unset($r);
            $dtResponse = $rows;
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

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


    case "getStok";

    $execType= ET_DEFAULT;
    try{
        $dql="
            SELECT sg
            FROM BIO\MLYT\ServiceGroup sg
            
            WHERE sg.serviceGroupId >= 10
            and sg.activeFl = 'Y'
            and sg.recordFl > 0
            
        ";
        print_r($dql);
        die();

    } catch(Exception $exMain){
        $resCode = 100;
        $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
    }
        break;



    // "Tüm birimler" (LabAsistan'da birim boş bırakılarak alınan liste) + LabAsistan birimleri
    case "getUNIT_NAME":
        $execType = ET_DEFAULT;
        $dtResponse = array(array("unitName" => MT_ALL_UNITS, "unitText" => "Tüm birimler (LabAsistan'da birim boş)", "activeFl" => "Y"));
        foreach (MT_UNITS as $n) { $dtResponse[] = array("unitName" => $n, "unitText" => $n, "activeFl" => "Y"); }
        $resCode = 0;
        $resMsg = "Ok";
        break;

    // Fire / zayi bildirim dosyasının birimi: Netsis depo listesi (şube, OSGB, lab, ana depo) + PCR
    case "getFIRE_UNIT":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = array();
            foreach (mtFireUnits($db, $companyId) as $u) { $dtResponse[] = array("unitName" => $u, "unitText" => $u, "activeFl" => "Y"); }
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getDEPOT_TYPE":
        $execType = ET_DEFAULT;
        $dtResponse = array();
        foreach (array(Depot::TYPE_CUSTOMER, Depot::TYPE_OSGB, Depot::TYPE_BRANCH, Depot::TYPE_LAB, Depot::TYPE_OTHER) as $t) {
            $dtResponse[] = array("depotType" => $t, "depotTypeName" => MonthlyReport::TYPE_NAMES[$t], "activeFl" => "Y");
        }
        $resCode = 0;
        $resMsg = "Ok";
        break;

    /* ---------- Dönem ---------- */

    // PERIOD_YM: "2026-10"; STUDY_ID isteğe bağlı. Dönem zaten varsa yenisi açılmaz, o seçilir.
    case "setPeriod":
        $execType = ET_CUSTOM;
        try{
            $ym = trim((string) ($_POST["PERIOD_YM"] ?? ""));
            if (!preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $ym)) { $resCode = 10; $resMsg = "Ay seçin."; break; }
            if ($ym > date("Y-m")) { $resCode = 10; $resMsg = "Gelecek ay için dönem açılamaz."; break; }
            $period = $db->getRepository(MonthlyPeriod::class)->findOneBy(array("company" => $companyId, "periodYm" => $ym));
            if ($period !== null && $period->isDeleted()) { $period->restore(); }
            if ($period === null) {
                $period = new MonthlyPeriod($db->getReference(Company::class, $companyId), $ym, mtFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId));
                $db->persist($period);
                $resMsg = mtPeriodText($ym) . " dönemi açıldı";
            } else {
                $resMsg = mtPeriodText($ym) . " dönemi zaten vardı, o seçildi";
            }
            $db->flush();
            $resObject = array("periodId" => $period->getPeriodId());
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setPeriodStudy":
        $execType = ET_CUSTOM;
        try{
            $period = mtFindPeriod($db, (int) ($_POST["PERIOD_ID"] ?? 0), $companyId);
            if ($period === null) { $resCode = 20; $resMsg = "Dönem bulunamadı."; break; }
            $period->setStudy(mtFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId));
            $db->flush();
            $resCode = 0;
            $resMsg = "Maliyet çalışması güncellendi";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    /* ---------- Dosyalar ---------- */

    case "getFiles":
        $execType = ET_CUSTOM;
        try{
            $period = mtFindPeriod($db, (int) ($_POST["PERIOD_ID"] ?? 0), $companyId);
            if ($period === null) { $resCode = 20; $resMsg = "Dönem bulunamadı."; break; }
            $files = (new MonthlyReport($db, $period))->files();
            foreach ($files as &$f) {
                $f["dateFrom"] = $f["dateFrom"]?->format("d.m.Y");
                $f["dateTo"]   = $f["dateTo"]?->format("d.m.Y");
                $f["cAt"]      = $f["cAt"]?->format("d.m.Y H:i");
                $f["warnings"] = $f["warnings"] ? explode("\n", $f["warnings"]) : array();
            }
            unset($f);
            $resObject = array("files" => $files, "periodYm" => $period->getPeriodYm(), "periodText" => mtPeriodText($period->getPeriodYm()),
                               "studyId" => $period->getStudy()?->getStudyId());
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // FormData: PERIOD_ID, SOURCE_CODE, UNIT_NAME (numune listelerinde), FILE (.xlsx). Aynı kaynak + birim yeniden yüklenirse değiştirilir.
    case "setUpload":
        $execType = ET_CUSTOM;
        try{
            if (empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
                $resCode = 1; $resMsg = "Dosya sunucunun kabul ettiği boyutu aşıyor (post_max_size = " . ini_get("post_max_size") . ")."; break;
            }
            $period = mtFindPeriod($db, (int) ($_POST["PERIOD_ID"] ?? 0), $companyId);
            $source = (string) ($_POST["SOURCE_CODE"] ?? "");
            $upload = $_FILES["FILE"] ?? null;
            if ($period === null) { $resCode = 20; $resMsg = "Önce dönem seçin."; break; }
            if (!in_array($source, PeriodFile::SOURCES, true)) { $resCode = 10; $resMsg = "Kaynak geçersiz."; break; }
            if ($upload === null || $upload["error"] === UPLOAD_ERR_NO_FILE) { $resCode = 10; $resMsg = "Dosya seçin."; break; }
            if ($upload["error"] !== UPLOAD_ERR_OK) { $resCode = 10; $resMsg = "Dosya yüklenemedi (hata " . $upload["error"] . "). En çok " . ini_get("upload_max_filesize") . "."; break; }
            $ext = strtolower(pathinfo((string) $upload["name"], PATHINFO_EXTENSION));
            if ($ext !== "xlsx") {
                $resCode = 10;
                $resMsg = in_array($ext, array("xls", "xlsb"), true)
                    ? "Eski Excel biçimi (." . $ext . "): dosyayı Excel'de açıp Farklı Kaydet → \"Excel Çalışma Kitabı (.xlsx)\" olarak kaydedin."
                    : "Yalnız .xlsx dosyası yüklenebilir.";
                break;
            }
            $unit = "-";
            if ($source === PeriodFile::FIRE_REPORT) {
                $unit = trim((string) ($_POST["UNIT_NAME"] ?? ""));
                if (!in_array($unit, mtFireUnits($db, $companyId), true)) { $resCode = 10; $resMsg = "Formun ait olduğu birimi seçin (şube ya da PCR)."; break; }
            } elseif (in_array($source, PeriodFile::PER_UNIT, true)) {
                $unit = trim((string) ($_POST["UNIT_NAME"] ?? ""));
                if ($unit !== MT_ALL_UNITS && !in_array($unit, MT_UNITS, true)) {
                    $resCode = 10; $resMsg = "LabAsistan'da listeyi alırken seçtiğiniz birimi seçin (birim boşsa \"Tüm birimler\")."; break;
                }
                // Tüm birimler listesi ile tek birim listeleri aynı dönemde karışmaz: aynı numune iki kez sayılır, birimi belirsizleşir
                $other = $db->createQuery("
                    SELECT f.unitName FROM BIO\MLYT\PeriodFile f
                     WHERE f.period = :PERIOD AND f.sourceCode IN (:SOURCES) AND f.recordFl > 0 AND "
                     . ($unit === MT_ALL_UNITS ? "f.unitName <> :ALL" : "f.unitName = :ALL")
                )->setParameter("PERIOD", $period)->setParameter("SOURCES", PeriodFile::PER_UNIT)->setParameter("ALL", MT_ALL_UNITS)
                 ->setMaxResults(1)->getOneOrNullResult();
                if ($other !== null) {
                    $resCode = 10;
                    $resMsg = $unit === MT_ALL_UNITS
                        ? "Bu dönemde birim birim yüklenmiş numune listeleri var (" . $other["unitName"] . "…). Tüm birimler listesi yüklemek için önce onları silin."
                        : "Bu dönemde \"Tüm birimler\" numune listesi var; birim listesi yüklemek için önce onu silin.";
                    break;
                }
            }

            $file = $db->getRepository(PeriodFile::class)->findOneBy(array("period" => $period, "sourceCode" => $source, "unitName" => $unit));
            $replaced = $file !== null;
            $fileName = mb_substr(basename((string) $upload["name"]), 0, 255);
            if ($file === null) {
                $file = new PeriodFile($period, $source, $unit, $fileName);
                $db->persist($file);
            } else {
                if ($file->isDeleted()) { $file->restore(); $replaced = false; }
                $file->setFileName($fileName);
            }

            $db->getConnection()->beginTransaction();
            try {
                $db->flush();
                $res = (new MonthlyImport($db))->import($file, (string) $upload["tmp_name"]);
                $db->getConnection()->commit();
            } catch (\RuntimeException $e) {
                $db->getConnection()->rollBack();
                $resCode = 30; $resMsg = $e->getMessage();
                break;
            }
            $resObject = array("fileId" => $file->getFileId(), "rows" => $res["rows"], "skipped" => $res["skipped"], "warnings" => $res["warnings"]);
            $resCode = 0;
            $resMsg = MonthlyReport::sourceName($source) . ($unit !== "-" ? " (" . $unit . ")" : "") . ": "
                    . number_format($res["rows"], 0, ",", ".") . " satır " . ($replaced ? "yeniden yüklendi" : "yüklendi");
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setDeleteFile":
        $execType = ET_CUSTOM;
        try{
            $file = $db->getRepository(PeriodFile::class)->find((int) ($_POST["FILE_ID"] ?? 0));
            if ($file === null || $file->isDeleted() || (int) $file->getPeriod()->getCompany()->getCompanyId() !== $companyId) {
                $resCode = 20; $resMsg = "Dosya bulunamadı."; break;
            }
            (new MonthlyImport($db))->deleteRows($file);
            $file->setRowCount(0)->setWarnings(null)->setDates(null, null);
            $file->softDelete();
            $db->flush();
            $resCode = 0;
            $resMsg = $file->getFileName() . " silindi";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    /* ---------- Rapor ---------- */

    case "getSummary":
        $execType = ET_CUSTOM;
        try{
            $period = mtFindPeriod($db, (int) ($_POST["PERIOD_ID"] ?? 0), $companyId);
            if ($period === null) { $resCode = 20; $resMsg = "Dönem bulunamadı."; break; }
            $rep = new MonthlyReport($db, $period);
            $sum = $rep->summary();
            $sum["netsis"]["dateFrom"] = $sum["netsis"]["dateFrom"] ? (new DateTimeImmutable($sum["netsis"]["dateFrom"]))->format("d.m.Y") : null;
            $sum["netsis"]["dateTo"]   = $sum["netsis"]["dateTo"] ? (new DateTimeImmutable($sum["netsis"]["dateTo"]))->format("d.m.Y") : null;
            $resObject = array(
                "summary"      => $sum,
                "byArrival"    => $rep->samplesBy("arrival"),
                "byInstitution"=> $rep->samplesBy("institution"),
                "byUnit"       => $rep->samplesBy("unit"),
                "byDepot"      => $rep->netsisByDepot(),
                "fireByUnit"   => $rep->firesBy("unit"),
                "fireByReason" => $rep->firesBy("reason"),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // postData: PERIOD_ID, FILTER (ALL / PRESENT / RATE / PRICE / NOCOST), Q. Yalnız tekrarı olan testler.
    case "getRepeatList":
        $execType = ET_JQGRID;
        try{
            $period = mtFindPeriod($db, (int) ($_POST["PERIOD_ID"] ?? 0), $companyId);
            $rows = array();
            $counts = array("ALL" => 0, "PRESENT" => 0, "RATE" => 0, "PRICE" => 0, "NOCOST" => 0);
            if ($period !== null) {
                $q = MonthlyImport::norm($_POST["Q"] ?? "");
                $filter = (string) ($_POST["FILTER"] ?? "ALL");
                foreach ((new MonthlyReport($db, $period))->repeats() as $r) {
                    if ($r["repeatQty"] <= 0) { continue; }
                    if ($q !== "" && !str_contains(MonthlyImport::norm($r["code"] . " " . $r["name"] . " " . $r["group"]), $q)) { continue; }
                    $cat = $r["amount"] === null ? "NOCOST"
                         : (in_array(MonthlyReport::FLAG_PRICE, $r["flags"], true) ? "PRICE" : (in_array(MonthlyReport::FLAG_RATE, $r["flags"], true) ? "RATE" : "PRESENT"));
                    $counts["ALL"]++; $counts[$cat]++;
                    if ($filter !== "ALL" && $filter !== $cat) { continue; }
                    $rows[] = array("code" => $r["code"], "name" => $r["name"], "group" => $r["group"], "testQty" => $r["testQty"], "repeatQty" => $r["repeatQty"],
                                    "rate" => $r["rate"], "unitCost" => $r["unitCost"], "amount" => $r["amount"], "status" => $r["status"],
                                    "category" => $cat, "warning" => MonthlyReport::flagText($r["flags"]));
                }
            }
            $sortKey = in_array($_POST["sidx"] ?? "", array("code", "name", "group", "testQty", "repeatQty", "rate", "unitCost", "amount"), true) ? $_POST["sidx"] : "amount";
            $desc = strtoupper($_POST["sord"] ?? "DESC") === "DESC";
            usort($rows, function ($a, $b) use ($sortKey, $desc) {
                $c = is_string($a[$sortKey]) || is_string($b[$sortKey]) ? strcmp((string) $a[$sortKey], (string) $b[$sortKey]) : ($a[$sortKey] ?? -1) <=> ($b[$sortKey] ?? -1);
                return ($desc ? -$c : $c) ?: ($b["repeatQty"] <=> $a["repeatQty"]);
            });
            $totalRecords = count($rows);
            $perPage = max(1, (int) ($_POST["rows"] ?? 50));
            $page = max(1, (int) ($_POST["page"] ?? 1));
            $dtResponse = array_slice($rows, ($page - 1) * $perPage, $perPage);
            $resObject = array("counts" => $counts);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // postData: PERIOD_ID, DEPOT_TYPE, Q
    case "getNetsisList":
        $execType = ET_JQGRID;
        try{
            $period = mtFindPeriod($db, (int) ($_POST["PERIOD_ID"] ?? 0), $companyId);
            $rows = $period === null ? array() : (new MonthlyReport($db, $period))->netsisItems((string) ($_POST["DEPOT_TYPE"] ?? ""), (string) ($_POST["Q"] ?? ""));
            $sortKey = in_array($_POST["sidx"] ?? "", array("stockCode", "stockName", "unit", "total", "lines", Depot::TYPE_CUSTOMER, Depot::TYPE_OSGB,
                                                             Depot::TYPE_BRANCH, Depot::TYPE_LAB, Depot::TYPE_OTHER), true) ? $_POST["sidx"] : "lines";
            $desc = strtoupper($_POST["sord"] ?? "DESC") === "DESC";
            usort($rows, function ($a, $b) use ($sortKey, $desc) {
                $c = is_string($a[$sortKey]) ? strcmp($a[$sortKey], (string) $b[$sortKey]) : $a[$sortKey] <=> $b[$sortKey];
                return ($desc ? -$c : $c) ?: strcmp($a["stockCode"], $b["stockCode"]);
            });
            $totalRecords = count($rows);
            $perPage = max(1, (int) ($_POST["rows"] ?? 50));
            $page = max(1, (int) ($_POST["page"] ?? 1));
            $dtResponse = array_slice($rows, ($page - 1) * $perPage, $perPage);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // postData: PERIOD_ID, CATEGORY (FIRE / ZAYI / SUPPLIER / OTHER / ''), Q
    case "getFireList":
        $execType = ET_JQGRID;
        try{
            $period = mtFindPeriod($db, (int) ($_POST["PERIOD_ID"] ?? 0), $companyId);
            $rows = array();
            $counts = array("ALL" => 0, MonthlyReport::CAT_FIRE => 0, MonthlyReport::CAT_ZAYI => 0, MonthlyReport::CAT_SUPPLIER => 0, MonthlyReport::CAT_OTHER => 0);
            if ($period !== null) {
                $q = MonthlyImport::norm($_POST["Q"] ?? "");
                $cat = (string) ($_POST["CATEGORY"] ?? "");
                foreach ((new MonthlyReport($db, $period))->fires() as $r) {
                    if ($q !== "" && !str_contains(MonthlyImport::norm($r["unit"] . " " . $r["material"] . " " . $r["stockCode"] . " " . $r["reason"] . " " . $r["lotNo"]), $q)) { continue; }
                    $counts["ALL"]++; $counts[$r["category"]]++;
                    if ($cat !== "" && $cat !== "ALL" && $cat !== $r["category"]) { continue; }
                    $rows[] = $r;
                }
            }
            $sortKey = in_array($_POST["sidx"] ?? "", array("date", "unit", "material", "quantity", "reason", "amount", "affectedTests"), true) ? $_POST["sidx"] : "fireId";
            $desc = strtoupper($_POST["sord"] ?? "ASC") === "DESC";
            usort($rows, function ($a, $b) use ($sortKey, $desc) {
                $va = $sortKey === "date" ? implode("", array_reverse(explode(".", $a["date"]))) : $a[$sortKey];
                $vb = $sortKey === "date" ? implode("", array_reverse(explode(".", $b["date"]))) : $b[$sortKey];
                $c = is_string($va) || is_string($vb) ? strcmp((string) $va, (string) $vb) : ($va ?? -1) <=> ($vb ?? -1);
                return ($desc ? -$c : $c) ?: $a["fireId"] <=> $b["fireId"];
            });
            $totalRecords = count($rows);
            $perPage = max(1, (int) ($_POST["rows"] ?? 50));
            $page = max(1, (int) ($_POST["page"] ?? 1));
            $dtResponse = array_slice($rows, ($page - 1) * $perPage, $perPage);
            $resObject = array("counts" => $counts);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getExcel":
        $execType = ET_CUSTOM;
        try{
            $period = mtFindPeriod($db, (int) ($_POST["PERIOD_ID"] ?? 0), $companyId);
            if ($period === null) { $resCode = 20; $resMsg = "Dönem seçin."; break; }
            $rep = new MonthlyReport($db, $period);
            $fileName = $rep->fileName();
            $token = \Biolab\Core\Http\PdfHandoff::stash($rep->excel(), $fileName,
                "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
            $resObject = array("url" => "/api/pdf.php?t=" . $token, "fileName" => $fileName);
            $resCode = 0;
            $resMsg = "Excel hazır";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    default:
        $resCode = 9000;
        $resMsg = "Bilinmeyen işlem: " . $query;
}

/* ---------- Yardımcılar ---------- */

function mtFindPeriod($db, int $periodId, int $companyId): ?MonthlyPeriod
{
    if ($periodId <= 0) { return null; }
    $p = $db->getRepository(MonthlyPeriod::class)->findOneBy(array("periodId" => $periodId, "company" => $companyId));
    return ($p !== null && !$p->isDeleted()) ? $p : null;
}

function mtFindStudy($db, int $studyId, int $companyId): ?Study
{
    if ($studyId <= 0) { return null; }
    $s = $db->getRepository(Study::class)->findOneBy(array("studyId" => $studyId, "company" => $companyId));
    return ($s !== null && !$s->isDeleted()) ? $s : null;
}

/** Fire / zayi bildirimi birimleri: "131 ALSANCAK ŞUBE" biçiminde depo listesi (diğer giderler hariç) + PCR; şubeler önce */
function mtFireUnits($db, int $companyId): array
{
    $order = array(Depot::TYPE_BRANCH => 1, Depot::TYPE_OSGB => 2, Depot::TYPE_LAB => 3, Depot::TYPE_STORE => 4);
    $rows = $db->createQuery("
        SELECT d.depotCode, d.depotName, d.depotType FROM BIO\MLYT\Depot d
         WHERE IDENTITY(d.company) = :COMPANY_ID AND d.recordFl > 0 AND d.depotType <> :OTHER
    ")->setParameter("COMPANY_ID", $companyId)->setParameter("OTHER", Depot::TYPE_OTHER)->getArrayResult();
    usort($rows, fn ($a, $b) => ($order[$a["depotType"]] ?? 9) <=> ($order[$b["depotType"]] ?? 9) ?: strcmp($a["depotName"], $b["depotName"]));
    $units = array_map(fn ($d) => $d["depotCode"] . " " . $d["depotName"], $rows);
    array_splice($units, count(array_filter($rows, fn ($d) => $d["depotType"] === Depot::TYPE_BRANCH)), 0, array(MonthlyReport::FIRE_UNIT_PCR));
    return $units;
}

/** "2026-10" → "Ekim 2026" */
function mtPeriodText(string $ym): string
{
    [$y, $m] = array_map("intval", explode("-", $ym));
    return (MT_MONTHS[$m] ?? $ym) . " " . $y;
}
