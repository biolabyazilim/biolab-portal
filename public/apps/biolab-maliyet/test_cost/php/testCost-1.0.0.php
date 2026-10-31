<?php
/**
 * Maliyet — Test maliyetleri (hasta başı malzeme maliyeti)
 *
 *   Seçim kutuları (lib.js $.fn.slb):
 *   getSTUDY_ID, getSERVICE_GROUP_ID, getCOST_METHOD, getLAB_UNIT → filtreler
 *   getMATERIAL_ID → reçeteye eklenecek malzeme
 *
 *   getGridList   → testler + maliyet + durum (jqGrid). Hesap Biolab\Core\Mlyt\CostCalculator ile, seçili çalışmanın
 *                   fiyat ve kurlarıyla; filtre / sıralama / sayfalama hesaptan sonra PHP'de yapılır (maliyete göre sıralanabilsin)
 *   getSelectedId → seçilen testin reçetesi / bileşenleri, kırılımı ve eksikleri
 *   setRecipe     → reçeteyi kaydeder (malzeme + hasta başı miktar); kayıttan sonra test yeniden hesaplanır
 *   getExcelPathology → PATOLOJİ sayfası düzeninde Excel (Biolab\Core\Mlyt\PathologyExport); indirme adresi döner (/api/pdf.php)
 *
 * Hesaplamalı (birleşik) testlerin bileşenleri burada değiştirilmez; maliyetleri bileşenlerin toplamıdır.
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords;

use Biolab\Core\Mlyt\CostCalculator;
use BIO\MLYT\Material;
use BIO\MLYT\RecipeItem;
use BIO\MLYT\Study;
use BIO\MLYT\Test;

$db = $dbm->getDB();
$resourceFile = "test_cost/testCost-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

const TC_METHOD_NAMES = array(
    Test::METHOD_KIT        => array("Kit / cihaz", "Kit / device"),
    Test::METHOD_RECIPE     => array("Reçete", "Recipe"),
    Test::METHOD_SERVICE    => array("Hizmet alımı", "Outsourced"),
    Test::METHOD_CALCULATED => array("Hesaplamalı", "Calculated"),
    Test::METHOD_UNDEFINED  => array("Belirsiz", "Undefined"),
);
const TC_UNIT_NAMES = array(
    Test::UNIT_BIOLAB    => array("Biolab", "Biolab"),
    Test::UNIT_PATHOLOGY => array("Patoloji", "Pathology"),
);
const TC_MATERIAL_UNITS = array(
    Material::UNIT_PIECE => array("adet", "piece"), Material::UNIT_TEST => array("test", "test"),
    Material::UNIT_ML    => array("ml", "ml"),      Material::UNIT_GR   => array("gr", "g"),
);

$companyId = (int) $_SESSION["COMPANY_ID"];
$sessionLang = (string) ($_SESSION["lang"] ?? "tr");
$li = $sessionLang === "en" ? 1 : 0;

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
            foreach ($rows as &$r) {
                $r["studyText"] = $r["studyName"] . " · " . ($statusText[$r["status"]] ?? $r["status"]);
            }
            unset($r);
            $dtResponse = $rows;
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getSERVICE_GROUP_ID":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = $db->createQuery("
                SELECT g.serviceGroupId, g.serviceGroupName, g.activeFl
                  FROM BIO\MLYT\ServiceGroup g
                 WHERE IDENTITY(g.company) = :COMPANY_ID AND g.recordFl > 0
                 ORDER BY g.serviceGroupName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getCOST_METHOD":
        $execType = ET_DEFAULT;
        $dtResponse = array();
        foreach (TC_METHOD_NAMES as $code => $names) {
            $dtResponse[] = array("costMethod" => $code, "costMethodName" => $names[$li], "activeFl" => "Y");
        }
        $resCode = 0;
        $resMsg = "Ok";
        break;

    case "getLAB_UNIT":
        $execType = ET_DEFAULT;
        $dtResponse = array();
        foreach (TC_UNIT_NAMES as $code => $names) {
            $dtResponse[] = array("labUnit" => $code, "labUnitName" => $names[$li], "activeFl" => "Y");
        }
        $resCode = 0;
        $resMsg = "Ok";
        break;

    // Reçeteye eklenecek malzeme: "DOKU TAKİP KASETİ (adet)"
    case "getMATERIAL_ID":
        $execType = ET_DEFAULT;
        try{
            $rows = $db->createQuery("
                SELECT m.materialId, m.materialName, m.usageUnit, m.activeFl
                  FROM BIO\MLYT\Material m
                 WHERE IDENTITY(m.company) = :COMPANY_ID AND m.recordFl > 0
                 ORDER BY m.materialName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            foreach ($rows as &$r) {
                $r["materialText"] = $r["materialName"] . " (" . (TC_MATERIAL_UNITS[$r["usageUnit"]][$li] ?? $r["usageUnit"]) . ")";
            }
            unset($r);
            $dtResponse = $rows;
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    /* ---------- Liste ---------- */

    // postData: STUDY_ID, TAB (all / durum kodu), LAB_UNIT, SERVICE_GROUP_ID, COST_METHOD, Q (kod / ad)
    // resObject: counts (durum sekmeleri; diğer filtrelere göre), total (listelenen testlerin maliyet toplamı), study
    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $study = tcFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId);

            $where = "";
            $params = array("COMPANY_ID" => $companyId);
            if (isset(TC_UNIT_NAMES[$_POST["LAB_UNIT"] ?? ""])) {
                $where .= " AND t.labUnit = :LAB_UNIT";
                $params["LAB_UNIT"] = $_POST["LAB_UNIT"];
            }
            if ((int) ($_POST["SERVICE_GROUP_ID"] ?? 0) > 0) {
                $where .= " AND g.serviceGroupId = :SERVICE_GROUP_ID";
                $params["SERVICE_GROUP_ID"] = (int) $_POST["SERVICE_GROUP_ID"];
            }
            if (isset(TC_METHOD_NAMES[$_POST["COST_METHOD"] ?? ""])) {
                $where .= " AND t.costMethod = :COST_METHOD";
                $params["COST_METHOD"] = $_POST["COST_METHOD"];
            }
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $where .= " AND (t.testCode LIKE :Q_LIKE OR t.testName LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }

            // Pasif testler maliyet çalışmasına girmez
            $tests = $db->createQuery("
                SELECT t.testId, t.testCode, t.testName, g.serviceGroupName, t.labUnit, t.costMethod, t.pathologyLevel, t.sourceMaterialText
                  FROM BIO\MLYT\Test t
                  JOIN t.serviceGroup g
                 WHERE IDENTITY(t.company) = :COMPANY_ID AND t.recordFl > 0 AND t.activeFl = 'Y'
            " . $where)->setParameters($params)->getArrayResult();

            $calc = new CostCalculator($db, $companyId, $study?->getStudyId(), $sessionLang);
            $counts = array("all" => 0);
            $rowsAll = array();
            foreach ($tests as $t) {
                $res = $calc->result((int) $t["testId"]);
                $counts["all"]++;
                $counts[$res["status"]] = ($counts[$res["status"]] ?? 0) + 1;
                $rowsAll[] = array(
                    "t_testId"             => (int) $t["testId"],
                    "t_testCode"           => $t["testCode"],
                    "t_testName"           => $t["testName"],
                    "g_serviceGroupName"   => $t["serviceGroupName"],
                    "t_labUnitName"        => TC_UNIT_NAMES[$t["labUnit"]][$li] ?? $t["labUnit"],
                    "t_costMethod"         => $t["costMethod"],
                    "t_costMethodName"     => TC_METHOD_NAMES[$t["costMethod"]][$li] ?? $t["costMethod"],
                    "t_pathologyLevel"     => $t["pathologyLevel"],
                    "t_sourceMaterialText" => $t["sourceMaterialText"],
                    "cost"                 => $res["cost"],
                    "status"               => $res["status"],
                    "statusText"           => $calc->statusText($res["status"]),
                    "missing"              => array_slice($res["missing"], 0, 3),
                    "missingCount"         => count($res["missing"]),
                    "lineCount"            => count($res["lines"]),
                );
            }

            $tab = (string) ($_POST["TAB"] ?? "all");
            $rows = $tab === "all" ? $rowsAll : array_values(array_filter($rowsAll, fn ($r) => $r["status"] === $tab));
            $totalRecords = count($rows);

            // Sıralama: izin listesi; maliyet boş olanlar her zaman sonda
            $sortMap = array("t.testCode" => "t_testCode", "t.testName" => "t_testName", "g.serviceGroupName" => "g_serviceGroupName",
                             "t.costMethod" => "t_costMethodName", "cost" => "cost", "status" => "statusText");
            $sortKey = $sortMap[$_POST["sidx"] ?? ""] ?? "t_testName";
            $desc = strtoupper($_POST["sord"] ?? "") === "DESC";
            $collator = class_exists("Collator") ? new Collator("tr_TR") : null;   // Türkçe sıra (Ç, Ğ, İ, Ö, Ş, Ü)
            usort($rows, function ($a, $b) use ($sortKey, $desc, $collator) {
                if ($sortKey === "cost") {
                    if ($a["cost"] === null || $b["cost"] === null) { return ($a["cost"] === null) <=> ($b["cost"] === null); }
                    $c = $a["cost"] <=> $b["cost"];
                } else {
                    $c = $collator ? $collator->compare((string) $a[$sortKey], (string) $b[$sortKey]) : strcmp((string) $a[$sortKey], (string) $b[$sortKey]);
                }
                return ($desc ? -$c : $c) ?: ($a["t_testId"] <=> $b["t_testId"]);
            });

            $perPage = max(1, (int) ($_POST["rows"] ?? 25));
            $page = max(1, (int) ($_POST["page"] ?? 1));
            $dtResponse = array_slice($rows, ($page - 1) * $perPage, $perPage);

            $resObject = array(
                "counts" => $counts,
                "total"  => round(array_sum(array_map(fn ($r) => $r["cost"] ?? 0, $rows)), 2),
                "study"  => $study ? array("studyId" => $study->getStudyId(), "studyName" => $study->getStudyName(), "editable" => $study->isEditable()) : null,
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getSelectedId":
        $execType = ET_DEFAULT;
        try{
            $test = tcFindTest($db, (int) ($_POST["TEST_ID"] ?? 0), $companyId);
            if ($test === null) { $resCode = 20; $resMsg = "Test bulunamadı."; break; }
            $study = tcFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId);
            $dtResponse = tcDetail($db, $test, $study, $companyId, $sessionLang, $li);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // ITEMS: JSON [{materialId, quantity}] — reçetenin tamamı (listede olmayan satır silinir)
    case "setRecipe":
        $execType = ET_CUSTOM;
        try{
            $test = tcFindTest($db, (int) ($_POST["TEST_ID"] ?? 0), $companyId);
            if ($test === null) { $resCode = 20; $resMsg = "Test bulunamadı."; break; }
            if ($test->getCostMethod() === Test::METHOD_CALCULATED) {
                $resCode = 30; $resMsg = "Hesaplamalı testin maliyeti bileşenlerinden gelir; reçete girilmez."; break;
            }
            $items = json_decode((string) ($_POST["ITEMS"] ?? "[]"), true);
            if (!is_array($items)) { $resCode = 10; $resMsg = "Reçete okunamadı."; break; }

            $errors = array();
            $wanted = array();   // materialId → miktar
            foreach ($items as $i => $it) {
                $mid = (int) ($it["materialId"] ?? 0);
                $qty = tcDecimal($it["quantity"] ?? null);
                if ($mid <= 0) { $errors[] = "Satır " . ($i + 1) . ": malzeme seçin."; continue; }
                if ($qty === null || (float) $qty <= 0) { $errors[] = "Satır " . ($i + 1) . ": miktar sıfırdan büyük olmalı."; continue; }
                if (isset($wanted[$mid])) { $errors[] = "Aynı malzeme iki kez eklenmiş."; continue; }
                $wanted[$mid] = $qty;
            }
            if ($errors) { $resCode = 10; $resMsg = implode(" ", array_unique($errors)); break; }

            $materials = array();
            foreach ($wanted as $mid => $qty) {
                $m = $db->getRepository(Material::class)->findOneBy(array("materialId" => $mid, "company" => $companyId));
                if ($m === null || $m->isDeleted()) { $errors[] = "Malzeme bulunamadı (#" . $mid . ")."; continue; }
                $materials[$mid] = $m;
            }
            if ($errors) { $resCode = 10; $resMsg = implode(" ", $errors); break; }

            // Mevcut satırlar: aynı malzeme güncellenir, listede olmayan silinir, yeni olan eklenir
            $existing = array();
            foreach ($db->getRepository(RecipeItem::class)->findBy(array("test" => $test, "consumption" => RecipeItem::PER_PATIENT)) as $ri) {
                $existing[$ri->getMaterial()->getMaterialId()] = $ri;
            }
            $sort = 0;
            foreach ($wanted as $mid => $qty) {
                $sort += 10;
                if (isset($existing[$mid])) {
                    $existing[$mid]->setQuantity($qty)->setSortNo($sort);
                    unset($existing[$mid]);
                } else {
                    $db->persist(new RecipeItem($test, $materials[$mid], $qty, RecipeItem::PER_PATIENT, $sort));
                }
            }
            foreach ($existing as $ri) { $db->remove($ri); }
            $db->flush();

            $study = tcFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId);
            $resObject = tcDetail($db, $test, $study, $companyId, $sessionLang, $li);
            $resCode = 0;
            $resMsg = "Reçete kaydedildi";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Seçili çalışmanın patoloji maliyet Excel'i (tüm aktif patoloji testleri; ekrandaki filtreler uygulanmaz)
    case "getExcelPathology":
        $execType = ET_CUSTOM;
        try{
            $study = tcFindStudy($db, (int) ($_POST["STUDY_ID"] ?? 0), $companyId);
            if ($study === null) { $resCode = 20; $resMsg = "Excel için bir maliyet çalışması seçin."; break; }
            $export = new \Biolab\Core\Mlyt\PathologyExport($db, $companyId, $study);
            $fileName = $export->fileName();
            $token = \Biolab\Core\Http\PdfHandoff::stash($export->bytes(), $fileName,
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

function tcFindStudy($db, int $studyId, int $companyId): ?Study
{
    if ($studyId <= 0) { return null; }
    $s = $db->getRepository(Study::class)->findOneBy(array("studyId" => $studyId, "company" => $companyId));
    return ($s !== null && !$s->isDeleted()) ? $s : null;
}

function tcFindTest($db, int $testId, int $companyId): ?Test
{
    if ($testId <= 0) { return null; }
    $t = $db->getRepository(Test::class)->findOneBy(array("testId" => $testId, "company" => $companyId));
    return ($t !== null && !$t->isDeleted()) ? $t : null;
}

/** Formu dolduran ayrıntı: test bilgisi + hesap (kırılım, eksikler) */
function tcDetail($db, Test $test, ?Study $study, int $companyId, string $sessionLang, int $li): array
{
    $calc = new CostCalculator($db, $companyId, $study?->getStudyId(), $sessionLang);
    $res = $calc->result((int) $test->getTestId());
    return array(
        "testId"             => $test->getTestId(),
        "testCode"           => $test->getTestCode(),
        "testName"           => $test->getTestName(),
        "serviceGroupName"   => $test->getServiceGroup()->getServiceGroupName(),
        "costMethod"         => $test->getCostMethod(),
        "costMethodName"     => TC_METHOD_NAMES[$test->getCostMethod()][$li] ?? $test->getCostMethod(),
        "pathologyLevel"     => $test->getPathologyLevel(),
        "sourceMaterialText" => $test->getSourceMaterialText(),
        "recipeEditable"     => $test->getCostMethod() !== Test::METHOD_CALCULATED,
        "studyName"          => $study?->getStudyName(),
        "cost"               => $res["cost"],
        "status"             => $res["status"],
        "statusText"         => $calc->statusText($res["status"]),
        "missing"            => $res["missing"],
        "lines"              => $res["lines"],
    );
}

/** "1.234,56" / "0,5" / "2" → "1234.56" ; boşsa null */
function tcDecimal($v): ?string
{
    if ($v === null) { return null; }
    $v = str_replace(array(" ", "\u{00A0}"), "", trim((string) $v));
    if ($v === "") { return null; }
    if (str_contains($v, ",")) { $v = str_replace(array(".", ","), array("", "."), $v); }
    return is_numeric($v) ? $v : null;
}
