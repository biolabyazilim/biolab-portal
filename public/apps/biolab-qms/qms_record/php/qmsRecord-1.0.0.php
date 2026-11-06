<?php
/**
 * Biolab QMS — Form doldur (pilot) veri kaynağı
 *
 * Yürürlükteki formun PDF'i ekranda açılır; kullanıcı üstüne yazar, çizer, ✓/✗ koyar. Kaydedince sayfaların işaret katmanı
 * (şeffaf PNG) onaylı PDF'in kopyasına basılır ve doldurulmuş form kayıt olarak saklanır (qms_form_record, yalnızca eklenir).
 *
 *   getForms   → doldurulabilir (FILLABLE_FL) formlar; klasörü, yüklenen özgün dosya adı ve kullanıcının son doldurma zamanı ile. Yürürlüğe girmemişler
 *                da çıkar (revisionId boş, pendingStatus/waitingRole ile) ama doldurulamaz
 *                (ekran son doldurduklarını üstte, kalanları klasör başlıklarıyla gösterir; arama Q yalnızca kısayol)
 *   getRecords → kayıtlar, en yeni önce; DOCUMENT_ID verilirse yalnızca o formun (boşsa bütün formların)
 *                (kalite, yönetici, denetçi hepsini; diğerleri yalnızca kendi kayıtlarını görür; kayıtlar yalnızca görüntülenir)
 *   setRecord  → REVISION_ID + RECORD_TITLE + PAGES[n] (sayfa n'nin katmanı, PNG data URL) + PAGE_W[n]/PAGE_H[n] (pt)
 *                + MARKS[i] = "tür|sayfa|x|y|boyut|veri" (x, y sayfaya göre 0–1; veri: kalem için "x,y;x,y…", yazı için metin)
 * Doldurulan PDF onaylı şablonun kendisidir (onay sayfası eklenmemiş hali); kayıtta hangi revizyondan doldurulduğu saklanır.
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use Biolab\Core\Qms\AuditLogger;
use Biolab\Core\Qms\Auth\QmsFormAccess;
use Biolab\Core\Qms\DocumentStatus;
use Biolab\Core\Qms\FileStorage;
use Biolab\Core\Qms\Sealer;
use QMS\DOC\Revision;
use QMS\MNG\AppUser;
use QMS\MNG\Company;
use QMS\REC\FormRecord;

$db = $dbm->getDB();
$resourceFile = "qms_record/qmsRecord-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];
$sessionUserId = (int) $_SESSION["USER_ID"];

const QMS_RECORD_ALL_ROLES  = array("QMS_ADMIN", "QMS_QUALITY", "QMS_AUDITOR");   // bütün kayıtları görenler
const QMS_RECORD_MAX_PAGE   = 4000000;   // sayfa katmanı (PNG, bayt)
const QMS_RECORD_MAX_MARKS  = 900;       // max_input_vars (1000) içinde kalır
const QMS_RECORD_MAX_LIST   = 300;

switch ($query) {

    case "getForms":
        $execType = ET_DEFAULT;
        try{
            $params = array("COMPANY_ID" => $companyId);
            $where = "";
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $where = " AND (d.docCode LIKE :Q_LIKE OR d.docTitle LIKE :Q_LIKE OR d.variantName LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }
            $docs = $db->createQuery("
                SELECT d.documentId, d.docCode, d.variantName, d.docTitle, t.docTypeName, c.categoryName,
                       cur.revisionId, cur.revisionNo, cur.effectiveDate, cur.sourceFileName,
                       CASE WHEN d.docCode IS NULL THEN 1 ELSE 0 END AS HIDDEN noCode
                  FROM QMS\DOC\Document d
                  JOIN d.docType t
                  JOIN t.category c
                  LEFT JOIN d.currentRevision cur WITH cur.revStatus = 'EFFECTIVE'
                 WHERE IDENTITY(d.company) = :COMPANY_ID AND d.fillableFl = 'Y' AND d.docStatus = 'ACTIVE' AND d.recordFl > 0" . $where . "
                 ORDER BY c.sortNo, c.categoryName, noCode, d.docCode, d.docTitle, d.variantName")
                ->setParameters($params)->getArrayResult();

            // Yürürlükteki revizyon doldurulur (revisionId dolu). Henüz yürürlüğe girmemiş form da listede çıkar
            // (revisionId boş; imzadaki revizyonun durumu ve sırası gelen adımla), ama doldurulamaz.
            $ids = array_column($docs, "documentId");
            $pending = DocumentStatus::pending($db, $ids);
            $counts = DocumentStatus::recordCounts($db, $ids);
            $mine = DocumentStatus::lastFilled($db, $ids, $sessionUserId);
            $dtResponse = array();
            foreach ($docs as $d) {
                $p = $d["revisionId"] === null ? ($pending[$d["documentId"]] ?? null) : null;
                if ($d["revisionId"] === null && $p === null) {
                    continue;
                }
                $dtResponse[] = array(
                    "revisionId"    => $d["revisionId"],
                    "revisionNo"    => $d["revisionNo"] ?? $p["revisionNo"],
                    "effectiveDate" => $d["effectiveDate"]?->format("d.m.Y"),
                    "sourceFileName" => $d["sourceFileName"],   // yüklenen özgün dosya (Word/Excel; yoksa PDF yüklendi)
                    "documentId"    => $d["documentId"],
                    "docCode"       => $d["docCode"],
                    "variantName"   => $d["variantName"],
                    "docTitle"      => $d["docTitle"],
                    "docTypeName"   => $d["docTypeName"],
                    "categoryName"  => $d["categoryName"],
                    "pendingStatus" => $p["revStatus"] ?? null,
                    "waitingRole"   => $p["waitingRole"] ?? null,
                    "recordCount"   => $counts[$d["documentId"]] ?? 0,
                    "lastFilled"    => isset($mine[$d["documentId"]]) ? $mine[$d["documentId"]]->format("d.m.Y H:i") : null,
                );
            }
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getRecords":
        $execType = ET_DEFAULT;
        try{
            $all = (bool) array_intersect((new QmsFormAccess($db))->roleCodes($sessionUserId, $companyId), QMS_RECORD_ALL_ROLES);
            $documentId = (int) ($_POST["DOCUMENT_ID"] ?? 0);
            // Boş DOCUMENT_ID → bütün formlar; ALL=1 → herkesin kayıtları (yetkili rol), 0 → yalnızca kendi kayıtları
            $dql = "
                SELECT fr.formRecordId AS recordId,
                       d.documentId    AS documentId,
                       d.docCode       AS docCode,
                       d.variantName   AS variantName,
                       d.docTitle      AS docTitle,
                       fr.filledAt     AS filledAt,
                       fr.recordTitle  AS recordTitle,
                       u.fullName      AS filledBy,
                       CASE WHEN u.userId = :USER_ID THEN 'Y' ELSE 'N' END AS mine,
                       r.revisionNo    AS revisionNo
                  FROM QMS\REC\FormRecord fr
                  JOIN fr.user u
                  JOIN fr.revision r
                  JOIN fr.document d
                 WHERE IDENTITY(fr.company) = :COMPANY_ID
                   AND (:DOCUMENT_ID = 0 OR d.documentId = :DOCUMENT_ID)
                   AND (:ALL = 1 OR u.userId = :USER_ID)
                 ORDER BY fr.formRecordId DESC
            ";
            $dtResponse = $db->createQuery($dql)
                ->setParameter("COMPANY_ID", $companyId)
                ->setParameter("USER_ID", $sessionUserId)
                ->setParameter("DOCUMENT_ID", $documentId)
                ->setParameter("ALL", $all ? 1 : 0)
                ->setMaxResults(QMS_RECORD_MAX_LIST)
                ->getScalarResult();
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getRecords2":
        // Deneme: "SELECT mt" ile kaydın bütün alanları mt_ önekiyle (mt_formRecordId, mt_filledAt …), join'lerden seçilenler kendi adıyla gelir.
        // SELECTED_ID verilirse yalnızca o kayıt, boşsa hepsi; yetkili rol değilse yalnızca kendi kayıtları.
        $execType = ET_DEFAULT;
        try{
            $all = (bool) array_intersect((new QmsFormAccess($db))->roleCodes($sessionUserId, $companyId), QMS_RECORD_ALL_ROLES);
            $dql ="
                SELECT mt,
                       d.documentId, d.docCode, d.variantName, d.docTitle,
                       r.revisionNo,
                       u.userId, u.fullName
                FROM QMS\REC\FormRecord mt
                LEFT JOIN mt.document d
                LEFT JOIN mt.revision r
                LEFT JOIN mt.user u
                WHERE IDENTITY(mt.company) = :COMPANY_ID
                  AND (:PKID = 0 OR mt.formRecordId = :PKID)
                  AND (:ALL = 1 OR u.userId = :USER_ID)
                ORDER BY mt.formRecordId DESC
            ";
            $dt = $db->createQuery($dql)
                ->setParameter("COMPANY_ID", $_SESSION["COMPANY_ID"])
                ->setParameter("PKID", (int) ($_POST["SELECTED_ID"] ?? 0))
                ->setParameter("ALL", $all ? 1 : 0)
                ->setParameter("USER_ID", $sessionUserId)
                ->setMaxResults(QMS_RECORD_MAX_LIST)
                ->getScalarResult();
            $dtResponse = $dt;

            if (count($dtResponse) > 0) {
                $resCode = 0;
                $resMsg = "Ok";
            } else {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            }
        }
        catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }

        break;
    case "setRecord":
        $execType = ET_SAVE;
        $stored = null;
        try{
            $rev   = $db->find(Revision::class, (int) ($_POST["REVISION_ID"] ?? 0));
            $title = trim((string) ($_POST["RECORD_TITLE"] ?? ""));
            $marks = qmsRecordMarks((array) ($_POST["MARKS"] ?? array()));
            $pages = array();
            $sizes = array();
            $badPage = false;
            foreach ((array) ($_POST["PAGES"] ?? array()) as $no => $dataUrl) {
                $png = qmsRecordPng((string) $dataUrl);
                if ($png === null || (int) $no < 1) {
                    $badPage = true;
                    break;
                }
                $pages[(int) $no] = $png;
            }
            foreach ((array) ($_POST["PAGE_W"] ?? array()) as $no => $w) {
                $sizes[(int) $no] = array((float) $w, (float) ($_POST["PAGE_H"][$no] ?? 0));
            }

            $error = null;
            if ($rev === null || $rev->getDocument()->getCompany()?->getCompanyId() !== $companyId || !$rev->getDocument()->isFillable()
                || $rev->getRevStatus() !== Revision::EFFECTIVE || $rev->getDocument()->getCurrentRevision() !== $rev) {
                $error = $lang["ERR_NOT_EFFECTIVE"];
            } elseif (mb_strlen($title) > 200) {
                $error = $lang["ERR_TITLE"];
            } elseif ($marks === null || $badPage || !$pages) {
                $error = $lang["ERR_EMPTY"];
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
            } else {
                $pdf = Sealer::overlay(FileStorage::absolute($rev->getPdfFilePath()), $pages, $sizes);
                if ($pdf === null) {
                    $resCode = 1;
                    $resMsg = $lang["ERR_PDF"];
                } else {
                    $doc = $rev->getDocument();
                    $stored = FileStorage::putContent($pdf, "rec/" . $doc->getDocumentId() . "/r" . $rev->getRevisionId(), "rec", "pdf");
                    $hash = hash_file("sha256", FileStorage::absolute($stored));
                    $record = $db->wrapInTransaction(function () use ($db, $rev, $doc, $companyId, $sessionUserId, $title, $stored, $hash, $marks, $pages) {
                        $record = new FormRecord($db->getReference(Company::class, $companyId), $rev, $db->getReference(AppUser::class, $sessionUserId),
                                                 new DateTimeImmutable(), $title, max(array_keys($pages)), $stored, $hash, $marks, $_SERVER["REMOTE_ADDR"] ?? null);
                        $db->persist($record);
                        $db->flush();
                        AuditLogger::log($db, "qms_form_record", $record->getFormRecordId(), "CREATE", array(
                            "documentId" => $doc->getDocumentId(), "revisionId" => $rev->getRevisionId(),
                            "recordTitle" => $record->getRecordTitle(), "fileHash" => $hash, "pages" => array_keys($pages)));
                        return $record;
                    });
                    $stored = null;
                    $resObject = array("RECORD_ID" => $record->getFormRecordId(), "DOCUMENT_ID" => $doc->getDocumentId());
                    $resCode = 0;
                    $resMsg = $lang["RECORD_OK"];
                }
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        } finally {
            // Kayıt yazılamadıysa depoya konan PDF silinir
            if ($stored !== null) {
                FileStorage::delete($stored);
            }
        }
        break;
}

/**
 * MARKS[i] = "tür|sayfa|x|y|boyut|veri" → JSON. Tür: pen, text, check, cross. Geçersiz ya da boşsa null.
 * Kalem verisi "x,y;x,y…" (0–1), yazı verisi metnin kendisi (| içerebilir: son alan bölünmez).
 */
