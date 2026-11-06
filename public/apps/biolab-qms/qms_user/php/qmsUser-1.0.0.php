<?php
/**
 * Biolab QMS — Kullanıcılar veri kaynağı
 *
 *   getGridList    → kullanıcı listesi (jqGrid; arama Q, departman DEPARTMENT_ID, pasifler SHOW_PASSIVE)
 *   getSelectedId  → seçilen kullanıcı: kart + güncel görev + roller + görev geçmişi
 *   getOptions     → departman / pozisyon / lokasyon / rol listeleri (form seçimleri, tek istekte)
 *   setSave/setAdd → kullanıcı kartı, görev ve roller tek transaction'da kaydedilir
 *
 * Kurallar:
 *   - Kullanıcı silinmez, pasife alınır (geçmiş imzaları sahipsiz kalmamalı). Bekleyen imza adımı olan pasife alınamaz.
 *   - Kullanıcı adı sonradan değişmez. Şifre yeni kullanıcıda zorunlu, güncellemede yalnızca girilirse değişir.
 *   - Görev tarihlidir: departman / pozisyon / lokasyon değişirse güncel görev GÖREV TARİHİ ile kapanır,
 *     aynı tarihten yeni görev açılır (qms_user_assignment). Geçmiş satırlar değişmez.
 *   - Roller oturumdaki şirkete göredir (mng_user_role); kaldırılan rol pasif olur, tekrar verilince açılır.
 *   - Yönetici kendi QMS_ADMIN rolünü kaldıramaz, kendini pasife alamaz.
 * Her kayıt qms_audit_log'a yazılır (şifre yazılmaz, yalnızca değiştiği bilgisi).
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use Biolab\Core\Qms\AuditLogger;
use QMS\MNG\AppUser;
use QMS\MNG\Company;
use QMS\MNG\Role;
use QMS\MNG\UserCompany;
use QMS\MNG\UserRole;
use QMS\ORG\Department;
use QMS\ORG\Location;
use QMS\ORG\Position;
use QMS\ORG\UserAssignment;

$db = $dbm->getDB();
$resourceFile = "qms_user/qmsUser-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];
$sessionUserId = (int) $_SESSION["USER_ID"];

const QMS_USER_MIN_PASSWORD = 6;

switch ($query) {

    case "getGridList":
        $execType = ET_JQGRID;
        try{
            $whereClause = "";
            $params = array("COMPANY_ID" => $companyId);
            $q = trim((string) ($_POST["Q"] ?? ""));
            if ($q !== "") {
                $whereClause .= " AND (u.username LIKE :Q_LIKE OR u.fullName LIKE :Q_LIKE OR u.registryNo LIKE :Q_LIKE)";
                $params["Q_LIKE"] = "%" . $q . "%";
            }
            if ((int) ($_POST["DEPARTMENT_ID"] ?? 0) > 0) {
                $whereClause .= " AND IDENTITY(a.department) = :DEPARTMENT_ID";
                $params["DEPARTMENT_ID"] = (int) $_POST["DEPARTMENT_ID"];
            }
            if (($_POST["SHOW_PASSIVE"] ?? "N") !== "Y") {
                $whereClause .= " AND u.activeFl = 'Y'";
            }
            // Güncel görev: bitiş tarihi boş olan atama
            $from = " FROM QMS\MNG\AppUser u
                      JOIN QMS\MNG\UserCompany uc WITH uc.user = u AND IDENTITY(uc.company) = :COMPANY_ID AND uc.recordFl > 0
                      LEFT JOIN QMS\ORG\UserAssignment a WITH a.user = u AND IDENTITY(a.company) = :COMPANY_ID
                                                          AND a.validTo IS NULL AND a.recordFl > 0
                      LEFT JOIN a.department d
                      LEFT JOIN a.position p
                     WHERE u.recordFl > 0";

            $totalRecords = (int) $db->createQuery("SELECT COUNT(u.userId)" . $from . $whereClause)
                ->setParameters($params)->getSingleScalarResult();

            // jqGrid sütun adı → DQL alanı (izin listesi)
            $sortable  = array("u.USERNAME" => "u.username", "u.FULL_NAME" => "u.fullName", "d.DEPARTMENT_NAME" => "d.departmentName",
                               "p.POSITION_NAME" => "p.positionName", "u.ACTIVE_FL" => "u.activeFl");
            $sortBy    = $sortable[$_POST["sidx"] ?? ""] ?? "u.fullName";
            $direction = strtoupper($_POST["sord"] ?? "") === "DESC" ? "DESC" : "ASC";
            $orderBy   = " ORDER BY " . $sortBy . " " . $direction . ($sortBy !== "u.fullName" ? ", u.fullName ASC" : "");
            $rows = max(1, (int) ($_POST["rows"] ?? 20));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $dtResponse = $db->createQuery("
                SELECT u.userId u_userId, u.username u_username, u.fullName u_fullName, u.title u_title,
                       u.activeFl u_activeFl, d.departmentName d_departmentName, p.positionName p_positionName
                " . $from . $whereClause . $orderBy)
                ->setParameters($params)
                ->setMaxResults($rows)
                ->setFirstResult(($page - 1) * $rows)
                ->getArrayResult();

            // Roller (bu şirkette aktif olanlar): sayfadaki kullanıcılar için tek sorgu, adlar virgülle birleşir
            $roleNames = array();
            if ($dtResponse) {
                foreach ($db->createQuery("
                    SELECT IDENTITY(ur.user) userId, r.roleName
                      FROM QMS\MNG\UserRole ur JOIN ur.role r
                     WHERE IDENTITY(ur.user) IN (:IDS) AND IDENTITY(ur.company) = :COMPANY_ID
                       AND ur.activeFl = 'Y' AND ur.recordFl > 0
                     ORDER BY r.roleId")
                    ->setParameters(array("IDS" => array_column($dtResponse, "u_userId"), "COMPANY_ID" => $companyId))
                    ->getArrayResult() as $r) {
                    $roleNames[$r["userId"]][] = $r["roleName"];
                }
            }
            foreach ($dtResponse as &$row) {
                $row["roleNames"] = implode(", ", $roleNames[$row["u_userId"]] ?? array());
            }
            unset($row);

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
            $user = qmsUserFind($db, (int) ($_POST["SELECTED_ID"] ?? 0), $companyId);
            if ($user === null) {
                $resCode = 21;
                $resMsg = $lang["NO_RECORDS_FOUND"];
            } else {
                $dtResponse = array(qmsUserData($db, $user, $companyId, true));
                $resCode = 0;
                $resMsg = "Ok";
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Form seçimleri. Pozisyonda departmanı da gelir: departman seçilince yalnızca o departmanın ve
    // şirket geneli (departmansız) pozisyonlar gösterilir. Pasifler activeFl = 'N' ile gelir.
    case "getOptions":
        $execType = ET_DEFAULT;
        try{
            $p = array("COMPANY_ID" => $companyId);
            $resObject = array(
                "DEPARTMENT" => $db->createQuery("SELECT d.departmentId id, d.departmentName name, d.activeFl activeFl FROM QMS\ORG\Department d WHERE IDENTITY(d.company) = :COMPANY_ID AND d.recordFl > 0 ORDER BY d.departmentName")->setParameters($p)->getArrayResult(),
                "POSITION"   => $db->createQuery("SELECT p.positionId id, p.positionName name, IDENTITY(p.department) departmentId, p.activeFl activeFl FROM QMS\ORG\Position p WHERE IDENTITY(p.company) = :COMPANY_ID AND p.recordFl > 0 ORDER BY p.positionName")->setParameters($p)->getArrayResult(),
                "LOCATION"   => $db->createQuery("SELECT l.locationId id, l.locationName name, l.activeFl activeFl FROM QMS\ORG\Location l WHERE IDENTITY(l.company) = :COMPANY_ID AND l.recordFl > 0 ORDER BY l.locationName")->setParameters($p)->getArrayResult(),
                "ROLE"       => $db->createQuery("SELECT r.roleCode code, r.roleName name FROM QMS\MNG\Role r WHERE r.appCode = 'biolab-qms' AND r.activeFl = 'Y' AND r.recordFl > 0 ORDER BY r.roleId")->getArrayResult(),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Ekle: formda seçili kullanıcı olsa bile yeni kullanıcı eklenir. USER_ID boşaltılıp setSave'e devam edilir (break yok).
    case "setAdd":
        $_POST["USER_ID"] = "";

    case "setSave":
        $execType = ET_SAVE;
        try{
            // --- Gelen alanlar ---
            $userId     = (int) ($_POST["USER_ID"] ?? 0);
            $username   = trim((string) ($_POST["USERNAME"] ?? ""));
            $fullName   = trim((string) ($_POST["FULL_NAME"] ?? ""));
            $title      = trim((string) ($_POST["TITLE"] ?? ""));
            $registryNo = trim((string) ($_POST["REGISTRY_NO"] ?? ""));
            $email      = trim((string) ($_POST["EMAIL"] ?? ""));
            $phone      = preg_replace('/[\s()+-]/', '', (string) ($_POST["PHONE"] ?? ""));
            $password   = (string) ($_POST["PASSWORD"] ?? "");
            $activeFl   = ($_POST["ACTIVE_FL"] ?? "Y") === "N" ? "N" : "Y";
            $deptId     = (int) ($_POST["DEPARTMENT_ID"] ?? 0);
            $posId      = (int) ($_POST["POSITION_ID"] ?? 0);
            $locId      = (int) ($_POST["LOCATION_ID"] ?? 0);
            $assignDate = DateTimeImmutable::createFromFormat("!Y-m-d", (string) ($_POST["ASSIGN_DATE"] ?? "")) ?: null;
            $reason     = trim((string) ($_POST["ASSIGN_REASON"] ?? ""));
            $roleCodes  = array_values(array_unique(array_map("strval", (array) ($_POST["ROLES"] ?? array()))));

            $user = $userId > 0 ? qmsUserFind($db, $userId, $companyId) : null;
            $dept = $deptId > 0 ? $db->getRepository(Department::class)->findOneBy(array("departmentId" => $deptId, "company" => $companyId)) : null;
            $pos  = $posId > 0 ? $db->getRepository(Position::class)->findOneBy(array("positionId" => $posId, "company" => $companyId)) : null;
            $loc  = $locId > 0 ? $db->getRepository(Location::class)->findOneBy(array("locationId" => $locId, "company" => $companyId)) : null;
            $current = $user !== null ? qmsUserCurrentAssignment($db, $user, $companyId) : null;
            $roles = $roleCodes ? $db->getRepository(Role::class)->findBy(array("roleCode" => $roleCodes)) : array();
            $assignChanged = $current === null
                || $current->getDepartment()->getDepartmentId() !== $deptId
                || $current->getPosition()->getPositionId() !== $posId
                || ($current->getLocation()?->getLocationId() ?? 0) !== $locId;

            // --- Sunucu tarafı kontrol (tarayıcıdaki doğrulama atlanabilir) ---
            $error = null;
            if ($userId > 0 && $user === null) {
                $error = $lang["NO_RECORDS_FOUND"];
            } elseif ($user === null && !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
                $error = $lang["ERR_USERNAME"];
            } elseif ($user === null && $db->getRepository(AppUser::class)->findOneBy(array("username" => $username)) !== null) {
                $error = sprintf($lang["ERR_USERNAME_TAKEN"], $username);
            } elseif ($fullName === "" || mb_strlen($fullName) > 100 || mb_strlen($title) > 50) {
                $error = $lang["ERR_FULL_NAME"];
            } elseif ($registryNo !== "" && qmsUserRegistryTaken($db, $registryNo, $user)) {
                $error = sprintf($lang["ERR_REGISTRY_TAKEN"], $registryNo);
            } elseif ($email !== "" && (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $error = $lang["ERR_EMAIL"];
            } elseif ($phone !== "" && !preg_match('/^\d{10,15}$/', $phone)) {
                $error = $lang["ERR_PHONE"];
            } elseif (($user === null || $password !== "") && mb_strlen($password) < QMS_USER_MIN_PASSWORD) {
                $error = sprintf($lang["ERR_PASSWORD"], QMS_USER_MIN_PASSWORD);
            } elseif ($dept === null || $dept->isDeleted() || $pos === null || $pos->isDeleted()) {
                $error = $lang["ERR_ASSIGNMENT"];
            } elseif ($pos->getDepartment() !== null && $pos->getDepartment()->getDepartmentId() !== $deptId) {
                $error = $lang["ERR_POSITION_DEPARTMENT"];
            } elseif ($locId > 0 && ($loc === null || $loc->isDeleted())) {
                $error = $lang["ERR_ASSIGNMENT"];
            } elseif ($assignChanged && (!$dept->isActive() || !$pos->isActive() || ($loc !== null && !$loc->isActive()))) {
                $error = $lang["ERR_ASSIGNMENT_PASSIVE"];
            } elseif ($assignChanged && $assignDate === null) {
                $error = $lang["ERR_ASSIGN_DATE"];
            } elseif ($assignChanged && $current !== null && $assignDate <= $current->getValidFrom()) {
                $error = sprintf($lang["ERR_ASSIGN_DATE_BEFORE"], $current->getValidFrom()->format("d.m.Y"));
            } elseif (count($roles) !== count($roleCodes) || count(array_filter($roles, fn (Role $r) => $r->getAppCode() !== "biolab-qms")) > 0) {
                $error = "Geçersiz rol.";
            } elseif ($user !== null && $user->getUserId() === $sessionUserId && ($activeFl === "N" || !in_array("QMS_ADMIN", $roleCodes, true))) {
                $error = $lang["ERR_SELF"];
            } elseif ($user !== null && $activeFl === "N" && $user->isActive() && ($pending = qmsUserPendingSteps($db, $user)) > 0) {
                $error = sprintf($lang["ERR_PENDING_STEPS"], $pending);
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
            } else {
                $db->wrapInTransaction(function () use ($db, &$user, $current, $assignChanged, $companyId, $username, $fullName, $title, $registryNo,
                                                         $email, $phone, $password, $activeFl, $dept, $pos, $loc, $assignDate, $reason, $roles, $lang, &$resMsg) {
                    $isNew  = $user === null;
                    $before = $isNew ? null : qmsUserData($db, $user, $companyId, false);
                    $company = $db->getReference(Company::class, $companyId);

                    if ($isNew) {
                        // C_BY, C_AT QmsAuditListener ile dolar
                        $user = new AppUser($username, $fullName, $password);
                        $db->persist($user);
                        $db->persist(new UserCompany($user, $company, true));
                        $resMsg = $lang["RECORD_IS_ADDED"];
                    } else {
                        $user->setFullName($fullName);
                        if ($password !== "") {
                            $user->changePassword($password);
                        }
                        $resMsg = $lang["RECORD_IS_CHANGED"];
                    }
                    $user->setTitle($title)->setRegistryNo($registryNo)->setEmail($email)->setPhone($phone);
                    $user->setActive($activeFl === "Y");
                    $db->flush();

                    // Görev: değiştiyse güncel görev kapanır, yenisi açılır
                    if ($assignChanged) {
                        $current?->close($assignDate);
                        $db->persist(new UserAssignment($user, $dept, $pos, $loc, $assignDate, $reason !== "" ? $reason : null));
                    }

                    // Roller (bu şirkette): istenen aktif, diğerleri pasif; olmayan eklenir
                    $wanted = array();
                    foreach ($roles as $r) {
                        $wanted[$r->getRoleId()] = $r;
                    }
                    foreach ($db->getRepository(UserRole::class)->findBy(array("user" => $user, "company" => $companyId)) as $ur) {
                        $rid = $ur->getRole()->getRoleId();
                        $ur->setActive(isset($wanted[$rid]));
                        unset($wanted[$rid]);
                    }
                    foreach ($wanted as $r) {
                        $db->persist(new UserRole($user, $r, $company));
                    }
                    $db->flush();

                    $after = qmsUserData($db, $user, $companyId, false);
                    if ($password !== "" && !$isNew) {
                        $after["passwordChanged"] = true;
                    }
                    AuditLogger::log($db, "mng_user", $user->getUserId(), $isNew ? "CREATE" : "UPDATE",
                        $isNew ? $after : array("before" => $before, "after" => $after));
                });

                $resObject = array("USER_ID" => $user->getUserId());
                $resCode = 0;
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

// Kullanıcı yalnızca oturumdaki şirkete bağlıysa ve silinmemişse bulunur
function qmsUserFind($db, int $id, int $companyId): ?AppUser
{
    if ($id <= 0) {
        return null;
    }
    $user = $db->getRepository(AppUser::class)->find($id);
    if ($user === null || $user->isDeleted()) {
        return null;
    }
    $link = $db->getRepository(UserCompany::class)->findOneBy(array("user" => $user, "company" => $companyId));
    return ($link === null || $link->isDeleted()) ? null : $user;
}

function qmsUserCurrentAssignment($db, AppUser $user, int $companyId): ?UserAssignment
{
    return $db->getRepository(UserAssignment::class)->findOneBy(array("user" => $user, "company" => $companyId, "validTo" => null));
}

// Sicil no bütün kullanıcılarda tekil (veritabanında UNIQUE)
function qmsUserRegistryTaken($db, string $registryNo, ?AppUser $self): bool
{
    $other = $db->getRepository(AppUser::class)->findOneBy(array("registryNo" => $registryNo));
    return $other !== null && $other !== $self;
}

// Kullanıcıyı bekleyen (henüz imzalanmamış) imza adımları; varsa pasife alınamaz, önce devredilmeli
function qmsUserPendingSteps($db, AppUser $user): int
{
    return (int) $db->createQuery("SELECT COUNT(s.signStepId) FROM QMS\SIGN\SignStep s WHERE s.user = :USER AND s.stepStatus IN ('WAITING', 'ACTIVE')")
        ->setParameter("USER", $user)->getSingleScalarResult();
}

/**
 * Formu dolduran ve denetim kaydına yazılan veri. $withHistory: görev geçmişi de eklenir (yalnızca ekran için).
 */
