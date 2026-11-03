<?php
/**
 * Biolab QMS — Doküman yükleme veri kaynağı (toplu yükleme)
 *
 * Kalite uzmanı klasörü ya da dosyaları sürükler; sistem kod, ad, varyant ve revizyonu dosya ve klasör adından okur
 * (Biolab\Core\Qms\FileNameParser), kontrol tablosunu gösterir. Uzman imzacıları seçer, satırlar tek tek yüklenir.
 *
 *   getOptions  → doküman tipleri, departmanlar, imzacı olabilecek kullanıcılar (aktif ve güncel görevi olan)
 *   parseFiles  → PATHS[n] (dosyaların göreli yolları; n = ekrandaki dosya numarası) → kontrol tablosu satırları
 *   checkRows   → ROWS[i][DOC_CODE, DOC_TITLE, …] (tabloda düzeltilen satırlar) → aynı kontroller yeniden
 *                 (max_input_vars sınırı için ekran satırları parça parça gönderir)
 *   setUpload   → tek satır: DOC_TYPE_ID, DOC_CODE, … + MAIN_FILE (+ SOURCE_FILE) + CHANGE_REASON
 *                 doküman (yeniyse) + revizyon + dosyalar + imza adımları + varsayılan dağıtım; revizyon imzaya gider
 *   getUploaded → son QMS_DOCUMENT_UPLOADED_DAYS günde yüklenen revizyonlar ("Yüklendi" süzgeci; sayfa yenilense de görünür)
 *                 imzacılar ve imzanın hangi adımda beklediği ile
 *
 * Kurallar:
 *   - Sistem kod ya da ad üretmez. Kod, tipin kod kalıbına uymalı; dış kaynaklıda kod boş olabilir.
 *   - Aynı kod + varyant bir kez: tabloda iki kez olamaz; veritabanında varsa yeni revizyon olur (numara büyük olmalı),
 *     imzada ya da taslakta revizyonu varsa yüklenemez.
 *   - İmza: Hazırlayan → Kontrol eden → Onaylayan, üçü zorunlu ve farklı kişiler. Dış kaynaklı dokümanda yalnızca onay.
 *   - İmzaya PDF gider; görsel (jpg/png) PDF'e çevrilir. Excel/Word özgün dosya olarak saklanır, tek başına yüklenemez.
 * Her yükleme qms_audit_log'a yazılır.
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use Biolab\Core\Qms\AuditLogger;
use Biolab\Core\Qms\FileNameParser;
use Biolab\Core\Qms\FileStorage;
use QMS\DOC\DocType;
use QMS\DOC\DocTypeDistribution;
use QMS\DOC\DocTypeStep;
use QMS\DOC\Document;
use QMS\DOC\Revision;
use QMS\DOC\RevisionDistribution;
use QMS\MNG\AppUser;
use QMS\MNG\Company;
use QMS\ORG\Department;
use QMS\SIGN\SignStep;

$db = $dbm->getDB();
$resourceFile = "qms_document/qmsDocument-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];
$sessionUserId = (int) $_SESSION["USER_ID"];

const QMS_DOCUMENT_UPLOADED_DAYS = 30;
const QMS_DOCUMENT_UPLOADED_MAX  = 500;

switch ($query) {

    case "getOptions":
        $execType = ET_DEFAULT;
        try{
            $conn = $db->getConnection();
            $resObject = array(
                "DOC_TYPE"   => array_map(fn ($t) => array("id" => $t["id"], "name" => $t["name"], "prefixes" => $t["prefixes"],
                                                            "external" => $t["external"], "categoryName" => $t["categoryName"]),
                                          qmsDocumentTypes($db, $companyId)),
                "DEPARTMENT" => $conn->fetchAllAssociative("SELECT DEPARTMENT_ID id, DEPARTMENT_NAME name FROM qms_department WHERE COMPANY_ID = ? AND ACTIVE_FL = 'Y' AND RECORD_FL > 0 ORDER BY DEPARTMENT_NAME", array($companyId)),
                "USER"       => qmsDocumentSigners($db, $companyId),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "parseFiles":
        $execType = ET_DEFAULT;
        try{
            $paths = $_POST["PATHS"] ?? array();
            if (!is_array($paths) || count($paths) === 0) {
                $resCode = 1;
                $resMsg = $lang["ERR_NO_FILES"];
            } else {
                $rows = FileNameParser::parse(array_map("strval", $paths), qmsDocumentTypes($db, $companyId));
                foreach ($rows as &$r) {
                    $r["fileErrors"] = $r["errors"];
                    $r["fileWarnings"] = $r["warnings"];
                }
                unset($r);
                $dtResponse = qmsDocumentCheck($db, $companyId, $rows, $lang, false);
                $resCode = 0;
                $resMsg = "Ok";
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "checkRows":
        $execType = ET_DEFAULT;
        try{
            $rows = array_map("qmsDocumentRowFromPost", array_values(array_filter((array) ($_POST["ROWS"] ?? array()), "is_array")));
            $dtResponse = qmsDocumentCheck($db, $companyId, $rows, $lang, true);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getUploaded":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = qmsDocumentUploaded($db, $companyId);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setUpload":
        $execType = ET_SAVE;
        $stored = array();
        try{
            $row = qmsDocumentRowFromPost($_POST);
            $reason = trim((string) ($_POST["CHANGE_REASON"] ?? ""));
            $main = $_FILES["MAIN_FILE"] ?? null;
            $source = $_FILES["SOURCE_FILE"] ?? null;
            $mainExt = $main ? mb_strtolower(pathinfo((string) $main["name"], PATHINFO_EXTENSION)) : "";
            $sourceExt = $source ? mb_strtolower(pathinfo((string) $source["name"], PATHINFO_EXTENSION)) : "";

            $error = null;
            if ($reason === "") {
                $error = $lang["ERR_REASON"];
            } elseif ($main === null || $main["error"] !== UPLOAD_ERR_OK || !in_array($mainExt, FileNameParser::MAIN_EXT, true)) {
                $error = $lang["ERR_NO_MAIN"];
            } elseif ($source !== null && ($source["error"] !== UPLOAD_ERR_OK || !in_array($sourceExt, FileNameParser::SOURCE_EXT, true))) {
                $error = $lang["ERR_FILE_TYPE"];
            } elseif ($mainExt === "pdf" ? file_get_contents($main["tmp_name"], false, null, 0, 5) !== "%PDF-" : getimagesize($main["tmp_name"]) === false) {
                $error = $lang["ERR_MAIN_UNREADABLE"];
            } else {
                $row["fileErrors"] = array();
                $checked = qmsDocumentCheck($db, $companyId, array($row), $lang, true, true)[0];
                if ($checked["errors"]) {
                    $error = implode(" ", $checked["errors"]);
                }
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
            } else {
                $result = $db->wrapInTransaction(function () use ($db, $companyId, $sessionUserId, $checked, $reason, $main, $source, $mainExt, $sourceExt, &$stored) {
                    $now  = new DateTimeImmutable();
                    $type = $db->find(DocType::class, $checked["docTypeId"]);
                    $doc  = $checked["documentId"] ? $db->find(Document::class, $checked["documentId"]) : null;
                    $isNew = $doc === null;
                    if ($isNew) {
                        $doc = new Document($type, $checked["docCode"], $checked["docTitle"],
                                            $db->getReference(Department::class, $checked["ownerDepartmentId"]), $checked["variantName"]);
                        $doc->setCompany($db->getReference(Company::class, $companyId));
                        $db->persist($doc);
                    } else {
                        $doc->setDocTitle($checked["docTitle"]);
                    }
                    $rev = new Revision($doc, (int) $checked["revisionNo"], $reason, $db->getReference(AppUser::class, $sessionUserId));
                    $db->persist($rev);
                    $db->flush();

                    // Dosyalar: doc/{doküman}/r{revizyon}/…  Görsel PDF'e çevrilir, görselin kendisi özgün dosya olur
                    $dir = "doc/" . $doc->getDocumentId() . "/r" . $rev->getRevisionId();
                    if ($mainExt === "pdf") {
                        $pdfPath = FileStorage::put($main["tmp_name"], $dir, "main", "pdf", is_uploaded_file($main["tmp_name"]));
                        $stored[] = $pdfPath;
                    } else {
                        $tmpPdf = tempnam(sys_get_temp_dir(), "qms");
                        try {
                            FileStorage::imageToPdf($main["tmp_name"], $tmpPdf);
                            $pdfPath = FileStorage::put($tmpPdf, $dir, "main", "pdf", false);
                            $stored[] = $pdfPath;
                        } finally {
                            @unlink($tmpPdf);
                        }
                        if ($source === null) {
                            $source = $main;
                            $sourceExt = $mainExt;
                        }
                    }
                    if ($source !== null) {
                        $srcPath = FileStorage::put($source["tmp_name"], $dir, "source", $sourceExt, is_uploaded_file($source["tmp_name"]));
                        $stored[] = $srcPath;
                        $rev->setSourceFile((string) $source["name"], $srcPath);
                    }
                    $pdfHash = hash_file("sha256", FileStorage::absolute($pdfPath));
                    $rev->setPdf($pdfPath, $pdfHash);
                    $rev->submitForSigning();

                    // İmza akışı: sırası gelen ilk adım açılır
                    $steps = array();
                    foreach ($checked["signers"] as $order => $s) {
                        $step = new SignStep($rev, $order + 1, $s["role"], $db->getReference(AppUser::class, $s["userId"]));
                        if ($order === 0) {
                            $step->activate($now);
                        }
                        $db->persist($step);
                        $steps[] = array("role" => $s["role"], "userId" => $s["userId"]);
                    }

                    // Dağıtım: tipin varsayılanı revizyona kopyalanır
                    $dist = array();
                    foreach ($db->getRepository(DocTypeDistribution::class)->findBy(array("docType" => $type, "activeFl" => "Y")) as $d) {
                        if ($d->isDeleted()) {
                            continue;
                        }
                        $db->persist(new RevisionDistribution($rev, $d->getSubjectType(), $d->getSubjectId(), RevisionDistribution::MODE_ADD,
                                                              RevisionDistribution::SOURCE_DEFAULT, "Doküman tipi varsayılanı"));
                        $dist[] = array("type" => $d->getSubjectType(), "id" => $d->getSubjectId());
                    }
                    $db->flush();

                    if ($isNew) {
                        AuditLogger::log($db, "qms_document", $doc->getDocumentId(), "CREATE", array(
                            "docTypeId" => $type->getDocTypeId(), "docCode" => $doc->getDocCode(), "variantName" => $doc->getVariantName(),
                            "docTitle" => $doc->getDocTitle(), "ownerDepartmentId" => $checked["ownerDepartmentId"]));
                    }
                    AuditLogger::log($db, "qms_revision", $rev->getRevisionId(), "UPLOAD", array(
                        "documentId" => $doc->getDocumentId(), "revisionNo" => $rev->getRevisionNo(), "changeReason" => $reason,
                        "mainFile" => (string) $main["name"], "sourceFile" => $source !== null ? (string) $source["name"] : null,
                        "pdfHash" => $pdfHash, "signers" => $steps, "distribution" => $dist));

                    return array("DOCUMENT_ID" => $doc->getDocumentId(), "REVISION_ID" => $rev->getRevisionId(), "NEW" => $isNew);
                });
                $stored = array();
                $resObject = $result;
                $resCode = 0;
                $resMsg = $lang["UPLOAD_OK"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        } finally {
            // Kayıt geri alındıysa depoya yazılan dosyalar silinir
            foreach ($stored as $p) {
                FileStorage::delete($p);
            }
        }
        break;
}

/**
 * Son yüklenen revizyonlar, kontrol tablosunun satır biçiminde (done = true). İmzacılar revizyonun güncel imza turundan;
 * waitingRole: sırası gelmiş (ACTIVE) adımın rolü. Elle aktarılan eski revizyonlar (LEGACY_FL) yüklemede sayılmaz.
 */
