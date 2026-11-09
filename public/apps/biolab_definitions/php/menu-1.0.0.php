<?php
/**
 * Created by Nur.
 * User: nur
 * Date: 06.11.2026
 * Time: 09:45:00
 */

global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat, $resourceFile;
use BIO\MNG\Menu;

$db = $dbm ->getDB();
$resourceFile = "user/menu-2.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

switch ($query){

    case "getPARENT_MENU_ID":
        try{
            $execType = ET_DEFAULT;
/*
            $dql = "
                  SELECT pat.menuId, 
                           pat.menuName
                      FROM BIO\MNG\Menu pat
                     WHERE pat.activeFl = 'Y'
                       AND pat.recordFl > 0";
*/
            $dql = "
    SELECT pat.menuId,
           pat.menuName,
           pat.menuCode,
           par.menuName AS parentMenuName
      FROM BIO\MNG\Menu pat
      LEFT JOIN pat.parentMenu par
     WHERE pat.form IS NULL
       AND pat.activeFl = 'Y'
       AND pat.recordFl > 0
     ORDER BY pat.menuOrder, pat.menuId";

            $dtResponse = $db->createQuery($dql)
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

    case "getFORM_ID":
        try{
            $execType = ET_DEFAULT;
            $dql = "
                SELECT f.formId, 
                           f.formName
                      FROM BIO\MNG\Form f
                     WHERE f.activeFl = 'Y'
                       AND f.recordFl > 0";

            $dtResponse = $db->createQuery($dql)
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

    case "getGridListMenu":
        $execType = ET_JQGRID;
        $whereClause = "
           WHERE (
                mt.menuName like :QUERY_MENU_NAME
            )
            AND mt.recordFl > 0
        ";
        $dql = "
                SELECT COUNT(mt) RECORD_COUNT
                  FROM BIO\MNG\Menu mt
                  LEFT JOIN mt.parentMenu pat
                  LEFT JOIN mt.form f 
                  ";

        $dql = $dql.$whereClause;
        $dt = $db->createQuery($dql)
            ->setParameter('QUERY_MENU_NAME',"%".$_POST["Q"]."%" )
            ->getArrayResult();
        $totalRecords = $dt[0]["RECORD_COUNT"];

        $dql= "
            SELECT mt,pat,f
             FROM BIO\MNG\Menu mt
             LEFT JOIN mt.parentMenu pat
             LEFT JOIN mt.form f 
        ";

        $dql = $dql.$whereClause;

        $sortBy = $_POST["sidx"];
        $direction = $_POST["sord"];
        if(strlen($direction) == 0){
            $direction = "ASC";
        }
        $dql.= " order by ".$sortBy." ".$direction;

        $dtResponse = $db->createQuery($dql)
            ->setParameter("QUERY_MENU_NAME","%".$_POST["Q"]."%" )
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

    case "getSelectedIdMenu":
        try{
            $dql = "
                SELECT mt, pat, f
                  FROM BIO\MNG\Menu mt
                  LEFT JOIN mt.parentMenu pat
                    LEFT JOIN mt.form f
                 WHERE mt.menuId = :MENU_ID
            ";
            $dtResponse = $db->createQuery($dql)
                ->setParameter("MENU_ID",$_POST["SELECTED_ID"])
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

    case "setDeleteMenu":
        $execType = ET_DELETE;
        try{
            $rs = $db->find(Menu::class, (int) ($_POST["MENU_ID"] ?? 0));
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

    case "setAddMenu":
        $_POST["MENU_ID"] = "";     // ekleme her zaman yeni kayıt açar
        // no break: kayıt kuralları setSaveMenu'da

    case "setSaveMenu":
        $execType = ET_SAVE;
        try{
            $menuId   = (int) ($_POST["MENU_ID"] ?? 0);
            $parentId = (int) ($_POST["PARENT_MENU_ID"] ?? 0);
            $formId   = (int) ($_POST["FORM_ID"] ?? 0);
            $menuName = trim($_POST["MENU_NAME"] ?? "");
            $nameEn   = trim($_POST["MENU_NAME_EN"] ?? "");

            $rs     = $menuId > 0 ? $db->find(Menu::class, $menuId) : null;
            $parent = $parentId > 0 ? $db->find(Menu::class, $parentId) : null;
            $form   = $formId > 0 ? $db->find(\BIO\MNG\Form::class, $formId) : null;   // boşsa grup satırı

            if ($menuName === "") {
                $resCode = 22;
                $resMsg = "Menü adı zorunludur.";
                break;
            }
            if ($parent === null) {
                $resCode = 22;
                $resMsg = "Üst menü seçilmelidir.";
                break;
            }
            if ($rs !== null && $parent->getMenuId() === $rs->getMenuId()) {
                $resCode = 22;
                $resMsg = "Bir menü kendi altına bağlanamaz.";
                break;
            }

            if ($rs !== null) {
                // Güncelle (U_BY / U_AT flush'ta otomatik dolar)
                $rs->setMenuName($menuName);
                $rs->setParent($parent);
                $rs->setForm($form);
                $rs->setActive(($_POST["ACTIVE_FL"] ?? 'Y') === 'Y');
                $resMsg = $lang["RECORD_IS_CHANGED"];
            } else {
                // Yeni kayıt (C_BY / C_AT flush'ta otomatik dolar)
                $rs = new Menu($menuName, $parent, $form);
                $db->persist($rs);
                $resMsg = $lang["RECORD_IS_ADDED"];
            }

            // İsteğe bağlı alanlar: setter ile
            $rs->setMenuNameEn($nameEn !== "" ? $nameEn : null);
            $rs->setMenuOrder((int) ($_POST["MENU_ORDER"] ?? 0));
            if (isset($_POST["MENU_ICON"])) {
                $rs->setMenuIcon(trim($_POST["MENU_ICON"]) !== "" ? trim($_POST["MENU_ICON"]) : null);
            }

            $db->flush();
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}
