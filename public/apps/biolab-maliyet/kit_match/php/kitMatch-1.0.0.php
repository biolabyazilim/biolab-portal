<?php
/**
 * Maliyet — Kit eşleştirme (kitleri testlere ad benzerliğiyle önerir, uzman onaylayınca bağlar)
 *
 *   Seçim kutuları (lib.js $.fn.slb):
 *   getCOST_METHOD → yöntem filtresi (Kit, Belirsiz, Reçete)
 *
 *   getGridList → aday testler + öneriler (jqGrid). Öneri Biolab\Core\Mlyt\KitMatcher ile; sekme / filtre / sıralama /
 *                 sayfalama PHP'de yapılır (testCost'taki gibi). resObject: counts (sekme sayıları), kits (bütün kitler)
 *   setLink     → seçilen test–kit çiftlerini bağlar: reçeteye hasta başı satır (Malzemeler'deki "Teste bağla" ile aynı).
 *                 Yöntemi Belirsiz olan test Kit olur.
 *   setUnlink   → testin kit satırını kaldırır (yanlış bağlama için)
 *
 * Sekmeler: HIGH (yüksek güven), MEDIUM (orta), NONE (öneri yok), LINKED (kit bağlı)
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords;

use Biolab\Core\Mlyt\KitMatcher;
use BIO\MLYT\Material;
use BIO\MLYT\MaterialCategory;
use BIO\MLYT\RecipeItem;
use BIO\MLYT\Test;

$db = $dbm->getDB();
$resourceFile = "kit_match/kitMatch-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

const KM_METHOD_NAMES = array(
    Test::METHOD_KIT       => array("Kit / cihaz", "Kit / device"),
    Test::METHOD_UNDEFINED => array("Belirsiz", "Undefined"),
    Test::METHOD_RECIPE    => array("Reçete", "Recipe"),
);
const KM_TABS = array(KitMatcher::HIGH, KitMatcher::MEDIUM, KitMatcher::NONE, KitMatcher::LINKED);

$companyId = (int) $_SESSION["COMPANY_ID"];
$sessionLang = (string) ($_SESSION["lang"] ?? "tr");
$li = $sessionLang === "en" ? 1 : 0;

switch ($query) {

    /* ---------- Seçim kutuları ---------- */

    case "getCOST_METHOD":
        $execType = ET_DEFAULT;
        $dtResponse = array();
        foreach (KM_METHOD_NAMES as $code => $names) {
            $dtResponse[] = array("costMethod" => $code, "costMethodName" => $names[$li], "activeFl" => "Y");
        }
        $resCode = 0;
        $resMsg = "Ok";
        break;

    /* ---------- Liste ---------- */

    // postData: TAB (HIGH / MEDIUM / NONE / LINKED), COST_METHOD, Q (test kodu / adı)
    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $matcher = new KitMatcher($db, $companyId);
            $method = isset(KM_METHOD_NAMES[$_POST["COST_METHOD"] ?? ""]) ? $_POST["COST_METHOD"] : null;
            $q = mb_strtoupper(str_replace(array("i", "ı"), array("İ", "I"), trim((string) ($_POST["Q"] ?? ""))), "UTF-8");

            // Sekme sayıları yöntem ve aramaya göre (sekme hariç)
            $counts = array_fill_keys(KM_TABS, 0);
            $rowsAll = array();
            foreach ($matcher->rows() as $r) {
                if ($method !== null && $r["costMethod"] !== $method) { continue; }
                if ($q !== "" && !str_contains(mb_strtoupper(str_replace(array("i", "ı"), array("İ", "I"), $r["testCode"] . " " . $r["testName"]), "UTF-8"), $q)) { continue; }
                $counts[$r["confidence"]]++;
                $rowsAll[] = array(
                    "t_testId"         => $r["testId"],
                    "t_testCode"       => $r["testCode"],
                    "t_testName"       => $r["testName"],
                    "t_costMethod"     => $r["costMethod"],
                    "t_costMethodName" => KM_METHOD_NAMES[$r["costMethod"]][$li] ?? $r["costMethod"],
                    "s_supplierName"   => $r["supplierName"],
                    "confidence"       => $r["confidence"],
                    "score"            => $r["suggestions"] ? $r["suggestions"][0]["match"] : null,
                    "suggestions"      => $r["suggestions"],
                    "linked"           => $r["linked"],
                );
            }

            $tab = in_array($_POST["TAB"] ?? "", KM_TABS, true) ? $_POST["TAB"] : KitMatcher::HIGH;
            $rows = array_values(array_filter($rowsAll, fn ($r) => $r["confidence"] === $tab));
            $totalRecords = count($rows);

            // Sıralama: izin listesi; puana göre sıralamada eşitlikte koda göre
            $sortMap = array("t.testCode" => "t_testCode", "t.testName" => "t_testName", "t.costMethod" => "t_costMethodName",
                             "s.supplierName" => "s_supplierName", "score" => "score");
            $sortKey = $sortMap[$_POST["sidx"] ?? ""] ?? "t_testCode";
            $desc = strtoupper($_POST["sord"] ?? "") === "DESC";
            $collator = class_exists("Collator") ? new Collator("tr_TR") : null;
            usort($rows, function ($a, $b) use ($sortKey, $desc, $collator) {
                $c = $sortKey === "score" ? ($a["score"] ?? 0) <=> ($b["score"] ?? 0)
                    : ($collator ? $collator->compare((string) $a[$sortKey], (string) $b[$sortKey]) : strcmp((string) $a[$sortKey], (string) $b[$sortKey]));
                return ($desc ? -$c : $c) ?: strcmp($a["t_testCode"], $b["t_testCode"]);
            });

            $perPage = max(1, (int) ($_POST["rows"] ?? 25));
            $page = max(1, (int) ($_POST["page"] ?? 1));
            $dtResponse = array_slice($rows, ($page - 1) * $perPage, $perPage);

            $resObject = array("counts" => $counts, "kits" => $matcher->kits());
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    /* ---------- Ekran işlemleri ---------- */

    // ITEMS: JSON [{testId, materialId, quantity}] — seçilen satırlar. Aynı test–kit zaten bağlıysa miktarı güncellenir.
    case "setLink":
        $execType = ET_CUSTOM;
        try{
            $items = json_decode((string) ($_POST["ITEMS"] ?? "[]"), true);
            if (!is_array($items) || !$items) { $resCode = 10; $resMsg = "Bağlanacak satır seçin."; break; }

            $errors = array();
            $pairs = array();
            foreach ($items as $i => $it) {
                $test = kmFindTest($db, (int) ($it["testId"] ?? 0), $companyId);
                $kit  = kmFindKit($db, (int) ($it["materialId"] ?? 0), $companyId);
                $qty  = kmDecimal($it["quantity"] ?? "1");
                $row  = "Satır " . ($i + 1);
                if ($test === null) { $errors[] = $row . ": test bulunamadı."; continue; }
                if ($test->getCostMethod() === Test::METHOD_CALCULATED) { $errors[] = $test->getTestCode() . ": hesaplamalı testin maliyeti bileşenlerinden gelir."; continue; }
                if ($kit === null) { $errors[] = $test->getTestCode() . ": kit seçin."; continue; }
                if ($qty === null || (float) $qty <= 0) { $errors[] = $test->getTestCode() . ": miktar sıfırdan büyük olmalı."; continue; }
                $pairs[] = array($test, $kit, $qty);
            }
            if ($errors) { $resCode = 10; $resMsg = implode(" ", $errors); break; }

            $created = $updated = $methodSet = 0;
            foreach ($pairs as [$test, $kit, $qty]) {
                $item = $db->getRepository(RecipeItem::class)->findOneBy(array("test" => $test, "material" => $kit, "consumption" => RecipeItem::PER_PATIENT));
                if ($item === null) {
                    $maxSort = (int) $db->createQuery("SELECT MAX(ri.sortNo) FROM BIO\MLYT\RecipeItem ri WHERE ri.test = :TEST")
                        ->setParameter("TEST", $test)->getSingleScalarResult();
                    $db->persist(new RecipeItem($test, $kit, $qty, RecipeItem::PER_PATIENT, $maxSort + 10));
                    $created++;
                } else {
                    $item->setQuantity($qty);
                    $updated++;
                }
                // Belirsiz test kit bağlanınca Kit olur (Test tanımları'nda belirsiz görünmesin)
                if ($test->getCostMethod() === Test::METHOD_UNDEFINED) {
                    $test->setCostMethod(Test::METHOD_KIT);
                    $methodSet++;
                }
            }
            $db->flush();

            $resObject = array("created" => $created, "updated" => $updated, "methodSet" => $methodSet);
            $resCode = 0;
            $parts = array();
            if ($created)   { $parts[] = $created . " test kite bağlandı"; }
            if ($updated)   { $parts[] = $updated . " testin miktarı güncellendi"; }
            if ($methodSet) { $parts[] = $methodSet . " testin yöntemi Kit yapıldı"; }
            $resMsg = implode("; ", $parts);
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Testin kit satırını kaldırır (TEST_ID, MATERIAL_ID). Yöntem değiştirilmez.
    case "setUnlink":
        $execType = ET_CUSTOM;
        try{
            $test = kmFindTest($db, (int) ($_POST["TEST_ID"] ?? 0), $companyId);
            $kit  = kmFindKit($db, (int) ($_POST["MATERIAL_ID"] ?? 0), $companyId);
            $item = ($test && $kit) ? $db->getRepository(RecipeItem::class)->findOneBy(array("test" => $test, "material" => $kit)) : null;
            if ($item === null) { $resCode = 20; $resMsg = "Bağlantı bulunamadı."; break; }
            $db->remove($item);
            $db->flush();
            $resCode = 0;
            $resMsg = $test->getTestCode() . " kit bağlantısı kaldırıldı";
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

function kmFindTest($db, int $testId, int $companyId): ?Test
{
    if ($testId <= 0) { return null; }
    $t = $db->getRepository(Test::class)->findOneBy(array("testId" => $testId, "company" => $companyId));
    return ($t !== null && !$t->isDeleted()) ? $t : null;
}

/** Bağlanabilecek kit: şirketin, silinmemiş, "Kit" kategorisindeki malzemesi */
function kmFindKit($db, int $materialId, int $companyId): ?Material
{
    if ($materialId <= 0) { return null; }
    $m = $db->getRepository(Material::class)->findOneBy(array("materialId" => $materialId, "company" => $companyId));
    return ($m !== null && !$m->isDeleted() && $m->getCategory()->getCategoryCode() === MaterialCategory::CODE_KIT) ? $m : null;
}

/** "1,5" / "1.5" → "1.5" ; boşsa null */
function kmDecimal($v): ?string
{
    if ($v === null) { return null; }
    $v = str_replace(array(" ", "\u{00A0}"), "", trim((string) $v));
    if ($v === "") { return null; }
    if (str_contains($v, ",")) { $v = str_replace(array(".", ","), array("", "."), $v); }
    return is_numeric($v) ? $v : null;
}
