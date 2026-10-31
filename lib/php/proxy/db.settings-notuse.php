<?php

$dbSettings = array(
    /*
    "MNG_000_DMED" =>           array("host" => "sql-server-2022-container,1433", "user" => "sa",
        "password" => "Dm3d_2016", "driver" => "pdo_sqlsrv",
        'charset'  => 'utf-8',
        "dbname" => "DSYS_PC_INIT", "driverOptions"=>array("TrustServerCertificate"=>true,"Encrypt"=>true, ),
        'options'  => [
            \PDO::ATTR_PERSISTENT => true,
            \PDO::ATTR_TIMEOUT => 2,
        ],),
   "CLN_001_SOFT_1"  =>          array("host" => "sql-server-2022-container,1433", "user" => "sa",
       "password" => "Dm3d_2016", "driver" => "pdo_sqlsrv",
       'charset'  => 'utf-8',
       "dbname" => "DSYS_PC_INIT", "driverOptions"=>array("TrustServerCertificate"=>true,"Encrypt"=>true, ),
       'options'  => [
           \PDO::ATTR_PERSISTENT => true,
           \PDO::ATTR_TIMEOUT => 2,
       ],),

    "CLN_002_SOFT_2"  =>          array("host" => "sql-server-2022-container,1433", "user" => "sa",
        "password" => "Dm3d_2016", "driver" => "pdo_sqlsrv",
        'charset'  => 'utf-8',
        "dbname" => "DSYS_PC_INIT", "driverOptions"=>array("TrustServerCertificate"=>true,"Encrypt"=>true, ),
        'options'  => [
            \PDO::ATTR_PERSISTENT => true,
            \PDO::ATTR_TIMEOUT => 2,
        ],),
    */


    "MNG_000_DMED" =>       array("host" => "nurhp", "user" => "sa",    "password" => "Dm3d_2016", "driver" => "pdo_sqlsrv", "dbname" => "DSYS_PC_SHOWCASE"),
    "CLN_001_HMC"  =>       array("host" => "nurhp", "user" => "sa",    "password" => "Dm3d_2016", "driver" => "pdo_sqlsrv", "dbname" => "DSYS_PC_SHOWCASE"),
    "CLN_001_HMC_DEMO"  =>       array("host" => "nurhp", "user" => "sa","password" => "Dm3d_2016", "driver" => "pdo_sqlsrv", "dbname" => "DSYS_PC_SHOWCASE"),

//    "MNG_000_DMED" =>       array("host" => "nurhp", "user" => "sa",    "password" => "Dm3d_2016", "driver" => "pdo_sqlsrv", "dbname" => "DSYS_PC_INIT"),
//    "CLN_001_HMC"  =>       array("host" => "nurhp", "user" => "sa",    "password" => "Dm3d_2016", "driver" => "pdo_sqlsrv", "dbname" => "DSYS_PC_INIT"),
//    "CLN_001_HMC_DEMO"  =>       array("host" => "nurhp", "user" => "sa",    "password" => "Dm3d_2016", "driver" => "pdo_sqlsrv", "dbname" => "DSYS_PC_INIT"),
);
