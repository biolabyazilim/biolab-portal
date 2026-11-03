<?php
/**
 * Biolab QMS — Organizasyon tanımları veri kaynağı (departman, pozisyon, lokasyon)
 *
 * Üç tanım aynı ekrandan yönetilir; hangisi olduğu TYPE ile gelir: DEPARTMENT / POSITION / LOCATION.
 *   getGridList      → seçili türün listesi (jqGrid; arama Q, pasifleri göster SHOW_PASSIVE)
 *   getSelectedId    → seçilen kayıt (formu doldurur)
 *   getDEPARTMENT_ID → pozisyon formundaki departman seçimi
 *   setSave / setAdd → kaydet (RECORD_ID doluysa güncelle) / her zaman yeni ekle
 *   setDelete        → yalnızca hiç kullanılmamış kayıt silinir (RECORD_FL = 0); kullanılmışsa pasife alınmalı
 *
 * Kullanılmış tanım silinmez: geçmiş görevlerde, imza yetkisinde ve dağıtımlarda görünmeye devam etmeli.
 * Pasife alınan (ACTIVE_FL = 'N') kayıt yeni seçimlerde çıkmaz.
 * Kayıtlar oturumdaki şirkete göredir; her ekleme / değişiklik / silme qms_audit_log'a yazılır.
 * Veritabanı: Biolab_QMS (proxy, apps/biolab-qms isteklerinde bootstrap-qms.php'yi kullanır).
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords;

use Biolab\Core\Qms\AuditLogger;
use QMS\ORG\Department;
use QMS\ORG\Location;
use QMS\ORG\Position;

$db = $dbm->getDB();
$resourceFile = "qms_org/qmsOrg-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];

// Tür → entity, tablo, alanlar. Ekran tek, kurallar ortak; tür eklemek için buraya satır eklenir.
const QMS_ORG_TYPES = array(
    "DEPARTMENT" => array("class" => Department::class, "table" => "qms_department", "id" => "departmentId", "name" => "departmentName"),
    "POSITION"   => array("class" => Position::class,   "table" => "qms_position",   "id" => "positionId",   "name" => "positionName"),
    "LOCATION"   => array("class" => Location::class,   "table" => "qms_location",   "id" => "locationId",   "name" => "locationName"),
);

$type = (string) ($_POST["TYPE"] ?? "");
$def  = QMS_ORG_TYPES[$type] ?? null;

switch ($query) {

    case "getGridList":
        $execType = ET_JQGRID;
        try{
            if ($def === null) {
                throw new InvalidArgumentException("Geçersiz tanım türü.");
            }
            $whereClause = "";
            $params = array("COMPANY_ID" => $companyId);
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $whereClause .= " AND (mt.code LIKE :Q_LIKE OR mt." . $def["name"] . " LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }
            if (($_POST["SHOW_PASSIVE"] ?? "N") !== "Y") {
                $whereClause .= " AND mt.activeFl = 'Y'";
            }
            $join = $type === "POSITION" ? " LEFT JOIN mt.department d" : "";
            $from = " FROM " . $def["class"] . " mt" . $join . "
                     WHERE IDENTITY(mt.company) = :COMPANY_ID
                       AND mt.recordFl > 0";

            $totalRecords = (int) $db->createQuery("SELECT COUNT(mt." . $def["id"] . ")" . $from . $whereClause)
                ->setParameters($params)->getSingleScalarResult();

            // Sıralama alanı istemciden gelir; izin listesiyle kontrol edilir
            $sortable  = array("mt.code", "mt.name", "mt.activeFl", "d.departmentName");
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "mt.code";
            $sortBy    = $sortBy === "mt.name" ? "mt." . $def["name"] : $sortBy;
            if ($sortBy === "d.departmentName" && $type !== "POSITION") {
                $sortBy = "mt.code";
            }
            $direction = strtoupper($_POST["sord"] ?? "") === "DESC" ? "DESC" : "ASC";
            $rows = max(1, (int) ($_POST["rows"] ?? 20));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $select = "SELECT mt." . $def["id"] . " mt_id, mt.code mt_code, mt." . $def["name"] . " mt_name, mt.activeFl mt_activeFl"
                    . ($type === "POSITION" ? ", d.departmentName d_departmentName" : "");
            // İkincil sıralama kod; SQL Server aynı kolonun ORDER BY'da iki kez geçmesine izin vermez
            $orderBy = " ORDER BY " . $sortBy . " " . $direction . ($sortBy !== "mt.code" ? ", mt.code ASC" : "");
            $dtResponse = $db->createQuery($select . $from . $whereClause . $orderBy)
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
            $record = $def !== null ? qmsOrgFind($db, $def, (int) ($_POST["SELECTED_ID"] ?? 0), $companyId) : null;
            if ($record === null) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } else {
                $getName = "get" . ucfirst($def["name"]);
                $dtResponse = array(array(
                    "RECORD_ID"     => (int) $record->{"get" . ucfirst($def["id"])}(),
                    "CODE"          => $record->getCode(),
                    "NAME"          => $record->$getName(),
                    "DEPARTMENT_ID" => $type === "POSITION" ? $record->getDepartment()?->getDepartmentId() : null,
                    "ACTIVE_FL"     => $record->isActive() ? "Y" : "N",
                ));
                $resCode = 0;
                $resMsg = "Ok";
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Pozisyon formundaki departman seçimi; pasif departman listede görünür ama seçilemez
    case "getDEPARTMENT_ID":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = $db->createQuery("
                SELECT d.departmentId, d.departmentName, d.activeFl
                  FROM QMS\ORG\Department d
                 WHERE IDENTITY(d.company) = :COMPANY_ID
                   AND d.recordFl > 0
                 ORDER BY d.departmentName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();

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

    // Ekle: formda seçili kayıt olsa bile yeni kayıt eklenir. RECORD_ID boşaltılıp setSave'e devam edilir (break yok).
    case "setAdd":
        $_POST["RECORD_ID"] = "";

    case "setSave":
        $execType = ET_SAVE;
        try{
            // --- Gelen alanlar ---
            $recordId = (int) ($_POST["RECORD_ID"] ?? 0);
            $code     = trim((string) ($_POST["CODE"] ?? ""));
            $name     = trim((string) ($_POST["NAME"] ?? ""));
            $deptId   = (int) ($_POST["DEPARTMENT_ID"] ?? 0);
            $activeFl = ($_POST["ACTIVE_FL"] ?? "Y") === "N" ? "N" : "Y";

            $record = ($def !== null && $recordId > 0) ? qmsOrgFind($db, $def, $recordId, $companyId) : null;
            $dept   = ($type === "POSITION" && $deptId > 0) ? $db->getRepository(Department::class)->findOneBy(array("departmentId" => $deptId, "company" => $companyId)) : null;

            // --- Sunucu tarafı kontrol (tarayıcıdaki doğrulama atlanabilir) ---
            $error = null;
            if ($def === null) {
                $error = "Geçersiz tanım türü.";
            } elseif ($code === "" || $name === "") {
                $error = $lang["ERR_REQUIRED"];
            } elseif (mb_strlen($code) > 20 || mb_strlen($name) > 150) {
                $error = $lang["ERR_LENGTH"];
            } elseif ($recordId > 0 && $record === null) {
                $error = $lang["NO_RECORDS_FOUND"];
            } elseif ($deptId > 0 && ($dept === null || $dept->isDeleted())) {
                $error = $lang["ERR_DEPARTMENT_NOT_FOUND"];
            } elseif ($deptId > 0 && !$dept->isActive() && $record?->getDepartment()?->getDepartmentId() !== $deptId) {
                $error = $lang["ERR_DEPARTMENT_PASSIVE"];
            } elseif (qmsOrgCodeTaken($db, $def, $code, $companyId, $record)) {
                $error = sprintf($lang["ERR_CODE_TAKEN"], $code);
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
            } else {
                $db->wrapInTransaction(function () use ($db, $def, $type, &$record, $code, $name, $dept, $activeFl, $lang, &$resMsg) {
                    $setName = "set" . ucfirst($def["name"]);
                    $isNew   = $record === null;
                    $before  = $isNew ? null : qmsOrgSnapshot($record, $def, $type);

                    if ($isNew) {
                        // Şirket, C_BY, C_AT QmsAuditListener ile dolar
                        $record = $type === "POSITION" ? new Position($code, $name, $dept) : new $def["class"]($code, $name);
                        $db->persist($record);
                        $resMsg = $lang["RECORD_IS_ADDED"];
                    } else {
                        $record->setCode($code)->$setName($name);
                        if ($type === "POSITION") {
                            $record->setDepartment($dept);
                        }
                        $resMsg = $lang["RECORD_IS_CHANGED"];
                    }
                    $record->setActive($activeFl === "Y");
                    $db->flush();

                    $after = qmsOrgSnapshot($record, $def, $type);
                    AuditLogger::log($db, $def["table"], (int) $record->{"get" . ucfirst($def["id"])}(), $isNew ? "CREATE" : "UPDATE",
                        $isNew ? $after : array("before" => $before, "after" => $after));
                });

                $resObject = array("RECORD_ID" => (int) $record->{"get" . ucfirst($def["id"])}());
                $resCode = 0;
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setDelete":
        $execType = ET_DELETE;
        try{
            $record = $def !== null ? qmsOrgFind($db, $def, (int) ($_POST["RECORD_ID"] ?? 0), $companyId) : null;
            $usage  = $record !== null ? qmsOrgUsage($db, $type, (int) $record->{"get" . ucfirst($def["id"])}()) : 0;

            if ($record === null) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } elseif ($usage > 0) {
                $resCode = 2;
                $resMsg = sprintf($lang["ERR_IN_USE"], $usage);
            } else {
                $db->wrapInTransaction(function () use ($db, $def, $type, $record) {
                    $snapshot = qmsOrgSnapshot($record, $def, $type);
                    $record->softDelete();
                    $record->setActive(false);
                    $db->flush();   // D_BY, D_AT QmsAuditListener ile dolar
                    AuditLogger::log($db, $def["table"], (int) $record->{"get" . ucfirst($def["id"])}(), "DELETE", $snapshot);
                });
                $resCode = 0;
                $resMsg = $lang["RECORD_IS_DELETED"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

// Kayıt yalnızca oturumdaki şirkette ve silinmemişse bulunur
function qmsOrgFind($db, array $def, int $id, int $companyId): ?object
{
    if ($id <= 0) {
        return null;
    }
    $record = $db->getRepository($def["class"])->findOneBy(array($def["id"] => $id, "company" => $companyId));
    return ($record === null || $record->isDeleted()) ? null : $record;
}

// Kod şirket içinde tekil (silinmiş kayıtlar dahil: veritabanındaki UNIQUE kısıtı da onları sayar)
function qmsOrgCodeTaken($db, array $def, string $code, int $companyId, ?object $self): bool
{
    $other = $db->getRepository($def["class"])->findOneBy(array("code" => $code, "company" => $companyId));
    return $other !== null && $other !== $self;
}

// Denetim kaydına yazılan alanlar
function qmsOrgSnapshot(object $record, array $def, string $type): array
{
    $getName = "get" . ucfirst($def["name"]);
    $data = array("code" => $record->getCode(), "name" => $record->$getName(), "active" => $record->isActive());
    if ($type === "POSITION") {
        $data["departmentId"] = $record->getDepartment()?->getDepartmentId();
    }
    return $data;
}

// Kaydın kullanıldığı yer sayısı (görevler, pozisyonlar, dokümanlar, imza yetkisi, dağıtımlar). 0 ise silinebilir.
function qmsOrgUsage($db, string $type, int $id): int
{
    $conn = $db->getConnection();
    $sql = array(
        "DEPARTMENT" => array(
            "SELECT COUNT(*) FROM qms_position WHERE DEPARTMENT_ID = :ID AND RECORD_FL > 0",
            "SELECT COUNT(*) FROM qms_user_assignment WHERE DEPARTMENT_ID = :ID",
            "SELECT COUNT(*) FROM qms_document WHERE OWNER_DEPARTMENT_ID = :ID",
        ),
        "POSITION" => array(
            "SELECT COUNT(*) FROM qms_user_assignment WHERE POSITION_ID = :ID",
            "SELECT COUNT(*) FROM qms_doc_type_step_position WHERE POSITION_ID = :ID AND RECORD_FL > 0",
        ),
        "LOCATION" => array(
            "SELECT COUNT(*) FROM qms_user_assignment WHERE LOCATION_ID = :ID",
        ),
    )[$type];
    // Dağıtımlarda SUBJECT_TYPE + SUBJECT_ID ile geçer (foreign key yok)
    $sql[] = "SELECT COUNT(*) FROM qms_doc_type_distribution WHERE SUBJECT_TYPE = '" . $type . "' AND SUBJECT_ID = :ID AND RECORD_FL > 0";
    $sql[] = "SELECT COUNT(*) FROM qms_revision_distribution WHERE SUBJECT_TYPE = '" . $type . "' AND SUBJECT_ID = :ID";

    $total = 0;
    foreach ($sql as $s) {
        $total += (int) $conn->fetchOne($s, array("ID" => $id));
    }
    return $total;
}
