<?php
/**
 * Created by PhpStorm.
 * User: ferhat
 * Date: 28.02.2017
 * Time: 23:09
 */

use Doctrine\Common\Cache\Cache;
use Doctrine\DBAL\Configuration;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;

/** @noinspection PhpIncludeInspection */
require dirname(__DIR__) . "../../../vendor/autoload.php";
include_once "db.settings-notuse.php";

global $dbm, $db;
try {
    $dbm = new DBManager($_SESSION?$_SESSION["COMPANY_DB_CODE"]:NULL);

    if($dbm->connected) {        $db = $dbm->getDB();
    }
}catch(Exception $ex){

}
function my_autoloader($class) {
    if(strpos($class, "DSYS") !== false) {
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $file = __DIR__ . '/../../../model/Entity/' .  $class . '.php';
        /** @noinspection PhpIncludeInspection */
        include $file;
    }
}
spl_autoload_register('my_autoloader');
class DBManager {

    private $isDevMode;
    private $proxyDir;
    private $cache;
    private $useSimpleAnnotationReader ;
    private $ormConfig;


    public $clinicConnections;
    public $noConnect = false;
    public $connected = false;
    public $errorMessage = "";
    public $errorCode = "";
    protected $connObj;
    public $db;

    function __construct($connName = NULL){

global $dbSettings;

        $this->clinicConnections = $dbSettings;

        $this->isDevMode = true;
        $this->proxyDir = "";
        $this->cache = null;
//        $this->cache = new Doctrine\Common\Cache\VoidCache();
        $this->useSimpleAnnotationReader = false;
        $this->ormConfig = Setup::createAnnotationMetadataConfiguration(array("../../../model/Entity/DSYS"), $this->isDevMode, $this->proxyDir, $this->cache, $this->useSimpleAnnotationReader);


//        $this->ormConfig->addCustomStringFunction('DATEDIFF', '\Doctrine\ORM\Query\CustomQuery\DateDiff');
//        $this->ormConfig->addCustomStringFunction('ROUND', '\Doctrine\ORM\Query\CustomQuery\Round');

        if($connName == NULL){
            $this->defaultConnection();
        }else{
            $this->connect($connName);
        }
      /*  if($this->db->getConnection()->getDatabasePlatform()->getName() == "mssql") {
            include("../../../model/Entity/Query/Mssql/Datepart.php");
//            include("../../../model/Entity/Query/Mssql/Year.php");
//            include("../../../model/Entity/Query/Mssql/Month.php");

//            $this->db->getConfiguration()->addCustomDatetimeFunction('YEAR', 'Doctrine\ORM\Query\CustomQuery\Mssql\Year');
//            $this->db->getConfiguration()->addCustomDatetimeFunction('MONTH', 'Doctrine\ORM\Query\CustomQuery\Mssql\Month');
            $this->db->getConfiguration()->addCustomDatetimeFunction('DATE_PART', 'Doctrine\ORM\Query\CustomQuery\Mssql\Datepart');
        }*/
    }
    public function getCompanyDBCode(): array
    {
        $dbCode = array();
        foreach($this->clinicConnections as $key=>$value){
            array_push($dbCode,
                array(
                    "COMPANY_CODE_ID" => $key,
                    "COMPANY_CODE" => $key
                )
            );
        }
        return $dbCode;

    }

    public function connect($connName){

        try {
            $this->connObj = EntityManager::create($this->clinicConnections[$connName], $this->ormConfig);
//            Doctrine\DBAL\Types\Type::addType('blob', '../../../model/Entity/DataType/Blob');
//            $this->connObj->getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('BLOB', 'blob');
            if($this->connObj != false) {
                $this->db = $this->connObj;
                $this->connected = true;
//                $this->db ->getConfiguration()->addCustomHydrationMode('CustomHydrator', 'MyProject\Hydrators\CustomHydrator');
//                $this->db ->getConfiguration()->addNamedQuery();
             }
        } catch (ORMException | \Doctrine\DBAL\Exception $e) {

        }

    }

