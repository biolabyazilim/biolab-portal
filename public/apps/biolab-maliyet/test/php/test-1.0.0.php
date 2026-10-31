<?php
/**
 * Maliyet — Test tanımları
 *
 *   Seçim kutuları (lib.js $.fn.slb; pasif kayıt listede görünür ama seçilemez):
 *   getSERVICE_GROUP_ID → hizmet grupları (filtre ve form)
 *   getCOST_METHOD      → maliyet yöntemleri (filtre ve form)
 *   getLAB_UNIT         → Biolab / Patoloji
 *   getSUPPLIER_ID      → tedarikçi / cihaz
 *
 *   getGridList   → test listesi (jqGrid; sekme / hizmet grubu / yöntem / birim / arama sunucuda)
 *   getSelectedId → seçilen test + bileşenleri (birleşik / hesaplamalı testlerde)
 *   setTest       → test ekle / düzenle
 *
 * Kod, ad, hizmet grubu ve birim Tenay test listesinden gelir; mevcut testte değiştirilmez (Excel aktarımı günceller).
 * Ekranda maliyet yöntemi, tedarikçi, patoloji düzeyi, SUT kodu, numune tipi, sorumlu, not ve aktiflik düzenlenir.
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords;

use BIO\MLYT\ServiceGroup;
use BIO\MLYT\Supplier;
use BIO\MLYT\Test;
use BIO\MLYT\TestComponent;
use BIO\MNG\Company;

$db = $dbm->getDB();
$resourceFile = "test/test-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

// Maliyet yöntemi: kod → [tr, en]
const TS_METHOD_NAMES = array(
    Test::METHOD_KIT        => array("Kit / cihaz", "Kit / device"),
    Test::METHOD_RECIPE     => array("Reçete", "Recipe"),
    Test::METHOD_SERVICE    => array("Hizmet alımı", "Outsourced"),
    Test::METHOD_CALCULATED => array("Hesaplamalı", "Calculated"),
    Test::METHOD_UNDEFINED  => array("Belirsiz", "Undefined"),
);
const TS_UNIT_NAMES = array(
    Test::UNIT_BIOLAB    => array("Biolab", "Biolab"),
    Test::UNIT_PATHOLOGY => array("Patoloji", "Pathology"),
);

$companyId = (int) $_SESSION["COMPANY_ID"];
$sessionLang = (string) ($_SESSION["lang"] ?? "tr");
$li = $sessionLang === "en" ? 1 : 0;

switch ($query) {

    /* ---------- Seçim kutuları ---------- */

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
        foreach (TS_METHOD_NAMES as $code => $names) {
            $dtResponse[] = array("costMethod" => $code, "costMethodName" => $names[$li], "activeFl" => "Y");
        }
        $resCode = 0;
        $resMsg = "Ok";
        break;

    case "getLAB_UNIT":
        $execType = ET_DEFAULT;
        $dtResponse = array();
        foreach (TS_UNIT_NAMES as $code => $names) {
            $dtResponse[] = array("labUnit" => $code, "labUnitName" => $names[$li], "activeFl" => "Y");
        }
        $resCode = 0;
        $resMsg = "Ok";
        break;

    case "getSUPPLIER_ID":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = $db->createQuery("
                SELECT s.supplierId, s.supplierName, s.activeFl
                  FROM BIO\MLYT\Supplier s
                 WHERE IDENTITY(s.company) = :COMPANY_ID AND s.recordFl > 0
                 ORDER BY s.supplierName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    /* ---------- Liste ---------- */

    // postData: TAB (all / undefined / inactive), SERVICE_GROUP_ID, COST_METHOD, LAB_UNIT, Q (kod / ad / tedarikçi)
    // resObject.counts: sekme sayıları (filtrelerden bağımsız)
    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $from = "
                  FROM BIO\MLYT\Test t
                  JOIN t.serviceGroup g
                  LEFT JOIN t.supplier s
                 WHERE IDENTITY(t.company) = :COMPANY_ID
                   AND t.recordFl > 0
            ";
            $params = array("COMPANY_ID" => $companyId);

            $cnt = $db->createQuery("
                SELECT COUNT(t.testId) AS allCount,
                       SUM(CASE WHEN t.costMethod = 'UNDEFINED' AND t.activeFl = 'Y' THEN 1 ELSE 0 END) AS undefinedCount,
                       SUM(CASE WHEN t.activeFl = 'N' THEN 1 ELSE 0 END) AS inactiveCount
            " . $from)->setParameters($params)->getSingleResult();

            $whereClause = "";
            $tab = (string) ($_POST["TAB"] ?? "all");
            if ($tab === "undefined") { $whereClause .= " AND t.costMethod = 'UNDEFINED' AND t.activeFl = 'Y'"; }
            if ($tab === "inactive")  { $whereClause .= " AND t.activeFl = 'N'"; }
            if ((int) ($_POST["SERVICE_GROUP_ID"] ?? 0) > 0) {
                $whereClause .= " AND g.serviceGroupId = :SERVICE_GROUP_ID";
                $params["SERVICE_GROUP_ID"] = (int) $_POST["SERVICE_GROUP_ID"];
            }
            if (isset(TS_METHOD_NAMES[$_POST["COST_METHOD"] ?? ""])) {
                $whereClause .= " AND t.costMethod = :COST_METHOD";
                $params["COST_METHOD"] = $_POST["COST_METHOD"];
            }
            if (isset(TS_UNIT_NAMES[$_POST["LAB_UNIT"] ?? ""])) {
                $whereClause .= " AND t.labUnit = :LAB_UNIT";
                $params["LAB_UNIT"] = $_POST["LAB_UNIT"];
            }
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $whereClause .= " AND (t.testCode LIKE :Q_LIKE OR t.testName LIKE :Q_LIKE OR s.supplierName LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }

            $totalRecords = (int) $db->createQuery("SELECT COUNT(t.testId) " . $from . $whereClause)
                ->setParameters($params)->getSingleScalarResult();

            // Sıralama alanı istemciden gelir; DQL'e eklenmeden önce izin listesiyle kontrol edilir
            $sortable  = array("t.testCode", "t.testName", "g.serviceGroupName", "t.labUnit", "t.costMethod", "s.supplierName", "t.pathologyLevel");
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "t.testName";
            $direction = strtoupper($_POST["sord"] ?? "") === "DESC" ? "DESC" : "ASC";

            $rows = max(1, (int) ($_POST["rows"] ?? 25));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $dtResponse = $db->createQuery("
                SELECT t.testId AS t_testId, t.testCode AS t_testCode, t.testName AS t_testName,
                       g.serviceGroupName AS g_serviceGroupName, t.labUnit AS t_labUnit, t.costMethod AS t_costMethod,
                       s.supplierName AS s_supplierName, t.pathologyLevel AS t_pathologyLevel, t.sutCode AS t_sutCode,
                       t.sourceSupplierText AS t_sourceSupplierText, t.responsibleName AS t_responsibleName, t.activeFl AS t_activeFl,
                       (SELECT COUNT(tc.testComponentId) FROM BIO\MLYT\TestComponent tc WHERE tc.test = t AND tc.recordFl > 0) AS componentCount
            " . $from . $whereClause . " ORDER BY " . $sortBy . " " . $direction . ", t.testId ASC")
                ->setParameters($params)
                ->setMaxResults($rows)
                ->setFirstResult(($page - 1) * $rows)
                ->getArrayResult();

            foreach ($dtResponse as &$r) {
                $r["t_costMethodName"] = TS_METHOD_NAMES[$r["t_costMethod"]][$li] ?? $r["t_costMethod"];
                $r["t_labUnitName"]    = TS_UNIT_NAMES[$r["t_labUnit"]][$li] ?? $r["t_labUnit"];
                $r["componentCount"]   = (int) $r["componentCount"];
            }
            unset($r);

            $resObject = array("counts" => array(
                "all"       => (int) $cnt["allCount"],
                "undefined" => (int) $cnt["undefinedCount"],
                "inactive"  => (int) $cnt["inactiveCount"],
            ));
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Seçilen test + bileşenleri → form
    case "getSelectedId":
        $execType = ET_DEFAULT;
        try{
            $test = tsFindTest($db, (int) ($_POST["TEST_ID"] ?? 0), $companyId);
            if ($test === null) { $resCode = 20; $resMsg = "Test bulunamadı."; break; }

            $components = $db->createQuery("
                SELECT c.testCode, c.testName, tc.quantity, tc.applyRate
                  FROM BIO\MLYT\TestComponent tc
                  JOIN tc.componentTest c
                 WHERE tc.test = :TEST AND tc.recordFl > 0
                 ORDER BY c.testName
            ")->setParameter("TEST", $test)->getArrayResult();

            $dtResponse = array(
                "testId"             => $test->getTestId(),
                "testCode"           => $test->getTestCode(),
                "testName"           => $test->getTestName(),
                "serviceGroupId"     => $test->getServiceGroup()->getServiceGroupId(),
                "serviceGroupName"   => $test->getServiceGroup()->getServiceGroupName(),
                "labUnit"            => $test->getLabUnit(),
                "costMethod"         => $test->getCostMethod(),
                "supplierId"         => $test->getSupplier()?->getSupplierId(),
                "supplierName"       => $test->getSupplier()?->getSupplierName(),
                "pathologyLevel"     => $test->getPathologyLevel(),
                "sutCode"            => $test->getSutCode(),
                "sampleType"         => $test->getSampleType(),
                "responsibleName"    => $test->getResponsibleName(),
                "note"               => $test->getNote(),
                "active"             => $test->isActive(),
                "sourceSupplierText" => $test->getSourceSupplierText(),
                "sourceLevelText"    => $test->getSourceLevelText(),
                "sourceMaterialText" => $test->getSourceMaterialText(),
                "components"         => $components,
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setTest":
        $execType = ET_CUSTOM;
        try{
            $testId = (int) ($_POST["TEST_ID"] ?? 0);
            $method = (string) ($_POST["COST_METHOD"] ?? "");
            $level  = ($_POST["PATHOLOGY_LEVEL"] ?? null) !== null ? (int) $_POST["PATHOLOGY_LEVEL"] : null;
            $sut    = trim((string) ($_POST["SUT_CODE"] ?? ""));

            $errors = array();
            if (!isset(TS_METHOD_NAMES[$method])) { $errors[] = "Maliyet yöntemi seçin."; }
            if ($level !== null && ($level < 1 || $level > 4)) { $errors[] = "Patoloji düzeyi 1 ile 4 arasında olmalı."; }
            if ($sut !== "" && !preg_match('/^\d{6}$/', $sut)) { $errors[] = "SUT kodu 6 haneli olmalı."; }

            if ($testId > 0) {
                $test = tsFindTest($db, $testId, $companyId);
                if ($test === null) { $resCode = 20; $resMsg = "Test bulunamadı."; break; }
            } else {
                // Yeni test: Tenay'da açılmış ama Excel'e henüz girmemiş test için
                $code  = trim((string) ($_POST["TEST_CODE"] ?? ""));
                $name  = trim((string) ($_POST["TEST_NAME"] ?? ""));
                $group = tsFindGroup($db, (int) ($_POST["SERVICE_GROUP_ID"] ?? 0), $companyId);
                $unit  = (string) ($_POST["LAB_UNIT"] ?? "");
                if ($code === "") { $errors[] = "Tenay test kodunu girin."; }
                if ($name === "") { $errors[] = "Test adını girin."; }
                if ($group === null) { $errors[] = "Hizmet grubu seçin."; }
                if (!isset(TS_UNIT_NAMES[$unit])) { $errors[] = "Birim seçin."; }
                if ($code !== "" && $db->getRepository(Test::class)->findOneBy(array("company" => $companyId, "testCode" => $code)) !== null) {
                    $errors[] = "Bu kodla bir test zaten var.";
                }
            }
            if ($errors) {
                $resCode = 10;
                $resMsg = implode(" ", $errors);
                break;
            }

            if ($testId <= 0) {
                $test = new Test($code, $name, $group);
                $test->setCompany($db->getReference(Company::class, $companyId))->setLabUnit($unit);
                $db->persist($test);
            }
            $test->setCostMethod($method)
                 ->setSupplier(tsSupplier($db, $companyId, $_POST["SUPPLIER_ID"] ?? null, $_POST["SUPPLIER_NAME"] ?? null))
                 ->setPathologyLevel($level)
                 ->setSutCode($sut !== "" ? $sut : null)
                 ->setSampleType(tsText($_POST["SAMPLE_TYPE"] ?? null))
                 ->setResponsibleName(tsText($_POST["RESPONSIBLE_NAME"] ?? null))
                 ->setNote(tsText($_POST["NOTE"] ?? null))
                 ->setActive(($_POST["ACTIVE"] ?? "Y") === "Y");
            $db->flush();

            $resObject = array("testId" => $test->getTestId());
            $resCode = 0;
            $resMsg = "Test kaydedildi";
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

function tsFindTest($db, int $testId, int $companyId): ?Test
{
    if ($testId <= 0) { return null; }
    $t = $db->getRepository(Test::class)->findOneBy(array("testId" => $testId, "company" => $companyId));
    return ($t !== null && !$t->isDeleted()) ? $t : null;
}

function tsFindGroup($db, int $groupId, int $companyId): ?ServiceGroup
{
    if ($groupId <= 0) { return null; }
    $g = $db->getRepository(ServiceGroup::class)->findOneBy(array("serviceGroupId" => $groupId, "company" => $companyId));
    return ($g !== null && $g->isActive() && !$g->isDeleted()) ? $g : null;
}

/** Seçilen tedarikçi; listede yoksa yazılan adla yenisi açılır; ikisi de boşsa tedarikçi yok */
function tsSupplier($db, int $companyId, $supplierId, $supplierName): ?Supplier
{
    if ($supplierId !== null && (int) $supplierId > 0) {
        return $db->getRepository(Supplier::class)->findOneBy(array("supplierId" => (int) $supplierId, "company" => $companyId));
    }
    $name = trim((string) $supplierName);
    if ($name === "") { return null; }
    $s = $db->getRepository(Supplier::class)->findOneBy(array("supplierName" => $name, "company" => $companyId));
    if ($s === null) {
        $s = new Supplier($name);
        $s->setCompany($db->getReference(Company::class, $companyId));
        $db->persist($s);
    }
    return $s;
}

function tsText($v): ?string
{
    $v = trim((string) $v);
    return $v === "" ? null : $v;
}