function qmsRecordMarks(array $raw): ?string
{
    if (!$raw || count($raw) > QMS_RECORD_MAX_MARKS) {
        return null;
    }
    $out = array();
    foreach ($raw as $line) {
        $f = explode("|", (string) $line, 6);
        if (count($f) !== 6 || !in_array($f[0], array("pen", "text", "check", "cross"), true) || (int) $f[1] < 1) {
            return null;
        }
        $mark = array("t" => $f[0], "p" => (int) $f[1], "x" => round((float) $f[2], 5), "y" => round((float) $f[3], 5), "s" => round((float) $f[4], 5));
        if ($f[0] === "pen") {
            if (!preg_match('/^[0-9.]+,[0-9.]+(;[0-9.]+,[0-9.]+)*$/', $f[5])) {
                return null;
            }
            $mark["d"] = array_map(fn ($p) => array_map("floatval", explode(",", $p)), explode(";", $f[5]));
        } elseif ($f[0] === "text") {
            if (trim($f[5]) === "" || mb_strlen($f[5]) > 500) {
                return null;
            }
            $mark["v"] = $f[5];
        }
        $out[] = $mark;
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

// "data:image/png;base64,…" → PNG baytları; geçersizse null
function qmsRecordPng(string $dataUrl): ?string
{
    if (!str_starts_with($dataUrl, "data:image/png;base64,")) {
        return null;
    }
    $bytes = base64_decode(substr($dataUrl, 22), true);
    if ($bytes === false || strlen($bytes) > QMS_RECORD_MAX_PAGE) {
        return null;
    }
    $info = @getimagesizefromstring($bytes);   // bozuk veride uyarı basmasın; false döner
    return ($info !== false && $info[2] === IMAGETYPE_PNG) ? $bytes : null;
}
