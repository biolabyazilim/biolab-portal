<?php
/**
 * Biolab Yönetim — Kullanıcı rolleri (mng_user_role, entity: BIO\MNG\UserRole)
 *
 * Bir kullanıcıya bir şirkette bir rol verir. Alanlar: USER_ROLE_ID, USER_ID, ROLE_ID, COMPANY_ID, ACTIVE_FL
 * Rol yalnızca kullanıcının bağlı olduğu şirkette verilebilir (mng_user_company).
 */
global $resourceFile;
use BIO\MNG\AppUser;
use BIO\MNG\Company;
use BIO\MNG\Role;
use BIO\MNG\UserRole;

$resourceFile = "user/userRole-2.0.0.json";

global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat;
$db = $dbm ->getDB();

$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

switch ($query){

    case "getUSER_ID":
        try{
            $execType = ET_DEFAULT;
            $dtResponse = $db->createQuery("
                SELECT u.userId,
                       u.fullName,
                       u.username,
                       u.activeFl
                  FROM BIO\MNG\AppUser u
                 WHERE u.recordFl > 0
                 ORDER BY u.fullName")
                ->getArrayResult();
            // Görünen metin PHP'de birleştirilir: sütunların collation'ı farklı, SQL'de CONCAT hata verir
            foreach ($dtResponse as &$r) {
                $r["userText"] = $r["fullName"] . " (" . $r["username"] . ")";
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

    case "getCOMPANY_ID":
        try{
            $execType = ET_DEFAULT;
            $dtResponse = $db->createQuery("
                SELECT c.companyId,
                       c.companyName,
                       c.activeFl
                  FROM BIO\MNG\Company c
                 WHERE c.recordFl > 0
                 ORDER BY c.companyName")
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

    case "getGridListUserRole":
        $execType = ET_JQGRID;

        $from = "
              FROM BIO\MNG\UserRole mt
              JOIN mt.user u
              JOIN mt.role r
              JOIN mt.company c
             WHERE (u.username LIKE :Q OR u.fullName LIKE :Q OR r.roleName LIKE :Q OR r.appCode LIKE :Q OR c.companyName LIKE :Q)
               AND mt.recordFl > 0
        ";
        $q = "%" . ($_POST["Q"] ?? "") . "%";

        $totalRecords = $db->createQuery("SELECT COUNT(mt) " . $from)
            ->setParameter("Q", $q)
            ->getSingleScalarResult();

        // Sıralama: yalnızca izin verilen alanlar (DQL injection'a karşı)
        $sortBy    = in_array($_POST["sidx"], ["mt.userRoleId", "u.fullName", "u.username", "r.roleName", "r.appCode", "c.companyName", "mt.activeFl"]) ? $_POST["sidx"] : "mt.userRoleId";
        $direction = $_POST["sord"] == "desc" ? "DESC" : "ASC";

        $dtResponse = $db->createQuery("
            SELECT mt.userRoleId mt_userRoleId,
                   u.fullName    u_fullName,
                   u.username    u_username,
                   r.roleName    r_roleName,
                   r.appCode     r_appCode,
                   c.companyName c_companyName,
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

    case "getSelectedIdUserRole":
        try{
            $dtResponse = $db->createQuery("
                SELECT mt.userRoleId mt_userRoleId,
                       u.userId      u_userId,
                       r.roleId      r_roleId,
                       c.companyId   c_companyId,
                       mt.activeFl   mt_activeFl
                  FROM BIO\MNG\UserRole mt
                  JOIN mt.user u
                  JOIN mt.role r
                  JOIN mt.company c
                 WHERE mt.userRoleId = :USER_ROLE_ID
                   AND mt.recordFl > 0")
                ->setParameter("USER_ROLE_ID", (int) $_POST["SELECTED_ID"])
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

    case "setDeleteUserRole":
        $execType = ET_DELETE;
        try{
            $rs = $db->find(UserRole::class, (int) ($_POST["USER_ROLE_ID"] ?? 0));

            if ($rs !== null && $rs->getUser()->getUserId() === (int) $_SESSION["USER_ID"]) {
                // Kullanıcı kendi rolünü kaldıramaz (kendini sistemden kilitleyebilir)
                $resCode = 22;
                $resMsg = "Kendi rolünüzü silemezsiniz.";
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

    case "setAddUserRole":
        $_POST["USER_ROLE_ID"] = "";    // ekleme her zaman yeni kayıt açar
        // no break: kayıt kuralları setSaveUserRole'da

    case "setSaveUserRole":
        $execType = ET_SAVE;
        try{
            $userRoleId = (int) ($_POST["USER_ROLE_ID"] ?? 0);
            $active     = ($_POST["ACTIVE_FL"] ?? 'Y') === 'Y';

            $rs      = $userRoleId > 0 ? $db->find(UserRole::class, $userRoleId) : null;
            $user    = $db->find(AppUser::class, (int) ($_POST["USER_ID"] ?? 0));
            $role    = $db->find(Role::class, (int) ($_POST["ROLE_ID"] ?? 0));
            $company = $db->find(Company::class, (int) ($_POST["COMPANY_ID"] ?? 0));

            // Kontroller (mesaj noty ile gösterilir)
            if ($user === null || $role === null || $company === null) {
                $resCode = 22;
                $resMsg = "Kullanıcı, rol ve şirket seçilmelidir.";
                break;
            }
            $inCompany = (int) $db->createQuery("
                SELECT COUNT(uc) FROM BIO\MNG\UserCompany uc
                 WHERE uc.user = :USER AND uc.company = :COMPANY AND uc.recordFl > 0")
                ->setParameters(["USER" => $user, "COMPANY" => $company])
                ->getSingleScalarResult();
            if ($inCompany === 0) {
                $resCode = 22;
                $resMsg = "Kullanıcı bu şirkete bağlı değil. Önce kullanıcıyı şirkete bağlayın.";
                break;
            }
            if ($rs !== null && $rs->getUser()->getUserId() === (int) $_SESSION["USER_ID"] && !$active) {
                $resCode = 22;
                $resMsg = "Kendi rolünüzü pasif yapamazsınız.";
                break;
            }

            // Aynı kullanıcı + rol + şirket bağı (silinmiş olsa da) tek kayıttır
            $same = $db->getRepository(UserRole::class)->findOneBy(["user" => $user, "role" => $role, "company" => $company]);
            if ($same !== null && $same->getUserRoleId() !== $userRoleId) {
                if (!$same->isDeleted()) {
                    $resCode = 22;
                    $resMsg = "Bu kullanıcıya bu şirkette bu rol zaten verilmiş.";
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
                $rs->setUser($user);
                $rs->setRole($role);
                $rs->setCompany($company);
                $resMsg = $userRoleId > 0 ? $lang["RECORD_IS_CHANGED"] : $lang["RECORD_IS_ADDED"];
            } else {
                // Yeni kayıt (C_BY / C_AT flush'ta otomatik dolar)
                $rs = new UserRole($user, $role, $company);
                $db->persist($rs);
                $resMsg = $lang["RECORD_IS_ADDED"];
            }
            $rs->setActive($active);

            $db->flush();
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}
