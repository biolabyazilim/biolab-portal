<?php
/**
 * Biolab QMS — Doküman tipleri veri kaynağı
 *
 * Doküman tipi, kalitenin bir kez tanımladığı kural setidir; yükleme ve imza ekranları bu tanımdan çalışır:
 * klasör, kod öneki ve kalıbı, imza akışı (hangi adımı hangi pozisyonlar imzalayabilir), varsayılan dağıtım,
 * gözden geçirme ve saklama süresi, okudum onayı, dış kaynaklı mı.
 *
 *   getCATEGORY_ID  → klasör seçimi ("KALİTE DOKÜMANLARI › TALİMATLAR"); yalnızca alt klasörü olmayan klasörler
 *   getGridList     → tip listesi (jqGrid; klasör süzgeci CATEGORY_ID, arama Q, pasifler SHOW_PASSIVE)
 *   getSelectedId   → seçilen tip + varsayılan dağıtım (DISTRIBUTION)
 *   getSubjects     → pozisyon / departman / lokasyon / kullanıcı listeleri (imza akışı ve dağıtım seçimleri)
 *   setSave/setAdd  → tip ve dağıtım tek transaction'da kaydedilir (DISTRIBUTION JSON gelir)
 *
 * İmzacılar tipte tanımlanmaz: dokümana göre değiştikleri için her dokümanın imzacıları yüklemede seçilir.
 * İmza yetkisi tabloları (qms_doc_type_step / _position) yerinde durur; ekran STEPS göndermediği sürece dokunulmaz.
 *   setDelete       → dokümanı olmayan tip silinir; dokümanı varsa pasife alınmalı
 *
 * İmza adımı ve dağıtım satırları silinmez: kullanılmayan satır ACTIVE_FL = 'N' olur, tekrar seçilince açılır.
 * Revizyon imzaya gönderildiğinde akış ve dağıtım revizyona kopyalanır; burada yapılan değişiklik eski revizyonları etkilemez.
 * Kayıtlar oturumdaki şirkete göredir; her değişiklik qms_audit_log'a yazılır.
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords;

use Biolab\Core\Qms\AuditLogger;
use QMS\DOC\DocCategory;
use QMS\DOC\DocType;
use QMS\DOC\DocTypeDistribution;
use QMS\DOC\DocTypeStep;
use QMS\DOC\DocTypeStepPosition;
use QMS\DOC\Distribution;
use QMS\ORG\Position;

$db = $dbm->getDB();
$resourceFile = "qms_doc_type/qmsDocType-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    // Klasör seçimi: yalnızca alt klasörü olmayan (yaprak) klasörlere tip bağlanır
    case "getCATEGORY_ID":
        $execType = ET_DEFAULT;
        try{
            $paths = qmsCategoryPaths($db, $companyId);
            $dtResponse = $db->getConnection()->fetchAllAssociative("
                SELECT c.CATEGORY_ID categoryId, c.ACTIVE_FL activeFl
                  FROM qms_doc_category c
                 WHERE c.COMPANY_ID = :COMPANY_ID AND c.RECORD_FL > 0
                   AND NOT EXISTS (SELECT 1 FROM qms_doc_category s WHERE s.PARENT_ID = c.CATEGORY_ID AND s.RECORD_FL > 0)
            ", array("COMPANY_ID" => $companyId));
            foreach ($dtResponse as &$r) {
                $r["categoryPath"] = $paths[$r["categoryId"]]["path"];
                $r["sortKey"]      = $paths[$r["categoryId"]]["sortKey"];
            }
            unset($r);
            usort($dtResponse, function ($a, $b) { return strcmp($a["sortKey"], $b["sortKey"]); });

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

    // Liste. FLOW_OK: akışta en az hazırlayan + onaylayan var mı (dış kaynaklıda en az bir adım) → "Akış eksik" rozeti
    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $whereClause = "";
            $params = array("COMPANY_ID" => $companyId);
            if ((int) ($_POST["CATEGORY_ID"] ?? 0) > 0) {
                $whereClause .= " AND mt.CATEGORY_ID = :CATEGORY_ID";
                $params["CATEGORY_ID"] = (int) $_POST["CATEGORY_ID"];
            }
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $whereClause .= " AND (mt.CODE_PREFIX LIKE :Q_LIKE OR mt.DOC_TYPE_NAME LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }
            if (($_POST["SHOW_PASSIVE"] ?? "N") !== "Y") {
                $whereClause .= " AND mt.ACTIVE_FL = 'Y'";
            }
            $from = " FROM qms_doc_type mt JOIN qms_doc_category c ON c.CATEGORY_ID = mt.CATEGORY_ID
                     WHERE mt.COMPANY_ID = :COMPANY_ID AND mt.RECORD_FL > 0";

            $conn = $db->getConnection();
            $totalRecords = (int) $conn->fetchOne("SELECT COUNT(*)" . $from . $whereClause, $params);

            // Sıralama alanı istemciden gelir; izin listesiyle kontrol edilir
            $sortable  = array("c.SORT_NO" => "c.SORT_NO", "mt.CODE_PREFIX" => "mt.CODE_PREFIX", "mt.DOC_TYPE_NAME" => "mt.DOC_TYPE_NAME", "mt.ACTIVE_FL" => "mt.ACTIVE_FL");
            $sortBy    = $sortable[$_POST["sidx"] ?? ""] ?? "c.SORT_NO";
            $direction = strtoupper($_POST["sord"] ?? "") === "DESC" ? "DESC" : "ASC";
            $orderBy   = " ORDER BY " . $sortBy . " " . $direction . ($sortBy !== "mt.CODE_PREFIX" ? ", mt.CODE_PREFIX ASC" : "");
            $rows = max(1, (int) ($_POST["rows"] ?? 20));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $dtResponse = $conn->fetchAllAssociative("
                SELECT mt.DOC_TYPE_ID mt_docTypeId, mt.CODE_PREFIX mt_codePrefix, mt.DOC_TYPE_NAME mt_docTypeName,
                       mt.STANDARD_CODE mt_standardCode, mt.EXTERNAL_FL mt_externalFl, mt.ACTIVE_FL mt_activeFl,
                       c.CATEGORY_ID c_categoryId,
                       (SELECT COUNT(*) FROM qms_doc_type_step s WHERE s.DOC_TYPE_ID = mt.DOC_TYPE_ID AND s.ACTIVE_FL = 'Y'
                           AND s.SIGN_ROLE IN ('PREPARED','APPROVED')) flowRoles,
                       (SELECT COUNT(*) FROM qms_doc_type_step s WHERE s.DOC_TYPE_ID = mt.DOC_TYPE_ID AND s.ACTIVE_FL = 'Y') flowSteps,
                       (SELECT COUNT(*) FROM qms_document d WHERE d.DOC_TYPE_ID = mt.DOC_TYPE_ID AND d.RECORD_FL > 0) docCount
                " . $from . $whereClause . $orderBy . " OFFSET " . (($page - 1) * $rows) . " ROWS FETCH NEXT " . $rows . " ROWS ONLY", $params);

            $paths = qmsCategoryPaths($db, $companyId);
            foreach ($dtResponse as &$r) {
                $r["categoryPath"] = $paths[$r["c_categoryId"]]["name"] ?? "";
                $r["flowOk"] = $r["mt_externalFl"] === "Y" ? ((int) $r["flowSteps"] > 0) : ((int) $r["flowRoles"] === 2);
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

    case "getSelectedId":
        $execType = ET_DEFAULT;
        try{
            $type = qmsDocTypeFind($db, (int) ($_POST["SELECTED_ID"] ?? 0), $companyId);
            if ($type === null) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } else {
                $dtResponse = array(qmsDocTypeData($db, $type));
                $resCode = 0;
                $resMsg = "Ok";
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // İmza akışı ve dağıtım seçimleri için listeler (tek istekte). Pasifler "active: false" ile gelir:
    // yeni seçimde gösterilmez, ama kayıtlı bir seçimde pasif olan görünmeye devam eder.
    case "getSubjects":
        $execType = ET_DEFAULT;
        try{
            $conn = $db->getConnection();
            $p = array("COMPANY_ID" => $companyId);
            $resObject = array(
                "POSITION"   => $conn->fetchAllAssociative("SELECT POSITION_ID id, POSITION_NAME name, ACTIVE_FL activeFl FROM qms_position WHERE COMPANY_ID = :COMPANY_ID AND RECORD_FL > 0 ORDER BY POSITION_NAME", $p),
                "DEPARTMENT" => $conn->fetchAllAssociative("SELECT DEPARTMENT_ID id, DEPARTMENT_NAME name, ACTIVE_FL activeFl FROM qms_department WHERE COMPANY_ID = :COMPANY_ID AND RECORD_FL > 0 ORDER BY DEPARTMENT_NAME", $p),
                "LOCATION"   => $conn->fetchAllAssociative("SELECT LOCATION_ID id, LOCATION_NAME name, ACTIVE_FL activeFl FROM qms_location WHERE COMPANY_ID = :COMPANY_ID AND RECORD_FL > 0 ORDER BY LOCATION_NAME", $p),
                "USER"       => $conn->fetchAllAssociative("
                    SELECT u.USER_ID id, u.FULL_NAME name, u.ACTIVE_FL activeFl
                      FROM mng_user u JOIN mng_user_company uc ON uc.USER_ID = u.USER_ID AND uc.RECORD_FL > 0
                     WHERE uc.COMPANY_ID = :COMPANY_ID AND u.RECORD_FL > 0 ORDER BY u.FULL_NAME", $p),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Ekle: formda seçili tip olsa bile yeni tip eklenir. DOC_TYPE_ID boşaltılıp setSave'e devam edilir (break yok).
    case "setAdd":
        $_POST["DOC_TYPE_ID"] = "";

    case "setSave":
        $execType = ET_SAVE;
        try{
            // --- Gelen alanlar ---
            $typeId     = (int) ($_POST["DOC_TYPE_ID"] ?? 0);
            $categoryId = (int) ($_POST["CATEGORY_ID"] ?? 0);
            // Bir tipin birden fazla öneki olabilir (REHBERLER: RH, KEK, KP, NEK); virgülle ayrılır, büyük harfe çevrilir
            $prefixes   = DocType::splitPrefixes((string) ($_POST["CODE_PREFIX"] ?? ""));
            $prefix     = implode(",", $prefixes);
            $name       = trim((string) ($_POST["DOC_TYPE_NAME"] ?? ""));
            $pattern    = trim((string) ($_POST["CODE_PATTERN"] ?? ""));
            $standard   = trim((string) ($_POST["STANDARD_CODE"] ?? ""));
            $review     = (int) ($_POST["REVIEW_MONTHS"] ?? 0);
            $retention  = (int) ($_POST["RETENTION_YEARS"] ?? 0);
            $readAck    = ($_POST["READ_ACK_FL"] ?? "Y") === "N" ? "N" : "Y";
            $external   = ($_POST["EXTERNAL_FL"] ?? "N") === "Y" ? "Y" : "N";
            $activeFl   = ($_POST["ACTIVE_FL"] ?? "Y") === "N" ? "N" : "Y";
            // STEPS (imza yetkileri) ekrandan gelmez; gelirse eskisi gibi kontrol edilip kaydedilir
            $steps      = isset($_POST["STEPS"]) ? json_decode((string) $_POST["STEPS"], true) : null;
            $dists      = json_decode((string) ($_POST["DISTRIBUTION"] ?? "[]"), true);

            $type     = $typeId > 0 ? qmsDocTypeFind($db, $typeId, $companyId) : null;
            $category = $categoryId > 0 ? $db->getRepository(DocCategory::class)->findOneBy(array("categoryId" => $categoryId, "company" => $companyId)) : null;

            // Kod kalıbı ekranda sorulmaz, önekten üretilir (TL → ^TL\d{1,4}$, RH,KEK → ^(RH|KEK)\d{1,4}$;
            // dış kaynaklıda serbest). Önek ve dış kaynaklı değişmediyse kayıtlı kalıp korunur (elle özelleştirilmiş olabilir).
            if ($pattern === "" && $prefixes) {
                $samePrefix = $type !== null && $type->getCodePrefix() === $prefix && $type->isExternal() === ($external === "Y");
                $pattern = $samePrefix ? $type->getCodePattern() : DocType::patternFor($prefixes, $external === "Y");
            }
            $takenPrefix = $prefixes ? qmsDocTypePrefixTaken($db, $prefixes, $companyId, $type) : null;

            // --- Sunucu tarafı kontrol (tarayıcıdaki doğrulama atlanabilir) ---
            $error = null;
            if ($typeId > 0 && $type === null) {
                $error = $lang["NO_RECORDS_FOUND"];
            } elseif ($category === null || $category->isDeleted()) {
                $error = $lang["ERR_CATEGORY_ID"];
            } elseif ($prefix === "" || $name === "") {
                $error = $lang["ERR_REQUIRED"];
            } elseif (mb_strlen($prefix) > 20 || mb_strlen($name) > 150 || mb_strlen($pattern) > 100 || mb_strlen($standard) > 30) {
                $error = $lang["ERR_LENGTH"];
            } elseif (@preg_match('/' . str_replace('/', '\/', $pattern) . '/u', '') === false) {
                $error = $lang["ERR_PATTERN_INVALID"];
            } elseif ($review < 1 || $review > 120 || $retention < 1 || $retention > 100) {
                $error = $lang["ERR_PERIOD"];
            } elseif (($steps !== null && !is_array($steps)) || !is_array($dists)) {
                $error = "Geçersiz istek.";
            } elseif (count(array_filter($prefixes, fn ($p) => !preg_match('/^[A-ZÇĞİÖŞÜ]{1,6}$/u', $p))) > 0) {
                $error = $lang["ERR_PREFIX_FORMAT"];
            } elseif ($takenPrefix !== null) {
                $error = sprintf($lang["ERR_PREFIX_TAKEN"], $takenPrefix);
            } else {
                $error = ($steps !== null ? qmsDocTypeCheckFlow($db, $steps, $external === "Y", $companyId, $lang) : null)
                      ?? qmsDocTypeCheckDistribution($db, $dists, $companyId, $lang);
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
            } else {
                $db->wrapInTransaction(function () use ($db, &$type, $category, $prefix, $name, $pattern, $standard, $review, $retention, $readAck, $external, $activeFl, $steps, $dists, $lang, &$resMsg) {
                    $isNew  = $type === null;
                    $before = $isNew ? null : qmsDocTypeData($db, $type);

                    if ($isNew) {
                        // Şirket, C_BY, C_AT QmsAuditListener ile dolar
                        $type = new DocType($category, $prefix, $name, $pattern);
                        $db->persist($type);
                        $resMsg = $lang["RECORD_IS_ADDED"];
                    } else {
                        $type->setCategory($category)->setCodePrefix($prefix)->setDocTypeName($name)->setCodePattern($pattern);
                        $resMsg = $lang["RECORD_IS_CHANGED"];
                    }
                    // Standart alanı ekranda yok; gönderilmediyse kayıtlı değer korunur
                    if (isset($_POST["STANDARD_CODE"])) {
                        $type->setStandardCode($standard !== "" ? $standard : null);
                    }
                    $type->setReviewMonths($review)
                         ->setRetentionYears($retention)
                         ->setReadAck($readAck === "Y")
                         ->setExternal($external === "Y");
                    $type->setActive($activeFl === "Y");
                    $db->flush();

                    if ($steps !== null) {
                        qmsDocTypeSyncFlow($db, $type, $steps);
                    }
                    qmsDocTypeSyncDistribution($db, $type, $dists);
                    $db->flush();

                    $after = qmsDocTypeData($db, $type);
                    AuditLogger::log($db, "qms_doc_type", $type->getDocTypeId(), $isNew ? "CREATE" : "UPDATE",
                        $isNew ? $after : array("before" => $before, "after" => $after));
                });

                $resObject = array("DOC_TYPE_ID" => $type->getDocTypeId());
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
            $type = qmsDocTypeFind($db, (int) ($_POST["DOC_TYPE_ID"] ?? 0), $companyId);
            $docCount = $type !== null ? (int) $db->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM qms_document WHERE DOC_TYPE_ID = :ID", array("ID" => $type->getDocTypeId())) : 0;

            if ($type === null) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } elseif ($docCount > 0) {
                $resCode = 2;
                $resMsg = sprintf($lang["ERR_IN_USE"], $docCount);
            } else {
                $db->wrapInTransaction(function () use ($db, $type) {
                    $snapshot = qmsDocTypeData($db, $type);
                    $type->softDelete();
                    $type->setActive(false);
                    $db->flush();   // D_BY, D_AT QmsAuditListener ile dolar
                    AuditLogger::log($db, "qms_doc_type", $type->getDocTypeId(), "DELETE", $snapshot);
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

// Tip yalnızca oturumdaki şirkette ve silinmemişse bulunur
function qmsDocTypeFind($db, int $id, int $companyId): ?DocType
{
    if ($id <= 0) {
        return null;
    }
    $type = $db->getRepository(DocType::class)->findOneBy(array("docTypeId" => $id, "company" => $companyId));
    return ($type === null || $type->isDeleted()) ? null : $type;
}

/**
 * Her önek şirket içinde tek bir tipe ait olmalı (toplu yüklemede dosya adındaki koddan tip bu önekle bulunur).
 * Başka tipte kullanılan ilk öneki döndürür; yoksa null. Silinmiş tipler de sayılır (CODE_PREFIX tekil kısıtı).
 */