    public function defaultConnection(){
        try{
            if($_SESSION && $_SESSION["COMPANY_DB_CODE"] != NULL && !$this->noConnect){

                if($this->connObj != $this->connect($_SESSION["COMPANY_DB_CODE"])){
                    /*}else{*/
                    $this->errorMessage = "No DB Connection";
                    $this->errorCode = "1";
                    $this->db = $this->connObj;
                    $this->connected = true;
                }
            }
        }catch(Exception $err){

        }
    }
    /*public static function getRS($table, $field){
        global $db;
        $sql = " select * from ".$table." where ".$field." = -1";
        return $rs = $db->Execute($sql);
    }*/
    public static function getMaxId($entity, $field): int
    {
        global $db;
        $dql = "
		    SELECT MAX(t.".$field.") as MAX_VALUE
		     FROM ".$entity." t
	    ";
        try {
            $rs = $db->createQuery($dql)
                ->getSingleResult();
            return $rs["MAX_VALUE"] + 1;
        } catch (NoResultException | NonUniqueResultException $e) {

            return -1;
        }
    }
    /*public static function getField($table, $field, $where){
        global $db;
        try{
            $sql = "
            select ".$field." FIELD_VALUE
              from ".$table."
             where ".$where."
            ";
            $rs = $db->Execute($sql);
            $data = $rs->FetchRow();
            return $data["FIELD_VALUE"];
        }catch(Exception $ex){
            return NULL;
        }
    }*/

    public function getDB(): EntityManager
    {
        return $this->connObj;
    }
}
function getManageDB(): ?string
{
//    return DBManager::$clinicConnections["MNG_000_DMED"]["dbname"];
    return "MNG_000_DMED";
}
/*function getRS($table, $field){
    global $dbm;
    return $dbm->getRs($table, $field);
}*/

/**
 * @param $table
 * @param $field
 * @return int
 */
function getMaxId($table, $field): int
{
    global $dbm;
    return $dbm->getMaxId($table, $field);
}

/*function getField($table, $field, $where){
    global $dbm;
    return $dbm->getField($table, $field, $where);
}*/

function getTRValue($table, $field, $pkField, $retField = NULL, $tableAlias = NULL): ?string
{// this function gets SQL script for TRanslated value if global config that "DB_LANG_CODE" same it just return same field name otherwise get translated value SQL

    if($retField == NULL){
        $retField = $field;
    }
    if($tableAlias != NULL){
        $aliasField = $tableAlias.".".$field;
    } else{
        $aliasField = $field;
    }
    if($_SESSION["DB_LANG_CODE"] != $_SESSION["lang"]) {
        $sqlTRVal = "
        if(
           (
            select vvtt.TRANSLATION_TABLE_VALUE
              from mng_translation_table_list vvttl, 
                   mng_translation_field_list vvtfl,
                   mng_translation_table vvtt
             where vvttl.TRANSLATION_TABLE_LIST_ID = vvtfl.TRANSLATION_TABLE_LIST_ID 
               and vvttl.TRANSLATION_TABLE_LIST_NAME = '" . $table . "'
               and vvtfl.TRANSLATION_FIELD_LIST_NAME = '" . $field . "'
               and vvtt.LANGUAGE_ID = " . $_SESSION["LANGUAGE_ID"] . "
               and vvtt.TRANSLATION_FIELD_LIST_ID = vvtfl.TRANSLATION_FIELD_LIST_ID
               and vvtt.TRANSLATION_TABLE_LIST_ID = vvttl.TRANSLATION_TABLE_LIST_ID
               and vvtt.TRANSLATION_TABLE_INDEX_ID = ".$pkField."
           ) is null, " . $aliasField . ", 
           (
            select vvtt.TRANSLATION_TABLE_VALUE
              from mng_translation_table_list vvttl, 
                   mng_translation_field_list vvtfl,
                   mng_translation_table vvtt
             where vvttl.TRANSLATION_TABLE_LIST_ID = vvtfl.TRANSLATION_TABLE_LIST_ID 
               and vvttl.TRANSLATION_TABLE_LIST_NAME = '" . $table . "'
               and vvtfl.TRANSLATION_FIELD_LIST_NAME = '" . $field . "'
               and vvtt.LANGUAGE_ID = " . $_SESSION["LANGUAGE_ID"] . "
               and vvtt.TRANSLATION_FIELD_LIST_ID = vvtfl.TRANSLATION_FIELD_LIST_ID
               and vvtt.TRANSLATION_TABLE_LIST_ID = vvttl.TRANSLATION_TABLE_LIST_ID
               and vvtt.TRANSLATION_TABLE_INDEX_ID = ".$pkField."
           )
           )
             " . $retField . "
        ";
    }else{
        $sqlTRVal = $retField;
    }
    return $sqlTRVal;
}
