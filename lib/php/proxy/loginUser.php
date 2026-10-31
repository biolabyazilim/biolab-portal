<?php
/**
 * Created by PhpStorm.
 * User: ferhat
 * Date: 28.02.2017
 * Time: 23:09
 */
//global $db, $dbm, $query, $qr, $dateFormat, $dateTimeFormat, $lang;
global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat, $resourceFile;

use Carbon\Carbon;
use DSYS\MNG\UserLogin;
use DSYS\MNG\UserLoginLog;



$companyCode = "MNG_000_DMED";

$dbm = new DBManager($companyCode);
if($dbm->connected) {
    $db = $dbm->getDB();
}
safePOSTData();
switch ($query){

    case "getFormPath":

        $lm->loadLanguageResource();
        $lang = $lm->lang;

        try{
            $execType = ET_DEFAULT;
            $dql = "
                select f
                  from DSYS\MNG\Form f
                 where f.activeFl = 'Y'
                   and f.recordFl > 0
                   and f.formNameUnique = :FORM_NAME
            ";
            $dtResponse = $db->createQuery($dql)
                ->setParameter("FORM_NAME",$_POST["formName"])
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

    case "getDashboardFormPath":

        $lm->loadLanguageResource();
        $lang = $lm->lang;

        try{
            $execType = ET_DEFAULT;
            $formId = NULL;
            $dql = "
                select u.firstPageId
                  from DSYS\MNG\UserCompany u
                 where u.user = :USER_ID 
            ";
            $dtUser = $db->createQuery($dql)
            ->setParameter("USER_ID", $_SESSION["USER_ID"])
            ->getArrayResult();

            if(count($dtUser) > 0){
                $formId = $dtUser[0]['firstPageId'];
            }


            $dql = "
                select f
                  from DSYS\MNG\Form f
                 where f.activeFl = 'Y'
                   and f.recordFl > 0
                   and f.formId = :FORM_ID
            ";
            $dtResponse = $db->createQuery($dql)
                ->setParameter("FORM_ID",$formId)
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
    case "menuList":
        try{
            $execType = ET_CUSTOM;

            //for MySQL 8
            /*$sql = "
                WITH RECURSIVE menus AS
                (
                SELECT m.*, ".getTRValue("mng_menu","FORM_NAME", "m.FORM_ID")." 'AFORM_NAME', CAST(MENU_ID AS CHAR(500)) AS path_menu, 1 level
                  FROM mng_menu m
                 WHERE m.PARENT_MENU_ID is NULL
                 UNION ALL
                SELECT m.*, ".getTRValue("mng_menu","FORM_NAME", "m.FORM_ID")." 'AFORM_NAME', CONCAT(ms.path_menu, ',', m.MENU_ID), level+1
                  FROM menus ms, mng_menu m
                 WHERE m.PARENT_MENU_ID = ms.MENU_ID
                )
                SELECT * FROM menus ORDER BY path_menu
            ";*/

//            $menuArray = array();
//            $dtResponse = recursiveMenu(NULL);

            $dql = "
                SELECT m,
                       f
                  FROM DSYS\MNG\RoleForm rf,
                       DSYS\MNG\Role r,
                       DSYS\MNG\UserRole ur,
                       DSYS\MNG\User u,
                       DSYS\MNG\UserCompany uc,
                       DSYS\MNG\Company c,
                       DSYS\MNG\Menu m,
                       DSYS\MNG\Form f
                 WHERE rf.role = r.roleId
                   AND r.roleId = ur.role
                   AND ur.company = c.companyId
                   AND uc.company = c.companyId
                   AND uc.user = u.userId
                   AND ur.user = u.userId
                   AND m.menuId = rf.form
                   AND f.formId = rf.form
                   AND ur.user = :USER_ID
                   AND ur.company = :COMPANY_ID
                   AND u.activeFl = 'Y'
                   AND u.recordFl > 0
                   AND uc.activeFl = 'Y'
                   AND uc.recordFl > 0
                   AND c.activeFl = 'Y'
                   AND c.recordFl > 0
                   AND rf.activeFl = 'Y'
                   AND rf.recordFl > 0
                   AND r.activeFl = 'Y'
                   AND r.recordFl > 0
                   AND ur.activeFl = 'Y'
                   AND ur.recordFl > 0      
            ";


            $dql = "
                SELECT  m, f as form, pm as parentMenu
                  FROM DSYS\MNG\Menu m
                  LEFT JOIN m.form f
                  LEFT JOIN m.parentMenu pm
                 WHERE m.menuId in (
                        SELECT mq.menuId
                          FROM DSYS\MNG\UserRole ur,
                               DSYS\MNG\RoleForm rf,
                               DSYS\MNG\Menu mq
                         WHERE ur.activeFl = 'Y'
                           AND ur.recordFl > 0
                           AND rf.activeFl = 'Y'
                           AND rf.recordFl > 0
                           AND mq.activeFl = 'Y'
                           AND mq.recordFl > 0
                           AND rf.role = ur.role
                           AND mq.form = rf.form
                           AND ur.user = :USER_ID
                           AND ur.company = :COMPANY_ID                           
                      ) 
                   AND m.activeFl = 'Y'
                   AND m.recordFl > 0
                   AND f.activeFl = 'Y'
                   AND f.recordFl > 0
                 ORDER BY pm.menuGroup, pm.menuOrder, m.menuGroup , 
                          m.menuOrder 
            ";


//            group by mn.menuId

            $dtResponse = $db->createQuery($dql)
                ->setParameter("USER_ID",$_SESSION["USER_ID_PARENT"])
                ->setParameter("COMPANY_ID",$_SESSION["COMPANY_ID"])
                ->getArrayResult();

            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
    case "checkLoginTimer":
        $execType = ET_CUSTOM;
        $sessionType = WITHOUT_SESSION;
        $resCode = 0;
        $resMsg  = "OK";
        break;
    case "userLogout":
        try{
            $execType = ET_CUSTOM;

            // $resCode = 0;
            // $resMsg  = "Logout";

            //session_cache_expire(SESSION_TIME_OUT);
            session_start(); // NEVER FORGET TO START THE SESSION!!!
            $_SESSION["COMPANY_ID"] = NULL;
            $_SESSION["COMPANY_NAME"] = NULL;
            $_SESSION["USER_ID"] = NULL;
            $_SESSION["USER_ID_PARENT"] = NULL;
            $_SESSION["USERNAME"] = NULL;
            $_SESSION["PERSON_NAME"] = NULL;
            $_SESSION["PERSON_SURNAME"] = NULL;
            $_SESSION['COMPANY_CODE'] = NULL;
            $_SESSION['COMPANY_DB_CODE'] = NULL;
            $_SESSION['SESSION_CODE'] = NULL;
            $_SESSION['SESSION_ID'] = NULL;
            $_SESSION['LOGIN_START_TIME'] = NULL;//date("d.m.Y H:i:s");
            $_SESSION['LOGIN_END_TIME'] = NULL;//date("d.m.Y H:i:s", mktime(date("H"),date("i")+60,date("s"),date("m"),date("d"),date("Y")));
            $_SESSION['IP_ADDRESS'] = NULL;
            $_SESSION['IS_OWNER'] = NULL;
            $_SESSION['USER_TITLE_ID'] = NULL;

            $_SESSION["lang"] = NULL;
            $_SESSION["langCultureInfo"] = NULL;


            $_SESSION['LANGUAGE_ID'] = NULL;

            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
    case "checkLogin":
        try{
            if(!isset($_SESSION["COMPANY_DB_CODE"])) $_SESSION["COMPANY_DB_CODE"] = NULL;
            if(!isset($_SESSION["COMPANY_ID"])) $_SESSION["COMPANY_ID"] = NULL;
            if(!isset($_SESSION["COMPANY_CODE"])) $_SESSION["COMPANY_CODE"] = NULL;
            if(!isset($_SESSION["COMPANY_NAME"])) $_SESSION["COMPANY_NAME"] = NULL;
            if(!isset($_SESSION["USERNAME"])) $_SESSION["USERNAME"] = NULL;
            if(!isset($_SESSION["PERSON_NAME"])) $_SESSION["PERSON_NAME"] = NULL;
            if(!isset($_SESSION["PERSON_SURNAME"])) $_SESSION["PERSON_SURNAME"] = NULL;
            if(!isset($_SESSION["SESSION_CODE"])) $_SESSION["SESSION_CODE"] = NULL;
            if(!isset($_SESSION["USER_ID_PARENT"])) $_SESSION["USER_ID_PARENT"] = NULL;
            if(!isset($_SESSION["IS_OWNER"])) $_SESSION["IS_OWNER"] = NULL;
            if(!isset($_SESSION["lang"])) $_SESSION["lang"] = NULL;
            if(!isset($_SESSION["USER_ID_PARENT"])) $_SESSION["USER_ID_PARENT"] = NULL;
            $execType = ET_CUSTOM;

            if($_SESSION["USER_ID_PARENT"] > 0 && $_SESSION["COMPANY_DB_CODE"] != NULL){
                session_start();
                $_SESSION["USER_CHANGED"] = false;

                $resCode = 0;
                $resMsg = "Already Logged In";

                $dql = "
                    select c.companyId as COMPANY_ID,
                           c.companyName as COMPANY_NAME,
                           c.companyCode as COMPANY_CODE
                      from DSYS\MNG\Company c,
                           DSYS\MNG\UserCompany uc
                     where uc.company = c.companyId
                       and uc.user = :USER_ID
                       and uc.activeFl = 'Y'
                       and uc.recordFl > 0
                ";
                $rs = $db->createQuery($dql)
                    ->setParameter("USER_ID",$_SESSION["USER_ID_PARENT"])->getArrayResult();
                $dtResponse["COMPANIES"] = $rs;
                $dtResponse["COMPANY_DETAIL"]["FIRST_PAGE"] = false;
                $dtResponse["COMPANY_DETAIL"]["PATIENT_CLICK"] = false;
            }else{
                $resCode = 98;
                $resMsg = "Need to relogin";
            }
            // $resCode = 0;
            // $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
    case "userLogin":
        try{

            ini_set('session.gc_maxlifetime', SESSION_TIME_OUT*60);
            session_set_cookie_params(SESSION_TIME_OUT*60);

            $execType = ET_CUSTOM;
            session_start();
            $dql ="
                SELECT u.userId,
                       c.companyId,
                       c.companyName,
                       u.username,
                       u.personName,
                       u.personSurname,
                       c.companyCode,
                       c.companyDbCode,
                       c.isOwnerFl,
                       IDENTITY(uc.userTask) as userTaskId,
                       IDENTITY(u.company) as PRIMARY_COMPANY_ID,
                       IDENTITY(u.country) as countryId,
                       l.languageId,
                       l.languageCode,
                       u.formatCode,
                       u.changePasswordFirstLogin
                  FROM DSYS\MNG\User u
                  LEFT JOIN u.language l,
                       DSYS\MNG\UserCompany uc,
                       DSYS\MNG\Company c
                 where u.username = :USERNAME
                   and u.password = :PASSWORD
                   and u.userId = uc.user
                   and c.companyId = uc.company
                   and u.activeFl = 'Y'
                   and u.recordFl > 0
                   and uc.activeFl = 'Y'
                   and uc.recordFl > 0
                   and c.activeFl = 'Y'
                   and c.recordFl > 0    
            ";
            $rs = $db->createQuery($dql)
                ->setParameter("USERNAME",$_POST["loginUsername"])
                ->setParameter("PASSWORD",$_POST["loginPassword"])
                ->getArrayResult();


            $dtResponse["COMPANIES"] = array();



            $dtResponse["firstLogin"] = "N";
            $successFl = "N";
            $userLoginId = NULL;

            if(count($rs) > 0){
                $successFl = "Y";
                $resCode = 0;
                $resMsg  = "Logged In";
                $companyIndex = 0;
                foreach($rs as $dt) {
                    if($dt["primaryCompanyId"] == $dt["companyId"] || $companyIndex == 0) {
                        //Set Defualt Language and localization code
                        $_SESSION["DB_LANG_CODE"] = "en";
                        $_SESSION["lang"] = "en";
                        $_SESSION["langCultureInfo"] = "en-gb";


                        $_SESSION["COMPANY_ID"] = $dt["companyId"];
                        $_SESSION["COMPANY_NAME"] = $dt["companyName"];
                        $_SESSION["USER_ID_PARENT"] = $dt["userId"];
                        $_SESSION["USER_ID"] = $dt["userId"];
                        $_SESSION["USERNAME"] = $dt["userName"];
                        $_SESSION["PERSON_NAME"] = $dt["personName"];
                        $_SESSION["PERSON_SURNAME"] = $dt["personSurname"];
                        $_SESSION['COMPANY_CODE'] = $dt["companyCode"];
                        $_SESSION['COMPANY_DB_CODE'] = $dt["companyDbCode"];
                        $_SESSION['SESSION_CODE'] = strtoupper(md5("" . rand() . date("U") . rand() . ""));
//                        $_SESSION['SESSION_ID'] = session_id();
                        $_SESSION['LOGIN_START_TIME'] = time();//date("d.m.Y H:i:s");
                        $_SESSION['LOGIN_END_TIME'] = time() + (SESSION_TIME_OUT * 60);//date("d.m.Y H:i:s", mktime(date("H"),date("i")+60,date("s"),date("m"),date("d"),date("Y")));
                        $_SESSION['IP_ADDRESS'] = $_SERVER['REMOTE_ADDR'];
                        if($dt["IS_OWNER_FL"] == 'Y'){
                            $_SESSION['IS_OWNER'] =  TRUE;
                        }else{
                            $_SESSION['IS_OWNER'] =  FALSE;
                        }

                        if(!isset($dt["USER_TITLE_ID"])){
                            $dt["USER_TITLE_ID"] = NULL;
                        }
                        $_SESSION["USER_TITLE_ID"] = $dt["userTitleId"];

                        $dql = "
                            SELECT c.countryId,
                                   c.countryCode,
                                   c.languageCode,
                                   c.formatCode,
                                   l.languageId
                              FROM DSYS\MNG\Country c
                              LEFT JOIN c.language l
                             WHERE c.countryId = :COUNTRY_ID 
                               and c.activeFl = 'Y'
                               and c.recordFl > 0
                        ";
                        $rsCountry = $db->createQuery($dql)
                            ->setParameter("COUNTRY_ID",$dt["countryId"])
                            ->getArrayResult();

                        if(count($rsCountry) > 0) {
                            $dtCountry = $rsCountry[0];
                            if(!isset($dtCountry["langCode"])){
                                $dtCountry["langCode"] = NULL;
                            }
                            $_SESSION["lang"] = $dtCountry["languageCode"];
                            $_SESSION["langCultureInfo"] = $dtCountry["formatCode"];
                            $_SESSION["LANGUAGE_ID"] = $dtCountry["languageId"];

                            if($dtCountry["languageId"] == NULL) {
                                $dql = "
                                SELECT l
                                  FROM DSYS\MNG\Language l
                                 WHERE l.languageCode = :LANGUAGE_CODE 
                                   and l.activeFl = 'Y'
                                   and l.recordFl > 0
                            ";
                                $rsLanguage = $db->createQuery($dql)
                                    ->setParameter("LANGUAGE_CODE", $dtCountry["langCode"])
                                    ->getArrayResult();

                                if (count($rsLanguage) > 0) {
                                    $dtLanguage = $rsLanguage[0];
                                    $_SESSION["LANGUAGE_ID"] = $dtLanguage["languageId"];
                                }
                            }
                        }

                        if($dt["languageId"] != NULL) {
                            $_SESSION["LANGUAGE_ID"] = $dt["languageId"];
                        }
                        if($dt["languageCode"] != NULL) {
                            $_SESSION["lang"] = $dt["languageCode"];
                        }
                        if($dt["formatCode"] != NULL) {
                            $_SESSION["langCultureInfo"] = $dt["formatCode"];
                        }

                        /*
                        if($_SESSION["lang"] != NULL) {
                            $_SESSION["lang"] = "en";
                        }
                        */


                       /* $dql = "
                                SELECT l
                                  FROM DSYS\MNG\Language l
                                 WHERE l.languageId = :LANGUAGE_ID
                                   and l.activeFl = 'Y'
                                   and l.recordFl > 0
                            ";
                        $rsLanguage = $db->createQuery($dql)
                            ->setParameter("LANGUAGE_ID",$dt["languageId"])
                            ->getArrayResult();

                        if(count($rsLanguage) > 0) {
                            $dtLanguage = $rsLanguage[0];
                            $_SESSION["lang"] = $dtLanguage["languageCode"];
                            if($dtLanguage["formatCode"] != NULL) {
                                $_SESSION["langCultureInfo"] = $dtLanguage["formatCode"];
                            }
                            $_SESSION["LANGUAGE_ID"] = $dtLanguage["languageId"];
                        }*/

                       /* if($dt["languageId"] != NULL){
                            $dql = "
                                SELECT l
                                  FROM DSYS\MNG\Language l
                                 WHERE l.languageId = :LANGUAGE_ID
                                   and l.activeFl = 'Y'
                                   and l.recordFl > 0
                            ";
                            $rsLanguage = $db->createQuery($dql)
                                ->setParameter("LANGUAGE_ID",$dt["languageId"])
                                ->getArrayResult();

                            if(count($rsLanguage) > 0) {
                                $dtLanguage = $rsLanguage[0];
                                $_SESSION["lang"] = $dtLanguage["languageCode"];
                                $_SESSION["LANGUAGE_ID"] = $dtLanguage["languageId"];
                            }

                        }*/
                    }
                    $dtResponse["COMPANIES"][] = array(
                        "COMPANY_ID" => $dt["companyId"],
                        "COMPANY_NAME" => $dt["companyName"],
                        "COMPANY_CODE" => $dt["companyCode"]
                    );
                    $companyIndex++;




                    $dtResponse["firstLogin"] = $dt["changePasswordFirstLogin"];
                }

                $dtLogin = $rs[0];
                $dql = "
                        SELECT ul
                          FROM DSYS\MNG\UserLogin ul
                         WHERE ul.company = :COMPANY_ID
                           AND ul.user = :USER_ID
                           AND ul.userLoginTime <= :LOGIN_TIME
                           AND ul.userLoginExpiredTime >= :LOGIN_TIME 
                    ";
                $rs = $db->createQuery($dql)
                    ->setParameter("COMPANY_ID",$dtLogin["companyId"])
                    ->setParameter("USER_ID",$dtLogin["userId"])
                    ->setParameter("LOGIN_TIME",Carbon::now())
                    ->getResult();

                $expiredTime = null;

                if(count($rs) == 0) {
                    $token = bin2hex(random_bytes(16));

                    try{
                        $expiredTime = Carbon::now()->addHours(24);
                        $newEntity = new UserLogin();
                        $userLoginId = $pkId = getMaxId("\DSYS\MNG\UserLogin","userLoginId");
                        $newEntity->setUserLoginId($pkId);
                        $newEntity->setUser($db->getRepository('\DSYS\MNG\User')->findBy(array("userId"=>$dtLogin["userId"]))[0]);
                        $newEntity->setCompany($db->getRepository('\DSYS\MNG\Company')->findBy(array("companyId"=>$dtLogin["companyId"]))[0]);
                        $newEntity->setUserLoginFirstTime(Carbon::now());
                        $newEntity->setUserLoginTime(Carbon::now());
                        $newEntity->setUserLoginExpiredTime($expiredTime);
                        $newEntity->setUserLoginKey($token);

                        $db->persist($newEntity);
                        $db->flush();


                    }catch(Exception $exMain){
                        $resCode = 100;//Console Log Error
                        $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
                    }
                }else{
                    $userLoginId = $rs[0]->getUserLoginId();

                    $token = $rs[0]->getUserLoginKey();
                    $expiredTime = Carbon::now()->addHours(24);
                    foreach($rs as $item=>&$value){
                        $value->setUserLoginTime(new DateTime("NOW"));
                        $value->setUserLoginExpiredTime($expiredTime);
                        $db->persist($value);
                        $db->flush();
                    }
                }
                $dtResponse["token"] = $token;
                $dtResponse["expireedTime"] = $expiredTime;
            }else{
                $resCode = 99;
                $resMsg  = "Username or Password is wrong";
            }



            try{

                if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                    $ip = $_SERVER['HTTP_CLIENT_IP'];
                } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
                } else {
                    $ip = $_SERVER['REMOTE_ADDR'];
                }

                $newEntity = new \DSYS\MNG\UserLoginLog();
                $pkId = getMaxId("\DSYS\MNG\UserLoginLog","userLoginLogId");
                $newEntity->setUserLoginLogId($pkId);
                $newEntity->setUser($db->getRepository("\DSYS\MNG\User")->findBy(array("userId"=>$_SESSION["USER_ID"]))[0]);
                $newEntity->setUserLogin($db->getRepository("\DSYS\MNG\UserLogin")->findBy(array("userLoginId"=>$userLoginId))[0]);
                $newEntity->setAttemptTime(Carbon::now());
                $newEntity->setIpAddress($ip);
                $newEntity->setLoginSuccessFl($successFl);


                $db->persist($newEntity);
                $db->flush();
            }catch(Exception $exMainLog){
                $resCode = 100;//Console Log Error
                $resMsg = $exMainLog->getCode()." Line=".$exMainLog->getLine()." " . $exMainLog->getMessage();
            }

            // $resCode = 0;
            // $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
    case "userLoginChangeCompany":
        try{

            ini_set('session.gc_maxlifetime', SESSION_TIME_OUT*60);
            session_set_cookie_params(SESSION_TIME_OUT*60);

            $execType = ET_CUSTOM;
            session_start();
            if(!isset($_SESSION["COMPANY_DB_CODE"])) $_SESSION["COMPANY_DB_CODE"] = NULL;
            if(!isset($_SESSION["COMPANY_ID"])) $_SESSION["COMPANY_ID"] = NULL;
            if(!isset($_SESSION["COMPANY_CODE"])) $_SESSION["COMPANY_CODE"] = NULL;
            if(!isset($_SESSION["COMPANY_NAME"])) $_SESSION["COMPANY_NAME"] = NULL;
            if(!isset($_SESSION["USERNAME"])) $_SESSION["USERNAME"] = NULL;
            if(!isset($_SESSION["PERSON_NAME"])) $_SESSION["PERSON_NAME"] = NULL;
            if(!isset($_SESSION["PERSON_SURNAME"])) $_SESSION["PERSON_SURNAME"] = NULL;
            if(!isset($_SESSION["SESSION_CODE"])) $_SESSION["SESSION_CODE"] = NULL;
            if(!isset($_SESSION["USER_ID_PARENT"])) $_SESSION["USER_ID_PARENT"] = NULL;
            if(!isset($_SESSION["IS_OWNER"])) $_SESSION["IS_OWNER"] = NULL;
            if(!isset($_SESSION["lang"])) $_SESSION["lang"] = NULL;
            if(!isset($_SESSION["USER_ID_PARENT"])) $_SESSION["USER_ID_PARENT"] = NULL;

            $dql = "
                SELECT u.userId,
                       c.companyId,
                       c.companyName,
                       u.username,
                       u.personName,
                       u.personSurname,
                       c.companyCode,
                       c.companyDbCode,
                       c.isOwnerFl,
                       IDENTITY(uc.userTask) as userTaskId,
                       IDENTITY(u.company) as primaryCompanyId
                  FROM DSYS\MNG\User u,
                       DSYS\MNG\UserCompany uc,
                       DSYS\MNG\Company c
                 WHERE u.userId = :USER_ID
                   and c.companyId = :COMPANY_ID
                   and u.userId = uc.user
                   and c.companyId = uc.company
                   and u.activeFl = 'Y'
                   and u.recordFl > 0
                   and uc.activeFl = 'Y'
                   and uc.recordFl > 0
                   and c.activeFl = 'Y'
                   and c.recordFl > 0
            ";
            $rs = $db->createQuery($dql)
                ->setParameter("USER_ID", $_SESSION["USER_ID_PARENT"])
                ->setParameter("COMPANY_ID", $_POST["companyId"])
                ->getScalarResult();

            $dtResponse["COMPANIES"] = array();

            if(count($rs)> 0){

                $resCode = 0;
                $resMsg  = "Logged In";
                $companyIndex = 0;
                $dt = NULL;
//                foreach($dt as $rs) {
                for($i=0; $i<count($rs); $i++){
                    $dt = $rs[$i];

                    if($dt["primaryCompanyId"] == $dt["companyId"] || $companyIndex == 0) {
                        $_SESSION["COMPANY_ID"] = $dt["companyId"];
                        $_SESSION["COMPANY_NAME"] = $dt["companyName"];
                        $_SESSION["USER_ID_PARENT"] = $dt["userId"];
                        $_SESSION["USER_ID"] = $dt["userId"];
                        $_SESSION["USERNAME"] = $dt["username"];
                        $_SESSION["PERSON_NAME"] = $dt["personName"];
                        $_SESSION["PERSON_SURNAME"] = $dt["personSurname"];
                        $_SESSION['COMPANY_CODE'] = $dt["companyCode"];
                        $_SESSION['COMPANY_DB_CODE'] = $dt["companyDbCode"];
                        $_SESSION['SESSION_CODE'] = strtoupper(md5("" . rand() . date("U") . rand() . ""));
//                        $_SESSION['SESSION_ID'] = session_id();
                        $_SESSION['LOGIN_START_TIME'] = time();//date("d.m.Y H:i:s");
                        $_SESSION['LOGIN_END_TIME'] = time() + (SESSION_TIME_OUT * 60);//date("d.m.Y H:i:s", mktime(date("H"),date("i")+60,date("s"),date("m"),date("d"),date("Y")));
                        $_SESSION['IP_ADDRESS'] = $_SERVER['REMOTE_ADDR'];
                        if($dt["isOwnerFl"] == 'Y'){
                            $_SESSION['IS_OWNER'] =  TRUE;
                        }else{
                            $_SESSION['IS_OWNER'] =  FALSE;
                        }
                        if(!isset($dt["userTaskId"])){
                            $dt["userTaskId"] = NULL;
                        }
                        $_SESSION["USER_TITLE_ID"] = $dt["userTaskId"];
                    }
                    array_push($dtResponse["COMPANIES"], array(
                        "COMPANY_ID" => $dt["companyId"],
                        "COMPANY_NAME" => $dt["companyName"],
                        "COMPANY_CODE" => $dt["companyCode"]
                    ));
                    $companyIndex++;



                }

                $dql = "
                        SELECT ul
                          FROM DSYS\MNG\UserLogin ul
                         WHERE ul.company = :COMPANY_ID
                           AND ul.user = :USER_ID
                           AND ul.userLoginTime <= :LOGIN_TIME
                           AND ul.userLoginExpiredTime >= :LOGIN_TIME 
                    ";
                $rs = $db->createQuery($dql)
                    ->setParameter("COMPANY_ID",$dt["companyId"])
                    ->setParameter("USER_ID",$dt["userId"])
                    ->setParameter("LOGIN_TIME",Carbon::now())
                    ->getResult();

                if(count($rs) == 0) {
                    $token = bin2hex(random_bytes(16));
                    try{
                        $newEntity = new UserLogin();
                        $pkId = getMaxId("\DSYS\MNG\UserLogin","userLoginId");
                        $newEntity->setUserLoginId($pkId);
                        $newEntity->setUser($db->getRepository('\DSYS\MNG\User')->findBy(array("userId"=>$dt["userId"]))[0]);
                        $newEntity->setCompany($db->getRepository('\DSYS\MNG\Company')->findBy(array("companyId"=>$dt["companyId"]))[0]);
                        $newEntity->setUserLoginFirstTime(Carbon::now());
                        $newEntity->setUserLoginTime(Carbon::now());
                        $newEntity->setUserLoginExpiredTime(Carbon::now()->addHours(24));
                        $newEntity->setUserLoginKey($token);

                        $db->persist($newEntity);
                        $db->flush();
                    }catch(Exception $exMain){
                        $resCode = 100;//Console Log Error
                        $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
                    }
                }else{
                    foreach($rs as $item=>&$value){
                        $value->setUserLoginTime(new DateTime("NOW"));
                        $value->setUserLoginExpiredTime(Carbon::now()->addHours(24));
                        $db->persist($value);
                        $db->flush();
                    }
                }

            }else{
                $resCode = 99;
                $resMsg  = "Username or Password is wrong";
            }
            // $resCode = 0;
            // $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
    case "loadResourceFile":
        $execType = ET_FILE_JSON;

        try {
            $dtResponse = "{}";
            $resourceFile = str_replace("../../../lib/","",$_POST["resourceFile"]);

            if(file_exists("../".$resourceFile)) {

                $loadedFile = file_get_contents("../".$resourceFile);

                if (strlen($loadedFile) > 0 && $loadedFile !== NULL) {
                    $dtResponse = json_decode($loadedFile, true);
                }
            }
        }catch (Exception $e){
            print_r($e);
        }

        break;

    case "forgotPassword":

        $lm->loadLanguageResource();
        $lang = $lm->lang;

        try {

            $execType = ET_CUSTOM;
            session_start();

            if(!empty($_POST["USERNAME"])) {

                $username = $_POST["USERNAME"];

                $dql = "
                        SELECT u.username , u.userId, u.userEMail
                        FROM DSYS\MNG\User u
                        WHERE u.username =:USERNAME
                        ";

                $dtResponse = $db->createQuery($dql)
                    ->setParameter("USERNAME", $_POST["USERNAME"])
                    ->getArrayResult();
                //$token = md5($email) . rand(10, 9999);
                // Send email to user with the token in a link they can click on
                //insert (store token in the password-reset database table against the user's email)
                $token = mb_substr(sha1(md5(uniqid())),0,32);
                $newEntity = new DSYS\MNG\MngToken();
                $pkId = getMaxId("DSYS\MNG\MngToken","tokenId");
                $newEntity->setTokenId($pkId);
                //print_r($db->getRepository("DSYS\MNG\User")->findBy(array("userId"=>$dtResponse[0]["userId"])));
                $newEntity->setUser($db->getRepository("DSYS\MNG\User")->findBy(["userId" => $dtResponse[0]["userId"]])[0]);
//                    $newEntity->setUser($db->getRepository("DSYS\MNG\User")->findBy(array("userId"=>$_POST["USER_ID"]))[0]);

                $newEntity->setCAt(new DateTime("NOW"));
                $newEntity->setToken($token);
                $db->persist($newEntity);
                $db->flush();

                // Create the Transport
                $transport = (new Swift_SmtpTransport('smtp.office365.com', 587, "tls"))
                    ->setUsername('talep@dmed.com.tr')
//                    ->setPassword('Tg159753');
                    ->setPassword('nwqrblrcpbhbgfgb');
                $email = $dtResponse[0]["userEMail"];
//                $reset_link = " Hi, You Can Click The Link For Reset Password <a href='http://localhost/DSYS-PC/resetLink.html?=email=".$email." &amp;token=".$token."'></a>";
                $reset_link = "Hi, You can Click the Link for Reset Password <a href='".$_SERVER["HTTP_ORIGIN"]."/module/userPassword/resetPasswordLink.html?key=" . $email . "&token=" . $token . "'>Click To Reset Your Password</a>";

                // Create the Mailer using your created Transpor
                $mailer = new Swift_Mailer($transport);

                // Create a message
                $message = (new Swift_Message('Password Reset Link'))
                    ->setContentType('text/html')
                    ->setFrom(['talep@dmed.com.tr' => 'Request MailFor Password']) // Buralar firmandan gelecek bilgilerle değişecek
//                    ->setTo(['nur.asiltas@dmed.com.tr'])
                    ->setTo([$dtResponse[0]["userEMail"]]) //Bunu farklı bir kullanıcıya mail atmasın diye devre dışı bıraktım açacağım sonra
                    ->setCharset('utf-8')
//                    ->setBody($reset_link);
                    ->setBody($reset_link, 'text/html', 'utf-8');


                // Send the message
                $result = $mailer->send($message);

                if (count($dtResponse) > 0 && $result) {
                    $resCode = 0;
                    $resMsg = "Ok";
                }elseif(!empty($dtResponse) && !$result){
                    $resCode = 21;
                    $resMsg = "Error While Sending An Email !";
                } else {
                    $resCode = 21;
                    $resMsg = "No Records Found !";
                }
            }

        }catch
        (Exception $ex){
            //print_r($ex);
            $resCode = 100;//Console Log Error
            $resMsg = $ex->getCode()." Line=".$ex->getLine()." " . $ex->getMessage();
        } break;

        /*
    case "setAdd":
        $execType = ET_SAVE;
        try{
            $newEntity = new DSYS\MNG\MngToken();
            $pkId = getMaxId("DSYS\MNG\MngToken","tokenId");
            $newEntity->setTokenId($pkId);
            $newEntity->setToken($_POST["TOKEN"]);
            $newEntity->setUser($db->getRepository("DSYS\PCR\Patient")->findBy(array("userId"=>$_POST["USER_ID"]))[0]);
//            $newEntity->setUser($db->getRepository("DSYS\MNG\User")->findBy(["user" => $dtResponse[0]["userId"]]));
            $newEntity->setCAt(getD($_POST["C_AT"], $dateFormat));


            $newEntity->setCAt(new DateTime("NOW"));

            $db->persist($newEntity);
            $db->flush();

            $resMsg = $lm->lang["RECORD_IS_ADDED"];
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
        */

    case "checkForgotLink":
        //session_start();
        try{
        $execType = ET_CUSTOM;
        $sessionType= WITHOUT_SESSION;
            //session_start();
        if(!empty($_POST)){
            if(!empty($_POST["key"]) && filter_var($_POST["key"],FILTER_VALIDATE_EMAIL)){
                if(!empty($_POST["token"]) && strlen($_POST["token"]) === 32){
                    // Strlen string uzunluğunu verir 32 32 ye eşitse giriyor if e

                    $dql = "
                        SELECT t, u.userId
                        FROM DSYS\MNG\MngToken t
                        LEFT JOIN t.user u
                        WHERE t.token =:token AND u.userEMail =:key
                        
                        ";

                    $dtResponse = $db->createQuery($dql)
                        ->setParameter("token", $_POST["token"])
                        ->setParameter("key", $_POST["key"])
                        ->getArrayResult();

                    if (count($dtResponse) > 0) {
                        $resCode = 0;
                        $resMsg = "Ok";
                    } else {
                        $resCode = 21;
                        $resMsg = "No Records Found !";
                    }

                }
            }

        }

        }catch
        (Exception $ex){
            //print_r($ex);
            $resCode = 100;//Console Log Error
            $resMsg = $ex->getCode()." Line=".$ex->getLine()." " . $ex->getMessage();
        } break;

    case "resetPassword":

//        $lm->loadLanguageResource();
//        $lang = $lm->lang;

        try {
            $execType = ET_SAVE;
            session_start();

            if(isset($_POST)){

                $dql = "
                      SELECT t, u.userId
                        FROM DSYS\MNG\MngToken t
                        LEFT JOIN t.user u
                        WHERE t.token =:token AND u.userEMail =:key
                     
                        ";

                $dtResponse = $db->createQuery($dql)
                ->setParameter("token", $_POST["token"])
                ->setParameter("key", $_POST["key"])
                ->getArrayResult();


                $rs = $db->getRepository("DSYS\MNG\User")
                    ->findBy(
                        array(
                            "userId" => $dtResponse[0]["userId"],
                            //  "company" => $_SESSION["COMPANY_ID"]
                        )
                    );


                $newPassword = strip_tags(htmlspecialchars(trim($_POST['NEW_PASSWORD'])));
                $confirmPassword = strip_tags(htmlspecialchars(trim($_POST['CONFIRM_PASSWORD'])));

                if (!empty($newPassword) && !empty($confirmPassword)) {
                    if (count($rs) > 0) {
//                    if ($_POST["$oldPassword"] == ["password"])


                        foreach ($rs as $item => &$value) {

                            $pkId = $_POST["USER_ID"];
                            $value->setUserId($dtResponse[0]["userId"]);
                            $value->setPassword($newPassword);
//                            $value->setUsername($_POST["USERNAME"]);
                            $value->setUserEMail($_POST["key"]);
//                            $value->setCompany($db->getRepository("DSYS\MNG\Company")->findBy(array("companyId" => $_SESSION["COMPANY_ID"]))[0]);
//                            $value->setUBy($_SESSION["USER_ID_PARENT"]);
//                            $value->setUAt(new DateTime("NOW"));
                            $value->setRecordFl(2);

                            $db->persist($value);
                            $db->flush();
                            $resMsg = $lm->lang["RECORD_IS_ADDED"];



                        }

                    }
                }
                if (count($dtResponse) > 0) {
                    $resCode = 0;
                    $resMsg = "Ok";
                } else {
                    $resCode = 21;
                    $resMsg = "No Records Found !";
                }

            }

        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }   break;

    case "saveUserLog":
        $execType = ET_SAVE;

        try{
            $newEntity = new \DSYS\MNG\UserLog();
            $pkId = getMaxId("DSYS\PCR\UserLog", "userLogId");

            $newEntity->setUserLogId($pkId);
            $newEntity->setUserLogin($db->getRepository("DSYS\MNG\UserLogin")->findBy(array("userLoginId" => $_POST["USER_LOGIN_ID"]))[0]);
            $newEntity->setFormMethod($db->getRepository("DSYS\MNG\FormMethod")->findBy(array("formMethodId" => $_POST["FORM_METHOD_ID"]))[0]);
            $newEntity->setForm($db->getRepository("DSYS\MNG\Form")->findBy(array("formId" => $_POST["FORM_ID"]))[0]);
            $newEntity->setUserLoginLog($db->getRepository("DSYS\MNG\UserLoginLog")->findBy(array("userLoginLogId" => $_POST["USER_LOGIN_LOG_ID"]))[0]);
            $newEntity->setUser($_SESSION["USER_ID"]);
            $newEntity->setCompany($_SESSION["COMPANY_ID"]);

//            $newEntity->setCBy($_SESSION["USER_ID_PARENT"]);
//            $newEntity->setCAt(new DateTime("NOW"));
//            $newEntity->setActiveFl("Y");
//            $newEntity->setRecordFl(1);

            $db->persist($newEntity);
            $db->flush();

            $resMsg = $lm->lang["RECORD_IS_ADDED"];

            /* $dql = "
                 SELECT tfu
                   FROM DSYS\PCR\DailyTreatmentFollowUp tfu
                  WHERE tfu.dtfuId = :PKID
             ";
             $dtResponse = $db->createQuery($dql)
                 ->setParameter("PKID", $pkId)
                 ->getArrayResult();*/
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }

        break;
}


//                            $pkId = $dtResponse[0]["userId"];
//                            //    $pkId = $_POST["USERNAME"];
//                            $value->setUserId($_POST["userId"]);
////                        $value->setUse($db->getRepository("DSYS\GNR\WorkingStatus")->findBy(array("workingStatusId" => $_POST["WORKING_STATUS_ID"]))[0]);
//                            $value->setUserEMail($_POST["key"]);
//                            $value->setPassword($newPassword);
//
//                            $value->setUBy($_SESSION["USER_ID_PARENT"]);
//                            $value->setUAt(new DateTime("NOW"));
//                            $value->setRecordFl(2);
//
//                            $db->persist($value);
//                            $db->flush();
//                            $resMsg = $lm->lang["RECORD_IS_ADDED"];

/*
function recursiveMenu($parentId){
//    global $dbm;
    global $db;
    if($parentId == NULL){
        $sql = "
(
        select mv.MENU_ID,
               #mv.MENU_NAME,
               ".getTRValue("mng_menu","MENU_NAME", "mv.MENU_ID",NULL,"mv")." ,
               fv.FORM_ID,
               fv.FORM_NAME,
               fv.FORM_PATH,
               fv.FORM_TYPE,
               substring(fv.FORM_PATH,1,locate(substring_index(fv.FORM_PATH,'/',-1),fv.FORM_PATH)-1) RELATIVE_PATH,
               mv.PARENT_MENU_ID,
               mv.MENU_GROUP,
               mv.MENU_ORDER
          from mng_menu mv
          left join mng_form fv on (fv.FORM_ID = mv.MENU_ID and fv.ACTIVE_FL = 'Y' and fv.RECORD_FL > 0 and mv.ACTIVE_FL = 'Y' and mv.RECORD_FL > 0)
         where mv.MENU_ID in (
          select m.PARENT_MENU_ID
            from mng_menu m
            left join mng_form f on ( f.ACTIVE_FL = 'Y' and f.RECORD_FL > 0 and f.FORM_ID = m.FORM_ID and m.FORM_ID in (
                    select rf.FORM_ID
                        from mng_role_form rf,
                            mng_role r,
                            mng_user_role ur,
                            mng_user u,
                            mng_user_company uc,
                            mng_company c
                        where rf.ROLE_ID = r.ROLE_ID
                        and r.ROLE_ID = ur.ROLE_ID
                        and ur.COMPANY_ID = c.COMPANY_ID
                        and uc.COMPANY_ID = c.COMPANY_ID
                        and uc.USER_ID = u.USER_ID
                        and ur.USER_ID = u.USER_ID
                        and ur.USER_ID = ?
                        and ur.COMPANY_ID = ?
                        and u.ACTIVE_FL = 'Y'
                        and u.RECORD_FL > 0
                        and uc.ACTIVE_FL = 'Y'
                        and uc.RECORD_FL > 0
                        and c.ACTIVE_FL = 'Y'
                        and c.RECORD_FL > 0
                        and rf.ACTIVE_FL = 'Y'
                        and rf.RECORD_FL > 0
                        and r.ACTIVE_FL = 'Y'
                        and r.RECORD_FL > 0
                        and ur.ACTIVE_FL = 'Y'
                        and ur.RECORD_FL > 0
                )
            ),
             mng_menu mu
         where m.ACTIVE_FL = 'Y'
           and m.RECORD_FL > 0
           and m.PARENT_MENU_ID = mu.MENU_ID
           and mu.PARENT_MENU_ID is null
           )
           #order by MENU_GROUP, MENU_ORDER, MENU_NAME asc
      )

           union

      (

         select mv.MENU_ID,
                #mv.MENU_NAME,
                ".getTRValue("mng_menu","MENU_NAME", "mv.MENU_ID",NULL,"mv")." ,
                fv.FORM_ID,
                fv.FORM_NAME,
                fv.FORM_PATH,
                fv.FORM_TYPE,
                substring(fv.FORM_PATH,1,locate(substring_index(fv.FORM_PATH,'/',-1),fv.FORM_PATH)-1) RELATIVE_PATH,
                mv.PARENT_MENU_ID,
                mv.MENU_GROUP,
                mv.MENU_ORDER
           from mng_menu mv
           left join mng_form fv on (fv.FORM_ID = mv.MENU_ID and fv.ACTIVE_FL = 'Y' and fv.RECORD_FL > 0 and mv.ACTIVE_FL = 'Y' and mv.RECORD_FL > 0)
          where mv.MENU_ID in (
                   select m.MENU_ID
                     from mng_menu m
                     left join mng_form f on ( f.ACTIVE_FL = 'Y' and f.RECORD_FL > 0 and f.FORM_ID = m.FORM_ID and m.FORM_ID in (
                             select rf.FORM_ID
                                 from mng_role_form rf,
                                     mng_role r,
                                     mng_user_role ur,
                                     mng_user u,
                                     mng_user_company uc,
                                     mng_company c
                                 where rf.ROLE_ID = r.ROLE_ID
                                 and r.ROLE_ID = ur.ROLE_ID
                                 and ur.COMPANY_ID = c.COMPANY_ID
                                 and uc.COMPANY_ID = c.COMPANY_ID
                                 and uc.USER_ID = u.USER_ID
                                 and ur.USER_ID = u.USER_ID
                                 and ur.USER_ID = ?
                                 and ur.COMPANY_ID = ?
                                 and u.ACTIVE_FL = 'Y'
                                 and u.RECORD_FL > 0
                                 and uc.ACTIVE_FL = 'Y'
                                 and uc.RECORD_FL > 0
                                 and c.ACTIVE_FL = 'Y'
                                 and c.RECORD_FL > 0
                                 and rf.ACTIVE_FL = 'Y'
                                 and rf.RECORD_FL > 0
                                 and r.ACTIVE_FL = 'Y'
                                 and r.RECORD_FL > 0
                                 and ur.ACTIVE_FL = 'Y'
                                 and ur.RECORD_FL > 0
                         )
                     )
                  where m.ACTIVE_FL = 'Y'
                    and m.RECORD_FL > 0
                    and m.PARENT_MENU_ID is null
                    and m.FORM_ID is not null
                )
                #order by MENU_GROUP, MENU_ORDER, MENU_NAME asc
                )
                order by MENU_GROUP, MENU_ORDER, MENU_NAME asc
        ";

        $rs = $db->Execute($sql,
            array(
                "USER_ID"=>$_SESSION["USER_ID_PARENT"],
                "COMPANY_ID"=>$_SESSION["COMPANY_ID"],

                "USER_ID2"=>$_SESSION["USER_ID_PARENT"],
                "COMPANY_ID2"=>$_SESSION["COMPANY_ID"]
            )
        );
    }else{
        $sql = "
            select m.MENU_ID,
                   #m.MENU_NAME,
                   ".getTRValue("mng_menu","MENU_NAME", "m.MENU_ID",NULL,"m")." ,
                   f.FORM_ID,
                   f.FORM_NAME,
                   f.FORM_PATH,
                   f.FORM_TYPE,
                   substring(f.FORM_PATH,1,locate(substring_index(f.FORM_PATH,'/',-1),f.FORM_PATH)-1) RELATIVE_PATH,
                   m.PARENT_MENU_ID
              from mng_menu m,
                   mng_form f,
                   mng_menu mu
             where m.ACTIVE_FL = 'Y'
               and m.RECORD_FL > 0
               and m.PARENT_MENU_ID = mu.MENU_ID
               and m.PARENT_MENU_ID = ?
               and f.ACTIVE_FL = 'Y'
               and f.RECORD_FL > 0
               and f.FORM_ID = m.FORM_ID
               and m.FORM_ID in (
                    select rf.FORM_ID
                      from mng_role_form rf,
                           mng_role r,
                           mng_user_role ur,
                           mng_user u,
                           mng_user_company uc,
                           mng_company c
                     where rf.ROLE_ID = r.ROLE_ID
                       and r.ROLE_ID = ur.ROLE_ID
                       and ur.COMPANY_ID = c.COMPANY_ID
                       and uc.COMPANY_ID = c.COMPANY_ID
                       and uc.USER_ID = u.USER_ID
                       and ur.USER_ID = u.USER_ID
                       and ur.USER_ID = ?
                       and ur.COMPANY_ID = ?
                       and u.ACTIVE_FL = 'Y'
                       and u.RECORD_FL > 0
                       and uc.ACTIVE_FL = 'Y'
                       and uc.RECORD_FL > 0
                       and c.ACTIVE_FL = 'Y'
                       and c.RECORD_FL > 0
                       and rf.ACTIVE_FL = 'Y'
                       and rf.RECORD_FL > 0
                       and r.ACTIVE_FL = 'Y'
                       and r.RECORD_FL > 0
                       and ur.ACTIVE_FL = 'Y'
                       and ur.RECORD_FL > 0
               )

            ";

            $db->Execute($sql,
                array(
                    "USER_ID"=>$_SESSION["USER_ID_PARENT"],
                    "COMPANY_ID"=>$_SESSION["COMPANY_ID"],
                    "PARENT_MENU_ID"=>$parentId,
                )
            );
    }

    if($parentId == NULL){
        $sql = "
			select mp.*, f.*
			  from mng_menu mn,
				   mng_menu mp,
				   mng_form f
			 where mn.MENU_ID in (
				select mq.MENU_ID
				  from mng_user_role ur,
					   mng_role_form rf,
					   mng_menu mq
				 where ur.ACTIVE_FL = 'Y'
				   and ur.RECORD_FL > 0
				   and rf.ACTIVE_FL = 'Y'
				   and rf.RECORD_FL > 0
				   and mq.ACTIVE_FL = 'Y'
				   and mq.RECORD_FL > 0
				   and rf.ROLE_ID = ur.ROLE_ID
				   and mq.FORM_ID = rf.FORM_ID
				   and ur.USER_ID = ?
				   and ur.COMPANY_ID = ?
				)
			   and mn.PARENT_MENU_ID = mp.MENU_ID
			   and mn.FORM_ID = f.FORM_ID
			   and mn.ACTIVE_FL = 'Y'
			   and mn.RECORD_FL > 0
			   and mp.ACTIVE_FL = 'Y'
			   and mp.RECORD_FL > 0
			   and f.ACTIVE_FL = 'Y'
			   and f.RECORD_FL > 0
			   and mp.PARENT_MENU_ID is NULL
			 group by mp.MENU_ID
			 order by mn.MENU_GROUP,
			          IF(ISNULL(mn.MENU_GROUP),1,0),
			          mn.MENU_ORDER
		";
        $rs = $db->Execute($sql,
            array(
                "USER_ID"=>$_SESSION["USER_ID_PARENT"],
                "COMPANY_ID"=>$_SESSION["COMPANY_ID"]
            )
        );
    }else{
        $sql = "
			select mn.*, f.*
			  from mng_menu mn
			  left join mng_form f on (mn.FORM_ID = f.FORM_ID)
			 where mn.MENU_ID in (
				select mq.MENU_ID
				  from mng_user_role ur,
					   mng_role_form rf,
					   mng_menu mq
				 where ur.ACTIVE_FL = 'Y'
				   and ur.RECORD_FL > 0
				   and rf.ACTIVE_FL = 'Y'
				   and rf.RECORD_FL > 0
				   and mq.ACTIVE_FL = 'Y'
				   and mq.RECORD_FL > 0
				   and rf.ROLE_ID = ur.ROLE_ID
				   and mq.FORM_ID = rf.FORM_ID
				   and ur.USER_ID = ?
				   and ur.COMPANY_ID = ?
				   and mq.PARENT_MENU_ID = ?
				)
			   and mn.ACTIVE_FL = 'Y'
			   and mn.RECORD_FL > 0
			   and f.ACTIVE_FL = 'Y'
			   and f.RECORD_FL > 0
			 group by mn.MENU_ID
			 order by mn.MENU_GROUP ,
			          IF(ISNULL(mn.MENU_GROUP),1,0) ,
			          mn.MENU_ORDER
		";
        $rs = $db->Execute($sql,
            array(
                "USER_ID"=>$_SESSION["USER_ID_PARENT"],
                "COMPANY_ID"=>$_SESSION["COMPANY_ID"],
                "PARENT_MENU_ID"=>$parentId
            )
        );
    }

    $menuList = array();
    while($dt = $rs->FetchRow()){
        $dt["items"] = recursiveMenu($dt["MENU_ID"]);
        array_push($menuList,$dt);
    }



    return $menuList;


}*/
/*

if($parentId == NULL){
    $sql = "
            select * from mng_menu md where md.MENU_ID in(
			select mn.PARENT_MENU_ID
			  from mng_menu mn,
				   mng_menu mp,
				   mng_form f
			 where mn.MENU_ID in (
				select mq.MENU_ID
				  from mng_user_role ur,
					   mng_role_form rf,
					   mng_menu mq
				 where ur.ACTIVE_FL = 'Y'
				   and ur.RECORD_FL > 0
				   and rf.ACTIVE_FL = 'Y'
				   and rf.RECORD_FL > 0
				   and mq.ACTIVE_FL = 'Y'
				   and mq.RECORD_FL > 0
				   and rf.ROLE_ID = ur.ROLE_ID
				   and mq.FORM_ID = rf.FORM_ID
				   and ur.USER_ID = ?
				   and ur.COMPANY_ID = ?
				)
			   and mn.PARENT_MENU_ID = mp.MENU_ID
			   and mn.FORM_ID = f.FORM_ID
			   and mn.ACTIVE_FL = 'Y'
			   and mn.RECORD_FL > 0
			   and mp.ACTIVE_FL = 'Y'
			   and mp.RECORD_FL > 0
			   and f.ACTIVE_FL = 'Y'
			   and f.RECORD_FL > 0
			   and mp.PARENT_MENU_ID is NULL
			 group by mp.MENU_ID)
			 order by md.MENU_GROUP,
			          IF(ISNULL(md.MENU_GROUP),1,0),
			          md.MENU_ORDER
		";
    $rs = $db->Execute($sql,
        array(
            "USER_ID"=>$_SESSION["USER_ID_PARENT"],
            "COMPANY_ID"=>$_SESSION["COMPANY_ID"]
        )
    );
}else{
    $sql = "
            select * from mng_menu md, mng_form mfd where mfd.FORM_ID = md.FORM_ID and md.MENU_ID in(
			select mn.MENU_ID
			  from mng_menu mn
			  left join mng_form f on (mn.FORM_ID = f.FORM_ID)
			 where mn.MENU_ID in (
				select mq.MENU_ID
				  from mng_user_role ur,
					   mng_role_form rf,
					   mng_menu mq
				 where ur.ACTIVE_FL = 'Y'
				   and ur.RECORD_FL > 0
				   and rf.ACTIVE_FL = 'Y'
				   and rf.RECORD_FL > 0
				   and mq.ACTIVE_FL = 'Y'
				   and mq.RECORD_FL > 0
				   and rf.ROLE_ID = ur.ROLE_ID
				   and mq.FORM_ID = rf.FORM_ID
				   and ur.USER_ID = ?
				   and ur.COMPANY_ID = ?
				   and mq.PARENT_MENU_ID = ?
				)
			   and mn.ACTIVE_FL = 'Y'
			   and mn.RECORD_FL > 0
			   and f.ACTIVE_FL = 'Y'
			   and f.RECORD_FL > 0
			 group by mn.MENU_ID
			)
			 order by md.MENU_GROUP asc,
			          IF(ISNULL(md.MENU_GROUP),1,0) asc,
			          md.MENU_ORDER asc
		";
    $rs = $db->Execute($sql,
        array(
            "USER_ID"=>$_SESSION["USER_ID_PARENT"],
            "COMPANY_ID"=>$_SESSION["COMPANY_ID"],
            "PARENT_MENU_ID"=>$parentId
        )
    );
}*/
