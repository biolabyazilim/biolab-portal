<?php
/**
 * Created by Ferhat.
 * User: ferhat
 * Date: 07.10.2020
 * Time: 14:02:27
 */
global $resourceFile;

use BIO\MNG\Form;

$resourceFile = "user/form-2.0.0.json";

global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat;
$db = $dbm ->getDB();

$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

switch ($query){

    case "getFORM_PATH":
        try{
            /** @noinspection PhpComposerExtensionStubsInspection */
            function getFileList($dir, $recurse = FALSE): array
            {
                $retval = [];
                if(substr($dir, -1) != "/") {
                    $dir .= "/";
                }

                $d = @dir($dir) or die("getFileList: Failed opening directory {$dir} for reading");
                WHILE(FALSE !== ($entry = $d->read())) {
                    if($entry[0] == ".") continue;
                    if(is_dir("{$dir}{$entry}") && strpos("{$dir}{$entry}", "/__")===false && strpos("{$dir}{$entry}", "/alfredORM")===false) {

                        if($recurse && is_readable("{$dir}{$entry}/")) {
                            $retval = array_merge($retval, getFileList("{$dir}{$entry}/", TRUE));
                        }
                    } elseif(!in_array($entry, ["index.html", "login.html"]) && is_readable("{$dir}{$entry}") && mime_content_type("{$dir}{$entry}") == "text/html") {   // uygulama kabuğu ekran değil
                        $retval[] = [
                            "formText"=>str_replace("../../../" ,"","{$dir}{$entry}"),
                            "formPath"=>str_replace("../../../" ,"","{$dir}{$entry}")
                        ];
                    }
                }
                $d->close();

                return $retval;
            }

            $execType = ET_CUSTOM;
            $module = getFileList('../../../apps/', TRUE);   // proxy public/lib/php/proxy'den çalışır: ../../../ = public/
            $dtResponse = $module;
            if (count($module) > 0) {
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

    case "getGridListFormorj":
        $execType = ET_JQGRID;

        $whereClause = "
        WHERE (
            mt.formName like :QUERY_FORM_NAME
            or
            mt.formPath like :QUERY_FORM_NAME
            )
            AND mt.recordFl > 0
        ";
        $dql = "
                SELECT COUNT(mt) RECORD_COUNT
                  FROM BIO\MNG\Form mt
        ";
        $dql = $dql.$whereClause;

        $dt = $db->createQuery($dql)
            ->setParameter('QUERY_FORM_NAME',"%".$_POST["Q"]."%" )
            ->getArrayResult();
        $totalRecords = $dt[0]["RECORD_COUNT"];

        $dql= "
            SELECT mt
               FROM BIO\MNG\Form mt
        ";
        $dql = $dql.$whereClause;

        $sortBy = $_POST["sidx"];
        $direction = $_POST["sord"];
        if(strlen($direction) == 0){
            $direction = "ASC";
        }
        $dql.= " order by ".$sortBy." ".$direction;

        $dtResponse = $db->createQuery($dql)
            ->setParameter("QUERY_FORM_NAME","%".$_POST["Q"]."%" )
            ->setMaxResults($_POST["rows"])
            ->setFirstResult((($_POST["page"]-1)*$_POST["rows"]))
            ->getScalarResult();
        if (count($dtResponse) > 0) {
            $resCode = 0;
            $resMsg = "Ok";
        } else {
            $resCode = 21;
            $resMsg = $lang["NO_RECORDS_FOUND"];
        }
        break;

    case "getSelectedIdForm":
        try{
            $dql = "
                SELECT mt
                FROM BIO\MNG\Form mt
                 WHERE mt.formId = :FORM_ID
            ";
            $dtResponse = $db->createQuery($dql)
                ->setParameter("FORM_ID",$_POST["SELECTED_ID"])
//                ->setHint(\Doctrine\ORM\Query::HINT_INCLUDE_META_COLUMNS, true)
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
    case "getGridListForm":
        $execType = ET_JQGRID;

        $whereClause = "
        WHERE (mt.formName LIKE :Q OR mt.formPath LIKE :Q)
          AND mt.recordFl > 0
    ";
        $q = "%" . ($_POST["Q"] ?? "") . "%";

        $totalRecords = $db->createQuery("SELECT COUNT(mt) FROM BIO\MNG\Form mt" . $whereClause)
            ->setParameter("Q", $q)
            ->getSingleScalarResult();

        // Sıralama: yalnızca izin verilen alanlar (DQL injection'a karşı)
        $sortBy    = in_array($_POST["sidx"], ["mt.formId", "mt.formCode", "mt.formName", "mt.formPath"]) ? $_POST["sidx"] : "mt.formId";
        $direction = $_POST["sord"] == "desc" ? "DESC" : "ASC";

        $dtResponse = $db->createQuery("SELECT mt FROM BIO\MNG\Form mt" . $whereClause . " ORDER BY " . $sortBy . " " . $direction)
            ->setParameter("Q", $q)
            ->setMaxResults((int) $_POST["rows"])
            ->setFirstResult(((int) $_POST["page"] - 1) * (int) $_POST["rows"])
            ->getScalarResult();

        if (count($dtResponse) > 0) {
            $resCode = 0;
            $resMsg = "Ok";
        } else {
            $resCode = 21;
            $resMsg = $lang["NO_RECORDS_FOUND"];
        }
        break;

    case "setDeleteFormorj":
        $execType = ET_DELETE;
        try{
            $rs = $db->getRepository("\BIO\MNG\Form")
                ->findBy(array("formId"=>$_POST["FORM_ID"]));

            foreach($rs as $item=>$value){
                $value->setDBy($_SESSION["USER_ID_PARENT"]);
                $value->setDAt(new DateTime("NOW"));
                $value->setActiveFl("N");
                $value->setRecordFl(0);
                $db->persist($value);
                $db->flush();
            }

            $resCode = 0;
            $resMsg = $lang["RECORD_IS_DELETED"];
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
    case "setDeleteForm":
        $execType = ET_DELETE;
        try{
            $rs = $db->find(Form::class, (int) ($_POST["FORM_ID"] ?? 0));
            if ($rs !== null) {
                $rs->setActive(false);
                $rs->softDelete();      // RECORD_FL = 0; D_BY / D_AT flush'ta otomatik dolar
                $db->flush();
            }
            $resCode = 0;
            $resMsg = $lang["RECORD_IS_DELETED"];
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setAddForm":
        $_POST["FORM_ID"] = "";     // ekleme her zaman yeni kayıt açar (seçili satır olsa da)
        // no break: kayıt kuralları setSaveForm'da

    case "setSaveForm":
        $execType = ET_SAVE;
        try{
            $formId   = (int) ($_POST["FORM_ID"] ?? 0);
            $formCode = trim($_POST["FORM_CODE"] ?? "");
            $formName = trim($_POST["FORM_NAME"] ?? "");
            $formPath = trim($_POST["FORM_PATH"] ?? "");
            $nameEn   = trim($_POST["FORM_NAME_EN"] ?? "");

            $entity = $formId > 0 ? $db->find(Form::class, $formId) : null;

            // Kontroller (mesaj noty ile gösterilir); FORM_CODE ve FORM_PATH tabloda benzersiz
            if ($formCode === "" || $formName === "" || $formPath === "") {
                $resCode = 22;
                $resMsg = "Form kodu, form adı ve form yolu zorunludur.";
                break;
            }
            $sameCode = $db->getRepository(Form::class)->findOneBy(["formCode" => $formCode]);
            if ($sameCode !== null && $sameCode->getFormId() !== $formId) {
                $resCode = 22;
                $resMsg = "Bu form kodu başka bir formda kullanılıyor: " . $sameCode->getFormName();
                break;
            }
            $samePath = $db->getRepository(Form::class)->findOneBy(["formPath" => $formPath]);
            if ($samePath !== null && $samePath->getFormId() !== $formId) {
                $resCode = 22;
                $resMsg = "Bu ekran yolu başka bir formda tanımlı: " . $samePath->getFormName();
                break;
            }

            if ($entity !== null) {
                // Güncelle (FORM_ID değişmez; U_BY / U_AT flush'ta otomatik dolar)
                $entity->setFormCode($formCode);
                $entity->setFormName($formName);
                $entity->setFormPath($formPath);
                $resMsg = $lang["RECORD_IS_CHANGED"];
            } else {
                // Yeni kayıt (C_BY / C_AT flush'ta otomatik dolar)
                $entity = new Form($formCode, $formName, $formPath);
                $db->persist($entity);
                $resMsg = $lang["RECORD_IS_ADDED"];
            }
            $entity->setFormNameEn($nameEn !== "" ? $nameEn : null);
            $entity->setActive(($_POST["ACTIVE_FL"] ?? 'Y') === 'Y');

            $db->flush();
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}
