<?php /** @noinspection PhpUndefinedClassInspection */
/**
 * Created by PhpStorm.
 * User: NUR
 * Date: 15.10.2026
 * Time: 23:09
 */
use Carbon\Carbon;
//Global data list,
// $dtResponse
// $rsResponse
// $totalRecords
// $resObject
// $resDetail
global $dateFormat;
$dateFormat = NULL;//"d.m.Y";
global $dateTimeFormat;
$dateTimeFormat = NULL;//"d.m.Y";
$lang = array();

//Start session and close writing
// Girişle aynı oturum (BIOLABSID, 30 dk); varsayılan PHPSESSID açılırsa giriş görülmez
require_once __DIR__ . '/../../../vendor/autoload.php';
// Biolab QMS ekranları (apps/biolab-qms/...) ayrı veritabanı ve ayrı oturumla çalışır:
// oturum BIOLABQMSSID, EntityManager bootstrap-qms.php (db.inc.php), yetki QmsFormAccess (checkAuthorization)
$qmsTarget = \Biolab\Core\Auth\FormAccess::targetFromDataSource(
    (string) explode('@', (string) ($_GET['app'] ?? $_POST['app'] ?? $_GET['uyg'] ?? $_POST['uyg'] ?? ''))[0]
);
define('QMS_REQUEST', $qmsTarget !== null && $qmsTarget['app'] === 'biolab-qms');
\Biolab\Core\Http\Session::start(QMS_REQUEST ? \Biolab\Core\Http\Session::QMS_NAME : \Biolab\Core\Http\Session::NAME);
//$_SESSION["lang"] = "tr";
//$_SESSION["langCultureInfo"] = "tr";/**/
if(!isset($_SESSION["langCultureInfo"])){
    $_SESSION["langCultureInfo"] = "en";
}
session_write_close();

//$execType Define
const ET_JQGRID = 101;
const ET_JQGRID_CUSTOM = 102;
const ET_DTABLES = 201;
const ET_DTABLES_CUSTOM = 202;
const ET_DEFAULT = 11;
const ET_CUSTOM = 12;
const ET_SAVE = 13;
const ET_DELETE = 14;
const ET_FILE = 1000;
const ET_FILE_JSON = 2000;
const ET_FILE_DOWNLOAD = 3000;
const ET_CALENDAR = 5000;

//$sessionType Define
const WITHOUT_SESSION = 0;
const WITH_SESSION = 1;
//Session Timeout Definition
const SESSION_TIME_OUT = 60;

//$accessType Default=private
const AT_PRIVATE = 1;
const AT_PUBLIC = 2;

