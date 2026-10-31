<?php
/**
 * HbRapor — Numune kabul veri kaynağı
 *
 * D.Sys düzeni: ekranın JS'i "sampleAcceptance-1.0.0@<query>" ile istek atar, buradaki switch ilgili case'i çalıştırır.
 * NOT: Bu dosyayı çağıracak proxy henüz kurulmadı. Kurulunca eklenecek case'ler:
 *      saveSample    → numuneyi kaydet (barkod tekrar kontrolü, elle giriş bayrağı BARCODE_MANUAL_FL)
 *      getTodayList  → bugün kabul edilen numuneler
 *      getD10Status  → D-10 cihaz bağlantı durumu
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $dateFormat;
//global $dbm, $db, $lm, $lang, $accessType, $query, $dateFormat, $dateTimeFormat, $resourceFile;

use BIO\BCR\SampleAcceptance;


$db = $dbm->getDB();
$resourceFile = "sample_acceptance/sampleAcceptance-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();
switch ($query) {

    // Barkod okutulunca: bu barkod daha önce kabul edildi mi, cihazda (D-10) sonucu var mı?
    // Hasta bilgisi kaynağı yok (barkod sadece numara, Tenay bağlantısı yok); yalnızca sistemde olan döner.
    case "getBarcodeInfo":
        try{
            $execType = ET_DEFAULT;
            $barcode = trim((string) ($_POST["BARCODE"] ?? ""));

            $acceptance = null;
            $runs = array();
            if ($barcode !== "" && ctype_digit($barcode)) {
                $sa = $db->getRepository("BIO\BCR\SampleAcceptance")->findOneBy(array(
                    "barcode" => $barcode,
                    "company" => $_SESSION["COMPANY_ID"],
                ));
                if ($sa !== null) {
                    $acceptance = array(
                        "sampleAcceptanceId" => $sa->getSampleAcceptanceId(),
                        "patientName"        => $sa->getPatientNameSurname(),
                        "fileNo"             => $sa->getFileNo(),
                        "acceptedAt"         => $sa->getAcceptedAt()->format("Y-m-d H:i:s"),
                        "status"             => $sa->getStatus(),
                        "rejectReason"       => $sa->getRejectReason(),
                    );
                }

                $results = $db->createQuery("
                    SELECT r FROM BIO\BCR\DeviceResult r
                     WHERE r.sampleId = :BARCODE
                       AND IDENTITY(r.company) = :COMPANY_ID
                       AND r.recordFl > 0
                     ORDER BY r.injectionDate ASC, r.injectionNo ASC
                ")->setParameters(array("BARCODE" => $barcode, "COMPANY_ID" => $_SESSION["COMPANY_ID"]))
                  ->getResult();
                foreach ($results as $r) {
                    $runs[] = array(
                        "injectionDate" => $r->getInjectionDate()->format("Y-m-d"),
                        "injectionNo"   => $r->getInjectionNo(),
                        "f"             => $r->getFValue(),
                        "a1c"           => $r->getA1cValue(),
                        "a2"            => $r->getA2Value(),
                        "comment"       => $r->getDeviceComment(),
                    );
                }
            }

            $dtResponse = array("barcode" => $barcode, "acceptance" => $acceptance, "runs" => $runs);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Numune reddi: hemolizli, pıhtılı, yetersiz vb. Kayıt silinmez; durum REJECTED olur, kim/ne zaman/neden saklanır.
    case "setReject":
        $execType = ET_SAVE;
        try{
            $id     = (int) ($_POST["SAMPLE_ACCEPTANCE_ID"] ?? 0);
            $reason = trim((string) ($_POST["REJECT_REASON"] ?? ""));

            $sa = $id > 0 ? $db->getRepository("BIO\BCR\SampleAcceptance")->findOneBy(array(
                "sampleAcceptanceId" => $id,
                "company"            => $_SESSION["COMPANY_ID"],
            )) : null;

            if ($sa === null) {
                $resCode = 1;
                $resMsg = "Reddedilecek numune bulunamadı.";
            } elseif ($reason === "") {
                $resCode = 1;
                $resMsg = "Red nedeni girin.";
            } elseif (!$sa->canReject()) {
                $resCode = 3;
                $resMsg = "Bu numune reddedilemez (durum: " . $sa->getStatus() . "). Onaylanmış numunede revizyon yapılır.";
            } else {
                $user = $db->getReference("BIO\MNG\AppUser", (int) $_SESSION["USER_ID"]);
                $sa->reject(mb_substr($reason, 0, 200), $user);
                $db->flush();

                $resObject = array("SAMPLE_ACCEPTANCE_ID" => $sa->getSampleAcceptanceId(), "status" => $sa->getStatus());
                $resMsg = "Numune reddedildi: " . $sa->getBarcode();
                $resCode = 0;
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getGENDER_ID":
        try{
            $execType = ET_DEFAULT;
            $dql = "
                SELECT g.genderId,
                       g.gender,
                       g.activeFl
                  FROM BIO\MNG\Gender g
                 WHERE g.recordFl > 0 
                 order by g.gender
            ";
            $dtResponse = $db->createQuery($dql)
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

    case "getGridList":
        $execType = ET_JQGRID;
        try{


            // Bugün kabul edilenler; arama kutusu (Q) barkod, ad soyad ve dosya no'da arar
            $whereClause = "
                AND mt.acceptedAt >= :TODAY AND mt.acceptedAt < :TOMORROW
                AND (
                    :Q = ''
                    OR mt.barcode LIKE :Q_LIKE
                    OR mt.patientNameSurname LIKE :Q_LIKE
                    OR mt.fileNo LIKE :Q_LIKE
                )
                AND mt.recordFl > 0
                AND mt.activeFl = 'Y'
            ";
            $q = trim((string) ($_POST["Q"] ?? ""));
            $params = [
                "COMPANY_ID" => $_SESSION["COMPANY_ID"],
                "TODAY"      => new DateTimeImmutable("today"),
                "TOMORROW"   => new DateTimeImmutable("tomorrow"),
                "Q"          => $q,
                "Q_LIKE"     => "%" . $q . "%",
            ];

            $dql = "
                SELECT COUNT(mt) RECORD_COUNT
                  FROM BIO\BCR\SampleAcceptance mt
                  LEFT JOIN mt.gender g
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
            ";
            $dql = $dql.$whereClause;
            $dt = $db->createQuery($dql)
                ->setParameters($params)
                ->getArrayResult();
            $totalRecords = $dt[0]["RECORD_COUNT"];

            $dql = "
                SELECT mt, g
                  FROM BIO\BCR\SampleAcceptance mt
                  LEFT JOIN mt.gender g
                 WHERE IDENTITY(mt.company) = :COMPANY_ID
            ";
            $dql = $dql.$whereClause;

            // Sıralama alanı istemciden gelir; DQL'e doğrudan eklenmeden önce izin listesiyle kontrol edilir
            $sortable  = ["mt.sampleAcceptanceId", "mt.barcode", "mt.patientNameSurname", "mt.fileNo", "g.gender", "mt.acceptedAt", "mt.status"];
            $sortBy    = in_array($_POST["sidx"] ?? "", $sortable, true) ? $_POST["sidx"] : "mt.sampleAcceptanceId";
            $direction = strtoupper($_POST["sord"] ?? "") === "ASC" ? "ASC" : "DESC";
            $dql.= " order by ".$sortBy." ".$direction;

            $rows = max(1, (int) ($_POST["rows"] ?? 10));
            $page = max(1, (int) ($_POST["page"] ?? 1));

            $dtResponse = $db->createQuery($dql)
                ->setParameters($params)
                ->setMaxResults($rows)
                ->setFirstResult(($page - 1) * $rows)
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
    case "getSelectedId":
        try{
            $dql = "
            SELECT mt, g
              FROM BIO\BCR\SampleAcceptance mt
              LEFT JOIN mt.gender g
             WHERE IDENTITY(mt.company) = :COMPANY_ID
               AND mt.sampleAcceptanceId = :SAMPLE_ACCEPTANCE_ID
        ";
            $dtResponse = $db->createQuery($dql)
                ->setParameter("COMPANY_ID", $_SESSION["COMPANY_ID"])
                ->setParameter("SAMPLE_ACCEPTANCE_ID", (int) $_POST["SELECTED_ID"])
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
    case "setSave":

        $execType = ET_SAVE;

        try{
            // --- Gelen alanlar ---
            $sampleAcceptanceId = (int) ($_POST["SAMPLE_ACCEPTANCE_ID"] ?? 0);
            $barcode            = trim((string) ($_POST["BARCODE"] ?? ""));
            $barcodeConfirm     = trim((string) ($_POST["BARCODE_CONFIRM"] ?? ""));
            $barcodeManual      = ($_POST["BARCODE_MANUAL_FL"] ?? "N") === "Y";
            $patientName        = trim((string) ($_POST["PATIENT_NAME"] ?? ""));
            $fileNo             = trim((string) ($_POST["FILE_NO"] ?? ""));
            $institution        = trim((string) ($_POST["INSTITUTION"] ?? ""));
            $birthDateText      = trim((string) ($_POST["BIRTH_DATE"] ?? ""));
            $birthDate          = DateTimeImmutable::createFromFormat("!d.m.Y", $birthDateText);
            // createFromFormat 31.02 gibi tarihleri sessizce 3 Mart'a çevirir; geri biçimlenince aynı değilse geçersizdir
            if ($birthDate !== false && $birthDate->format("d.m.Y") !== $birthDateText) {
                $birthDate = false;
            }
            $gender             = $db->find("BIO\MNG\Gender", (int) ($_POST["GENDER_ID"] ?? 0));

            // --- Sunucu tarafı kontrol (tarayıcıdaki doğrulama atlanabilir) ---
            $error = null;
            if ($barcode === "" || !ctype_digit($barcode)) {
                $error = "Barkod yalnızca rakamlardan oluşmalı.";
            } elseif ($sampleAcceptanceId === 0 && $barcodeManual && $barcodeConfirm !== $barcode) {
                $error = "Elle girilen barkod ile tekrar yazılan barkod eşleşmiyor.";
            } elseif ($patientName === "" || $fileNo === "") {
                $error = "Ad soyad ve dosya no zorunludur.";
            } elseif ($gender === null) {
                $error = "Cinsiyet seçin.";
            } elseif ($birthDate === false || $birthDate > new DateTimeImmutable("today")) {
                $error = "Doğum tarihi gg.aa.yyyy biçiminde ve bugünden ileri olmayan bir tarih olmalı.";
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
            } else {
                // Aynı barkod başka bir kayıtta var mı? (benzersiz kısıtı veritabanına bırakılırsa EntityManager kapanır)
                $dup = $db->getRepository("BIO\BCR\SampleAcceptance")->findOneBy(array("barcode" => $barcode));
                if ($dup !== null && $dup->getSampleAcceptanceId() !== $sampleAcceptanceId) {
                    $resCode = 2;
                    $resMsg = $lang["RECORD_IS_ALREADY_THERE"] ?? "Bu barkod zaten kabul edilmiş.";
                } else {
                    // Seçili kayıt (güncelleme) yalnızca oturumdaki şirkete aitse bulunur
                    $rs = $sampleAcceptanceId > 0
                        ? $db->getRepository("BIO\BCR\SampleAcceptance")->findBy(
                            array(
                                "sampleAcceptanceId" => $sampleAcceptanceId,
                                "company"            => $_SESSION["COMPANY_ID"]
                            ))
                        : array();

                    if (count($rs) == 0) {
                        // --- Yeni kabul ---
                        // C_AT/C_BY, ACTIVE_FL/RECORD_FL, şirket ve kabul zamanı (ACCEPTED_AT = şimdi) otomatik dolar
                        $newEntity = new SampleAcceptance($barcode, $patientName, $fileNo, $gender, $birthDate, $barcodeManual);
                        $newEntity->setInstitution($institution !== "" ? $institution : null);

                        $db->persist($newEntity);
                        $db->flush();

                        // Cihaz bu barkodu kabulden önce çalışmışsa sonuçlar hemen bu numuneye bağlanır
                        // (bir sonraki cihaz çekmesi beklenmez); numune doğrudan "Onay bekliyor" olur.
                        $deviceRuns = $db->createQuery("
                            SELECT r FROM BIO\BCR\DeviceResult r
                             WHERE r.sampleId = :BARCODE
                               AND IDENTITY(r.company) = :COMPANY_ID
                               AND r.sampleAcceptance IS NULL
                               AND r.recordFl > 0
                        ")->setParameters(array("BARCODE" => $barcode, "COMPANY_ID" => $_SESSION["COMPANY_ID"]))
                          ->getResult();
                        foreach ($deviceRuns as $run) {
                            $run->linkTo($newEntity, \BIO\BCR\DeviceResult::MATCH_AUTO);   // durumu da "onay bekliyor" yapar
                        }
                        if ($deviceRuns) {
                            $db->flush();
                        }

                        $resObject = array(
                            "SAMPLE_ACCEPTANCE_ID" => $newEntity->getSampleAcceptanceId(),
                            "linkedRuns"           => count($deviceRuns),
                            "status"               => $newEntity->getStatus(),
                        );
                        $resMsg = ($lang["RECORD_IS_ADDED"] ?? "Kayıt eklendi.")
                            . ($deviceRuns ? " · Cihazdaki " . count($deviceRuns) . " sonuç bu numuneye bağlandı (onay bekliyor)." : "");
                        $resCode = 0;
                    } else {
                        // --- Güncelleme ---
                        $value = $rs[0];
                        if ($value->getStatus() === SampleAcceptance::STATUS_REJECTED) {
                            $resCode = 3;
                            $resMsg = "Reddedilmiş numune değiştirilemez.";
                        } elseif ($value->getBarcode() !== $barcode) {
                            // Barkod cihaz sonucuyla eşleşmenin anahtarı; kabulden sonra değiştirilmez
                            $resCode = 3;
                            $resMsg = "Kabul edilmiş numunenin barkodu değiştirilemez.";
                        } else {
                            $value->setPatientNameSurname($patientName);
                            $value->setFileNo($fileNo);
                            $value->setGender($gender);
                            $value->setBirthDate($birthDate);
                            $value->setInstitution($institution !== "" ? $institution : null);

                            $db->flush();   // U_AT/U_BY AuditListener ile dolar

                            $resObject = array("SAMPLE_ACCEPTANCE_ID" => $value->getSampleAcceptanceId());
                            $resMsg = $lang["RECORD_IS_CHANGED"] ?? "Kayıt güncellendi.";
                            $resCode = 0;
                        }
                    }
                }
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}
