<?php
/**
 * Created by PhpStorm.
 * User: ferhat
 * Date: 05.07.2018
 * Time: 22:10
 */

header('Content-type: application/json');
header('Content-Type: text/html; charset=utf-8' );

header("ETag: PUB" . time());
header("Last-Modified: " . gmdate("D, d M Y H:i:s", time()-10) . " GMT");
header("Expires: " . gmdate("D, d M Y H:i:s", time() + 5) . " GMT");
header("Pragma: no-cache");
header("Cache-Control: max-age=1, s-maxage=1, no-cache, must-revalidate");

require_once("../../libFB/php/lib/yahoo.json.php");


$targetRootDir = __DIR__;
$targetDir = "../pcr_files/";
$tmpDir = "../pcr_files/temp/";
$targetFile = $targetRootDir.$tmpDir . basename($_FILES["file"]["name"]);


$fileDirname = strtolower(pathinfo($targetFile,PATHINFO_DIRNAME));
$fileBasename = strtolower(pathinfo($targetFile,PATHINFO_BASENAME));
$fileExtension = strtolower(pathinfo($targetFile,PATHINFO_EXTENSION));
$fileFileName = strtolower(pathinfo($targetFile,PATHINFO_FILENAME));

$uniqueFileId = 1;
$targetFile = $tmpDir . date("YmdHis")."_".$fileFileName. "_".$uniqueFileId.".".$fileExtension;


if (move_uploaded_file($_FILES["file"]["tmp_name"], $targetFile)) {
    $responseObj = array(
        "resCode"=>0,
        "resMsg"=>"Ok",
        "size"=>$_POST["file_size"],
        "type"=>$_POST["file_type"],
        "name"=>$_POST["file_name"],
        "ext"=>$_POST["file_ext"],
        "doc_id"=>NULL,
        "doc_name"=>NULL,
        "doc_type"=>NULL
    );
} else {
    $responseObj = array(
        "resCode"=>1,
        "resMsg"=>"Err"
    );
}
$json = new Services_JSON();
echo $json->encode($responseObj);