function checkAuthorization(){

    global $accessType,$db, $dbm, $dataSource;
    global $lm;

    if($accessType == AT_PRIVATE){
        // Önce giriş yapılmış ve şirketi seçilmiş mi bakılır.
        if (empty($_SESSION["USER_ID"]) || empty($_SESSION["COMPANY_ID"])) {
            $returnValue = new returnStd();
            $returnValue->resCode = "9999";
            $returnValue->resMsg = $lm->lang["UNAUTHORIZED_ACCESS"] ?? "Yetkisiz erişim. Lütfen tekrar giriş yapın.";
            $json = new Services_JSON();
            echo $json->encode($returnValue);
            exit;
        }

        // 1. Uygulama yetkisi (bütün uygulamalar): kullanıcının bu uygulamaya ait (mng_role.APP_CODE) bir rolü olmalı.
        // 2. Ekran yetkisi (mng_user_role → mng_role_form → mng_form): yalnızca FormAccess::ENFORCED_APPS'te.
        // QMS: kullanıcı, rol ve ekran tabloları Biolab_QMS'te; ekran yetkisi her zaman kontrol edilir
        $target = \Biolab\Core\Auth\FormAccess::targetFromDataSource((string) $dataSource);
        $access = $target === null ? null
            : (QMS_REQUEST ? new \Biolab\Core\Qms\Auth\QmsFormAccess($db) : new \Biolab\Core\Auth\FormAccess($db));
        if ($target !== null && !$access->canEnterApp((int) $_SESSION["USER_ID"], (int) $_SESSION["COMPANY_ID"], $target["app"])) {
            $returnValue = new returnStd();
            $returnValue->resCode = "9997";
            $returnValue->resMsg = "Bu uygulama için yetkiniz bulunmamaktadır. Sistem yöneticisine başvurun.";
            $json = new Services_JSON();
            echo $json->encode($returnValue);
            exit;
        }
        if ($target !== null && (QMS_REQUEST || \Biolab\Core\Auth\FormAccess::isEnforced($target["app"]))
            && !$access->canAccess((int) $_SESSION["USER_ID"], (int) $_SESSION["COMPANY_ID"], $target["path"])) {
            $returnValue = new returnStd();
            $returnValue->resCode = "9998";
            $returnValue->resMsg = "Bu ekrana erişim yetkiniz yok. Sistem yöneticisine başvurun.";
            $json = new Services_JSON();
            echo $json->encode($returnValue);
            exit;
        }
        return;

        $companyCode = getManageDB();//"MANAGE";
        $dbmA = new DBManager($companyCode);
        $dbA = $dbmA->db;


        $moduleName = basename($dataSource.".php", ".php").".html";

        $dql = "
            select fq
              from DSYS\MNG\UserRole ur
              join DSYS\MNG\RoleForm rf WITH rf.role = ur.role
              join DSYS\MNG\Form fq WITH fq.formId = rf.form
            where ur.activeFl = 'Y'
                  and ur.recordFl > 0
                  and rf.activeFl = 'Y'
                  and rf.recordFl > 0
                  and ur.recordFl > 0
                  and fq.activeFl = 'Y'
                  and fq.recordFl > 0
                  and ur.user = :USER_ID
                  and ur.company = :COMPANY_ID
                  and fq.formPath like :FORM_PATH
        ";
        $rs = $dbA->createQuery($dql)
            ->setParameter("COMPANY_ID", $_SESSION["COMPANY_ID"])
            ->setParameter("USER_ID", $_SESSION["USER_ID_PARENT"])
            ->setParameter('FORM_PATH',"%".$moduleName."" )
            ->getResult();
        if (count($rs) < 1) {
            $returnValue = new returnStd();
            $returnValue->resCode = "9999";
            $returnValue->resMsg = $lm->lang["UNAUTHORIZED_ACCESS"];
            $json = new Services_JSON();
            echo $json->encode($returnValue);
            exit;
        }

    }
}

header('Content-type: application/json');
header('Content-Type: text/html; charset=utf-8' );
//header('Content-Type: text/html; charset=iso-8859-9' );


