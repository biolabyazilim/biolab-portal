<?php
/**
 * Biolab Yönetim — Kullanıcı şirketleri (mng_user_company, entity: BIO\MNG\UserCompany)
 *
 * Kullanıcıyı bir şirkete (şubeye) bağlar. Alanlar: USER_COMPANY_ID, USER_ID, COMPANY_ID, DEFAULT_FL, ACTIVE_FL
 * Girişte varsayılan (DEFAULT_FL = 'Y') aktif bağ kullanılır, yoksa ilk aktif bağ (Biolab\Core\Auth\AuthService).
 * Kullanıcıya bir şirkette rol verilebilmesi için önce o şirkete bağlı olması gerekir (userRole ekranı).
 */
global $resourceFile;
use BIO\MNG\AppUser;
use BIO\MNG\Company;
use BIO\MNG\UserCompany;

$resourceFile = "user/userCompany-2.0.0.json";

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

    case "getGridListUserCompany":
        $execType = ET_JQGRID;

        $from = "
              FROM BIO\MNG\UserCompany mt
              JOIN mt.user u
              JOIN mt.company c
             WHERE (u.username LIKE :Q OR u.fullName LIKE :Q OR c.companyName LIKE :Q OR c.code LIKE :Q)
               AND mt.recordFl > 0
        ";
        $q = "%" . ($_POST["Q"] ?? "") . "%";

        $totalRecords = $db->createQuery("SELECT COUNT(mt) " . $from)
            ->setParameter("Q", $q)
            ->getSingleScalarResult();

        // Sıralama: yalnızca izin verilen alanlar (DQL injection'a karşı)
        $sortBy    = in_array($_POST["sidx"], ["mt.userCompanyId", "u.fullName", "u.username", "c.companyName", "c.code", "mt.defaultFl", "mt.activeFl"]) ? $_POST["sidx"] : "mt.userCompanyId";
        $direction = $_POST["sord"] == "desc" ? "DESC" : "ASC";

        $dtResponse = $db->createQuery("
            SELECT mt.userCompanyId mt_userCompanyId,
                   u.fullName       u_fullName,
                   u.username       u_username,
                   c.code           c_code,
                   c.companyName    c_companyName,
                   mt.defaultFl     mt_defaultFl,
                   mt.activeFl      mt_activeFl
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

    case "getSelectedIdUserCompany":
        try{
            $dtResponse = $db->createQuery("
                SELECT mt.userCompanyId mt_userCompanyId,
                       u.userId         u_userId,
                       c.companyId      c_companyId,
                       mt.defaultFl     mt_defaultFl,
                       mt.activeFl      mt_activeFl
                  FROM BIO\MNG\UserCompany mt
                  JOIN mt.user u
                  JOIN mt.company c
                 WHERE mt.userCompanyId = :USER_COMPANY_ID
                   AND mt.recordFl > 0")
                ->setParameter("USER_COMPANY_ID", (int) $_POST["SELECTED_ID"])
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

    case "setDeleteUserCompany":
        $execType = ET_DELETE;
        try{
            $rs = $db->find(UserCompany::class, (int) ($_POST["USER_COMPANY_ID"] ?? 0));

            if ($rs !== null && $rs->getUser()->getUserId() === (int) $_SESSION["USER_ID"]) {
                // Kullanıcı kendi şirket bağını kaldıramaz (kendini sistemden kilitleyebilir)
                $resCode = 22;
                $resMsg = "Kendi şirket bağınızı silemezsiniz.";
                break;
            }
            if ($rs !== null && userCompanyRoleCount($db, $rs->getUser(), $rs->getCompany()) > 0) {
                // Bağ silinirse o şirketteki roller işe yaramaz hale gelir
                $resCode = 22;
                $resMsg = "Kullanıcının bu şirkette rolleri var. Önce Kullanıcı Rolleri ekranından rolleri kaldırın.";
                break;
            }
            if ($rs !== null) {
                $rs->setActive(false);
                $rs->setDefault(false);
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

    case "setAddUserCompany":
        $_POST["USER_COMPANY_ID"] = "";     // ekleme her zaman yeni kayıt açar
        // no break: kayıt kuralları setSaveUserCompany'de

    case "setSaveUserCompany":
        $execType = ET_SAVE;
        try{
            $userCompanyId = (int) ($_POST["USER_COMPANY_ID"] ?? 0);
            $active        = ($_POST["ACTIVE_FL"] ?? 'Y') === 'Y';
            $isDefault     = ($_POST["DEFAULT_FL"] ?? 'N') === 'Y';

            $rs      = $userCompanyId > 0 ? $db->find(UserCompany::class, $userCompanyId) : null;
            $user    = $db->find(AppUser::class, (int) ($_POST["USER_ID"] ?? 0));
            $company = $db->find(Company::class, (int) ($_POST["COMPANY_ID"] ?? 0));

            // Kontroller (mesaj noty ile gösterilir)
            if ($user === null || $company === null) {
                $resCode = 22;
                $resMsg = "Kullanıcı ve şirket seçilmelidir.";
                break;
            }
            if ($rs !== null && $rs->getUser()->getUserId() === (int) $_SESSION["USER_ID"]
                && (!$active || $rs->getCompany()->getCompanyId() !== $company->getCompanyId())) {
                $resCode = 22;
                $resMsg = "Kendi şirket bağınızı pasif yapamaz ya da değiştiremezsiniz.";
                break;
            }
            if ($isDefault && !$active) {
                $resCode = 22;
                $resMsg = "Pasif bağ varsayılan şirket olamaz.";
                break;
            }

            // Aynı kullanıcı + şirket bağı (silinmiş olsa da) tek kayıttır
            $same = $db->getRepository(UserCompany::class)->findOneBy(["user" => $user, "company" => $company]);
            if ($same !== null && $same->getUserCompanyId() !== $userCompanyId) {
                if (!$same->isDeleted()) {
                    $resCode = 22;
                    $resMsg = "Bu kullanıcı bu şirkete zaten bağlı.";
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
            // Şirketi değişen bağın eski şirketinde rolleri kalmasın
            if ($rs !== null && $userCompanyId > 0 && $rs->getCompany()->getCompanyId() !== $company->getCompanyId()
                && userCompanyRoleCount($db, $rs->getUser(), $rs->getCompany()) > 0) {
                $resCode = 22;
                $resMsg = "Kullanıcının eski şirkette rolleri var. Önce Kullanıcı Rolleri ekranından rolleri kaldırın.";
                break;
            }

            if ($rs !== null) {
                // Güncelle (U_BY / U_AT flush'ta otomatik dolar)
                $rs->setUser($user);
                $rs->setCompany($company);
                $resMsg = $userCompanyId > 0 ? $lang["RECORD_IS_CHANGED"] : $lang["RECORD_IS_ADDED"];
            } else {
                // Yeni kayıt (C_BY / C_AT flush'ta otomatik dolar)
                $rs = new UserCompany($user, $company);
                $db->persist($rs);
                $resMsg = $lang["RECORD_IS_ADDED"];
            }
            $rs->setActive($active);
            $rs->setDefault($isDefault);

            // Kullanıcının tek varsayılan şirketi olur: diğer bağların varsayılanı kaldırılır
            if ($isDefault) {
                $others = $db->getRepository(UserCompany::class)->findBy(["user" => $user]);
                foreach ($others as $other) {
                    if ($other !== $rs && $other->isDefault()) {
                        $other->setDefault(false);
                    }
                }
            }

            $db->flush();
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

// Kullanıcının bu şirketteki silinmemiş rol sayısı (mng_user_role)
function userCompanyRoleCount($db, AppUser $user, Company $company): int
{
    return (int) $db->createQuery("
        SELECT COUNT(ur) FROM BIO\MNG\UserRole ur
         WHERE ur.user = :USER AND ur.company = :COMPANY AND ur.recordFl > 0")
        ->setParameters(["USER" => $user, "COMPANY" => $company])
        ->getSingleScalarResult();
}