function qmsUserData($db, AppUser $user, int $companyId, bool $withHistory): array
{
    $a = qmsUserCurrentAssignment($db, $user, $companyId);
    $roles = array_column($db->createQuery("
        SELECT r.roleCode FROM QMS\MNG\UserRole ur JOIN ur.role r
         WHERE ur.user = :USER AND IDENTITY(ur.company) = :COMPANY_ID AND ur.activeFl = 'Y' AND ur.recordFl > 0 ORDER BY r.roleId")
        ->setParameters(array("USER" => $user, "COMPANY_ID" => $companyId))->getArrayResult(), "roleCode");

    $data = array(
        "USER_ID"       => $user->getUserId(),
        "USERNAME"      => $user->getUsername(),
        "FULL_NAME"     => $user->getFullName(),
        "TITLE"         => $user->getTitle(),
        "REGISTRY_NO"   => $user->getRegistryNo(),
        "EMAIL"         => $user->getEmail(),
        "PHONE"         => $user->getPhone(),
        "ACTIVE_FL"     => $user->isActive() ? "Y" : "N",
        "DEPARTMENT_ID" => $a?->getDepartment()->getDepartmentId(),
        "POSITION_ID"   => $a?->getPosition()->getPositionId(),
        "LOCATION_ID"   => $a?->getLocation()?->getLocationId(),
        "ASSIGN_DATE"   => $a?->getValidFrom()->format("Y-m-d"),
        "ROLES"         => $roles,
    );
    if ($withHistory) {
        $data["HISTORY"] = array_map(fn ($h) => array(
            "validFrom"      => $h["validFrom"]->format("d.m.Y"),
            "validTo"        => $h["validTo"]?->format("d.m.Y"),
            "departmentName" => $h["departmentName"],
            "positionName"   => $h["positionName"],
            "locationName"   => $h["locationName"],
            "reason"         => $h["reason"],
        ), $db->createQuery("
            SELECT a.validFrom, a.validTo, d.departmentName, p.positionName, l.locationName, a.reason
              FROM QMS\ORG\UserAssignment a JOIN a.department d JOIN a.position p LEFT JOIN a.location l
             WHERE a.user = :USER AND IDENTITY(a.company) = :COMPANY_ID AND a.recordFl > 0
             ORDER BY a.validFrom DESC, a.assignmentId DESC")
            ->setParameters(array("USER" => $user, "COMPANY_ID" => $companyId))->getArrayResult());
    }
    return $data;
}
