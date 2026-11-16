<?php
/**
 * Saha Takip — Personeller veri kaynağı
 *
 *   getUSER_ID       → kullanıcı seçim kutusu (oturumdaki şirkete bağlı portal kullanıcıları)
 *   getGridList      → personel listesi (jqGrid; arama Q: kod, ad, kullanıcı adı)
 *   getSelectedId    → seçilen personel (formu doldurur)
 *   setSave          → personel ekle / güncelle
 *   setAdd           → formdaki bilgilerle her zaman yeni personel ekler (setSave ile aynı kod)
 *   setDelete        → personeli siler (RECORD_FL = 0), telefon oturumlarını kapatır
 *   setRevokeTokens  → personelin bütün telefon oturumlarını kapatır (telefon kaybolursa / değişirse)
 *
 * Kurallar:
 *   - Bir portal kullanıcısının tek personel kaydı olur (sht_personel.USER_ID tekil).
 *   - PERSONEL_CODE = Traccar'daki cihaz Identifier'ı; harf ve rakam, en fazla 20, büyük harfe çevrilir, tekil.
 *   - Kaydedilen personelin kullanıcısına SHT_PERSONEL rolü (mobil giriş yetkisi) yoksa otomatik verilir.
 *   - Personel pasife alınır ya da silinirse açık telefon oturumları iptal edilir (telefon 401 alıp girişe döner).
 *
 * Bütün kayıtlar oturumdaki şirkete göredir (COMPANY_ID). C_BY/U_BY/D_BY ve tarihler AuditListener ile dolar.
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use BIO\MNG\AppUser;
use BIO\MNG\Company;
use BIO\MNG\Role;
use BIO\MNG\UserRole;
use BIO\SHT\DeviceToken;
use BIO\SHT\Personel;

$db = $dbm->getDB();
$resourceFile = "sht_personel/personel-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

const SHT_FIELD_ROLE = 'SHT_PERSONEL';   // mobil uygulamaya giriş rolü

$companyId = (int) $_SESSION["COMPANY_ID"];

switch ($query) {

    // Kullanıcı seçimi: bu şirkete bağlı (mng_user_company) silinmemiş portal kullanıcıları. Metin "Ad Soyad (kullanıcı adı)"
    case "getUSER_ID":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = $db->createQuery("
                SELECT u.userId, u.fullName, u.username, u.activeFl
                  FROM BIO\MNG\UserCompany uc
                  JOIN uc.user u
                 WHERE IDENTITY(uc.company) = :COMPANY_ID
                   AND uc.recordFl > 0
                   AND u.recordFl > 0
                 ORDER BY u.fullName
            ")->setParameter("COMPANY_ID", $companyId)->getArrayResult();
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

    // Personel listesi. activeTokens: açık telefon oturumu sayısı, lastSeenAt: telefonun portala son isteği
    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $whereClause = "";
            $params = array("COMPANY_ID" => $companyId);
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $whereClause .= " AND (mt.personelCode LIKE :Q_LIKE OR u.fullName LIKE :Q_LIKE OR u.username LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }

            $from = "
                  FROM BIO\SHT\Personel mt
                  JOIN mt.user u
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.recordFl > 0
            ";

            $totalRecords = (int) $db->createQuery("SELECT COUNT(mt.personelId) " . $from . $whereClause)
                ->setParameters($params)->getSingleScalarResult();

            // Sıralama alanı istemciden gelir; DQL'e eklenmeden önce izin listesiyle kontrol edilir
            $sortable  = array("mt.personelId", "mt.personelCode", "u.fullName", "u.username", "mt.jobType", "mt.activeFl");
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "mt.personelCode";
            $direction = strtoupper($_POST["sord"] ?? "") === "DESC" ? "DESC" : "ASC";

            $rows = max(1, (int) ($_POST["rows"] ?? 20));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $params["NOW"] = new \DateTimeImmutable();
            $dtResponse = $db->createQuery("
                SELECT mt.personelId mt_personelId,
                       mt.personelCode mt_personelCode,
                       mt.jobType mt_jobType,
                       mt.phone mt_phone,
                       mt.traccarDeviceId mt_traccarDeviceId,
                       mt.activeFl mt_activeFl,
                       u.fullName u_fullName,
                       u.username u_username,
                       (SELECT COUNT(t.tokenId) FROM BIO\SHT\DeviceToken t
                         WHERE t.personel = mt AND t.revokedAt IS NULL AND t.expiresAt > :NOW AND t.recordFl > 0) activeTokens,
                       (SELECT MAX(t2.lastSeenAt) FROM BIO\SHT\DeviceToken t2
                         WHERE t2.personel = mt) lastSeenAt
            " . $from . $whereClause . " ORDER BY " . $sortBy . " " . $direction . ", mt.personelId ASC")
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

    case "getSelectedId":
        $execType = ET_DEFAULT;
        try{
            // Alanlar tek tek seçilir: "SELECT mt, u" kullanıcının şifre özetini (PASSWORD_HASH) de tarayıcıya gönderirdi
            $dtResponse = $db->createQuery("
                SELECT mt.personelId mt_personelId,
                       mt.personelCode mt_personelCode,
                       mt.jobType mt_jobType,
                       mt.phone mt_phone,
                       mt.traccarDeviceId mt_traccarDeviceId,
                       mt.activeFl mt_activeFl,
                       u.userId u_userId
                  FROM BIO\SHT\Personel mt
                  JOIN mt.user u
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
                   AND mt.personelId = :PERSONEL_ID
                   AND mt.recordFl > 0
            ")
                ->setParameter("COMPANY_ID", $companyId)
                ->setParameter("PERSONEL_ID", (int) ($_POST["SELECTED_ID"] ?? 0))
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

    // Ekle: formda seçili bir personel olsa bile yeni kayıt eklenir. PERSONEL_ID boşaltılıp setSave'e devam edilir (break yok).
    case "setAdd":
        $_POST["PERSONEL_ID"] = "";

    case "setSave":
        $execType = ET_SAVE;
        try{
            // --- Gelen alanlar ---
            $personelId  = (int) ($_POST["PERSONEL_ID"] ?? 0);
            $userId      = (int) ($_POST["USER_ID"] ?? 0);
            $code        = strtoupper(trim((string) ($_POST["PERSONEL_CODE"] ?? "")));
            $jobType     = trim((string) ($_POST["JOB_TYPE"] ?? ""));
            $phone       = trim((string) ($_POST["PHONE"] ?? ""));
            $traccarRaw  = trim((string) ($_POST["TRACCAR_DEVICE_ID"] ?? ""));
            $active      = ($_POST["ACTIVE_FL"] ?? "Y") === "Y";

            $personel = $personelId > 0 ? $db->getRepository(Personel::class)->findOneBy(array("personelId" => $personelId, "company" => $companyId)) : null;

            // Kullanıcı bu şirkete bağlı olmalı
            $user = $userId > 0 ? $db->createQuery("
                SELECT u FROM BIO\MNG\UserCompany uc JOIN uc.user u
                 WHERE IDENTITY(uc.company) = :COMPANY_ID AND u.userId = :USER_ID
                   AND uc.recordFl > 0 AND u.recordFl > 0
            ")->setParameters(array("COMPANY_ID" => $companyId, "USER_ID" => $userId))->setMaxResults(1)->getOneOrNullResult() : null;

            // Tekillik: silinmiş kayıtlar da sayılır (veritabanındaki tekil indeks onları da kapsar)
            $sameUser = $user === null ? null : $db->getRepository(Personel::class)->findOneBy(array("user" => $user));
            $sameCode = $code === "" ? null : $db->getRepository(Personel::class)->findOneBy(array("personelCode" => $code));

            // --- Sunucu tarafı kontrol (tarayıcıdaki doğrulama atlanabilir) ---
            $error = null;
            if ($personelId > 0 && ($personel === null || $personel->isDeleted())) {
                $error = $lang["ERR_NOT_FOUND"];
            } elseif ($user === null) {
                $error = $lang["ERR_USER_ID"];
            } elseif (!preg_match('/^[A-Z0-9]{1,20}$/', $code)) {
                $error = $lang["ERR_PERSONEL_CODE"];
            } elseif ($sameCode !== null && $sameCode !== $personel) {
                $error = $sameCode->isDeleted()
                    ? sprintf($lang["ERR_CODE_USED_DELETED"], $code)
                    : sprintf($lang["ERR_CODE_USED"], $code, $sameCode->getUser()->getFullName());
            } elseif ($sameUser !== null && $sameUser !== $personel && !$sameUser->isDeleted()) {
                $error = sprintf($lang["ERR_USER_USED"], $sameUser->getPersonelCode());
            } elseif ($traccarRaw !== "" && !ctype_digit($traccarRaw)) {
                $error = $lang["ERR_TRACCAR_DEVICE_ID"];
            } elseif (mb_strlen($jobType) > 50 || mb_strlen($phone) > 20) {
                $error = $lang["ERR_LENGTH"];
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
                break;
            }

            if ($personel === null && $sameUser !== null && $sameUser->isDeleted()) {
                // Bu kullanıcının daha önce silinmiş kaydı var: yeni satır yerine o kayıt geri getirilir (USER_ID tekil)
                $personel = $sameUser;
                $personel->restore();
                $personel->setPersonelCode($code);
                $resMsg = $lang["RECORD_IS_ADDED"];
            } elseif ($personel === null) {
                // --- Yeni personel --- (şirket, C_BY, C_AT otomatik dolar)
                $personel = new Personel($user, $code);
                $db->persist($personel);
                $resMsg = $lang["RECORD_IS_ADDED"];
            } else {
                // --- Güncelleme --- (U_BY, U_AT otomatik dolar). Kullanıcı değişmez; başka kullanıcı için yeni kayıt açılır.
                if ($personel->getUser()->getUserId() !== $user->getUserId()) {
                    $resCode = 1;
                    $resMsg = $lang["ERR_USER_CHANGE"];
                    break;
                }
                $personel->setPersonelCode($code);
                $resMsg = $lang["RECORD_IS_CHANGED"];
            }
            $personel->setJobType($jobType !== "" ? $jobType : null)
                     ->setPhone($phone !== "" ? $phone : null)
                     ->setTraccarDeviceId($traccarRaw !== "" ? (int) $traccarRaw : null);
            $personel->setActive($active);

            // Pasife alınan personelin telefon oturumları kapanır
            if (!$active && $personel->getPersonelId() !== null) {
                $n = shtRevokeTokens($db, $personel);
                if ($n > 0) { $resMsg .= " · " . sprintf($lang["INF_TOKENS_REVOKED"], $n); }
            }

            // Mobil giriş rolü yoksa verilir (kullanıcının bu şirketteki SHT_PERSONEL rolü)
            if (shtEnsureFieldRole($db, $user, $companyId)) {
                $resMsg .= " · " . $lang["INF_ROLE_GRANTED"];
            }

            $db->flush();

            $resObject = array("PERSONEL_ID" => $personel->getPersonelId());
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Personel silinmez: pasife alınır, RECORD_FL = 0 olur, telefon oturumları kapanır. Rolü elle alınır (yönetim ekranı).
    case "setDelete":
        $execType = ET_DELETE;
        try{
            $personel = $db->getRepository(Personel::class)->findOneBy(array(
                "personelId" => (int) ($_POST["PERSONEL_ID"] ?? 0),
                "company"    => $companyId,
            ));
            if ($personel === null || $personel->isDeleted()) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } else {
                shtRevokeTokens($db, $personel);
                $personel->setActive(false);
                $personel->softDelete();
                $db->flush();   // D_BY, D_AT AuditListener ile dolar
                $resCode = 0;
                $resMsg = $lang["RECORD_IS_DELETED"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Telefon oturumlarını kapat: personel bir sonraki istekte giriş ekranına döner, yeniden giriş yapması gerekir
    case "setRevokeTokens":
        $execType = ET_SAVE;
        try{
            $personel = $db->getRepository(Personel::class)->findOneBy(array(
                "personelId" => (int) ($_POST["PERSONEL_ID"] ?? 0),
                "company"    => $companyId,
            ));
            if ($personel === null || $personel->isDeleted()) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } else {
                $n = shtRevokeTokens($db, $personel);
                $db->flush();
                $resCode = 0;
                $resMsg = $n > 0 ? sprintf($lang["INF_TOKENS_REVOKED"], $n) : $lang["INF_NO_TOKENS"];
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

/** Personelin açık telefon oturumlarını iptal eder (flush çağıran yapar). Kapatılan oturum sayısını döner. */
function shtRevokeTokens($db, Personel $personel): int
{
    $now    = new \DateTimeImmutable();
    $tokens = $db->getRepository(DeviceToken::class)->findBy(array("personel" => $personel, "revokedAt" => null));
    $n = 0;
    foreach ($tokens as $t) {
        if ($t->isUsable($now)) { $n++; }
        $t->revoke($now);
    }
    return $n;
}

/** Kullanıcının bu şirkette SHT_PERSONEL rolü yoksa ekler (silinmiş/pasif bağ varsa geri açar). Rol eklendiyse true. */
function shtEnsureFieldRole($db, AppUser $user, int $companyId): bool
{
    $role = $db->getRepository(Role::class)->findOneBy(array("roleCode" => SHT_FIELD_ROLE));
    if ($role === null) {
        return false;   // migration uygulanmamış; giriş yine rol kontrolüne takılır
    }
    $company = $db->getReference(Company::class, $companyId);
    $link = $db->getRepository(UserRole::class)->findOneBy(array("user" => $user, "role" => $role, "company" => $company));
    if ($link !== null) {
        if (!$link->isDeleted() && $link->isActive()) {
            return false;
        }
        $link->restore();
        $link->setActive(true);
        return true;
    }
    $db->persist(new UserRole($user, $role, $company));
    return true;
}
