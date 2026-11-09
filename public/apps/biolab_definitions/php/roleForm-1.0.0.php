<?php
/**
 * Biolab Yönetim — Rol ekran yetkileri (mng_role_form, entity: BIO\MNG\RoleForm)
 *
 * Bir role bir ekranı (formu) verir. Alanlar: ROLE_FORM_ID, ROLE_ID, FORM_ID, ACTIVE_FL
 * Menüde ekranı görmek ve ekranın PHP'sini çağırabilmek bu kayda bağlıdır (Biolab\Core\Auth\FormAccess).
 */
global $resourceFile;
use BIO\MNG\Form;
use BIO\MNG\Role;
use BIO\MNG\RoleForm;

$resourceFile = "user/roleForm-2.0.0.json";

global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat;
$db = $dbm ->getDB();

$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

switch ($query){

    case "getROLE_ID":
        try{
            $execType = ET_DEFAULT;
            // Rolün uygulaması da görünsün: aynı adlı roller karışmasın
            $dtResponse = $db->createQuery("
                SELECT r.roleId,
                       r.roleName,
                       r.appCode,
                       r.activeFl
                  FROM BIO\MNG\Role r
                 WHERE r.recordFl > 0
                 ORDER BY r.appCode, r.roleName")
                ->getArrayResult();
            // Görünen metin PHP'de birleştirilir: sütunların collation'ı farklı, SQL'de CONCAT hata verir
            foreach ($dtResponse as &$r) {
                $r["roleText"] = $r["roleName"] . ($r["appCode"] ? " (" . $r["appCode"] . ")" : "");
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

    case "getFORM_ID":
        try{
            $execType = ET_DEFAULT;
            // Yolu da görünsün: farklı uygulamalarda aynı adlı ekranlar karışmasın
            $dtResponse = $db->createQuery("
                SELECT f.formId,
                       f.formName,
                       f.formPath,
                       f.activeFl
                  FROM BIO\MNG\Form f
                 WHERE f.recordFl > 0
                 ORDER BY f.formPath")
                ->getArrayResult();
            // Görünen metin PHP'de birleştirilir: sütunların collation'ı farklı, SQL'de CONCAT hata verir
            foreach ($dtResponse as &$r) {
                $r["formText"] = $r["formName"] . " (" . $r["formPath"] . ")";
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

    case "getGridListRoleForm":
        $execType = ET_JQGRID;

        $from = "
              FROM BIO\MNG\RoleForm mt
              JOIN mt.role r
              JOIN mt.form f
             WHERE (r.roleName LIKE :Q OR r.roleCode LIKE :Q OR r.appCode LIKE :Q OR f.formName LIKE :Q OR f.formPath LIKE :Q)
               AND mt.recordFl > 0
        ";
        $q = "%" . ($_POST["Q"] ?? "") . "%";

        $totalRecords = $db->createQuery("SELECT COUNT(mt) " . $from)
            ->setParameter("Q", $q)
            ->getSingleScalarResult();

        // Sıralama: yalnızca izin verilen alanlar (DQL injection'a karşı)
        $sortBy    = in_array($_POST["sidx"], ["mt.roleFormId", "r.roleName", "r.appCode", "f.formName", "f.formPath", "mt.activeFl"]) ? $_POST["sidx"] : "mt.roleFormId";
        $direction = $_POST["sord"] == "desc" ? "DESC" : "ASC";

        $dtResponse = $db->createQuery("
            SELECT mt.roleFormId mt_roleFormId,
                   r.roleName    r_roleName,
                   r.appCode     r_appCode,
                   f.formName    f_formName,
                   f.formPath    f_formPath,
                   mt.activeFl   mt_activeFl
            " . $from . " ORDER BY " . $sortBy . " " . $direction)
            ->setParameter("Q", $q)
            ->setMaxResults((int) $_POST["rows"])
            ->setFirstResult(((int) $_POST["page"] - 1) * (int) $_POST["rows"])
            ->getArrayResult();

        if (count($dtResponse) > 0) {
            $resCode = 0;
            $resMsg = "Ok";
        } else {
            $resCode = 21;
            $resMsg = $lang["NO_RECORDS_FOUND"];
        }
        break;

    case "getSelectedIdRoleForm":
        try{
            $dtResponse = $db->createQuery("
                SELECT mt.roleFormId mt_roleFormId,
                       r.roleId      r_roleId,
                       f.formId      f_formId,
                       mt.activeFl   mt_activeFl
                  FROM BIO\MNG\RoleForm mt
                  JOIN mt.role r
                  JOIN mt.form f
                 WHERE mt.roleFormId = :ROLE_FORM_ID
                   AND mt.recordFl > 0")
                ->setParameter("ROLE_FORM_ID", (int) $_POST["SELECTED_ID"])
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

    case "setDeleteRoleForm":
        $execType = ET_DELETE;
        try{
            $rs = $db->find(RoleForm::class, (int) ($_POST["ROLE_FORM_ID"] ?? 0));
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

    case "setAddRoleForm":
        $_POST["ROLE_FORM_ID"] = "";    // ekleme her zaman yeni kayıt açar
        // no break: kayıt kuralları setSaveRoleForm'da

    case "setSaveRoleForm":
        $execType = ET_SAVE;
        try{
            $roleFormId = (int) ($_POST["ROLE_FORM_ID"] ?? 0);

            $rs   = $roleFormId > 0 ? $db->find(RoleForm::class, $roleFormId) : null;
            $role = $db->find(Role::class, (int) ($_POST["ROLE_ID"] ?? 0));
            $form = $db->find(Form::class, (int) ($_POST["FORM_ID"] ?? 0));

            // Kontroller (mesaj noty ile gösterilir)
            if ($role === null || $form === null) {
                $resCode = 22;
                $resMsg = "Rol ve ekran seçilmelidir.";
                break;
            }

            // Aynı rol + ekran bağı (silinmiş olsa da) tek kayıttır
            $same = $db->getRepository(RoleForm::class)->findOneBy(["role" => $role, "form" => $form]);
            if ($same !== null && $same->getRoleFormId() !== $roleFormId) {
                if (!$same->isDeleted()) {
                    $resCode = 22;
                    $resMsg = "Bu ekran bu role zaten verilmiş.";
                    break;
                }
                if ($rs === null) {
                    // Silinmiş bağı geri getir (benzersiz indeks yeni satıra izin vermez)
                    $rs = $same;
                    $rs->restore();
                } else {
                    $resCode = 22;
                    $resMsg = "Bu bağ daha önce silinmiş; yeni kayıt olarak ekleyin.";
                    break;
                }
            }

            if ($rs !== null) {
                // Güncelle (U_BY / U_AT flush'ta otomatik dolar)
                $rs->setRole($role);
                $rs->setForm($form);
                $resMsg = $roleFormId > 0 ? $lang["RECORD_IS_CHANGED"] : $lang["RECORD_IS_ADDED"];
            } else {
                // Yeni kayıt (C_BY / C_AT flush'ta otomatik dolar)
                $rs = new RoleForm($role, $form);
                $db->persist($rs);
                $resMsg = $lang["RECORD_IS_ADDED"];
            }
            $rs->setActive(($_POST["ACTIVE_FL"] ?? 'Y') === 'Y');

            $db->flush();
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}
