<?php
/**
 * Biolab Yönetim — Şirket ve şube tanımlama (mng_company, entity: BIO\MNG\Company)
 *
 * Alanlar: COMPANY_ID, CODE, COMPANY_NAME, TAX_NUMBER, LANGUAGE_ID, COMPANY_TYPE_ID, PARENT_COMPANY_ID, ACTIVE_FL
 * Kurallar: Şube tipindeki şirketin üst şirketi zorunludur ve Merkez tipinde olmalıdır; Merkez'in üst şirketi olmaz.
 */
global $resourceFile;
use BIO\MNG\Company;
use BIO\MNG\CompanyType;
use BIO\MNG\Language;

$resourceFile = "company/company-1.0.0.json";

global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat;
$db = $dbm ->getDB();

$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

switch ($query){

    case "getLANGUAGE_ID":
        try{
            $execType = ET_DEFAULT;
            $dtResponse = $db->createQuery("
                SELECT l.languageId,
                       l.name,
                       l.activeFl
                  FROM BIO\MNG\Language l
                 WHERE l.recordFl > 0
                 ORDER BY l.name")
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

    case "getCOMPANY_TYPE_ID":
        try{
            $execType = ET_DEFAULT;
            $dtResponse = $db->createQuery("
                SELECT ct.companyTypeId,
                       ct.code,
                       ct.companyTypeName,
                       ct.activeFl
                  FROM BIO\MNG\CompanyType ct
                 WHERE ct.recordFl > 0
                 ORDER BY ct.companyTypeId")
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

    case "getPARENT_COMPANY_ID":
        try{
            $execType = ET_DEFAULT;
            // Üst şirket yalnızca şube olmayan (Merkez) şirketlerden seçilir
            $dtResponse = $db->createQuery("
                SELECT pat.companyId,
                       pat.companyName,
                       pat.activeFl
                  FROM BIO\MNG\Company pat
                  LEFT JOIN pat.companyType ct
                 WHERE pat.recordFl > 0
                   AND (ct.code IS NULL OR ct.code <> :BRANCH)
                 ORDER BY pat.companyName")
                ->setParameter("BRANCH", CompanyType::BRANCH)
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

    case "getGridListCompany":
        $execType = ET_JQGRID;

        $from = "
              FROM BIO\MNG\Company mt
              JOIN mt.language l
              LEFT JOIN mt.companyType ct
              LEFT JOIN mt.parentCompany pat
             WHERE (mt.code LIKE :Q OR mt.companyName LIKE :Q OR mt.taxNumber LIKE :Q OR pat.companyName LIKE :Q OR ct.companyTypeName LIKE :Q)
               AND mt.recordFl > 0
        ";
        $q = "%" . ($_POST["Q"] ?? "") . "%";

        $totalRecords = $db->createQuery("SELECT COUNT(mt) " . $from)
            ->setParameter("Q", $q)
            ->getSingleScalarResult();

        // Sıralama: yalnızca izin verilen alanlar (DQL injection'a karşı)
        $sortBy    = in_array($_POST["sidx"], ["mt.companyId", "mt.code", "mt.companyName", "ct.companyTypeName", "pat.companyName", "l.name", "mt.taxNumber", "mt.activeFl"]) ? $_POST["sidx"] : "mt.companyId";
        $direction = $_POST["sord"] == "desc" ? "DESC" : "ASC";

        $dtResponse = $db->createQuery("
            SELECT mt.companyId        mt_companyId,
                   mt.code             mt_code,
                   mt.companyName      mt_companyName,
                   ct.companyTypeName  ct_companyTypeName,
                   pat.companyName     pat_companyName,
                   l.name              l_name,
                   mt.taxNumber        mt_taxNumber,
                   mt.activeFl         mt_activeFl
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

    case "getSelectedIdCompany":
        try{
            $dtResponse = $db->createQuery("
                SELECT mt.companyId      mt_companyId,
                       mt.code           mt_code,
                       mt.companyName    mt_companyName,
                       mt.taxNumber      mt_taxNumber,
                       l.languageId      l_languageId,
                       ct.companyTypeId  ct_companyTypeId,
                       pat.companyId     pat_companyId,
                       mt.activeFl       mt_activeFl
                  FROM BIO\MNG\Company mt
                  JOIN mt.language l
                  LEFT JOIN mt.companyType ct
                  LEFT JOIN mt.parentCompany pat
                 WHERE mt.companyId = :COMPANY_ID
                   AND mt.recordFl > 0")
                ->setParameter("COMPANY_ID", (int) $_POST["SELECTED_ID"])
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

    case "setDeleteCompany":
        $execType = ET_DELETE;
        try{
            $rs = $db->find(Company::class, (int) ($_POST["COMPANY_ID"] ?? 0));

            if ($rs !== null && $rs->getCompanyId() === (int) $_SESSION["COMPANY_ID"]) {
                $resCode = 22;
                $resMsg = "Oturum açtığınız şirketi silemezsiniz.";
                break;
            }
            if ($rs !== null && companyBranchCount($db, $rs) > 0) {
                $resCode = 22;
                $resMsg = "Bu şirketin şubeleri var. Önce şubeleri silin ya da başka bir şirkete bağlayın.";
                break;
            }
            if ($rs !== null && companyUserCount($db, $rs) > 0) {
                $resCode = 22;
                $resMsg = "Bu şirkete bağlı kullanıcılar var. Önce Kullanıcı Şirket Tanımlama ekranından bağları kaldırın.";
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

    case "setAddCompany":
        $_POST["COMPANY_ID"] = "";      // ekleme her zaman yeni kayıt açar
        // no break: kayıt kuralları setSaveCompany'de

    case "setSaveCompany":
        $execType = ET_SAVE;
        try{
            $companyId   = (int) ($_POST["COMPANY_ID"] ?? 0);
            $code        = trim($_POST["CODE"] ?? "");
            $companyName = trim($_POST["COMPANY_NAME"] ?? "");
            $taxNumber   = trim($_POST["TAX_NUMBER"] ?? "");
            $parentId    = (int) ($_POST["PARENT_COMPANY_ID"] ?? 0);

            $rs       = $companyId > 0 ? $db->find(Company::class, $companyId) : null;
            $language = $db->find(Language::class, (int) ($_POST["LANGUAGE_ID"] ?? 0));
            $type     = $db->find(CompanyType::class, (int) ($_POST["COMPANY_TYPE_ID"] ?? 0));
            $parent   = $parentId > 0 ? $db->find(Company::class, $parentId) : null;

            // Kontroller (mesaj noty ile gösterilir)
            if ($code === "" || $companyName === "" || $language === null || $type === null) {
                $resCode = 22;
                $resMsg = "Şirket kodu, şirket adı, dil ve şirket tipi zorunludur.";
                break;
            }
            $sameCode = $db->getRepository(Company::class)->findOneBy(["code" => $code]);
            if ($sameCode !== null && $sameCode->getCompanyId() !== $companyId) {
                $resCode = 22;
                $resMsg = "Bu şirket kodu başka bir şirkette kullanılıyor: " . $sameCode->getCompanyName();
                break;
            }
            if ($type->isBranch()) {
                if ($parent === null) {
                    $resCode = 22;
                    $resMsg = "Şube için üst şirket seçilmelidir.";
                    break;
                }
                if ($rs !== null && $parent->getCompanyId() === $rs->getCompanyId()) {
                    $resCode = 22;
                    $resMsg = "Bir şirket kendi altına bağlanamaz.";
                    break;
                }
                if ($parent->getCompanyType()?->isBranch()) {
                    $resCode = 22;
                    $resMsg = "Üst şirket bir şube olamaz.";
                    break;
                }
                if ($rs !== null && companyBranchCount($db, $rs) > 0) {
                    $resCode = 22;
                    $resMsg = "Bu şirketin şubeleri var; şube tipine çevrilemez.";
                    break;
                }
            } else {
                $parent = null;     // Merkez'in üst şirketi olmaz
            }

            if ($rs !== null) {
                // Güncelle (U_BY / U_AT flush'ta otomatik dolar)
                $rs->setCode($code);
                $rs->setCompanyName($companyName);
                $rs->setLanguage($language);
                $resMsg = $lang["RECORD_IS_CHANGED"];
            } else {
                // Yeni kayıt (C_BY / C_AT flush'ta otomatik dolar)
                $rs = new Company($code, $companyName, $language);
                $db->persist($rs);
                $resMsg = $lang["RECORD_IS_ADDED"];
            }
            // İsteğe bağlı alanlar: setter ile
            $rs->setTaxNumber($taxNumber !== "" ? $taxNumber : null);
            $rs->setCompanyType($type);
            $rs->setParentCompany($parent);
            $rs->setActive(($_POST["ACTIVE_FL"] ?? 'Y') === 'Y');

            $db->flush();
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

// Şirketin silinmemiş şube sayısı
function companyBranchCount($db, Company $company): int
{
    return (int) $db->createQuery("
        SELECT COUNT(c) FROM BIO\MNG\Company c
         WHERE c.parentCompany = :COMPANY AND c.recordFl > 0")
        ->setParameter("COMPANY", $company)
        ->getSingleScalarResult();
}

// Şirkete bağlı silinmemiş kullanıcı sayısı (mng_user_company)
function companyUserCount($db, Company $company): int
{
    return (int) $db->createQuery("
        SELECT COUNT(uc) FROM BIO\MNG\UserCompany uc
         WHERE uc.company = :COMPANY AND uc.recordFl > 0")
        ->setParameter("COMPANY", $company)
        ->getSingleScalarResult();
}
