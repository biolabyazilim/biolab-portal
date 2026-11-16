<?php
/**
 * Saha Takip — Firmalar veri kaynağı (personelin ziyaret ettiği müşteri işyerleri)
 *
 *   getGridList    → firma listesi (jqGrid; arama Q: ad, adres, il/ilçe, İSG-KATİP no)
 *   getSelectedId  → seçilen firma (formu doldurur)
 *   setSave        → firma ekle / güncelle
 *   setAdd         → formdaki bilgilerle her zaman yeni firma ekler
 *   setDelete      → firmayı siler (RECORD_FL = 0). Açık (bitmemiş) görevi olan firma silinemez.
 *
 * Konum (LAT / LON) haritadan seçilir; zorunlu değil ama yoksa "Vardım" mesafesi ve en yakın personel hesaplanamaz.
 * Bütün kayıtlar oturumdaki şirkete göredir. C_BY/U_BY/D_BY ve tarihler AuditListener ile dolar.
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use BIO\SHT\Customer;

$db = $dbm->getDB();
$resourceFile = "sht_firma/firma-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $whereClause = "";
            $params = array("COMPANY_ID" => $companyId);
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $whereClause .= " AND (mt.customerName LIKE :Q_LIKE OR mt.address LIKE :Q_LIKE OR mt.city LIKE :Q_LIKE OR mt.isgKatipNo LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }

            $from = "
                  FROM BIO\SHT\Customer mt
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.recordFl > 0
            ";

            $totalRecords = (int) $db->createQuery("SELECT COUNT(mt.customerId) " . $from . $whereClause)
                ->setParameters($params)->getSingleScalarResult();

            $sortable  = array("mt.customerId", "mt.customerName", "mt.city", "mt.isgKatipNo", "mt.activeFl");
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "mt.customerName";
            $direction = strtoupper($_POST["sord"] ?? "") === "DESC" ? "DESC" : "ASC";

            $rows = max(1, (int) ($_POST["rows"] ?? 25));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $dtResponse = $db->createQuery("
                SELECT mt.customerId mt_customerId,
                       mt.customerName mt_customerName,
                       mt.city mt_city,
                       mt.address mt_address,
                       mt.isgKatipNo mt_isgKatipNo,
                       mt.lat mt_lat,
                       mt.activeFl mt_activeFl,
                       (SELECT COUNT(t.taskId) FROM BIO\SHT\Task t
                         WHERE t.customer = mt AND t.recordFl > 0 AND t.status NOT IN ('DONE', 'CANCELLED')) openTasks
            " . $from . $whereClause . " ORDER BY " . $sortBy . " " . $direction . ", mt.customerId ASC")
                ->setParameters($params)
                ->setMaxResults($rows)
                ->setFirstResult(($page - 1) * $rows)
                ->getArrayResult();

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

    case "getSelectedId":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = $db->createQuery("
                SELECT mt.customerId mt_customerId, mt.customerName mt_customerName, mt.address mt_address,
                       mt.city mt_city, mt.lat mt_lat, mt.lon mt_lon, mt.contactName mt_contactName,
                       mt.phone mt_phone, mt.isgKatipNo mt_isgKatipNo, mt.note mt_note, mt.activeFl mt_activeFl
                  FROM BIO\SHT\Customer mt
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.customerId = :CUSTOMER_ID
                   AND mt.recordFl > 0
            ")
                ->setParameter("COMPANY_ID", $companyId)
                ->setParameter("CUSTOMER_ID", (int) ($_POST["SELECTED_ID"] ?? 0))
                ->getArrayResult();
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

    // Ekle: formda seçili firma olsa bile yeni kayıt eklenir (break yok, setSave'e devam)
    case "setAdd":
        $_POST["CUSTOMER_ID"] = "";

    case "setSave":
        $execType = ET_SAVE;
        try{
            $customerId = (int) ($_POST["CUSTOMER_ID"] ?? 0);
            $name    = trim((string) ($_POST["CUSTOMER_NAME"] ?? ""));
            $text    = function (string $k, int $max) { $v = trim((string) ($_POST[$k] ?? "")); return $v === "" ? null : mb_substr($v, 0, $max); };
            $latRaw  = trim((string) ($_POST["LAT"] ?? ""));
            $lonRaw  = trim((string) ($_POST["LON"] ?? ""));

            $customer = $customerId > 0 ? $db->getRepository(Customer::class)->findOneBy(array("customerId" => $customerId, "company" => $companyId)) : null;

            $error = null;
            if ($customerId > 0 && ($customer === null || $customer->isDeleted())) {
                $error = $lang["NO_RECORDS_FOUND"];
            } elseif ($name === "" || mb_strlen($name) > 200) {
                $error = $lang["ERR_CUSTOMER_NAME"];
            } elseif (($latRaw === "") !== ($lonRaw === "")
                || ($latRaw !== "" && (!is_numeric($latRaw) || abs((float) $latRaw) > 90 || !is_numeric($lonRaw) || abs((float) $lonRaw) > 180))) {
                $error = $lang["ERR_LOCATION"];
            }
            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
                break;
            }

            if ($customer === null) {
                $customer = new Customer($name);
                $db->persist($customer);
                $resMsg = $lang["RECORD_IS_ADDED"];
            } else {
                $customer->setCustomerName($name);
                $resMsg = $lang["RECORD_IS_CHANGED"];
            }
            $customer->setAddress($text("ADDRESS", 500))
                     ->setCity($text("CITY", 50))
                     ->setContactName($text("CONTACT_NAME", 100))
                     ->setPhone($text("PHONE", 20))
                     ->setIsgKatipNo($text("ISG_KATIP_NO", 50))
                     ->setNote($text("NOTE", 500))
                     ->setLocation($latRaw !== "" ? (float) $latRaw : null, $lonRaw !== "" ? (float) $lonRaw : null);
            $customer->setActive(($_POST["ACTIVE_FL"] ?? "Y") === "Y");
            $db->flush();

            $resObject = array("CUSTOMER_ID" => $customer->getCustomerId());
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setDelete":
        $execType = ET_DELETE;
        try{
            $customer = $db->getRepository(Customer::class)->findOneBy(array(
                "customerId" => (int) ($_POST["CUSTOMER_ID"] ?? 0),
                "company"    => $companyId,
            ));
            $openTasks = $customer === null ? 0 : (int) $db->createQuery("
                SELECT COUNT(t.taskId) FROM BIO\SHT\Task t
                 WHERE t.customer = :C AND t.recordFl > 0 AND t.status NOT IN ('DONE', 'CANCELLED')
            ")->setParameter("C", $customer)->getSingleScalarResult();

            if ($customer === null || $customer->isDeleted()) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } elseif ($openTasks > 0) {
                $resCode = 1;
                $resMsg = sprintf($lang["ERR_HAS_OPEN_TASKS"], $openTasks);
            } else {
                $customer->setActive(false);
                $customer->softDelete();
                $db->flush();
                $resCode = 0;
                $resMsg = $lang["RECORD_IS_DELETED"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}
