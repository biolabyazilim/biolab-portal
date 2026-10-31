<?php
/**
 * Created by PhpStorm.
 * User: ferhat
 * Date: 3.03.2017
 * Time: 23:55
 */


//global $db, $query, $dateFormat, $dateTimeFormat;
//use DSYS\PCR\Patient;
//use DSYS\GNR\Education;

global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat, $resourceFile;

use Doctrine\ORM\Tools\Pagination\Paginator;
use DSYS\PCR\File;


$resourceFile = "client/clientSearch-1.0.0.json";

$db = $dbm->getDB();
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PUBLIC;
checkAuthorization();

switch ($query){


    case "mainClientSearch":
        $keywords = $_POST["clientKeywords"];
        $activeDate = "";
        if($_POST["ACTIVE_DATE"] == NULL || $_POST["ACTIVE_DATE"] == ""){
            $activeDate = getD(date("Y-m-d"), "Y-m-d");
        }else{
            $activeDate = getD($_POST["ACTIVE_DATE"], $dateFormat);
        }

        $activeDate->add(new DateInterval('P10D'));
        switch($_POST["clientSearchSearchGroupType"]){
            case 2:
                $dqlFilter = "
                AND p.patientId IN (
                    SELECT IDENTITY(dt.patient)
                      FROM DSYS\PCR\DailyTreatment dt
                     WHERE DATE_DIFF(dt.dailyTreatmentDate,:ACTIVE_DATE) = 0
                   AND dt.activeFl = 'Y'
                   AND dt.recordFl > 0    
                   )
                
                ";
            break;
            case 0:
                $dqlFilter = "
                 and :ACTIVE_DATE = :ACTIVE_DATE
                ";
            break;
            case 1:
            default:
                $dqlFilter = "
                AND p.patientId IN (
                    SELECT IDENTITY(ps.patient)
                      FROM DSYS\PCR\PatientStatus ps
                     WHERE ps.companyId = :COMPANY_ID
                       AND (ps.startDate <= :ACTIVE_DATE or ps.startDate is NULL) 
                       AND (ps.endDate >= :ACTIVE_DATE or ps.endDate is NULL)
                           AND ps.activeFl = 'Y'
                           AND ps.recordFl > 0
                   )
                ";
            break;
        }

     /*   $dqlFilter .= "
           OR p.patientId IN (
                SELECT pd.patientId
                  FROM DSYS\PCR\Patient pd
                 WHERE pd.patientId NOT IN (
                    SELECT IDENTITY(psd.patient)
                      FROM DSYS\PCR\PatientStatus psd
                     WHERE psd.companyId = :COMPANY_ID
                 ) AND pd.companyId = :COMPANY_ID
                 AND (
                 pd.patientName like :KEYWORD
                 or pd.patientSurname like :KEYWORD
                 or pd.patientNumber like :KEYWORD
                 )
            )
        ";*/
        $dqlFilter .= "
           
        ";

        $dql = "
            SELECT COUNT(p) TOTAL_RECORD
              FROM DSYS\PCR\Patient p
              LEFT JOIN p.gender g
             WHERE (p.patientName like :KEYWORD  
                or p.patientSurname like :KEYWORD 
                or p.patientNumber like :KEYWORD
                or p.hmcNumber like :KEYWORD
                 ) and p.companyId = :COMPANY_ID
                   AND p.activeFl = 'Y'
                   AND p.recordFl > 0    
        ".$dqlFilter;
        $dtResponse = $db->createQuery($dql)
            ->setParameter("KEYWORD", "%".$keywords."%")
//            ->setParameter("PATIENTSURNAME", "%".$keywords."%")
            ->setParameter("COMPANY_ID", $_SESSION["COMPANY_ID"])
            ->setParameter("ACTIVE_DATE", $activeDate)
            ->getArrayResult();
        $totalPage = $dtResponse[0]['TOTAL_RECORD'];
        $resObject = array(
            "totalRecord"=>$totalPage,
            "page"=>ceil($totalPage/$_POST["limit"])
        );
        $dql = "
            SELECT p.patientId,
                   p.patientName,
                   p.patientSurname,
                   p.patientNumber,
                   g.genderAbr,
                   p.hbsagPosFl,
                   p.hcvPosFl,
                   p.diabetesPosFl,
                   p.hivPosFl,
                   p.hmcNumber,
                   DATE_DIFF(CURRENT_DATE(), p.birthDate)/365 age,
                   
                   CONCAT(CONCAT(f.fileNameOnDisk,'.'), f.fileExtension) as avatarFilePath
              FROM DSYS\PCR\Patient p
              LEFT JOIN p.gender g
              LEFT JOIN DSYS\PCR\File f WITH (f.fileTablePkId = p.patientId AND f.companyId = :COMPANY_ID AND f.fileTable = 'patient' AND f.activeFl = 'Y' AND f.recordFl > 0)
             WHERE (p.patientName like :KEYWORD  
                or p.patientSurname like :KEYWORD 
                or p.patientNumber like :KEYWORD
                or p.hmcNumber like :KEYWORD) and p.companyId = :COMPANY_ID 
                   AND p.activeFl = 'Y'
                   AND p.recordFl > 0    
                   
        ".$dqlFilter. " ORDER BY p.patientId DESC";

        $dtResponse = $db->createQuery($dql)
            ->setParameter("KEYWORD", "%".$keywords."%")
//            ->setParameter("PATIENTSURNAME", "%".$keywords."%")
            ->setParameter("COMPANY_ID", $_SESSION["COMPANY_ID"])
//            ->setParameter("COMPANY_ID_DETAIL", $_SESSION["COMPANY_ID"])
            ->setParameter("ACTIVE_DATE", $activeDate)
            ->setFirstResult(($_POST["page"]-1)*$_POST["limit"])
            ->setMaxResults($_POST["limit"])
            ->getArrayResult();

        /*(
        SELECT COUNT(pa)
                      FROM DSYS\PCR\PatientAllergy pa
                     WHERE pa.patient = p.patientId
    AND pa.companyId = :COMPANY_ID_DETAIL
                   ) allergy,*/
//        $rsResponse = $db->Execute($sql);
        $resCode = 0;
        $resMsg  = "OK";

        break;
    case "getGridListPatient":
        try{
            $execType = ET_JQGRID;
            $keywords = $_POST["Q"];
            $activeDate = "";
            if($_POST["ACTIVE_DATE"] == NULL || $_POST["ACTIVE_DATE"] == ""){
                $activeDate = getD(date("Y-m-d"), "Y-m-d");
            }else{
                $activeDate = getD($_POST["ACTIVE_DATE"], $dateFormat);
            }
//        var_dump($activeDate);
            switch($_POST["T"]){
                case 2:
                    $dqlFilter = "
                AND p.patientId IN (
                    SELECT IDENTITY(dt.patient)
                      FROM DSYS\PCR\DailyTreatment dt
                     WHERE DATE_DIFF(dt.dailyTreatmentDate,:ACTIVE_DATE) = 0
                   AND dt.activeFl = 'Y'
                   AND dt.recordFl > 0    
                   )
                
                ";
                    break;
                case 0:
                    $dqlFilter = "
                 and :ACTIVE_DATE = :ACTIVE_DATE
                ";
                    break;
                case 1:
                default:
                    $dqlFilter = "
                AND p.patientId IN (
                    SELECT IDENTITY(ps.patient)
                      FROM DSYS\PCR\PatientStatus ps
                     WHERE ps.companyId = :COMPANY_ID
                       AND ps.startDate <= :ACTIVE_DATE
                       AND (ps.endDate >= :ACTIVE_DATE or ps.endDate is NULL)
                           AND ps.activeFl = 'Y'
                           AND ps.recordFl > 0
                   )
                ";
                    break;
            }
            $dql = "
            SELECT COUNT(p) TOTAL_RECORD
              FROM DSYS\PCR\Patient p
              LEFT JOIN p.gender g
             WHERE (p.patientName like :KEYWORD  or p.patientSurname like :KEYWORD or p.patientNumber like :KEYWORD) and p.companyId = :COMPANY_ID
                   AND p.activeFl = 'Y'
                   AND p.recordFl > 0    
        ".$dqlFilter;
            $dtResponse = $db->createQuery($dql)
                ->setParameter("KEYWORD", "%".$keywords."%")
                ->setParameter("COMPANY_ID", $_SESSION["COMPANY_ID"])
                ->setParameter("ACTIVE_DATE", $activeDate)
                ->getArrayResult();
            $totalRecords = $dtResponse[0]['TOTAL_RECORD'];

            $dql = "
            SELECT p.patientId,
                   p.patientName,
                   p.patientSurname,
                   p.patientNumber,
                   g.genderAbr,
                   p.hbsagPosFl,
                   p.hcvPosFl,
                   p.diabetesPosFl,
                   p.birthDate,
                   'N' hivPosFl,
                   DATE_DIFF(CURRENT_DATE(), p.birthDate)/365 age,
                   (
                    SELECT COUNT(pa)
                      FROM DSYS\PCR\PatientAllergy pa
                     WHERE pa.patient = p.patientId 
                       AND pa.companyId = :COMPANY_ID_DETAIL
                   ) allergy
              FROM DSYS\PCR\Patient p
              LEFT JOIN p.gender g
             WHERE (p.patientName like :KEYWORD  or p.patientSurname like :KEYWORD or p.patientNumber like :KEYWORD) and p.companyId = :COMPANY_ID 
                   AND p.activeFl = 'Y'
                   AND p.recordFl > 0    
        ".$dqlFilter;

            $sortBy = $_POST["sidx"];
            $direction = $_POST["sord"];
            if(strlen($direction) == 0){
                $direction = "ASC";
            }
            $dql.= " order by ".$sortBy." ".$direction;

            $query = $db->createQuery($dql)
                ->setParameter("KEYWORD", "%".$keywords."%")
                ->setParameter("COMPANY_ID", $_SESSION["COMPANY_ID"])
                ->setParameter("COMPANY_ID_DETAIL", $_SESSION["COMPANY_ID"])
                ->setParameter("ACTIVE_DATE", $activeDate)
                ->setFirstResult(($_POST["page"]-1)*$_POST["rows"])
                ->setMaxResults($_POST["rows"]);

//            $paginator = new Paginator($query, $fetchJoinCollection = false);
//
//            $totalRecords = count($paginator);
            $dtResponse = $query
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
}