header("ETag: PUB" . time());
header("Last-Modified: " . gmdate("D, d M Y H:i:s", time()-10) . " GMT");
header("Expires: " . gmdate("D, d M Y H:i:s", time() + 5) . " GMT");
header("Pragma: no-cache");
header("Cache-Control: max-age=1, s-maxage=1, no-cache, must-revalidate");
//header("Access-Control-Allow-Origin: http://localhost:3000");
//header("Access-Control-Allow-Origin: *");
// csrf code add here (see below...)
$http_origin = $_SERVER['HTTP_ORIGIN'] ?? '';   // aynı adresten gelen isteklerde başlık yok; uyarı JSON'u bozardı
if ($http_origin == "http://dev.local:3000" || $http_origin == "http://localhost:3000"){
    header("Access-Control-Allow-Origin: $http_origin");
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: X-Requested-With, Origin, Content-Type, X-CSRF-Token, Accept');


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
if(!isset($_SESSION["lang"])) $_SESSION["lang"] = "en";
if(!isset($_SESSION["USER_ID_PARENT"])) $_SESSION["USER_ID_PARENT"] = NULL;

require_once("db.inc.php");

/** @noinspection PhpUnnecessaryParenthesesInspection */

// __DIR__ şart: proxy public'teki giriş dosyasından çağrılıyor, "../" ile başlayan yol oraya göre çözülürdü
require __DIR__ . '/../phpLIB/yahoo.json.php';

convertEmptyStringToNull();

/*session_cache_limiter('private');
$cache_limiter = session_cache_limiter();
session_cache_expire(SESSION_TIME_OUT);
$session_expire = session_cache_expire();*/
//this section will be deleted
//session_cache_expire(SESSION_TIME_OUT);
/*
$c = new Carbon();
$c->setLocale('tr');
echo $c->locale();
echo $c->toDateString();
echo $c->localToStringFormat();
echo Carbon::now()->formatLocalized('%d %B %Y %H');;
print_r($c);*/

class langManager{
    public $lang;
    public $cultureInfo = array(
        "dateFormat" => array(
            "tr" => "d.m.Y",
            "en" => "m/d/Y",
            "qa" => "m/d/Y",
            "gb" => "m/d/Y",
            "en-gb" => "d/m/Y",
            "en-qa" => "d/m/Y",
            "en-GB" => "d/m/Y"
        ),
        "dateTimeFormat" => array(
            "tr" => "d.m.Y H:i:s",
            "en" => "m/d/Y H:i:s",
            "qa" => "m/d/Y H:i:s A",
            "gb" => "m/d/Y H:i:s",
            "en-gb" => "d/m/Y H:i:s",
            "en-qa" => "d/m/Y H:i:s A",
            "en-GB" => "d/m/Y H:i:s"
        )
    );
    function __construct(){
        $this->setCultureInfo();
        $this->loadLanguageResourceGeneral();
    }
    public function loadLanguageResourceGeneral(){

        if($_SESSION["lang"] == NULL){
            $_SESSION["lang"] = "en";
        }
        $loadedFile = file_get_contents("../../app/resources/".$_SESSION["lang"]."/general.json");
        $this->lang = json_decode($loadedFile, true);
    }
    public function loadLanguageResource(){
        try {
            global $resourceFile;
            // $resourceFile boşsa klasör dosya gibi okunmaya çalışılıp uyarı basılıyordu
            if(!empty($resourceFile) && is_file("../../app/resources/" . $_SESSION["lang"] . "/" . $resourceFile)) {
                $loadedFile = file_get_contents("../../app/resources/" . $_SESSION["lang"] . "/" . $resourceFile);
                if (strlen($loadedFile) > 0) {
                    $lang = json_decode($loadedFile, true);
                    $this->lang = array_merge($this->lang, $lang);
                }
            }
        }catch (Exception $e){
            print_r($e);
        }
    }
    public function setCultureInfo(){
        global $dateFormat;
//        echo $_SESSION["langCultureInfo"];
         $dateFormat = $this->cultureInfo["dateFormat"][$_SESSION["langCultureInfo"]];
        global $dateTimeFormat;
         $dateTimeFormat = $this->cultureInfo["dateTimeFormat"][$_SESSION["langCultureInfo"]];
    }
}
$lm = new langManager();
$execType = ET_DEFAULT;
$sessionType = WITH_SESSION;

function convertEmptyStringToNull()
{
    array_walk_recursive(
        $_POST,
        function (&$value) {
//            global $db;

            $value = $value === ""?NULL:$value;

        }
    );
}
class  returnStd
{
    public $resCode;
    public $resMsg;
    public $records;
    public $resObject;
    public $resDetail;
    public $s;
}
class returnJQGrid extends returnStd
{
    public $page;
    public $total;
    public $rows;
}
class returnDTables extends returnStd
{
    public $draw;
    public $recordsTotal;
    public $recordsFiltered;
    public $data;
}
class JSONPManager{

    public $dataSource;
    public $query;
    private $execType;
    private $sessionType;

    function __construct(){
//        global $execType, $sessionType;
        $this->execType = ET_DEFAULT;
        $this->sessionType = WITH_SESSION;
        $this->getDataSource();
    }
    public function getDataSource()
    {
        $app = NULL;
        if(isset($_GET["app"])){
            $app = $_GET["app"];
        }else if(isset($_POST["app"])){
            $app = $_POST["app"];
        }else if(isset($_GET["uyg"])){
            $app = $_GET["uyg"];
        }else if(isset($_POST["uyg"])){
            $app = $_POST["uyg"];
        }
        list($this->dataSource, $this->query) = preg_split('/@/', $app);
    }
    public function executeDataSource()
    {
        global $execType, $sessionType, /*$rsResponse, */$resObject, $resDetail, $query, $resCode, $resMsg, $totalRecords, $dtResponse, $dataSource;
        try{

//            convertDateTimeToFlat($dtResponse);
//            $returnValue = NULL;
//            $this->getDataSource();
            if($_SESSION["COMPANY_ID"] == NULL && $this->dataSource!='loginUser' && $this->dataSource!='../../../module/rt_mon/php/rtmon-1.0.0'){
                $returnValue = new returnStd();
                $returnValue->resCode = $resCode;
                $returnValue->resMsg = $resMsg;
                try {
//                    if ($rsResponse) {
//                        $dtResponse = $rsResponse->GetArray();
//                    } else {
//                        $returnValue->resCode = 1000;// . " " . $resCode;
//                        $returnValue->resMsg = "RS is NULL" . " " . $resMsg . "<br>Data Source: " . $this->dataSource . "<br>Function: " . $this->query;
//                    }
//                    convertDateTimeToFlat($dtResponse);
                    $returnValue->records = $dtResponse;
                } catch (Exception $execException) {
                    $returnValue->resCode = 1001;
                    $returnValue->resMsg = $execException->getCode() . " : " . $execException->getMessage();
                }
                $returnValue->s = $this->getSession();
                $returnValue->resObject = $resObject;
                $returnValue->resDetail = $resDetail;
                $json = new Services_JSON();
                return $json->encode($returnValue);
            }


            if (isset($this->dataSource)) {
                $query = $this->query;
                $dataSource = $this->dataSource;
                if (!file_exists($this->dataSource . ".php")) {
                    $returnValue = new returnStd();
                    $returnValue->resCode = 9000;
                    $returnValue->resMsg = "Module Couldn't Found";
                }else{
                    /** @noinspection PhpIncludeInspection */
                    require_once($this->dataSource . ".php");
                    $this->execType = $execType;
                    $this->sessionType = $sessionType;
                    convertDateTimeToFlat($dtResponse);
                    switch ($this->execType) {
                        case ET_SAVE:
                        case ET_DELETE:
                        case ET_CUSTOM:
//                        convertDateTimeToFlat($dtResponse);
                            $returnValue = new returnStd();
                            $returnValue->resCode = $resCode;
                            $returnValue->resMsg = $resMsg;
                            try {
                                $returnValue->records = $dtResponse;
                            } catch (Exception $execException) {
                                $returnValue->resCode = 1001;
                                $returnValue->resMsg = $execException->getCode() . " : " . $execException->getMessage();
                            }
                            break;
                        /*case ET_CUSTOM:
                            $returnValue = new returnStd();
                            $returnValue->resCode = $resCode;
                            $returnValue->resMsg = $resMsg;
                            try {
                                $returnValue->records = $dtResponse;
                            } catch (Exception $execException) {
                                $returnValue->resCode = 1001;
                                $returnValue->resMsg = $execException->getCode() . " : " . $execException->getMessage();
                            }
                            break;*/
                        case ET_JQGRID:
                            $returnValue = new returnJQGrid();
                            $returnValue->resCode = $resCode;
                            $returnValue->resMsg = $resMsg;
                            try {
                                //global $totalRecords;
//                                if ($rsResponse) {
//                                    $dtResponse = $rsResponse->GetArray();
//                                } else {
//                                    $dtResponse = array();
//                                    $returnValue->resCode = 1000;// . " " . $resCode;
//                                    $returnValue->resMsg = "RS is NULL" . " " . $resMsg . "<br>Data Source: " . $this->dataSource . "<br>Function: " . $this->query;
//                                }


//                                convertDateTimeToFlat($dtResponse);

                                $returnValue->rows = $dtResponse;
                                $returnValue->page = $_POST["page"];
                                $returnValue->records = $totalRecords;
                                if ($_POST["rows"] > 0) {
                                    $returnValue->total = ceil($totalRecords / $_POST["rows"]);
                                } else {
                                    $returnValue->total = 0;
                                }

                            } catch (Exception $execException) {
                                $returnValue->resCode = 1001;
                                $returnValue->resMsg = $execException->getCode() . " : " . $execException->getMessage();
                            }
                            break;
                        case ET_JQGRID_CUSTOM:
                            $returnValue = new returnJQGrid();
                            $returnValue->resCode = $resCode;
                            $returnValue->resMsg = $resMsg;
                            try {
                                //global $dtResponse, $totalRecords;
//                                convertDateTimeToFlat($dtResponse);
                                $returnValue->rows = $dtResponse;
                                $returnValue->page = $_POST["page"];
                                $returnValue->records = $totalRecords;
                                if ($_POST["rows"] > 0) {
                                    $returnValue->total = ceil($totalRecords / $_POST["rows"]);
                                } else {
                                    $returnValue->total = 0;
                                }
                            } catch (Exception $execException) {
                                $returnValue->resCode = 1001;
                                $returnValue->resMsg = $execException->getCode() . " : " . $execException->getMessage();
                            }
                            break;
                        case ET_DTABLES:
                            $returnValue = new returnDTables();
                            $returnValue->resCode = $resCode;
                            $returnValue->resMsg = $resMsg;
                            try {
                                //global $totalRecords;

//                                if ($rsResponse) {
//                                    $dtResponse = $rsResponse->GetArray();
//                                } else {
//                                    $dtResponse = array();
//                                    $returnValue->resCode = 1000;// . " " . $resCode;
//                                    $returnValue->resMsg = "RS is NULL" . " " . $resMsg . "<br>Data Source: " . $this->dataSource . "<br>Function: " . $this->query;
//                                }
//                                convertDateTimeToFlat($dtResponse);
                                $returnValue->data = $dtResponse;
                                $returnValue->draw = $_POST["draw"];
                                $returnValue->recordsTotal = $totalRecords;
                                $returnValue->recordsFiltered = $totalRecords;

                            } catch (Exception $execException) {
                                $returnValue->resCode = 1001;
                                $returnValue->resMsg = $execException->getCode() . " : " . $execException->getMessage();
                            }
                            break;
                        case ET_DTABLES_CUSTOM:
                            $returnValue = new returnDTables();
                            $returnValue->resCode = $resCode;
                            $returnValue->resMsg = $resMsg;
                            try {
                                //global $dtResponse, $totalRecords;
//                                convertDateTimeToFlat($dtResponse);
                                $returnValue->data = $dtResponse;
                                $returnValue->draw = $_POST["draw"];
                                $returnValue->recordsTotal = $totalRecords;
                                $returnValue->recordsFiltered = $totalRecords;
                            } catch (Exception $execException) {
                                $returnValue->resCode = 1001;
                                $returnValue->resMsg = $execException->getCode() . " : " . $execException->getMessage();
                            }
                            break;


                        case ET_CALENDAR:

                            header('Content-type: application/json');
                            header('Content-Type: text/html; charset=utf-8' );
                            $json = new Services_JSON();
                            return $json->encode($dtResponse);
//                            break;
                        case ET_FILE:
                            header('Content-type: application/text');
                            return $dtResponse;
                        case ET_FILE_DOWNLOAD:
//                            header('Content-type: application/text');
                            return $dtResponse;
//                            break;
                        case ET_FILE_JSON:
                            $json = new Services_JSON();
                            return $json->encode($dtResponse);
//                            break;
                        case ET_DEFAULT:
                        default:
                            $returnValue = new returnStd();
                            $returnValue->resCode = $resCode;
                            $returnValue->resMsg = $resMsg;
                            try {
//                                if ($rsResponse) {
//                                    $dtResponse = $rsResponse->getArrayResult();
//                                } else {
//                                    $dtResponse = array();
//                                    $returnValue->resCode = 1000;// . " " . $resCode;
//                                    $returnValue->resMsg = "RS is NULL" . " " . $resMsg . "<br>Data Source: " . $this->dataSource . "<br>Function: " . $this->query;
//                                }
                                $returnValue->records = $dtResponse;
                            } catch (Exception $execException) {
                                $returnValue->resCode = 1001;
                                $returnValue->resMsg = $execException->getCode() . " : " . $execException->getMessage();
                            }
                            break;

                    }
                }
                $returnValue->s = $this->getSession();
                $returnValue->resObject = $resObject;
                $returnValue->resDetail = $resDetail;

//                $json = new Services_JSON();
//                return $json->encode($returnValue);
                return json_encode($returnValue);
            }
        }catch(Exception $eDS){
            print_r($eDS);
        }
        return NULL;
    }

    /** @noinspection PhpTernaryExpressionCanBeReplacedWithConditionInspection */
    public static function getSession(): array
    {
        global $sessionType;
        session_start();

        $inactive = SESSION_TIME_OUT*60; //20 minutes *60
        if(isset($_SESSION['LOGIN_START_TIME']) ) {
            $session_life = time() - $_SESSION['LOGIN_START_TIME'];
            if($session_life >= $inactive){
                $_SESSION = array();
                $_SESSION['SESSION_CODE'] = NULL;
                $_SESSION["COMPANY_CODE"] = NULL;
                $_SESSION["COMPANY_DB_CODE"] = NULL;
            }
        }
        if($sessionType != WITHOUT_SESSION) {
            $_SESSION['LOGIN_START_TIME'] = time();
            $_SESSION['LOGIN_END_TIME'] = time() + ($inactive);
        }

        /*$_SESSION["lang"] = "en";
        $_SESSION["langCultureInfo"] = "en-GB";*/
        session_write_close();
        return array(
            "companyId"=>$_SESSION["COMPANY_ID"],
            "companyCode"=>$_SESSION["COMPANY_CODE"],
            //"companyDBCode"=>$_SESSION["COMPANY_DB_CODE"],
            "companyName"=>$_SESSION["COMPANY_NAME"],
            "userId"=>$_SESSION["USER_ID_PARENT"],
            "username"=>$_SESSION["USERNAME"],
            "personName"=>$_SESSION["PERSON_NAME"],
            "personSurname"=>$_SESSION["PERSON_SURNAME"],
            "sCode"=>$_SESSION["SESSION_CODE"],
            "sTimeout"=>$_SESSION["SESSION_CODE"] == NULL ? true:false,
            "sTimeoutReDetect"=>(SESSION_TIME_OUT+1),
            "isOwner"=>$_SESSION["IS_OWNER"],
            "lang"=>$_SESSION["lang"],
            "langCultureInfo"=>$_SESSION["langCultureInfo"]

        );
    }

}
$jspManager = new JSONPManager();
if($jspManager->dataSource!='loginUser') {
    safePOSTData();
}
echo $r = $jspManager->executeDataSource();

function getD($string, $inFormat=NULL): ?DateTime
{
if($string == NULL || $string == "") return NULL;

    global $dateFormat;
   /* if($inFormat == NULL){
        $inFormat = $dateFormat;
    }*/
    if($inFormat == NULL){
        $inFormat = $dateFormat;
    }elseif ($inFormat == "H:i"){
        $inFormat = "Y-m-dH:i";
        $string = date("Y-m-d").$string;
    }elseif ($inFormat == "H:i:s"){
        $inFormat = "Y-m-dH:i:S";
        $string = date("Y-m-d").$string;
    }
//    echo $dateFormat;
//    echo Carbon::createFromFormat('Y-m-d H', '1975-05-21 22')->toDateTimeString();
//    echo $string;
    if(strlen($string) > 7){
        $dateTimeObj = Carbon::createFromFormat($inFormat, $string); //date_create_from_format($inFormat, $string);
        $dateTimeObj = $dateTimeObj->toDateTime();
//        print_r($dateTimeObj);
    }else{
        $dateTimeObj = NULL;
    }
    return $dateTimeObj;
}

function getDStr($string, $outFormat="Y.m.d", $inFormat=NULL): ?string
{
    global $dateFormat;
    if($inFormat == NULL){
        $inFormat = $dateFormat;
    }
    if(strlen($string) > 7){
//        $dateObj = date_create_from_format($inFormat, $string);
        $dateTimeObj = Carbon::createFromFormat($inFormat, $string); //date_create_from_format($inFormat, $string);
        $dateTimeObj = $dateTimeObj->format($outFormat);
//        $outStr = $dateObj->convert($outFormat);
//        $dateObj->__destruct();
    }else{
        $dateTimeObj = NULL;
    }
    return $dateTimeObj;
}
function getDStrLDOFM($string, $outFormat="Y.m.d"): string
{
    try {
        $d = new DateTime($string); $d->modify( 'last day of this month' );
        return $d->format( $outFormat );
    } catch (Exception $e) {
        return "";
    }

}
function getDStrFDOFM($string, $outFormat="Y.m.d"): string
{
    try {
        $d = new DateTime($string);
        $d->modify( 'first day of this month' );
        return $d->format( $outFormat );
    } catch (Exception $e) {
        return "";
    }

}

function safePOSTData()
{

}

function array_sort_by_column(&$arr, $col, $dir = SORT_ASC) {
    $sort_col = array();
    foreach ($arr as $key=> $row) {
        $sort_col[$key] = $row;
    }

    array_multisort($sort_col, $dir, $arr);
    return $sort_col;
}

function array_search_by_column($arr, $key, $value): array
{
    $results = array();

    if (is_array($arr)) {
        if (isset($arr[$key]) && $arr[$key] == $value) {
            $results[] = $arr;
        }

        foreach ($arr as $subarray) {
            $results = array_merge($results, array_search_by_column($subarray, $key, $value));
        }
    }

    return $results;
}

function convertDateTimeToFlat(&$arrayObject)
{
    // Kayıt döndürmeyen case'lerde $dtResponse null olur; foreach uyarısı loga düşmesin
    if (!is_array($arrayObject) && !is_object($arrayObject)) {
        return;
    }

    foreach($arrayObject as $key=>&$value){

        if(is_object($value)) {
            // Biolab entity'leri DateTimeImmutable kullanır; ikisi de DateTimeInterface. Çıktı biçimi D.Sys ile aynı.
            if ($value instanceof DateTimeInterface){
                $value = $value->format("Y-m-d H:i:s");
            }

        }

        if(is_array($value)){
            $value = (array)$value;
            convertDateTimeToFlat($value);
        }

    }
}
