<?php
/**
 * Biolab QMS — Doküman ana listesi (kontrollü doküman listesi; kalite ve denetçi)
 *
 *   getTree   → klasörler (qms_doc_category) ve her klasördeki doküman sayısı
 *   getDocs   → CATEGORY_ID (boş ya da kök: hepsi) + SHOW_CANCELLED: dokümanlar; yürürlükteki revizyon (yürürlük ve
 *               gözden geçirme tarihi, gecikti mi) ve varsa imzadaki / reddedilmiş revizyon (sırası gelen adım)
 *   getDetail → DOCUMENT_ID: doküman bilgisi, bütün revizyonlar ve her revizyonun imza adımları
 *               (imzalananlarda imza anındaki ad, unvan, pozisyon, zaman, doğrulama ve kayıt hash'i; reddedilenlerde gerekçe)
 * Yalnızca okur; değişiklik yoktur. Dosyalar api/qms-file.php'den açılır.
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use Biolab\Core\Qms\DocumentStatus;

$db = $dbm->getDB();
$resourceFile = "qms_master_list/qmsMasterList-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    case "getTree":
        $execType = ET_DEFAULT;
        try{
            $all = ($_POST["SHOW_CANCELLED"] ?? "N") === "Y";
            $counts = array();
            foreach ($db->createQuery("
                SELECT IDENTITY(t.category) categoryId, COUNT(d.documentId) n
                  FROM QMS\DOC\Document d JOIN d.docType t
                 WHERE IDENTITY(d.company) = :COMPANY_ID AND d.recordFl > 0" . ($all ? "" : " AND d.docStatus = 'ACTIVE'") . "
                 GROUP BY t.category")
                ->setParameter("COMPANY_ID", $companyId)->getArrayResult() as $r) {
                $counts[(int) $r["categoryId"]] = (int) $r["n"];
            }
            $dtResponse = array_map(fn ($c) => array(
                "id"       => $c["categoryId"],
                "parentId" => $c["parentId"],
                "name"     => $c["categoryName"],
                "docCount" => $counts[$c["categoryId"]] ?? 0,
            ), $db->createQuery("
                SELECT c.categoryId, IDENTITY(c.parent) parentId, c.categoryName, CASE WHEN c.parent IS NULL THEN 0 ELSE 1 END AS HIDDEN isChild
                  FROM QMS\DOC\DocCategory c
                 WHERE IDENTITY(c.company) = :COMPANY_ID AND c.recordFl > 0
                 ORDER BY isChild, c.sortNo, c.categoryName")
                ->setParameter("COMPANY_ID", $companyId)->getArrayResult());
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getDocs":
        $execType = ET_DEFAULT;
        try{
            $params = array("COMPANY_ID" => $companyId);
            $where = "";
            $categoryId = (int) ($_POST["CATEGORY_ID"] ?? 0);
            if ($categoryId > 0) {
                // Klasör ya da alt klasörleri (ağaç iki seviye: KALİTE DOKÜMANLARI › FORMLAR …)
                $where .= " AND (c.categoryId = :CATEGORY_ID OR IDENTITY(c.parent) = :CATEGORY_ID)";
                $params["CATEGORY_ID"] = $categoryId;
            }
            if (($_POST["SHOW_CANCELLED"] ?? "N") !== "Y") {
                $where .= " AND d.docStatus = 'ACTIVE'";
            }
            $docs = $db->createQuery("
                SELECT d.documentId, d.docCode, d.variantName, d.docTitle, d.docStatus, d.fillableFl,
                       t.docTypeName, t.externalFl, c.categoryName, dep.departmentName,
                       cur.revisionId, cur.revisionNo, cur.effectiveDate, cur.nextReviewDate,
                       CASE WHEN d.docCode IS NULL THEN 1 ELSE 0 END AS HIDDEN noCode
                  FROM QMS\DOC\Document d
                  JOIN d.docType t
                  JOIN t.category c
                  JOIN d.ownerDepartment dep
                  LEFT JOIN d.currentRevision cur WITH cur.revStatus = 'EFFECTIVE'
                 WHERE IDENTITY(d.company) = :COMPANY_ID AND d.recordFl > 0" . $where . "
                 ORDER BY c.sortNo, c.categoryName, noCode, d.docCode, d.docTitle, d.variantName")
                ->setParameters($params)->getArrayResult();
            $ids = array_column($docs, "documentId");
            $pending = DocumentStatus::pending($db, $ids);
            $counts = DocumentStatus::recordCounts($db, $ids);
            $today = new DateTimeImmutable("today");
            $dtResponse = array_map(function ($d) use ($pending, $counts, $today) {
                $p = $pending[$d["documentId"]] ?? null;
                return array(
                    "documentId"        => $d["documentId"],
                    "docCode"           => $d["docCode"],
                    "variantName"       => $d["variantName"],
                    "docTitle"          => $d["docTitle"],
                    "docStatus"         => $d["docStatus"],
                    "fillableFl"        => $d["fillableFl"],
                    "docTypeName"       => $d["docTypeName"],
                    "externalFl"        => $d["externalFl"],
                    "categoryName"      => $d["categoryName"],
                    "ownerName"         => $d["departmentName"],
                    "currentRevisionId" => $d["revisionId"],
                    "currentRevisionNo" => $d["revisionNo"],
                    "effectiveDate"     => $d["effectiveDate"]?->format("d.m.Y"),
                    "nextReviewDate"    => $d["nextReviewDate"]?->format("d.m.Y"),
                    "reviewOverdue"     => $d["nextReviewDate"] !== null && $d["nextReviewDate"] < $today ? "Y" : "N",
                    "pendingRevisionId" => $p["revisionId"] ?? null,
                    "pendingRevisionNo" => $p["revisionNo"] ?? null,
                    "pendingStatus"     => $p["revStatus"] ?? null,
                    "waitingRole"       => $p["waitingRole"] ?? null,
                    "recordCount"       => $counts[$d["documentId"]] ?? 0,
                );
            }, $docs);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getDetail":
        $execType = ET_DEFAULT;
        try{
            $docId = (int) ($_POST["DOCUMENT_ID"] ?? 0);
            $doc = $db->createQuery("
                SELECT d.documentId, d.docCode, d.variantName, d.docTitle, d.docStatus, d.fillableFl,
                       IDENTITY(d.currentRevision) currentRevisionId, d.firstPublishDate,
                       t.docTypeName, c.categoryName, dep.departmentName ownerName
                  FROM QMS\DOC\Document d JOIN d.docType t JOIN t.category c JOIN d.ownerDepartment dep
                 WHERE d.documentId = :ID AND IDENTITY(d.company) = :COMPANY_ID AND d.recordFl > 0")
                ->setParameters(array("ID" => $docId, "COMPANY_ID" => $companyId))->getArrayResult()[0] ?? null;
            if (!$doc) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } else {
                $doc["firstPublishDate"] = $doc["firstPublishDate"]?->format("d.m.Y");
                $doc["recordCount"] = DocumentStatus::recordCounts($db, array($docId))[$docId] ?? 0;

                // İmza adımları; imzalananlarda imza anındaki ad, unvan, pozisyon ve kayıt hash'i
                $steps = array();
                foreach ($db->createQuery("
                    SELECT r.revisionId, s.signRound, s.stepOrder, s.signRole, s.stepStatus, s.completedAt, s.rejectNote,
                           u.fullName userName, sg.signerName, sg.signerTitle, sg.signerPosition, sg.signedAt, sg.authMethod, sg.recordHash
                      FROM QMS\SIGN\SignStep s
                      JOIN s.revision r
                      JOIN s.user u
                      LEFT JOIN QMS\SIGN\Signature sg WITH sg.signStep = s
                     WHERE IDENTITY(r.document) = :ID AND s.recordFl > 0
                     ORDER BY r.revisionId, s.signRound DESC, s.stepOrder")
                    ->setParameter("ID", $docId)->getArrayResult() as $s) {
                    $s["completedAt"] = $s["completedAt"]?->format("d.m.Y H:i");
                    $s["signedAt"] = $s["signedAt"]?->format("d.m.Y H:i:s");
                    $steps[$s["revisionId"]][] = $s;
                }
                $revisions = array_map(fn ($r) => array(
                    "revisionId"     => $r["revisionId"],
                    "revisionNo"     => $r["revisionNo"],
                    "revStatus"      => $r["revStatus"],
                    "signRound"      => $r["signRound"],
                    "changeReason"   => $r["changeReason"],
                    "uploadedBy"     => $r["uploadedBy"],
                    "uploadedAt"     => $r["cAt"]?->format("d.m.Y H:i"),
                    "effectiveDate"  => $r["effectiveDate"]?->format("d.m.Y"),
                    "nextReviewDate" => $r["nextReviewDate"]?->format("d.m.Y"),
                    "obsoleteDate"   => $r["obsoleteDate"]?->format("d.m.Y"),
                    "pdfHash"        => $r["pdfHash"],
                    "signedHash"     => $r["signedHash"],
                    "sourceFileName" => $r["sourceFileName"],
                    "legacyFl"       => $r["legacyFl"],
                    // Mühürlü dosya: sealed-… (PDF + onay sayfası) ya da cert-… (birleştirilemedi, onay sayfası ayrı)
                    "certOnly"       => $r["signedFilePath"] !== null && str_contains(basename((string) $r["signedFilePath"]), "cert-"),
                    "hasSource"      => $r["sourceFileName"] !== null,
                    "steps"          => $steps[$r["revisionId"]] ?? array(),
                ), $db->createQuery("
                    SELECT r.revisionId, r.revisionNo, r.revStatus, r.signRound, r.changeReason, r.cAt, u.fullName uploadedBy,
                           r.effectiveDate, r.nextReviewDate, r.obsoleteDate, r.pdfHash, r.signedHash, r.signedFilePath,
                           r.sourceFileName, r.legacyFl
                      FROM QMS\DOC\Revision r JOIN r.uploadedBy u
                     WHERE IDENTITY(r.document) = :ID AND r.recordFl > 0
                     ORDER BY r.revisionNo DESC, r.revisionId DESC")
                    ->setParameter("ID", $docId)->getArrayResult());
                $resObject = $doc + array("revisions" => $revisions);
                $resCode = 0;
                $resMsg = "Ok";
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}