function qmsDocTypePrefixTaken($db, array $prefixes, int $companyId, ?DocType $self): ?string
{
    foreach ($db->getRepository(DocType::class)->findBy(array("company" => $companyId)) as $other) {
        if ($other === $self) {
            continue;
        }
        $common = array_intersect($prefixes, DocType::splitPrefixes($other->getCodePrefix()));
        if ($common) {
            return reset($common);
        }
    }
    return null;
}

/**
 * Formu dolduran ve denetim kaydına yazılan veri: tip alanları + aktif imza adımları (pozisyonlarıyla) + aktif dağıtım.
 */
function qmsDocTypeData($db, DocType $type): array
{
    $conn = $db->getConnection();
    $steps = $conn->fetchAllAssociative("
        SELECT s.DOC_TYPE_STEP_ID id, s.SIGN_ROLE role, s.STEP_ORDER stepOrder
          FROM qms_doc_type_step s
         WHERE s.DOC_TYPE_ID = :ID AND s.ACTIVE_FL = 'Y'
         ORDER BY s.STEP_ORDER, s.DOC_TYPE_STEP_ID", array("ID" => $type->getDocTypeId()));
    foreach ($steps as &$s) {
        $s["positions"] = array_map("intval", $conn->fetchFirstColumn("
            SELECT POSITION_ID FROM qms_doc_type_step_position
             WHERE DOC_TYPE_STEP_ID = :ID AND ACTIVE_FL = 'Y' ORDER BY POSITION_ID", array("ID" => $s["id"])));
        $s["stepOrder"] = (int) $s["stepOrder"];
        unset($s["id"]);
    }
    unset($s);

    $dists = array_map(function ($d) {
        return array("type" => $d["type"], "id" => $d["id"] !== null ? (int) $d["id"] : null);
    }, $conn->fetchAllAssociative("
        SELECT SUBJECT_TYPE type, SUBJECT_ID id FROM qms_doc_type_distribution
         WHERE DOC_TYPE_ID = :ID AND ACTIVE_FL = 'Y'
         ORDER BY SUBJECT_TYPE, SUBJECT_ID", array("ID" => $type->getDocTypeId())));

    return array(
        "DOC_TYPE_ID"     => $type->getDocTypeId(),
        "CATEGORY_ID"     => $type->getCategory()->getCategoryId(),
        "CODE_PREFIX"     => $type->getCodePrefix(),
        "DOC_TYPE_NAME"   => $type->getDocTypeName(),
        "CODE_PATTERN"    => $type->getCodePattern(),
        "STANDARD_CODE"   => $type->getStandardCode(),
        "REVIEW_MONTHS"   => $type->getReviewMonths(),
        "RETENTION_YEARS" => $type->getRetentionYears(),
        "READ_ACK_FL"     => $type->requiresReadAck() ? "Y" : "N",
        "EXTERNAL_FL"     => $type->isExternal() ? "Y" : "N",
        "ACTIVE_FL"       => $type->isActive() ? "Y" : "N",
        "STEPS"           => $steps,
        "DISTRIBUTION"    => $dists,
    );
}

/**
 * İmza akışı kontrolü. $steps: [{role: PREPARED|CHECKED|APPROVED, stepOrder: 1.., positions: [POSITION_ID…]}]
 * Kurallar: her rol en fazla bir kez; her adımda en az bir pozisyon; normal tipte hazırlayan ve onaylayan zorunlu,
 * dış kaynaklıda en az bir adım; hazırlayan ilk sırada; pozisyonlar bu şirketin aktif pozisyonları
 * (kayıtlı akışta zaten seçili olan pasif pozisyon kalabilir).
 */
function qmsDocTypeCheckFlow($db, array $steps, bool $external, int $companyId, array $lang): ?string
{
    $roles = array();
    foreach ($steps as $s) {
        $role = (string) ($s["role"] ?? "");
        if (!in_array($role, DocTypeStep::ROLES, true) || isset($roles[$role])) {
            return "Geçersiz imza adımı.";
        }
        $order = (int) ($s["stepOrder"] ?? 0);
        if ($order < 1 || $order > 9) {
            return $lang["ERR_STEP_ORDER"];
        }
        $positions = array_values(array_unique(array_map("intval", (array) ($s["positions"] ?? array()))));
        if (!$positions) {
            return sprintf($lang["ERR_STEP_POSITIONS"], $lang["ROLE_" . $role]);
        }
        $found = (int) $db->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM qms_position WHERE COMPANY_ID = ? AND RECORD_FL > 0 AND POSITION_ID IN (" . implode(",", $positions) . ")",
            array($companyId));
        if ($found !== count($positions)) {
            return $lang["ERR_POSITION_NOT_FOUND"];
        }
        $roles[$role] = $order;
    }
    if ($external ? !$roles : (!isset($roles["PREPARED"]) || !isset($roles["APPROVED"]))) {
        return $external ? $lang["ERR_FLOW_EXTERNAL"] : $lang["ERR_FLOW_REQUIRED"];
    }
    if (isset($roles["PREPARED"]) && $roles["PREPARED"] !== min($roles)) {
        return $lang["ERR_FLOW_PREPARED_FIRST"];
    }
    if (isset($roles["APPROVED"]) && $roles["APPROVED"] !== max($roles)) {
        return $lang["ERR_FLOW_APPROVED_LAST"];
    }
    return null;
}

/**
 * Dağıtım kontrolü. $dists: [{type: ALL|OWNER_DEPT|DEPARTMENT|POSITION|LOCATION|USER, id: null|ID}]
 * Hedef bu şirkette olmalı; aynı hedef iki kez seçilemez.
 */
function qmsDocTypeCheckDistribution($db, array $dists, int $companyId, array $lang): ?string
{
    $tables = array(
        "DEPARTMENT" => "SELECT COUNT(*) FROM qms_department WHERE DEPARTMENT_ID = ? AND COMPANY_ID = ? AND RECORD_FL > 0",
        "POSITION"   => "SELECT COUNT(*) FROM qms_position WHERE POSITION_ID = ? AND COMPANY_ID = ? AND RECORD_FL > 0",
        "LOCATION"   => "SELECT COUNT(*) FROM qms_location WHERE LOCATION_ID = ? AND COMPANY_ID = ? AND RECORD_FL > 0",
        "USER"       => "SELECT COUNT(*) FROM mng_user_company WHERE USER_ID = ? AND COMPANY_ID = ? AND RECORD_FL > 0",
    );
    $seen = array();
    foreach ($dists as $d) {
        $type = (string) ($d["type"] ?? "");
        $id   = isset($d["id"]) && $d["id"] !== "" && $d["id"] !== null ? (int) $d["id"] : null;
        try {
            Distribution::assertValid($type, $id);
        } catch (InvalidArgumentException $e) {
            return $lang["ERR_DISTRIBUTION"];
        }
        $key = $type . ":" . $id;
        if (isset($seen[$key])) {
            return $lang["ERR_DISTRIBUTION_DUPLICATE"];
        }
        $seen[$key] = true;
        if ($id !== null && (int) $db->getConnection()->fetchOne($tables[$type], array($id, $companyId)) === 0) {
            return $lang["ERR_DISTRIBUTION"];
        }
    }
    return null;
}

// İmza akışını formdakiyle eşitler: rol başına tek satır (UNIQUE DOC_TYPE_ID + SIGN_ROLE); kullanılmayan rol pasif olur
function qmsDocTypeSyncFlow($db, DocType $type, array $steps): void
{
    $byRole = array();
    foreach ($db->getRepository(DocTypeStep::class)->findBy(array("docType" => $type)) as $existing) {
        $byRole[$existing->getSignRole()] = $existing;
    }
    $posted = array();
    foreach ($steps as $s) {
        $posted[$s["role"]] = $s;
    }

    foreach (DocTypeStep::ROLES as $role) {
        $step = $byRole[$role] ?? null;
        if (!isset($posted[$role])) {
            if ($step !== null) {
                $step->setActive(false);
            }
            continue;
        }
        if ($step === null) {
            $step = new DocTypeStep($type, (int) $posted[$role]["stepOrder"], $role);
            $db->persist($step);
            $db->flush();
        }
        $step->setStepOrder((int) $posted[$role]["stepOrder"]);
        $step->setActive(true);

        // Pozisyonlar: aynı mantık (UNIQUE DOC_TYPE_STEP_ID + POSITION_ID)
        $wanted = array_flip(array_map("intval", (array) $posted[$role]["positions"]));
        $have   = array();
        foreach ($db->getRepository(DocTypeStepPosition::class)->findBy(array("step" => $step)) as $sp) {
            $pid = $sp->getPosition()->getPositionId();
            $have[$pid] = true;
            $sp->setActive(isset($wanted[$pid]));
        }
        foreach (array_keys($wanted) as $pid) {
            if (!isset($have[$pid])) {
                $db->persist(new DocTypeStepPosition($step, $db->getReference(Position::class, $pid)));
            }
        }
    }
}

// Varsayılan dağıtımı formdakiyle eşitler (UNIQUE DOC_TYPE_ID + SUBJECT_TYPE + SUBJECT_ID); kaldırılan satır pasif olur
function qmsDocTypeSyncDistribution($db, DocType $type, array $dists): void
{
    $wanted = array();
    foreach ($dists as $d) {
        $id = isset($d["id"]) && $d["id"] !== "" && $d["id"] !== null ? (int) $d["id"] : null;
        $wanted[$d["type"] . ":" . $id] = array($d["type"], $id);
    }
    foreach ($db->getRepository(DocTypeDistribution::class)->findBy(array("docType" => $type)) as $existing) {
        $key = $existing->getSubjectType() . ":" . $existing->getSubjectId();
        $existing->setActive(isset($wanted[$key]));
        unset($wanted[$key]);
    }
    foreach ($wanted as [$subjectType, $subjectId]) {
        $db->persist(new DocTypeDistribution($type, $subjectType, $subjectId));
    }
}

/**
 * Şirketteki klasörlerin yolu ve sıralama anahtarı: [CATEGORY_ID => [path: "KALİTE DOKÜMANLARI › TALİMATLAR",
 * name: "TALİMATLAR", sortKey: "0010/0100"]]. Tek sorgu, yol PHP'de üst klasörler takip edilerek kurulur.
 */
function qmsCategoryPaths($db, int $companyId): array
{
    $rows = $db->getConnection()->fetchAllAssociative("
        SELECT CATEGORY_ID id, PARENT_ID parentId, CATEGORY_NAME name, SORT_NO sortNo
          FROM qms_doc_category WHERE COMPANY_ID = :COMPANY_ID AND RECORD_FL > 0
    ", array("COMPANY_ID" => $companyId));
    $byId = array();
    foreach ($rows as $r) {
        $byId[$r["id"]] = $r;
    }
    $result = array();
    foreach ($byId as $id => $r) {
        $names = array($r["name"]);
        $keys  = array(sprintf("%04d", $r["sortNo"]));
        $parentId = $r["parentId"];
        // Bozuk veride sonsuz döngü olmasın diye en fazla 20 seviye
        for ($i = 0; $parentId !== null && isset($byId[$parentId]) && $i < 20; $i++) {
            array_unshift($names, $byId[$parentId]["name"]);
            array_unshift($keys, sprintf("%04d", $byId[$parentId]["sortNo"]));
            $parentId = $byId[$parentId]["parentId"];
        }
        $result[$id] = array("path" => implode(" › ", $names), "name" => $r["name"], "sortKey" => implode("/", $keys));
    }
    return $result;
}
