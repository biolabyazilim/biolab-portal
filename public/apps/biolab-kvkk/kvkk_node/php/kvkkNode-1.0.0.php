<?php
/**
 * KVKK Yönetimi — Menü ağacı veri kaynağı
 *
 *   getNodeTree           → ağaç (SELECTED_ID boşsa kök başlıklar, doluysa o başlığın altındakiler)
 *   getPARENT_NODE_ID     → üst başlık seçim kutusu ("Ana › Alt" yoluyla)
 *   getSelectedId         → seçilen başlık (formu doldurur)
 *   setSave               → başlık ekle (NODE_ID boş) / güncelle
 *   setChangeTreePosition → ağaçta sürükle-bırak ile üst başlığı değiştirir
 *   setDelete             → başlığı siler (RECORD_FL = 0); altında başlık ya da belge varsa izin vermez
 *
 * Bütün kayıtlar oturumdaki şirkete göredir (COMPANY_ID). C_BY/U_BY/D_BY ve tarihler AuditListener ile dolar.
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $resourceFile;

use BIO\KVK\KvkkNode;

$db = $dbm->getDB();
$resourceFile = "kvkk_node/kvkkNode-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    // Ağaç: treeView her "+" tıklamasında o başlığın NODE_ID'sini SELECTED_ID olarak gönderir.
    // HAS_SUB_MENU = alt başlık sayısı; 0 ise ağaçta yaprak ikonu çıkar (lib.js treeView şablonu).
    case "getNodeTree":
        $execType = ET_DEFAULT;
        try{
            $selectedId = (int) ($_POST["SELECTED_ID"] ?? 0);
            $dql = "
                SELECT mt.nodeId nodeId,
                       IDENTITY(mt.parent) parentNodeId,
                       mt.nodeName nodeName,
                       mt.activeFl activeFl,
                       mt.recordFl recordFl,
                       (SELECT COUNT(c.nodeId) FROM BIO\KVK\KvkkNode c
                         WHERE c.parent = mt AND c.recordFl > 0) HAS_SUB_MENU
                  FROM BIO\KVK\KvkkNode mt
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.recordFl > 0
            ";
            $dql .= $selectedId > 0 ? " AND IDENTITY(mt.parent) = :PARENT_NODE_ID" : " AND mt.parent IS NULL";
            $dql .= " ORDER BY mt.sortNo, mt.nodeName";

            $q = $db->createQuery($dql)->setParameter("COMPANY_ID", $companyId);
            if ($selectedId > 0) {
                $q->setParameter("PARENT_NODE_ID", $selectedId);
            }
            $dtResponse = $q->getArrayResult();

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

    // Üst başlık seçim kutusu: aynı ad farklı başlıkların altında olabilir (ör. "İnsan Kaynakları Birimi"),
    // bu yüzden metin olarak başlığın yolu verilir: "Aydınlatma Metinleri › İnsan Kaynakları Birimi"
    case "getPARENT_NODE_ID":
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

    case "getSelectedId":
        $execType = ET_DEFAULT;
        try{
            $dql = "
                SELECT mt, pat
                  FROM BIO\KVK\KvkkNode mt
                  LEFT JOIN mt.parent pat
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.nodeId = :NODE_ID
                   AND mt.recordFl > 0
            ";
            $dtResponse = $db->createQuery($dql)
                ->setParameter("COMPANY_ID", $companyId)
                ->setParameter("NODE_ID", (int) ($_POST["SELECTED_ID"] ?? 0))
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

    // Ekle: formda seçili bir başlık olsa bile yeni başlık eklenir. NODE_ID boşaltılıp setSave'e devam edilir (break yok).
    case "setAdd":
        $_POST["NODE_ID"] = "";

    case "setSave":
        $execType = ET_SAVE;
        try{
            // --- Gelen alanlar ---
            $nodeId   = (int) ($_POST["NODE_ID"] ?? 0);
            $parentId = (int) ($_POST["PARENT_NODE_ID"] ?? 0);
            $nodeName = trim((string) ($_POST["NODE_NAME"] ?? ""));
            $nodeDesc = trim((string) ($_POST["NODE_DESC"] ?? ""));
            $sortNo   = max(0, min(999, (int) ($_POST["SORT_NO"] ?? 0)));
            $activeFl = ($_POST["ACTIVE_FL"] ?? "Y") === "N" ? "N" : "Y";

            // Kayıtlar yalnızca oturumdaki şirkette aranır
            $node   = $nodeId > 0 ? $db->getRepository(KvkkNode::class)->findOneBy(array("nodeId" => $nodeId, "company" => $companyId)) : null;
            $parent = $parentId > 0 ? $db->getRepository(KvkkNode::class)->findOneBy(array("nodeId" => $parentId, "company" => $companyId)) : null;

            // --- Sunucu tarafı kontrol (tarayıcıdaki doğrulama atlanabilir) ---
            $error = null;
            if ($nodeName === "") {
                $error = $lang["ERR_NODE_NAME"];
            } elseif (mb_strlen($nodeName) > 200 || mb_strlen($nodeDesc) > 500) {
                $error = "Başlık en fazla 200, açıklama en fazla 500 karakter olabilir.";
            } elseif ($nodeId > 0 && ($node === null || $node->isDeleted())) {
                $error = "Başlık bulunamadı.";
            } elseif ($parentId > 0 && ($parent === null || $parent->isDeleted())) {
                $error = "Üst başlık bulunamadı.";
            } elseif ($node !== null && $parent !== null && $parent->getNodeId() === $node->getNodeId()) {
                // Genelde seçili başlık güncellenirken yeni alt başlık eklenmek istendiğinde olur
                $error = "Başlık kendisinin üst başlığı olamaz. Seçili başlığın altına yeni başlık eklemek için "
                       . "Kaydet'in yanındaki oktan \"Ekle\"yi kullanın ya da önce \"Yeni\"ye basın.";
            } elseif ($node !== null && $parent !== null && kvkIsUnderNode($parent, $node)) {
                $error = "Başlık kendi altındaki bir başlığa taşınamaz.";
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
            } else {
                if ($node === null) {
                    // --- Yeni başlık --- (şirket, C_BY, C_AT otomatik dolar)
                    $node = new KvkkNode($nodeName, $parent, $sortNo);
                    $db->persist($node);
                    $resMsg = $lang["RECORD_IS_ADDED"];
                } else {
                    // --- Güncelleme --- (U_BY, U_AT otomatik dolar)
                    $node->setNodeName($nodeName)->setParent($parent)->setSortNo($sortNo);
                    $resMsg = $lang["RECORD_IS_CHANGED"];
                }
                $node->setNodeDesc($nodeDesc !== "" ? $nodeDesc : null);
                $node->setActive($activeFl === "Y");
                $db->flush();

                $resObject = array("NODE_ID" => $node->getNodeId());
                $resCode = 0;
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Sürükle-bırak: PARENT_NODE_ID boş gelirse başlık ana seviyeye taşınmıştır
    case "setChangeTreePosition":
        $execType = ET_SAVE;
        try{
            $nodeId   = (int) ($_POST["NODE_ID"] ?? 0);
            $parentId = (int) ($_POST["PARENT_NODE_ID"] ?? 0);
            $node   = $db->getRepository(KvkkNode::class)->findOneBy(array("nodeId" => $nodeId, "company" => $companyId));
            $parent = $parentId > 0 ? $db->getRepository(KvkkNode::class)->findOneBy(array("nodeId" => $parentId, "company" => $companyId)) : null;

            if ($node === null || $node->isDeleted() || ($parentId > 0 && ($parent === null || $parent->isDeleted()))) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } elseif ($parent !== null && kvkIsUnderNode($parent, $node)) {
                $resCode = 1;
                $resMsg = "Başlık kendi altındaki bir başlığa taşınamaz.";
            } else {
                $node->setParent($parent);
                $db->flush();
                $resCode = 0;
                $resMsg = $lang["RECORD_IS_CHANGED"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setDelete":
        $execType = ET_DELETE;
        try{
            $node = $db->getRepository(KvkkNode::class)->findOneBy(array(
                "nodeId"  => (int) ($_POST["NODE_ID"] ?? 0),
                "company" => $companyId,
            ));

            // Altında silinmemiş başlık ya da belge var mı?
            $childCount = 0;
            $documentCount = 0;
            if ($node !== null) {
                $childCount = (int) $db->createQuery("
                    SELECT COUNT(c.nodeId) FROM BIO\KVK\KvkkNode c WHERE c.parent = :NODE AND c.recordFl > 0
                ")->setParameter("NODE", $node)->getSingleScalarResult();
                $documentCount = (int) $db->createQuery("
                    SELECT COUNT(d.documentId) FROM BIO\KVK\KvkkDocument d WHERE d.node = :NODE AND d.recordFl > 0
                ")->setParameter("NODE", $node)->getSingleScalarResult();
            }

            if ($node === null || $node->isDeleted()) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } elseif ($childCount > 0) {
                $resCode = 2;
                $resMsg = "Bu başlığın altında " . $childCount . " başlık var. Önce onları silin ya da taşıyın.";
            } elseif ($documentCount > 0) {
                $resCode = 2;
                $resMsg = "Bu başlığa bağlı " . $documentCount . " belge var. Önce Belgeler ekranından onları silin ya da taşıyın.";
            } else {
                $node->softDelete();
                $node->setActive(false);
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

/**
 * $candidate başlığı $node'un kendisi ya da altındaki bir başlık mı?
 * Başlık kendi altına taşınırsa ağaç kopar (döngü olur); kaydetmeden önce bununla kontrol edilir.
 */
function kvkIsUnderNode(KvkkNode $candidate, KvkkNode $node): bool
{
    for ($n = $candidate, $i = 0; $n !== null && $i < 20; $n = $n->getParent(), $i++) {
        if ($n->getNodeId() === $node->getNodeId()) {
            return true;
        }
    }
    return false;
}
