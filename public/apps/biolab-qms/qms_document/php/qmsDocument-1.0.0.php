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
 *                 (e-imzasız tipte imza adımı açılmaz, revizyon doğrudan yürürlüğe girer)
 *   getUploaded → son QMS_DOCUMENT_UPLOADED_DAYS günde yüklenen revizyonlar ("Yüklendi" süzgeci; sayfa yenilense de görünür)
 *                 imzacılar ve imzanın hangi adımda beklediği ile
 *
 * Kurallar:
 *   - Sistem kod ya da ad üretmez. Kod isteğe bağlıdır; verilirse tipin kod kalıbına uymalı. Kodsuz doküman başlığıyla tanımlanır.
 *   - Aynı kod + varyant bir kez: tabloda iki kez olamaz; veritabanında varsa yeni revizyon olur (numara büyük olmalı),
 *     imzada revizyonu varsa yüklenemez. Reddedilmiş (taslağa dönmüş) revizyonu varsa yeni dosya o revizyona konur ve
 *     yeni imza turu açılır (düzeltme); revizyon numarası değişmez, eski tur ve red gerekçesi kayıtlarda kalır.
 *   - İmza yalnızca e-imzalı tipte (ESIGN_FL; Form, Liste): Hazırlayan → Kontrol eden → Onaylayan, üçü zorunlu ve farklı
 *     kişiler; dış kaynaklıda yalnızca onay. E-imzasız tipte ıslak imza basılı nüshaya atılır: imzacı seçilmez,
 *     revizyon yüklendiği gün yürürlüğe girer ve önceki revizyon geçersiz olur.
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
            $resObject = array(
                "DOC_TYPE"   => array_map(fn ($t) => array("id" => $t["id"], "name" => $t["name"], "prefixes" => $t["prefixes"],
                                                            "external" => $t["external"], "esign" => $t["esign"], "categoryName" => $t["categoryName"]),
                                          qmsDocumentTypes($db, $companyId)),
                "DEPARTMENT" => $db->createQuery("SELECT d.departmentId id, d.departmentName name FROM QMS\ORG\Department d WHERE IDENTITY(d.company) = :COMPANY_ID AND d.activeFl = 'Y' AND d.recordFl > 0 ORDER BY d.departmentName")
                    ->setParameter("COMPANY_ID", $companyId)->getArrayResult(),
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
                $dtResponse = qmsDocumentCheck($db, $companyId, $rows, $lang);
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
            $dtResponse = qmsDocumentCheck($db, $companyId, $rows, $lang);
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
            $dtResponse = qmsDocumentUploaded($db, $companyId, $lang);
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
                $checked = qmsDocumentCheck($db, $companyId, array($row), $lang, true)[0];
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
                        $doc->setFillable($checked["fillableFl"] === "Y");
                        $db->persist($doc);
                    } else {
                        $doc->setDocTitle($checked["docTitle"])->setFillable($checked["fillableFl"] === "Y");
                    }
                    // Düzeltme: reddedilmiş (taslak) revizyona yeni dosya konur; değilse yeni revizyon açılır
                    $rev = $checked["resubmitRevisionId"] ? $db->find(Revision::class, $checked["resubmitRevisionId"]) : null;
                    $resubmit = $rev !== null;
                    if ($resubmit && ($rev->getRevStatus() !== Revision::DRAFT || $rev->getDocument() !== $doc)) {
                        throw new LogicException("Düzeltilecek revizyon taslakta değil.");
                    }
                    $previousPdfHash = $rev?->getPdfHash();
                    if (!$resubmit) {
                        $rev = new Revision($doc, (int) $checked["revisionNo"], $reason, $db->getReference(AppUser::class, $sessionUserId));
                        $db->persist($rev);
                    }
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

                    // E-imzasız tip (ıslak imza basılı nüshada): yüklendiği gün yürürlüğe girer, önceki revizyon geçersiz olur
                    $steps = array();
                    if (!$type->isESign()) {
                        $today = new DateTimeImmutable("today");
                        foreach ($db->getRepository(Revision::class)->findBy(array("document" => $doc, "revStatus" => Revision::EFFECTIVE)) as $old) {
                            if ($old !== $rev) {
                                $old->makeObsolete($today);
                            }
                        }
                        $rev->publishWithoutSigning($today, $type->getReviewMonths());
                        $doc->publish($rev, $today);
                    } else {
                        $rev->submitForSigning();
                    }

                    // İmza akışı (e-imzalı tip): sırası gelen ilk adım açılır
                    foreach ($checked["signers"] as $order => $s) {
                        $step = new SignStep($rev, $order + 1, $s["role"], $db->getReference(AppUser::class, $s["userId"]));
                        if ($order === 0) {
                            $step->activate($now);
                        }
                        $db->persist($step);
                        $steps[] = array("role" => $s["role"], "userId" => $s["userId"]);
                    }

                    // Dağıtım: tipin varsayılanı revizyona kopyalanır (düzeltmede ilk yüklemedeki dağıtım kalır)
                    $dist = array();
                    foreach ($resubmit ? array() : $db->getRepository(DocTypeDistribution::class)->findBy(array("docType" => $type, "activeFl" => "Y")) as $d) {
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
                            "docTitle" => $doc->getDocTitle(), "ownerDepartmentId" => $checked["ownerDepartmentId"], "fillable" => $checked["fillableFl"]));
                    }
                    AuditLogger::log($db, "qms_revision", $rev->getRevisionId(), $resubmit ? "RESUBMIT" : "UPLOAD", array(
                        "documentId" => $doc->getDocumentId(), "revisionNo" => $rev->getRevisionNo(), "changeReason" => $reason,
                        "signRound" => $rev->getSignRound(), "previousPdfHash" => $previousPdfHash,
                        "mainFile" => (string) $main["name"], "sourceFile" => $source !== null ? (string) $source["name"] : null,
                        "pdfHash" => $pdfHash, "signers" => $steps, "distribution" => $dist, "fillable" => $checked["fillableFl"],
                        "esign" => $type->isESign()));
                    if (!$type->isESign()) {
                        AuditLogger::log($db, "qms_revision", $rev->getRevisionId(), "PUBLISH", array(
                            "documentId" => $doc->getDocumentId(), "revisionNo" => $rev->getRevisionNo(),
                            "effectiveDate" => $rev->getEffectiveDate()->format("Y-m-d"), "esign" => false));
                    }

                    return array("DOCUMENT_ID" => $doc->getDocumentId(), "REVISION_ID" => $rev->getRevisionId(), "NEW" => $isNew,
                                 "EFFECTIVE" => !$type->isESign());
                });
                $stored = array();
                $resObject = $result;
                $resCode = 0;
                $resMsg = $lang[$result["EFFECTIVE"] ? "UPLOAD_OK_EFFECTIVE" : "UPLOAD_OK"];
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
function qmsDocumentUploaded($db, int $companyId, array $lang): array
{
    $revs = $db->createQuery("
        SELECT r.revisionId, r.revisionNo, r.revStatus, r.sourceFileName, r.cAt, ub.fullName uploadedBy,
               d.documentId, d.docCode, d.variantName, d.docTitle, d.fillableFl, t.docTypeId, t.docTypeName, t.externalFl, t.esignFl,
               dep.departmentId, dep.departmentName
          FROM QMS\DOC\Revision r
          JOIN r.document d
          JOIN d.docType t
          JOIN d.ownerDepartment dep
          LEFT JOIN r.uploadedBy ub
         WHERE IDENTITY(d.company) = :COMPANY_ID AND r.recordFl > 0 AND r.legacyFl = 'N' AND r.cAt >= :SINCE
         ORDER BY r.revisionId DESC")
        ->setParameters(array("COMPANY_ID" => $companyId, "SINCE" => new DateTimeImmutable("-" . QMS_DOCUMENT_UPLOADED_DAYS . " days")))
        ->setMaxResults(QMS_DOCUMENT_UPLOADED_MAX)
        ->getArrayResult();
    if (!$revs) {
        return array();
    }

    // Güncel imza turunun adımları (iptal edilenler hariç)
    $steps = array();
    foreach ($db->createQuery("
        SELECT r.revisionId, s.signRole, s.stepStatus, u.userId, u.title, u.fullName
          FROM QMS\SIGN\SignStep s JOIN s.revision r JOIN s.user u
         WHERE r.revisionId IN (:IDS) AND s.signRound = r.signRound AND s.stepStatus <> 'VOID' AND s.recordFl > 0
         ORDER BY s.stepOrder")
        ->setParameter("IDS", array_column($revs, "revisionId"))->getArrayResult() as $s) {
        $steps[$s["revisionId"]][] = $s;
    }

    $fields = array(DocTypeStep::ROLE_PREPARED => "preparedId", DocTypeStep::ROLE_CHECKED => "checkedId", DocTypeStep::ROLE_APPROVED => "approvedId");
    return array_map(function ($r) use ($db, $steps, $fields, $lang) {
        $row = array(
            "done"              => true,
            "revisionId"        => (int) $r["revisionId"],
            "documentId"        => (int) $r["documentId"],
            "docTypeId"         => (int) $r["docTypeId"],
            "docTypeName"       => $r["docTypeName"],
            "external"          => $r["externalFl"] === "Y",
            "esign"             => $r["esignFl"] === "Y",
            "docCode"           => $r["docCode"],
            "variantName"       => $r["variantName"],
            "docTitle"          => $r["docTitle"],
            "revisionNo"        => (int) $r["revisionNo"],
            "revStatus"         => $r["revStatus"],
            "ownerDepartmentId" => (int) $r["departmentId"],
            "ownerName"         => $r["departmentName"],
            "fillableFl"        => $r["fillableFl"],
            "uploadedAt"        => $r["cAt"]?->format("d.m.Y H:i"),
            "uploadedBy"        => $r["uploadedBy"],
            "fileNames"         => array_values(array_filter(array("PDF", $r["sourceFileName"]))),
            "fileIndexes"       => array(),
            "signerNames"       => array(),
            "waitingRole"       => null,
            "errors"            => array(),
            "warnings"          => array(),
        );
        if ($r["revStatus"] === Revision::DRAFT && ($rej = qmsDocumentRejection($db, $row["revisionId"], $lang)) !== null) {
            $row["warnings"][] = $rej;
        }
        foreach ($steps[$row["revisionId"]] ?? array() as $s) {
            $field = $fields[$s["signRole"]];
            $row[$field] = (int) $s["userId"];
            $row["signerNames"][$field] = qmsDocumentPersonName($s["title"], $s["fullName"]);
            if ($s["stepStatus"] === SignStep::ACTIVE) {
                $row["waitingRole"] = $s["signRole"];
            }
        }
        return $row;
    }, $revs);
}

// Revizyonun son reddi: "Ad Soyad (Kontrol eden) 04.11.2026 15:20 reddetti: gerekçe"; reddedilmemişse null
function qmsDocumentRejection($db, int $revisionId, array $lang): ?string
{
    $r = $db->createQuery("
        SELECT u.fullName, s.signRole, s.completedAt, s.rejectNote
          FROM QMS\SIGN\SignStep s JOIN s.user u
         WHERE IDENTITY(s.revision) = :ID AND s.stepStatus = 'REJECTED'
         ORDER BY s.signRound DESC, s.signStepId DESC")
        ->setParameter("ID", $revisionId)->setMaxResults(1)->getArrayResult()[0] ?? null;
    return $r ? sprintf($lang["REJECT_INFO"], $r["fullName"], $lang["ROLE_" . $r["signRole"]] ?? $r["signRole"],
                        $r["completedAt"]?->format("d.m.Y H:i"), $r["rejectNote"]) : null;
}

// İmzada ve listelerde gösterilen ad: "Uzm. Dr. Ayşe Yılmaz" (unvan boşsa yalnızca ad)
function qmsDocumentPersonName(?string $title, string $fullName): string
{
    return trim(($title !== null && $title !== "" ? $title . " " : "") . $fullName);
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
        "fillableFl"        => $p["FILLABLE_FL"] ?? null,
        "fileErrors"        => array_values((array) ($p["FILE_ERRORS"] ?? array())),
        "fileWarnings"      => array_values((array) ($p["FILE_WARNINGS"] ?? array())),
    );
}

// Aktif doküman tipleri: önekler, dış kaynaklı mı, klasörü (ayrıştırıcı klasör adını tipin klasörüyle karşılaştırır)
function qmsDocumentTypes($db, int $companyId): array
{
    $rows = $db->createQuery("
        SELECT t.docTypeId, t.docTypeName, t.codePrefix, t.externalFl, t.esignFl, c.categoryName
          FROM QMS\DOC\DocType t JOIN t.category c
         WHERE IDENTITY(t.company) = :COMPANY_ID AND t.activeFl = 'Y' AND t.recordFl > 0
         ORDER BY c.sortNo, c.categoryName")
        ->setParameter("COMPANY_ID", $companyId)->getArrayResult();
    return array_map(fn ($r) => array(
        "id"           => (int) $r["docTypeId"],
        "name"         => $r["docTypeName"],
        "prefixes"     => DocType::splitPrefixes((string) $r["codePrefix"]),
        "external"     => $r["externalFl"] === "Y",
        "esign"        => $r["esignFl"] === "Y",
        "categoryName" => $r["categoryName"],
    ), $rows);
}

// İmzacı olabilecekler: aktif, bu şirkette ve güncel görevi olan kullanıcılar (imzada görev bilgisi basılır)
function qmsDocumentSigners($db, int $companyId): array
{
    return array_map(fn ($u) => array(
        "id"             => (int) $u["userId"],
        "name"           => qmsDocumentPersonName($u["title"], $u["fullName"]),
        "departmentName" => $u["departmentName"],
        "positionName"   => $u["positionName"],
    ), $db->createQuery("
        SELECT u.userId, u.title, u.fullName, d.departmentName, p.positionName
          FROM QMS\MNG\AppUser u
          JOIN QMS\MNG\UserCompany uc WITH uc.user = u AND IDENTITY(uc.company) = :COMPANY_ID AND uc.recordFl > 0
          JOIN QMS\ORG\UserAssignment a WITH a.user = u AND a.company = uc.company AND a.validTo IS NULL AND a.recordFl > 0
          JOIN a.department d
          JOIN a.position p
         WHERE u.activeFl = 'Y' AND u.recordFl > 0
         ORDER BY u.fullName")
        ->setParameter("COMPANY_ID", $companyId)->getArrayResult());
}

/**
 * Kontrol tablosu satırlarını doğrular ve tamamlar (mevcut doküman, revizyon numarası, son imzacılar).
 * $withSigners: imzacı ve sahip departman da kontrol edilir (yükleme anında zorunlu; tabloda boşsa yalnızca uyarı).
 * Hata metinleri errors'a, uyarılar warnings'e yazılır; dosya kaynaklı hata/uyarı kodları fileErrors/fileWarnings'te taşınır.
 */
function qmsDocumentCheck($db, int $companyId, array $rows, array $lang, bool $withSigners = false): array
{
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
    $departments = array_map("intval", array_column($db->createQuery("SELECT d.departmentId FROM QMS\ORG\Department d WHERE IDENTITY(d.company) = :COMPANY_ID AND d.activeFl = 'Y' AND d.recordFl > 0")
        ->setParameter("COMPANY_ID", $companyId)->getArrayResult(), "departmentId"));

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
        // Tip, kod ve "kodsuz" durumları her kontrolde baştan değerlendirilir (tabloda düzeltilmiş olabilir)
        $errors   = array_values(array_diff($errors, array($lang["ERR_PREFIX"], $lang["ERR_NO_CODE"], $lang["ERR_NO_TYPE"])));
        $warnings = array_values(array_diff($warnings, array($lang["WRN_NO_CODE"])));

        $documentId = null;
        $currentRev = null;
        $lastSigners = array();
        $existingFillable = null;
        $resubmitId = null;
        if ($type === null) {
            $errors[] = $code === null ? $lang["ERR_NO_TYPE"] : $lang["ERR_PREFIX"];
        } elseif ($code !== null && !$type->matchesCode($code)) {
            $errors[] = sprintf($lang["ERR_CODE_PATTERN"], $code, $type->getDocTypeName());
        }
        // Kod isteğe bağlı: kodsuz doküman başlığıyla tanımlanır; dış kaynaklı dışında uyarı verilir
        if ($code === null && $type !== null && !$type->isExternal()) {
            $warnings[] = $lang["WRN_NO_CODE"];
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
            $existing = $db->createQuery("
                SELECT d.documentId, IDENTITY(d.docType) docTypeId, d.docStatus, d.fillableFl
                  FROM QMS\DOC\Document d
                 WHERE IDENTITY(d.company) = :COMPANY_ID AND d.variantName = :VARIANT AND d.recordFl > 0
                   AND " . ($code !== null ? "d.docCode = :KEY" : "d.docCode IS NULL AND d.docTitle = :KEY"))
                ->setParameters(array("COMPANY_ID" => $companyId, "VARIANT" => $variant, "KEY" => $code ?? $title))
                ->setMaxResults(1)->getArrayResult()[0] ?? null;
            if ($existing) {
                $documentId = (int) $existing["documentId"];
                $existingFillable = $existing["fillableFl"];
                $open = (int) $db->createQuery("SELECT COUNT(r.revisionId) FROM QMS\DOC\Revision r WHERE IDENTITY(r.document) = :ID AND r.revStatus = 'SIGNING' AND r.recordFl > 0")
                    ->setParameter("ID", $documentId)->getSingleScalarResult();
                $draft = $db->createQuery("SELECT r.revisionId, r.revisionNo FROM QMS\DOC\Revision r WHERE IDENTITY(r.document) = :ID AND r.revStatus = 'DRAFT' AND r.recordFl > 0 ORDER BY r.revisionId DESC")
                    ->setParameter("ID", $documentId)->setMaxResults(1)->getArrayResult()[0] ?? null;
                $currentRev = $db->createQuery("SELECT MAX(r.revisionNo) FROM QMS\DOC\Revision r WHERE IDENTITY(r.document) = :ID AND r.revStatus IN ('EFFECTIVE', 'OBSOLETE') AND r.recordFl > 0")
                    ->setParameter("ID", $documentId)->getSingleScalarResult();
                $currentRev = $currentRev === null ? null : (int) $currentRev;
                if ($existing["docStatus"] === Document::STATUS_CANCELLED) {
                    $errors[] = $lang["ERR_CANCELLED"];
                } elseif ((int) $existing["docTypeId"] !== $typeId) {
                    $errors[] = $lang["ERR_OTHER_TYPE"];
                } elseif ($open > 0) {
                    $errors[] = $lang["ERR_IN_PROGRESS"];
                } elseif ($draft) {
                    // Reddedilmiş revizyonun düzeltmesi: aynı revizyon numarası, yeni imza turu
                    $resubmitId = (int) $draft["revisionId"];
                    $revNo = (int) $draft["revisionNo"];
                    $rej = qmsDocumentRejection($db, $resubmitId, $lang);
                    $warnings[] = sprintf($lang["WRN_RESUBMIT"], str_pad((string) $revNo, 2, "0", STR_PAD_LEFT)) . ($rej !== null ? " " . $rej : "");
                } elseif ($currentRev !== null && $revNo !== null && $revNo <= $currentRev) {
                    $errors[] = sprintf($lang["ERR_REVISION_LOW"], str_pad((string) $currentRev, 2, "0", STR_PAD_LEFT));
                }
                $revNo ??= $currentRev !== null ? $currentRev + 1 : 0;
                // Son revizyonun imzacıları sonraki revizyonda önerilir (aynı rolde son turunki geçerli)
                foreach ($db->createQuery("
                    SELECT s.signRole, IDENTITY(s.user) userId FROM QMS\SIGN\SignStep s
                     WHERE IDENTITY(s.revision) = (SELECT MAX(r2.revisionId) FROM QMS\DOC\Revision r2 WHERE IDENTITY(r2.document) = :ID AND r2.recordFl > 0)
                       AND s.stepStatus <> 'VOID' AND s.recordFl > 0 ORDER BY s.signRound, s.stepOrder")
                    ->setParameter("ID", $documentId)->getArrayResult() as $s) {
                    $lastSigners[$s["signRole"]] = (int) $s["userId"];
                }
            }
        }
        $revNo ??= 0;

        // İmzacılar: e-imzalı tipte iç dokümanda üç adım, dış kaynaklıda yalnızca onay; e-imzasız tipte imzacı yok
        $external = $type !== null && $type->isExternal();
        $esign = $type === null || $type->isESign();
        $roles = !$esign ? array()
            : ($external ? array(DocTypeStep::ROLE_APPROVED) : array(DocTypeStep::ROLE_PREPARED, DocTypeStep::ROLE_CHECKED, DocTypeStep::ROLE_APPROVED));
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

        // Doldurulur mu: ekranda seçildiyse o; seçilmediyse mevcut dokümanın değeri, yeni dokümanda tipin varsayılanı
        $fillable = in_array($r["fillableFl"] ?? null, array("Y", "N"), true)
            ? $r["fillableFl"] : ($existingFillable ?? ($type !== null && $type->isFillable() ? "Y" : "N"));

        $ownerId = (int) ($r["ownerDepartmentId"] ?? 0);
        if ($documentId === null && $withSigners && !in_array($ownerId, $departments, true)) {
            $errors[] = $lang["ERR_OWNER"];
        }

        $out[] = array_merge($r, array(
            "docTypeId"         => $type?->getDocTypeId(),
            "docTypeName"       => $type?->getDocTypeName(),
            "external"          => $external,
            "esign"             => $esign,
            "fillableFl"        => $fillable,
            "docCode"           => $code,
            "variantName"       => $variant,
            "docTitle"          => $title,
            "revisionNo"        => $revNo,
            "documentId"        => $documentId,
            "currentRevisionNo" => $currentRev,
            "resubmitRevisionId" => $resubmitId,
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