function qmsDocumentUploaded($db, int $companyId): array
{
    $conn = $db->getConnection();
    $revs = $conn->fetchAllAssociative("
        SELECT TOP " . QMS_DOCUMENT_UPLOADED_MAX . " r.REVISION_ID, r.REVISION_NO, r.REV_STATUS, r.SIGN_ROUND, r.SOURCE_FILE_NAME,
               FORMAT(r.C_AT, 'dd.MM.yyyy HH:mm') uploadedAt, ub.FULL_NAME uploadedBy,
               d.DOCUMENT_ID, d.DOC_CODE, d.VARIANT_NAME, d.DOC_TITLE, d.DOC_TYPE_ID, t.DOC_TYPE_NAME, t.EXTERNAL_FL,
               d.OWNER_DEPARTMENT_ID, dep.DEPARTMENT_NAME
          FROM qms_revision r
          JOIN qms_document d ON d.DOCUMENT_ID = r.DOCUMENT_ID
          JOIN qms_doc_type t ON t.DOC_TYPE_ID = d.DOC_TYPE_ID
          JOIN qms_department dep ON dep.DEPARTMENT_ID = d.OWNER_DEPARTMENT_ID
          LEFT JOIN mng_user ub ON ub.USER_ID = r.UPLOADED_BY
         WHERE d.COMPANY_ID = ? AND r.RECORD_FL > 0 AND r.LEGACY_FL = 'N'
           AND r.C_AT >= DATEADD(DAY, -" . QMS_DOCUMENT_UPLOADED_DAYS . ", SYSDATETIME())
         ORDER BY r.REVISION_ID DESC", array($companyId));
    if (!$revs) {
        return array();
    }

    $steps = array();
    foreach ($conn->fetchAllAssociative("
        SELECT s.REVISION_ID, s.SIGN_ROLE, s.USER_ID, s.STEP_STATUS, CONCAT(NULLIF(u.TITLE, '') + ' ', u.FULL_NAME) userName
          FROM qms_sign_step s
          JOIN qms_revision r ON r.REVISION_ID = s.REVISION_ID AND r.SIGN_ROUND = s.SIGN_ROUND
          JOIN mng_user u ON u.USER_ID = s.USER_ID
         WHERE s.REVISION_ID IN (" . implode(",", array_map(fn ($r) => (int) $r["REVISION_ID"], $revs)) . ")
           AND s.STEP_STATUS <> 'VOID' AND s.RECORD_FL > 0
         ORDER BY s.STEP_ORDER") as $s) {
        $steps[(int) $s["REVISION_ID"]][] = $s;
    }

    $fields = array(DocTypeStep::ROLE_PREPARED => "preparedId", DocTypeStep::ROLE_CHECKED => "checkedId", DocTypeStep::ROLE_APPROVED => "approvedId");
    return array_map(function ($r) use ($steps, $fields) {
        $row = array(
            "done"              => true,
            "revisionId"        => (int) $r["REVISION_ID"],
            "documentId"        => (int) $r["DOCUMENT_ID"],
            "docTypeId"         => (int) $r["DOC_TYPE_ID"],
            "docTypeName"       => $r["DOC_TYPE_NAME"],
            "external"          => $r["EXTERNAL_FL"] === "Y",
            "docCode"           => $r["DOC_CODE"],
            "variantName"       => $r["VARIANT_NAME"],
            "docTitle"          => $r["DOC_TITLE"],
            "revisionNo"        => (int) $r["REVISION_NO"],
            "revStatus"         => $r["REV_STATUS"],
            "ownerDepartmentId" => (int) $r["OWNER_DEPARTMENT_ID"],
            "ownerName"         => $r["DEPARTMENT_NAME"],
            "uploadedAt"        => $r["uploadedAt"],
            "uploadedBy"        => $r["uploadedBy"],
            "fileNames"         => array_values(array_filter(array("PDF", $r["SOURCE_FILE_NAME"]))),
            "fileIndexes"       => array(),
            "signerNames"       => array(),
            "waitingRole"       => null,
            "errors"            => array(),
            "warnings"          => array(),
        );
        foreach ($steps[$row["revisionId"]] ?? array() as $s) {
            $field = $fields[$s["SIGN_ROLE"]];
            $row[$field] = (int) $s["USER_ID"];
            $row["signerNames"][$field] = $s["userName"];
            if ($s["STEP_STATUS"] === SignStep::ACTIVE) {
                $row["waitingRole"] = $s["SIGN_ROLE"];
            }
        }
        return $row;
    }, $revs);
}

// Ekrandan gelen satır alanları (ROWS[i][…] ya da setUpload'ın düz alanları) iç adlara çevrilir
function qmsDocumentRowFromPost(array $p): array
{
    return array(
        "docTypeId"         => $p["DOC_TYPE_ID"] ?? null,
        "docCode"           => $p["DOC_CODE"] ?? null,
        "variantName"       => $p["VARIANT_NAME"] ?? "",
        "docTitle"          => $p["DOC_TITLE"] ?? "",
        "revisionNo"        => $p["REVISION_NO"] ?? null,
        "ownerDepartmentId" => $p["OWNER_DEPARTMENT_ID"] ?? null,
        "preparedId"        => $p["PREPARED_ID"] ?? null,
        "checkedId"         => $p["CHECKED_ID"] ?? null,
        "approvedId"        => $p["APPROVED_ID"] ?? null,
        "fileErrors"        => array_values((array) ($p["FILE_ERRORS"] ?? array())),
        "fileWarnings"      => array_values((array) ($p["FILE_WARNINGS"] ?? array())),
    );
}

// Aktif doküman tipleri: önekler, dış kaynaklı mı, klasörü (ayrıştırıcı klasör adını tipin klasörüyle karşılaştırır)
function qmsDocumentTypes($db, int $companyId): array
{
    $rows = $db->getConnection()->fetchAllAssociative("
        SELECT t.DOC_TYPE_ID, t.DOC_TYPE_NAME, t.CODE_PREFIX, t.EXTERNAL_FL, c.CATEGORY_NAME
          FROM qms_doc_type t JOIN qms_doc_category c ON c.CATEGORY_ID = t.CATEGORY_ID
         WHERE t.COMPANY_ID = ? AND t.ACTIVE_FL = 'Y' AND t.RECORD_FL > 0
         ORDER BY c.SORT_NO, c.CATEGORY_NAME", array($companyId));
    return array_map(fn ($r) => array(
        "id"           => (int) $r["DOC_TYPE_ID"],
        "name"         => $r["DOC_TYPE_NAME"],
        "prefixes"     => DocType::splitPrefixes((string) $r["CODE_PREFIX"]),
        "external"     => $r["EXTERNAL_FL"] === "Y",
        "categoryName" => $r["CATEGORY_NAME"],
    ), $rows);
}

// İmzacı olabilecekler: aktif, bu şirkette ve güncel görevi olan kullanıcılar (imzada görev bilgisi basılır)
function qmsDocumentSigners($db, int $companyId): array
{
    return $db->getConnection()->fetchAllAssociative("
        SELECT u.USER_ID id, CONCAT(NULLIF(u.TITLE, '') + ' ', u.FULL_NAME) name, d.DEPARTMENT_NAME departmentName, p.POSITION_NAME positionName
          FROM mng_user u
          JOIN mng_user_company uc ON uc.USER_ID = u.USER_ID AND uc.COMPANY_ID = ? AND uc.RECORD_FL > 0
          JOIN qms_user_assignment a ON a.USER_ID = u.USER_ID AND a.COMPANY_ID = uc.COMPANY_ID AND a.VALID_TO IS NULL AND a.RECORD_FL > 0
          JOIN qms_department d ON d.DEPARTMENT_ID = a.DEPARTMENT_ID
          JOIN qms_position p ON p.POSITION_ID = a.POSITION_ID
         WHERE u.ACTIVE_FL = 'Y' AND u.RECORD_FL > 0
         ORDER BY u.FULL_NAME", array($companyId));
}

/**
 * Kontrol tablosu satırlarını doğrular ve tamamlar (mevcut doküman, revizyon numarası, son imzacılar).
 * $withSigners: imzacı ve sahip departman da kontrol edilir (yükleme anında zorunlu; tabloda boşsa yalnızca uyarı).
 * Hata metinleri errors'a, uyarılar warnings'e yazılır; dosya kaynaklı hata/uyarı kodları fileErrors/fileWarnings'te taşınır.
 */
function qmsDocumentCheck($db, int $companyId, array $rows, array $lang, bool $edited, bool $withSigners = false): array
{
    $conn = $db->getConnection();
    $types = array();
    foreach ($db->getRepository(DocType::class)->findBy(array("company" => $companyId, "activeFl" => "Y")) as $t) {
        if (!$t->isDeleted()) {
            $types[$t->getDocTypeId()] = $t;
        }
    }
    $signers = array();
    foreach (qmsDocumentSigners($db, $companyId) as $u) {
        $signers[(int) $u["id"]] = $u["name"];
    }
    $departments = array_map("intval", $conn->fetchFirstColumn("SELECT DEPARTMENT_ID FROM qms_department WHERE COMPANY_ID = ? AND ACTIVE_FL = 'Y' AND RECORD_FL > 0", array($companyId)));

    $seen = array();
    $out = array();
    foreach ($rows as $r) {
        $typeId  = (int) ($r["docTypeId"] ?? 0);
        $type    = $types[$typeId] ?? null;
        $code    = $r["docCode"] ?? null;
        $code    = $code === null || trim((string) $code) === "" ? null : mb_strtoupper(trim((string) $code), "UTF-8");
        $variant = trim(preg_replace('/\s+/u', " ", (string) ($r["variantName"] ?? "")));
        $title   = trim(preg_replace('/\s+/u', " ", (string) ($r["docTitle"] ?? "")));
        $revRaw  = $r["revisionNo"] ?? null;
        $revNo   = ($revRaw === null || $revRaw === "") ? null : (ctype_digit((string) $revRaw) ? (int) $revRaw : -1);
        $errors   = array_map(fn ($c) => $lang[$c] ?? $c, (array) ($r["fileErrors"] ?? array()));
        $warnings = array_map(fn ($c) => $lang[$c] ?? $c, (array) ($r["fileWarnings"] ?? array()));
        // Tabloda tip ya da kod düzeltildiyse ayrıştırıcının tip/kod hataları geçersizdir
        if ($edited) {
            $errors = array_values(array_diff($errors, array($lang["ERR_PREFIX"], $lang["ERR_NO_CODE"])));
        }

        $documentId = null;
        $currentRev = null;
        $lastSigners = array();
        if ($type === null) {
            if (!in_array($lang["ERR_NO_CODE"], $errors, true)) {
                $errors[] = $lang["ERR_PREFIX"];
            }
        } elseif ($code === null && !$type->isExternal()) {
            $errors[] = $lang["ERR_NO_CODE"];
        } elseif ($code !== null && !$type->matchesCode($code)) {
            $errors[] = sprintf($lang["ERR_CODE_PATTERN"], $code, $type->getDocTypeName());
        }
        if ($title === "" || mb_strlen($title) > 250 || mb_strlen($variant) > 150) {
            $errors[] = $lang["ERR_TITLE"];
        }
        if ($revNo !== null && ($revNo < 0 || $revNo > 999)) {
            $errors[] = $lang["ERR_REVISION_NO"];
        }

        // Aynı kod + varyant (kodsuz dış kaynaklıda ad + varyant) tabloda bir kez
        $key = ($code ?? "#" . FileNameParser::fold($title)) . "|" . FileNameParser::fold($variant);
        if (isset($seen[$key])) {
            $errors[] = $lang["ERR_DUPLICATE_ROW"];
        }
        $seen[$key] = true;

        // Veritabanında var mı: yeni revizyon olur
        if ($type !== null && $title !== "") {
            $existing = $code !== null
                ? $conn->fetchAssociative("SELECT DOCUMENT_ID, DOC_TYPE_ID, DOC_STATUS FROM qms_document WHERE COMPANY_ID = ? AND DOC_CODE = ? AND VARIANT_NAME = ? AND RECORD_FL > 0", array($companyId, $code, $variant))
                : $conn->fetchAssociative("SELECT DOCUMENT_ID, DOC_TYPE_ID, DOC_STATUS FROM qms_document WHERE COMPANY_ID = ? AND DOC_CODE IS NULL AND DOC_TITLE = ? AND VARIANT_NAME = ? AND RECORD_FL > 0", array($companyId, $title, $variant));
            if ($existing) {
                $documentId = (int) $existing["DOCUMENT_ID"];
                $open = (int) $conn->fetchOne("SELECT COUNT(*) FROM qms_revision WHERE DOCUMENT_ID = ? AND REV_STATUS IN ('DRAFT', 'SIGNING') AND RECORD_FL > 0", array($documentId));
                $currentRev = $conn->fetchOne("SELECT MAX(REVISION_NO) FROM qms_revision WHERE DOCUMENT_ID = ? AND REV_STATUS IN ('EFFECTIVE', 'OBSOLETE') AND RECORD_FL > 0", array($documentId));
                $currentRev = $currentRev === null || $currentRev === false ? null : (int) $currentRev;
                if ($existing["DOC_STATUS"] === Document::STATUS_CANCELLED) {
                    $errors[] = $lang["ERR_CANCELLED"];
                } elseif ((int) $existing["DOC_TYPE_ID"] !== $typeId) {
                    $errors[] = $lang["ERR_OTHER_TYPE"];
                } elseif ($open > 0) {
                    $errors[] = $lang["ERR_IN_PROGRESS"];
                } elseif ($currentRev !== null && $revNo !== null && $revNo <= $currentRev) {
                    $errors[] = sprintf($lang["ERR_REVISION_LOW"], str_pad((string) $currentRev, 2, "0", STR_PAD_LEFT));
                }
                $revNo ??= $currentRev !== null ? $currentRev + 1 : 0;
                // Son revizyonun imzacıları sonraki revizyonda önerilir
                $lastSigners = $conn->fetchAllKeyValue("
                    SELECT s.SIGN_ROLE, s.USER_ID FROM qms_sign_step s
                     WHERE s.REVISION_ID = (SELECT MAX(REVISION_ID) FROM qms_revision WHERE DOCUMENT_ID = ? AND RECORD_FL > 0)
                       AND s.STEP_STATUS <> 'VOID' AND s.RECORD_FL > 0 ORDER BY s.SIGN_ROUND, s.STEP_ORDER", array($documentId));
            }
        }
        $revNo ??= 0;

        // İmzacılar: iç dokümanda üç adım, dış kaynaklıda yalnızca onay
        $external = $type !== null && $type->isExternal();
        $roles = $external ? array(DocTypeStep::ROLE_APPROVED) : array(DocTypeStep::ROLE_PREPARED, DocTypeStep::ROLE_CHECKED, DocTypeStep::ROLE_APPROVED);
        $fields = array(DocTypeStep::ROLE_PREPARED => "preparedId", DocTypeStep::ROLE_CHECKED => "checkedId", DocTypeStep::ROLE_APPROVED => "approvedId");
        $chosen = array();
        foreach ($roles as $role) {
            $uid = (int) ($r[$fields[$role]] ?? 0);
            if ($uid <= 0 && isset($lastSigners[$role]) && isset($signers[(int) $lastSigners[$role]])) {
                $uid = (int) $lastSigners[$role];
            }
            $chosen[$role] = $uid;
        }
        $signerList = array();
        $missing = count(array_filter($chosen, fn ($u) => $u <= 0)) > 0;
        if ($missing) {
            if ($withSigners) {
                $errors[] = $external ? $lang["ERR_SIGNER_APPROVED"] : $lang["ERR_SIGNERS"];
            }
        } elseif (count(array_filter($chosen, fn ($u) => !isset($signers[$u]))) > 0) {
            $errors[] = $lang["ERR_SIGNER_INVALID"];
        } elseif (count(array_unique($chosen)) !== count($chosen)) {
            $errors[] = $lang["ERR_SIGNER_SAME"];
        } else {
            foreach ($chosen as $role => $uid) {
                $signerList[] = array("role" => $role, "userId" => $uid);
            }
        }

        $ownerId = (int) ($r["ownerDepartmentId"] ?? 0);
        if ($documentId === null && $withSigners && !in_array($ownerId, $departments, true)) {
            $errors[] = $lang["ERR_OWNER"];
        }

        $out[] = array_merge($r, array(
            "docTypeId"         => $type?->getDocTypeId(),
            "docTypeName"       => $type?->getDocTypeName(),
            "external"          => $external,
            "docCode"           => $code,
            "variantName"       => $variant,
            "docTitle"          => $title,
            "revisionNo"        => $revNo,
            "documentId"        => $documentId,
            "currentRevisionNo" => $currentRev,
            "ownerDepartmentId" => $ownerId > 0 ? $ownerId : null,
            "preparedId"        => ($chosen[DocTypeStep::ROLE_PREPARED] ?? 0) ?: null,
            "checkedId"         => ($chosen[DocTypeStep::ROLE_CHECKED] ?? 0) ?: null,
            "approvedId"        => ($chosen[DocTypeStep::ROLE_APPROVED] ?? 0) ?: null,
            "signers"           => $signerList,
            "errors"            => array_values(array_unique($errors)),
            "warnings"          => array_values(array_unique($warnings)),
        ));
    }
    return $out;
}
