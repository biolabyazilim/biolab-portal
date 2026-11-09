<?php
/**
 * Biolab Yönetim — Kullanıcı tanımlama (mng_user, entity: BIO\MNG\AppUser)
 *
 * Alanlar: USER_ID, USERNAME, FULL_NAME, TITLE, EMAIL, PASSWORD, ACTIVE_FL
 * Şirket ve rol bağları ayrı ekranlarda (userCompany, userRole) yapılır.
 * Şifre veritabanında hash'li durur; ekrana hiçbir zaman gönderilmez.
 */
global $resourceFile;
use BIO\MNG\AppUser;

$resourceFile = "user/user-2.0.0.json";

global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat;
$db = $dbm ->getDB();

$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

const USER_MIN_PASSWORD = 6;

switch ($query){

    case "getGridListUser":
        $execType = ET_JQGRID;

        $whereClause = "
            WHERE (mt.username LIKE :Q OR mt.fullName LIKE :Q OR mt.email LIKE :Q)
              AND mt.recordFl > 0
        ";
        $q = "%" . ($_POST["Q"] ?? "") . "%";

        $totalRecords = $db->createQuery("SELECT COUNT(mt) FROM BIO\MNG\AppUser mt" . $whereClause)
            ->setParameter("Q", $q)
            ->getSingleScalarResult();

        // Sıralama: yalnızca izin verilen alanlar (DQL injection'a karşı)
        $sortBy    = in_array($_POST["sidx"], ["mt.userId", "mt.username", "mt.fullName", "mt.title", "mt.email", "mt.activeFl"]) ? $_POST["sidx"] : "mt.userId";
        $direction = $_POST["sord"] == "desc" ? "DESC" : "ASC";

        // Alanlar tek tek seçilir: "SELECT mt" şifre hash'ini de ekrana gönderirdi
        $dtResponse = $db->createQuery("
            SELECT mt.userId   mt_userId,
                   mt.username mt_username,
                   mt.fullName mt_fullName,
                   mt.title    mt_title,
                   mt.email    mt_email,
                   mt.activeFl mt_activeFl
              FROM BIO\MNG\AppUser mt" . $whereClause . " ORDER BY " . $sortBy . " " . $direction)
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

    case "getSelectedIdUser":
        try{
            $dtResponse = $db->createQuery("
                SELECT mt.userId   mt_userId,
                       mt.username mt_username,
                       mt.fullName mt_fullName,
                       mt.title    mt_title,
                       mt.email    mt_email,
                       mt.activeFl mt_activeFl
                  FROM BIO\MNG\AppUser mt
                 WHERE mt.userId = :USER_ID
                   AND mt.recordFl > 0")
                ->setParameter("USER_ID", (int) $_POST["SELECTED_ID"])
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

    case "setDeleteUser":
        $execType = ET_DELETE;
        try{
            $userId = (int) ($_POST["USER_ID"] ?? 0);

            if ($userId === (int) $_SESSION["USER_ID"]) {
                // Kullanıcı kendi hesabını silemez (sistemde yönetici kalmayabilir)
                $resCode = 22;
                $resMsg = "Kendi kullanıcınızı silemezsiniz.";
                break;
            }

            $rs = $db->find(AppUser::class, $userId);
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

    case "setAddUser":
        $_POST["USER_ID"] = "";     // ekleme her zaman yeni kayıt açar
        // no break: kayıt kuralları setSaveUser'da

    case "setSaveUser":
        $execType = ET_SAVE;
        try{
            $userId   = (int) ($_POST["USER_ID"] ?? 0);
            $username = trim($_POST["USERNAME"] ?? "");
            $fullName = trim($_POST["FULL_NAME"] ?? "");
            $email    = trim($_POST["EMAIL"] ?? "");
            $password = (string) ($_POST["PASSWORD"] ?? "");

            $rs = $userId > 0 ? $db->find(AppUser::class, $userId) : null;

            // Kontroller (mesaj noty ile gösterilir)
            $same = $db->getRepository(AppUser::class)->findOneBy(["username" => $username]);
            if ($username === "" || $fullName === "") {
                $resCode = 22;
                $resMsg = "Kullanıcı adı ve ad soyad zorunludur.";
                break;
            }
            if ($same !== null && $same->getUserId() !== $userId) {
                $resCode = 22;
                $resMsg = "Bu kullanıcı adı başka bir kullanıcıda kullanılıyor.";
                break;
            }
            if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $resCode = 22;
                $resMsg = "E-posta adresi geçerli değil.";
                break;
            }
            // Yeni kullanıcıda şifre zorunlu; güncellemede boş bırakılırsa şifre değişmez
            if (($rs === null || $password !== "") && mb_strlen($password) < USER_MIN_PASSWORD) {
                $resCode = 22;
                $resMsg = "Şifre en az " . USER_MIN_PASSWORD . " karakter olmalıdır.";
                break;
            }

            if ($rs !== null) {
                // Güncelle (U_BY / U_AT flush'ta otomatik dolar)
                $rs->setUsername($username);
                $rs->setFullName($fullName);
                $rs->setActive(($_POST["ACTIVE_FL"] ?? 'Y') === 'Y');
                if ($password !== "") {
                    $rs->changePassword($password);
                }
                $resMsg = $lang["RECORD_IS_CHANGED"];
            } else {
                // Yeni kayıt (C_BY / C_AT flush'ta otomatik dolar)
                $rs = new AppUser($username, $fullName, $password);
                $db->persist($rs);
                $resMsg = $lang["RECORD_IS_ADDED"];
            }

            // İsteğe bağlı alanlar: setter ile
            $rs->setTitle($_POST["TITLE"] ?? null);
            $rs->setEmail($email);

            $db->flush();
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}
