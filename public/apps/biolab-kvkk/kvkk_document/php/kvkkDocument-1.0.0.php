<?php
/**
 * KVKK Yönetimi — Belgeler veri kaynağı
 *
 *   getNODE_ID       → başlık seçim kutuları (liste süzgeci ve form; "Ana › Alt" yoluyla)
 *   getGridList      → belge listesi (jqGrid; başlık süzgeci NODE_ID, arama Q)
 *   getSelectedId    → seçilen belge (formu doldurur)
 *   getFileGridList  → seçili belgenin PDF versiyonları (jqGrid)
 *   getFilePdf       → bir versiyonun PDF'ini yeni sekmede açmak için adres (/api/pdf.php?t=…)
 *   setSave          → belge ekle / güncelle; PDF seçildiyse yeni versiyon olarak ekler (multipart/form-data)
 *   setAdd           → formdaki bilgilerle her zaman yeni belge ekler (setSave ile aynı kod)
 *   setDelete        → belgeyi siler (RECORD_FL = 0) ve yayından kaldırır; PDF'leri arşivde kalır
 *
 * Bütün kayıtlar oturumdaki şirkete göredir (COMPANY_ID). C_BY/U_BY/D_BY ve tarihler AuditListener ile dolar.
 *
 * PDF yükleme sınırı 20 MB (KVK_MAX_FILE_SIZE). PHP'nin kendi sınırları da en az bu kadar olmalı:
 *   php.ini → upload_max_filesize = 20M, post_max_size = 25M  (IIS'te ayrıca maxAllowedContentLength)
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use BIO\KVK\KvkkDocument;
use BIO\KVK\KvkkDocumentFile;
use BIO\KVK\KvkkNode;

$db = $dbm->getDB();
$resourceFile = "kvkk_document/kvkkDocument-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

const KVK_MAX_FILE_SIZE = 20 * 1024 * 1024;   // 20 MB

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    // Başlık seçim kutusu: aynı ad farklı başlıkların altında olabilir, bu yüzden metin başlığın yoludur
    case "getNODE_ID":
        $execType = ET_DEFAULT;
        try{
            $paths = kvkNodePaths($db, $companyId);
            $dtResponse = $db->createQuery("
                SELECT n.nodeId, n.activeFl
                  FROM BIO\KVK\KvkkNode n
                 WHERE IDENTITY(n.company) = :COMPANY_ID
                   AND n.recordFl > 0
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
            foreach ($dtResponse as &$r) {
                $r["nodePath"] = $paths[$r["nodeId"]];
            }
            unset($r);
            usort($dtResponse, function ($a, $b) { return strcmp($a["nodePath"], $b["nodePath"]); });

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

    // Belge listesi. lastVersionNo / lastUploadAt: son yüklenen PDF (dosyası yoksa boş)
    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $whereClause = "";
            $params = array("COMPANY_ID" => $companyId);
            if ((int) ($_POST["NODE_ID"] ?? 0) > 0) {
                $whereClause .= " AND n.nodeId = :NODE_ID";
                $params["NODE_ID"] = (int) $_POST["NODE_ID"];
            }
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $whereClause .= " AND (mt.documentName LIKE :Q_LIKE OR n.nodeName LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }

            $from = "
                  FROM BIO\KVK\KvkkDocument mt
                  JOIN mt.node n
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.recordFl > 0
            ";

            $totalRecords = (int) $db->createQuery("SELECT COUNT(mt.documentId) " . $from . $whereClause)
                ->setParameters($params)->getSingleScalarResult();

            // Sıralama alanı istemciden gelir; DQL'e eklenmeden önce izin listesiyle kontrol edilir
            $sortable  = array("mt.documentId", "n.nodeName", "mt.documentName", "mt.sortNo", "mt.publishFl");
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "n.nodeName";
            $direction = strtoupper($_POST["sord"] ?? "") === "DESC" ? "DESC" : "ASC";

            $rows = max(1, (int) ($_POST["rows"] ?? 20));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $dtResponse = $db->createQuery("
                SELECT mt.documentId mt_documentId,
                       mt.documentName mt_documentName,
                       mt.sortNo mt_sortNo,
                       mt.publishFl mt_publishFl,
                       n.nodeId n_nodeId,
                       (SELECT MAX(f.versionNo) FROM BIO\KVK\KvkkDocumentFile f
                         WHERE f.document = mt AND f.recordFl > 0) lastVersionNo,
                       (SELECT MAX(f2.cAt) FROM BIO\KVK\KvkkDocumentFile f2
                         WHERE f2.document = mt AND f2.recordFl > 0) lastUploadAt
            " . $from . $whereClause . " ORDER BY " . $sortBy . " " . $direction . ", mt.sortNo ASC, mt.documentId ASC")
                ->setParameters($params)
                ->setMaxResults($rows)
                ->setFirstResult(($page - 1) * $rows)
                ->getArrayResult();

            // Başlık kolonu: başlığın yolu ("Aydınlatma Metinleri › İnsan Kaynakları Birimi")
            $paths = kvkNodePaths($db, $companyId);
            foreach ($dtResponse as &$r) {
                $r["nodePath"] = $paths[$r["n_nodeId"]] ?? "";
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
            $dql = "
                SELECT mt, n
                  FROM BIO\KVK\KvkkDocument mt
                  JOIN mt.node n
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.documentId = :DOCUMENT_ID
                   AND mt.recordFl > 0
            ";
            $dtResponse = $db->createQuery($dql)
                ->setParameter("COMPANY_ID", $companyId)
                ->setParameter("DOCUMENT_ID", (int) ($_POST["SELECTED_ID"] ?? 0))
                ->getScalarResult();
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

    // PDF versiyonları. İçerik (CONTENT) listeye alınmaz; yalnızca bilgi alanları seçilir.
    case "getFileGridList":
        $execType = ET_JQGRID;
        try{
            $from = "
                  FROM BIO\KVK\KvkkDocumentFile f
                  JOIN f.document d
                  LEFT JOIN f.cBy u
                 WHERE IDENTITY(d.company) = :COMPANY_ID
                   AND d.documentId = :DOCUMENT_ID
                   AND f.recordFl > 0
            ";
            $params = array("COMPANY_ID" => $companyId, "DOCUMENT_ID" => (int) ($_POST["DOCUMENT_ID"] ?? 0));

            $totalRecords = (int) $db->createQuery("SELECT COUNT(f.documentFileId) " . $from)
                ->setParameters($params)->getSingleScalarResult();

            $sortable  = array("f.versionNo", "f.fileName", "f.fileSize", "u.fullName", "f.cAt");
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "f.versionNo";
            $direction = strtoupper($_POST["sord"] ?? "") === "ASC" ? "ASC" : "DESC";

            $rows = max(1, (int) ($_POST["rows"] ?? 5));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $dtResponse = $db->createQuery("
                SELECT f.documentFileId f_documentFileId,
                       f.versionNo f_versionNo,
                       f.fileName f_fileName,
                       f.fileSize f_fileSize,
                       f.cAt f_cAt,
                       u.fullName u_fullName
            " . $from . " ORDER BY " . $sortBy . " " . $direction)
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

    // PDF'i açmak için kısa ömürlü adres (aynı oturumda 10 dakika geçerli; Biolab\Core\Http\PdfHandoff)
    case "getFilePdf":
        $execType = ET_DEFAULT;
        try{
            $file = $db->createQuery("
                SELECT f FROM BIO\KVK\KvkkDocumentFile f
                  JOIN f.document d
                 WHERE f.documentFileId = :DOCUMENT_FILE_ID
                   AND IDENTITY(d.company) = :COMPANY_ID
                   AND f.recordFl > 0
            ")->setParameters(array(
                "DOCUMENT_FILE_ID" => (int) ($_POST["DOCUMENT_FILE_ID"] ?? 0),
                "COMPANY_ID"       => $companyId,
            ))->getOneOrNullResult();

            if ($file === null) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } else {
                $resObject = array(
                    "url"      => "/api/pdf.php?t=" . \Biolab\Core\Http\PdfHandoff::stash($file->getContent(), $file->getFileName()),
                    "fileName" => $file->getFileName(),
                );
                $resCode = 0;
                $resMsg = "Ok";
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Ekle: formda seçili bir belge olsa bile yeni belge eklenir. DOCUMENT_ID boşaltılıp setSave'e devam edilir (break yok).
    case "setAdd":
        $_POST["DOCUMENT_ID"] = "";

    case "setSave":
        $execType = ET_SAVE;
        try{
            // Dosya post_max_size'ı aşarsa PHP $_POST ve $_FILES'ı tamamen boş bırakır; alanlar eksik sanılmasın
            if (empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
                $resCode = 1;
                $resMsg = "Dosya sunucunun kabul ettiği boyutu aşıyor (post_max_size = " . ini_get("post_max_size") . ").";
                break;
            }

            // --- Gelen alanlar ---
            $documentId   = (int) ($_POST["DOCUMENT_ID"] ?? 0);
            $nodeId       = (int) ($_POST["NODE_ID"] ?? 0);
            $documentName = trim((string) ($_POST["DOCUMENT_NAME"] ?? ""));
            $sortNo       = max(0, min(999, (int) ($_POST["SORT_NO"] ?? 0)));
            $publish      = ($_POST["PUBLISH_FL"] ?? "N") === "Y";
            $upload       = $_FILES["DOCUMENT_FILE"] ?? null;
            $hasUpload    = $upload !== null && $upload["error"] !== UPLOAD_ERR_NO_FILE;

            // Kayıtlar yalnızca oturumdaki şirkette aranır
            $document = $documentId > 0 ? $db->getRepository(KvkkDocument::class)->findOneBy(array("documentId" => $documentId, "company" => $companyId)) : null;
            $node     = $nodeId > 0 ? $db->getRepository(KvkkNode::class)->findOneBy(array("nodeId" => $nodeId, "company" => $companyId)) : null;

            // Belgenin şu ana kadarki son versiyonu (yeni belgede 0)
            $lastVersion = $document === null ? 0 : (int) $db->createQuery("
                SELECT MAX(f.versionNo) FROM BIO\KVK\KvkkDocumentFile f WHERE f.document = :DOCUMENT
            ")->setParameter("DOCUMENT", $document)->getSingleScalarResult();

            // --- Sunucu tarafı kontrol (tarayıcıdaki doğrulama atlanabilir) ---
            $error = null;
            if ($documentId > 0 && ($document === null || $document->isDeleted())) {
                $error = "Belge bulunamadı.";
            } elseif ($node === null || $node->isDeleted()) {
                $error = $lang["ERR_NODE_ID"];
            } elseif ($documentName === "" || mb_strlen($documentName) > 200) {
                $error = $lang["ERR_DOCUMENT_NAME"] . " (en fazla 200 karakter)";
            } elseif (!$hasUpload && $lastVersion === 0) {
                $error = $lang["ERR_DOCUMENT_FILE"];
            }

            // --- PDF kontrolü: yükleme hatası, boyut, içerik gerçekten PDF mi (uzantıya güvenilmez) ---
            $bytes = null;
            if ($error === null && $hasUpload) {
                if ($upload["error"] === UPLOAD_ERR_INI_SIZE || $upload["error"] === UPLOAD_ERR_FORM_SIZE) {
                    $error = "Dosya sunucunun kabul ettiği boyutu aşıyor (upload_max_filesize = " . ini_get("upload_max_filesize") . ").";
                } elseif ($upload["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($upload["tmp_name"])) {
                    $error = "Dosya yüklenemedi (hata kodu " . $upload["error"] . ").";
                } elseif ($upload["size"] > KVK_MAX_FILE_SIZE) {
                    $error = "Dosya en fazla 20 MB olabilir.";
                } else {
                    $bytes = (string) file_get_contents($upload["tmp_name"]);
                    $mime  = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                    if (strncmp($bytes, "%PDF-", 5) !== 0 || $mime !== "application/pdf") {
                        $error = "Seçilen dosya PDF değil. Yalnızca PDF yüklenebilir.";
                    }
                }
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
            } else {
                if ($document === null) {
                    // --- Yeni belge --- (şirket, C_BY, C_AT otomatik dolar; yayın anahtarı kurucuda üretilir)
                    $document = new KvkkDocument($node, $documentName, $sortNo);
                    $db->persist($document);
                    $resMsg = $lang["RECORD_IS_ADDED"];
                } else {
                    // --- Güncelleme --- (U_BY, U_AT otomatik dolar)
                    $document->setNode($node)->setDocumentName($documentName)->setSortNo($sortNo);
                    $resMsg = $lang["RECORD_IS_CHANGED"];
                }
                $document->setPublished($publish);

                // PDF seçildiyse yeni versiyon; dosya adı sadece bilgi amaçlı saklanır
                if ($bytes !== null) {
                    $fileName = mb_substr(basename((string) $upload["name"]), 0, 200);
                    $db->persist(new KvkkDocumentFile($document, $lastVersion + 1, $fileName, $bytes));
                    $resMsg .= " · PDF versiyon " . ($lastVersion + 1) . " eklendi.";
                }

                $db->flush();   // belge ve dosya tek seferde yazılır

                $resObject = array("DOCUMENT_ID" => $document->getDocumentId());
                $resCode = 0;
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Belge silinmez: RECORD_FL = 0 ve yayından kaldırılır. PDF versiyonları arşivde kalır.
    case "setDelete":
        $execType = ET_DELETE;
        try{
            $document = $db->getRepository(KvkkDocument::class)->findOneBy(array(
                "documentId" => (int) ($_POST["DOCUMENT_ID"] ?? 0),
                "company"    => $companyId,
            ));
            if ($document === null || $document->isDeleted()) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } else {
                $document->setPublished(false);
                $document->setActive(false);
                $document->softDelete();
                $db->flush();   // D_BY, D_AT AuditListener ile dolar
                $resCode = 0;
                $resMsg = $lang["RECORD_IS_DELETED"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

/**
 * Şirketteki bütün (silinmemiş) başlıkların yolu: [NODE_ID => "Ana › Alt › Başlık"].
 * Tek sorguyla bütün başlıklar alınır, yol PHP'de üst başlıklar takip edilerek kurulur.
 * (Menü ağacı ekranındaki kvkkNode-1.0.0.php ile aynı fonksiyon.)
 */
function kvkNodePaths($db, int $companyId): array
{
    $rows = $db->createQuery("
        SELECT n.nodeId, IDENTITY(n.parent) parentNodeId, n.nodeName
          FROM BIO\KVK\KvkkNode n
         WHERE IDENTITY(n.company) = :COMPANY_ID
           AND n.recordFl > 0
    ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();

    $byId = array();
    foreach ($rows as $r) {
        $byId[$r["nodeId"]] = $r;
    }

    $paths = array();
    foreach ($byId as $id => $r) {
        $names = array($r["nodeName"]);
        $parentId = $r["parentNodeId"];
        // Üst başlıklara doğru çıkılır; bozuk veride sonsuz döngü olmasın diye en fazla 20 seviye
        for ($i = 0; $parentId !== null && isset($byId[$parentId]) && $i < 20; $i++) {
            array_unshift($names, $byId[$parentId]["nodeName"]);
            $parentId = $byId[$parentId]["parentNodeId"];
        }
        $paths[$id] = implode(" › ", $names);
    }
    return $paths;
}
