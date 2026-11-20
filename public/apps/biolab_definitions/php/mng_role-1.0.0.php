<?php
/**
 * Biolab Yönetim — Rol tanımlama (mng_role, entity: BIO\MNG\Role)
 *
 * Alanlar: ROLE_ID, ROLE_CODE, ROLE_NAME, ROLE_NAME_EN, APP_CODE, ACTIVE_FL
 * APP_CODE rolün ait olduğu uygulamadır (apps/ altındaki klasör = menü kökünün MENU_CODE'u).
 * Role ekran vermek Rol Form Tanımlama, kullanıcıya rol vermek Kullanıcı Rol Tanımlama ekranındadır.
 */
global $resourceFile;
use BIO\MNG\Role;

$resourceFile = "user/role-2.0.0.json";

global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat;
$db = $dbm ->getDB();

$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

switch ($query){

    case "getAPP_CODE":
        try{
            $execType = ET_DEFAULT;
            // Uygulamalar: menü kökleri (MENU_CODE = uygulama klasörü)
            $apps = $db->createQuery("
                SELECT m.menuCode appCode,
                       m.menuName appName
                  FROM BIO\MNG\Menu m
                 WHERE m.parentMenu IS NULL
                   AND m.menuCode IS NOT NULL
                   AND m.recordFl > 0
                 ORDER BY m.menuName")
                ->getArrayResult();

            // Rollerde kullanılıp menü kökü olmayan kodlar da listede olsun (var olan değer seçili görünsün)
            $known = array_column($apps, "appCode");
            $used  = $db->createQuery("
                SELECT DISTINCT r.appCode
                  FROM BIO\MNG\Role r
                 WHERE r.appCode IS NOT NULL
                   AND r.recordFl > 0")
                ->getSingleColumnResult();
            foreach ($used as $code) {
                if (!in_array($code, $known, true)) {
                    $apps[] = ["appCode" => $code, "appName" => $code];
                }
            }

            $dtResponse = [];
            foreach ($apps as $a) {
                $dtResponse[] = [
                    "appCode"  => $a["appCode"],
                    "appText"  => $a["appName"] . " (" . $a["appCode"] . ")",
                    "activeFl" => "Y",
                ];
            }

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

    case "getGridListRole":
        $execType = ET_JQGRID;

        $whereClause = "
            WHERE (mt.roleCode LIKE :Q OR mt.roleName LIKE :Q OR mt.roleNameEn LIKE :Q OR mt.appCode LIKE :Q)
              AND mt.recordFl > 0
        ";
        $q = "%" . ($_POST["Q"] ?? "") . "%";

        $totalRecords = $db->createQuery("SELECT COUNT(mt) FROM BIO\MNG\Role mt" . $whereClause)
            ->setParameter("Q", $q)
            ->getSingleScalarResult();

        // Sıralama: yalnızca izin verilen alanlar (DQL injection'a karşı)
        $sortBy    = in_array($_POST["sidx"], ["mt.roleId", "mt.roleCode", "mt.roleName", "mt.roleNameEn", "mt.appCode", "mt.activeFl"]) ? $_POST["sidx"] : "mt.roleId";
        $direction = $_POST["sord"] == "desc" ? "DESC" : "ASC";

        // Rolün kaç ekranı ve kaç kullanıcısı olduğu da gösterilir (silmeden önce görülsün)
        $dtResponse = $db->createQuery("
            SELECT mt.roleId     mt_roleId,
                   mt.roleCode   mt_roleCode,
                   mt.roleName   mt_roleName,
                   mt.roleNameEn mt_roleNameEn,
                   mt.appCode    mt_appCode,
                   mt.activeFl   mt_activeFl,
                   (SELECT COUNT(rf) FROM BIO\MNG\RoleForm rf WHERE rf.role = mt AND rf.recordFl > 0) formCount,
                   (SELECT COUNT(ur) FROM BIO\MNG\UserRole ur WHERE ur.role = mt AND ur.recordFl > 0) userCount
              FROM BIO\MNG\Role mt" . $whereClause . " ORDER BY " . $sortBy . " " . $direction)
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

    case "getSelectedIdRole":
        try{
            $dtResponse = $db->createQuery("
                SELECT mt.roleId     mt_roleId,
                       mt.roleCode   mt_roleCode,
                       mt.roleName   mt_roleName,
                       mt.roleNameEn mt_roleNameEn,
                       mt.appCode    mt_appCode,
                       mt.activeFl   mt_activeFl
                  FROM BIO\MNG\Role mt
                 WHERE mt.roleId = :ROLE_ID
                   AND mt.recordFl > 0")
                ->setParameter("ROLE_ID", (int) $_POST["SELECTED_ID"])
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

    case "setDeleteRole":
        $execType = ET_DELETE;
        try{
            $rs = $db->find(Role::class, (int) ($_POST["ROLE_ID"] ?? 0));

            if ($rs !== null && roleUserCount($db, $rs) > 0) {
                $resCode = 22;
                $resMsg = "Bu rol kullanıcılara verilmiş. Önce Kullanıcı Rol Tanımlama ekranından kaldırın.";
                break;
            }
            if ($rs !== null && roleFormCount($db, $rs) > 0) {
                $resCode = 22;
                $resMsg = "Bu role ekranlar verilmiş. Önce Rol Form Tanımlama ekranından kaldırın.";
                break;
            }
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

    case "setAddRole":
        $_POST["ROLE_ID"] = "";     // ekleme her zaman yeni kayıt açar
        // no break: kayıt kuralları setSaveRole'da

    case "setSaveRole":
        $execType = ET_SAVE;
        try{
            $roleId   = (int) ($_POST["ROLE_ID"] ?? 0);
            $roleCode = strtoupper(trim($_POST["ROLE_CODE"] ?? ""));
            $roleName = trim($_POST["ROLE_NAME"] ?? "");
            $nameEn   = trim($_POST["ROLE_NAME_EN"] ?? "");
            $appCode  = trim($_POST["APP_CODE"] ?? "");
            $active   = ($_POST["ACTIVE_FL"] ?? 'Y') === 'Y';

            $rs = $roleId > 0 ? $db->find(Role::class, $roleId) : null;

            // Kontroller (mesaj noty ile gösterilir)
            if ($roleCode === "" || $roleName === "") {
                $resCode = 22;
                $resMsg = "Rol kodu ve rol adı zorunludur.";
                break;
            }
            // Kod kodda rol kontrolü için kullanılır: harf, rakam ve alt çizgi
            if (!preg_match('/^[A-Z0-9_]{2,50}$/', $roleCode)) {
                $resCode = 22;
                $resMsg = "Rol kodu yalnızca harf, rakam ve alt çizgi içerebilir (ör. KVKK_READER).";
                break;
            }
            $sameCode = $db->getRepository(Role::class)->findOneBy(["roleCode" => $roleCode]);
            if ($sameCode !== null && $sameCode->getRoleId() !== $roleId) {
                $resCode = 22;
                $resMsg = "Bu rol kodu başka bir rolde kullanılıyor: " . $sameCode->getRoleName();
                break;
            }
            if ($appCode !== "" && !preg_match('/^[\w.-]{1,50}$/', $appCode)) {
                $resCode = 22;
                $resMsg = "Uygulama kodu geçerli değil.";
                break;
            }
            // Kullanıcının kendi giriş yaptığı rolü (ör. DEF_ADMIN) pasif yapması kendini kilitler
            if ($rs !== null && !$active && roleIsMine($db, $rs)) {
                $resCode = 22;
                $resMsg = "Kendi kullandığınız rolü pasif yapamazsınız.";
                break;
            }

            if ($rs !== null) {
                // Güncelle (U_BY / U_AT flush'ta otomatik dolar)
                $rs->setRoleCode($roleCode);
                $rs->setRoleName($roleName);
                $resMsg = $lang["RECORD_IS_CHANGED"];
            } else {
                // Yeni kayıt (C_BY / C_AT flush'ta otomatik dolar)
                $rs = new Role($roleCode, $roleName);
                $db->persist($rs);
                $resMsg = $lang["RECORD_IS_ADDED"];
            }
            // İsteğe bağlı alanlar: setter ile
            $rs->setRoleNameEn($nameEn !== "" ? $nameEn : null);
            $rs->setAppCode($appCode !== "" ? $appCode : null);
            $rs->setActive($active);

            $db->flush();
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

// Rolün verildiği silinmemiş kullanıcı bağı sayısı (mng_user_role)
function roleUserCount($db, Role $role): int
{
    return (int) $db->createQuery("
        SELECT COUNT(ur) FROM BIO\MNG\UserRole ur
         WHERE ur.role = :ROLE AND ur.recordFl > 0")
        ->setParameter("ROLE", $role)
        ->getSingleScalarResult();
}

// Role verilmiş silinmemiş ekran sayısı (mng_role_form)
function roleFormCount($db, Role $role): int
{
    return (int) $db->createQuery("
        SELECT COUNT(rf) FROM BIO\MNG\RoleForm rf
         WHERE rf.role = :ROLE AND rf.recordFl > 0")
        ->setParameter("ROLE", $role)
        ->getSingleScalarResult();
}

// Oturumdaki kullanıcı bu role oturumdaki şirkette sahip mi
function roleIsMine($db, Role $role): bool
{
    return (int) $db->createQuery("
        SELECT COUNT(ur) FROM BIO\MNG\UserRole ur
         WHERE ur.role = :ROLE AND IDENTITY(ur.user) = :USER_ID AND IDENTITY(ur.company) = :COMPANY_ID
           AND ur.recordFl > 0")
        ->setParameters(["ROLE" => $role, "USER_ID" => (int) $_SESSION["USER_ID"], "COMPANY_ID" => (int) $_SESSION["COMPANY_ID"]])
        ->getSingleScalarResult() > 0;
}
