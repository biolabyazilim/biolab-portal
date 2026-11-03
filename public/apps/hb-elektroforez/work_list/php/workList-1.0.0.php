<?php
/**
 * HbRapor — İş listesi veri kaynağı
 *
 *   getFetchStatus      → D-10 şeridi: son çekme zamanı, başarılı mı, şu an sürüyor mu
 *   setFetchDeviceList  → diaweb.cgi sonuç listesini okur, yeni çalışmaları device_result'a yazar,
 *                         barkodu kabulde olanları bağlar ve numuneyi "Sonuç geldi" yapar
 *   getWorkList         → sekmelerin verisi (onay bekleyen / kabulü olmayan sonuç / sonucu gelmeyen / onaylanan)
 *   getReportDownload   → onaylananlardan seçilenlerin rapor PDF'leri (tek → PDF, çok → ZIP)
 *
 * Ekran cihaza doğrudan bağlanmaz; çekme kısa adımlarla bu case'ler üzerinden yapılır (cihaz çok yavaş).
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $resourceFile;

use Biolab\Core\Hb\HbReportRules;
use BIO\BCR\DeviceFetchLog;
use BIO\BCR\DeviceResult;
use BIO\BCR\DeviceResultFile;
use BIO\BCR\DeviceResultPeak;
use BIO\BCR\SampleAcceptance;
use BIO\MNG\Company;
use Symfony\Component\HttpClient\HttpClient;

$db = $dbm->getDB();
$resourceFile = "work_list/workList-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$d10 = (require __DIR__ . '/../../../../../config.php')['d10'];

switch ($query) {

    case "getFetchStatus":
        $execType = ET_DEFAULT;
        try{
            $last = $db->createQuery("SELECT l FROM BIO\BCR\DeviceFetchLog l ORDER BY l.deviceFetchLogId DESC")
                ->setMaxResults(1)->getOneOrNullResult();
            $lastOk = $db->createQuery("SELECT l FROM BIO\BCR\DeviceFetchLog l WHERE l.successFl = 'Y' ORDER BY l.deviceFetchLogId DESC")
                ->setMaxResults(1)->getOneOrNullResult();

            $dtResponse = array(
                "running"       => wlIsRunning($last, $d10),
                "lastSuccess"   => $last !== null && $last->isSuccess(),
                "lastAt"        => $last?->getFinishedAt() ?? $last?->getStartedAt(),
                "lastMessage"   => $last?->getMessage(),
                "lastOkAt"      => $lastOk?->getFinishedAt(),
                "lastOkNew"     => $lastOk?->getNewCount() ?? 0,
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setFetchDeviceList":
        $execType = ET_CUSTOM;
        set_time_limit(max(300, (int) $d10["timeout"] * 2));
        try{
            $force = ($_POST["FORCE"] ?? "0") === "1";   // düğme: 1, otomatik: 0

            // --- Kilit: başka bir ekran şu an çekiyorsa ya da az önce çektiyse atla ---
            $last = $db->createQuery("SELECT l FROM BIO\BCR\DeviceFetchLog l ORDER BY l.deviceFetchLogId DESC")
                ->setMaxResults(1)->getOneOrNullResult();
            if (wlIsRunning($last, $d10)) {
                $resCode = 0;
                $resMsg = "Cihazdan çekme zaten sürüyor.";
                $resObject = array("skipped" => true, "newCount" => 0, "reportPending" => array()   /* çekmeyi yapan ekran raporları da alır */);
                break;
            }
            if (!$force && $last !== null && $last->getFinishedAt() !== null
                && $last->getFinishedAt() > new DateTimeImmutable("-" . (int) $d10["min_interval"] . " seconds")) {
                $resCode = 0;
                $resMsg = "Son çekme yeni; atlandı.";
                $resObject = array("skipped" => true, "newCount" => 0, "reportPending" => wlPendingReportIds($db, (int) $_SESSION["COMPANY_ID"]));
                break;
            }

            $company  = $db->getReference(Company::class, (int) $_SESSION["COMPANY_ID"]);
            $dateTo   = new DateTimeImmutable("today");
            $dateFrom = $dateTo->modify("-" . (int) $d10["days_back"] . " days");
            // Düğmeyle çekmede ekrandaki tarih aralığı kullanılır (cihaz yavaş: en fazla max_days_range gün)
            if ($force) {
                $reqFrom = DateTimeImmutable::createFromFormat("!d.m.Y", (string) ($_POST["DATE_FROM"] ?? ""));
                $reqTo   = DateTimeImmutable::createFromFormat("!d.m.Y", (string) ($_POST["DATE_TO"] ?? ""));
                if ($reqFrom && $reqTo && $reqFrom <= $reqTo) {
                    $dateTo   = min($reqTo, $dateTo);
                    $dateFrom = max($reqFrom, $dateTo->modify("-" . (int) ($d10["max_days_range"] ?? 62) . " days"));
                }
            }
            $userId   = isset($_SESSION["USER_ID"]) ? (int) $_SESSION["USER_ID"] : null;

            $totalList = 0;
            $totalNew  = 0;
            $errors    = array();

            foreach ($d10["tests"] as $testCode) {
                $log = new DeviceFetchLog($testCode, $dateFrom, $dateTo, $userId);
                $db->persist($log);
                $db->flush();   // "sürüyor" kaydı hemen görünsün

                try {
                    $html = wlFetchResultList($d10, $testCode, $dateFrom, $dateTo);
                    $rows = wlParseResultList($html);
                    $new  = 0;

                    foreach ($rows as $row) {
                        $existing = $db->getRepository(DeviceResult::class)->findOneBy(array(
                            "injectionDate" => $row["injectionDate"],
                            "injectionNo"   => $row["injectionNo"],
                        ));
                        if ($existing === null) {
                            $existing = new DeviceResult($company, $row["sampleId"], $testCode,
                                $row["injectionDate"], $row["injectionNo"], $row["deviceKey"]);
                            $existing->setRackPosition($row["rackPosition"]);
                            $db->persist($existing);
                            $new++;
                        }
                        // Değerler/yorum cihazda sonradan değişebilir; her çekmede güncellenir
                        $existing->setValues($row["f"], $row["a1c"], $row["a2"], $row["comment"]);
                    }
                    $db->flush();

                    $log->finish(true, count($rows), $new);
                    $db->flush();

                    $totalList += count($rows);
                    $totalNew  += $new;
                } catch (Throwable $exFetch) {
                    $log->finish(false, 0, 0, $exFetch->getMessage());
                    $db->flush();
                    $errors[] = $testCode . ": " . $exFetch->getMessage();
                }
            }

            // Kabulü sonradan yapılanlar dahil, eşleşmemiş sonuçları barkodla bağla
            $linked = wlLinkResults($db, (int) $_SESSION["COMPANY_ID"]);

            $resObject = array("skipped" => false, "listCount" => $totalList, "newCount" => $totalNew, "linked" => $linked,
                "reportPending" => wlPendingReportIds($db, (int) $_SESSION["COMPANY_ID"]));   // raporu alınacak çalışmalar
            if ($errors) {
                $resCode = 50;
                $resMsg = "Cihaza ulaşılamadı: " . implode(" | ", $errors);
            } else {
                $resCode = 0;
                $resMsg = $totalNew . " yeni sonuç";
            }
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Tek bir çalışmanın raporunu cihazdan alır: enjeksiyon saati, yöntem, pik tablosu, Total Area,
    // orijinal PDF ve kromatogram. JS raporu alınmamış çalışmalar için bunu sırayla (tek tek) çağırır.
    case "setFetchReport":
        $execType = ET_CUSTOM;
        set_time_limit(max(120, (int) $d10["timeout"] + 30));
        try{
            $result = $db->getRepository(DeviceResult::class)->findOneBy(array(
                "deviceResultId" => (int) ($_POST["DEVICE_RESULT_ID"] ?? 0),
                "company"        => (int) $_SESSION["COMPANY_ID"],
            ));
            if ($result === null) {
                $resCode = 1;
                $resMsg = "Çalışma bulunamadı.";
                break;
            }
            if ($result->isReportFetched()) {
                $resCode = 0;
                $resMsg = "Rapor zaten alınmış.";
                $resObject = array("skipped" => true);
                break;
            }

            try {
                $fetched = wlFetchReportPdf($d10, $result->getTestCode(), $result->getDeviceKey());
                $parsed  = wlParseReportPdf($fetched["pdf"]);

                // Rapor gerçekten bu çalışmaya mı ait? (yanlış rapor kaydetmektense hata ver)
                if ($parsed["sampleId"] !== $result->getSampleId() || $parsed["injectionNo"] !== $result->getInjectionNo()
                    || $parsed["injectedAt"] === null
                    || $parsed["injectedAt"]->format("Y-m-d") !== $result->getInjectionDate()->format("Y-m-d")) {
                    throw new RuntimeException("Rapor çalışmayla uyuşmuyor (rapor: " . ($parsed["sampleId"] ?? "?")
                        . " enj. " . ($parsed["injectionNo"] ?? "?") . ")");
                }
            } catch (Throwable $exFetch) {
                $result->markReportFailed($exFetch->getMessage());
                $db->flush();
                $resCode = 50;
                $resMsg = "Rapor alınamadı: " . $exFetch->getMessage();
                $resObject = array("deviceResultId" => $result->getDeviceResultId(), "attempts" => $result->getReportAttempts());
                break;
            }

            $result->setReportData($parsed["injectedAt"], $parsed["method"], $parsed["totalArea"]);
            foreach ($parsed["peaks"] as $pk) {
                $db->persist(new DeviceResultPeak($result, $pk["sortNo"], $pk["peakName"], $pk["retentionTime"],
                    $pk["height"], $pk["area"], $pk["areaPct"]));
            }
            $db->persist(new DeviceResultFile($result, DeviceResultFile::TYPE_DEVICE_PDF, "application/pdf",
                $fetched["pdf"], $fetched["fileName"]));
            if ($parsed["chromatogramPng"] !== null) {
                $db->persist(new DeviceResultFile($result, DeviceResultFile::TYPE_CHROMATOGRAM_PNG, "image/png",
                    $parsed["chromatogramPng"]));
            }
            if ($parsed["peakError"] !== null) {
                $result->markReportFailed($parsed["peakError"]);   // rapor alındı ama pik tablosu okunamadı: uyarı kalır
            }
            $db->flush();

            $unknown = array_values(array_filter($parsed["peaks"], fn($p) => HbReportRules::isAbnormalPeak($p["peakName"])));
            $resObject = array(
                "deviceResultId" => $result->getDeviceResultId(),
                "peakCount"      => count($parsed["peaks"]),
                "unknownPeaks"   => count($unknown),
                "injectedAt"     => $parsed["injectedAt"]->format("Y-m-d H:i"),
            );
            $resCode = 0;
            $resMsg = $result->getSampleId() . " raporu alındı";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Kabulü olmayan bir sonucun bağlanabileceği kabuller: son 30 gün, reddedilmemiş ve onaylanmamış
    case "getMatchCandidates":
        $execType = ET_DEFAULT;
        try{
            $rows = $db->createQuery("
                SELECT sa FROM BIO\BCR\SampleAcceptance sa
                 WHERE IDENTITY(sa.company) = :COMPANY_ID
                   AND sa.status IN (:STATUSES)
                   AND sa.acceptedAt >= :SINCE
                   AND sa.recordFl > 0 AND sa.activeFl = 'Y'
                 ORDER BY sa.acceptedAt DESC
            ")->setParameters(array(
                "COMPANY_ID" => (int) $_SESSION["COMPANY_ID"],
                "STATUSES"   => array(SampleAcceptance::STATUS_WAITING, SampleAcceptance::STATUS_RESULTED, SampleAcceptance::STATUS_RERUN),
                "SINCE"      => new DateTimeImmutable("today -30 days"),
            ))->getResult();

            $dtResponse = array();
            foreach ($rows as $sa) {
                $dtResponse[] = array(
                    "sampleAcceptanceId" => $sa->getSampleAcceptanceId(),
                    "barcode"            => $sa->getBarcode(),
                    "patientName"        => $sa->getPatientNameSurname(),
                    "fileNo"             => $sa->getFileNo(),
                    "acceptedAt"         => $sa->getAcceptedAt()->format("Y-m-d H:i:s"),
                    "status"             => $sa->getStatus(),
                );
            }
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Kabulü olmayan sonucu elle bir kabule bağlar (ör. barkod yazım hatası). Gerekçe zorunlu, iz saklanır.
    case "setMatchResult":
        $execType = ET_SAVE;
        try{
            $resultId = (int) ($_POST["DEVICE_RESULT_ID"] ?? 0);
            $saId     = (int) ($_POST["SAMPLE_ACCEPTANCE_ID"] ?? 0);
            $note     = trim((string) ($_POST["MATCH_NOTE"] ?? ""));
            $companyId = (int) $_SESSION["COMPANY_ID"];

            $result = $resultId > 0 ? $db->getRepository(DeviceResult::class)->findOneBy(array(
                "deviceResultId" => $resultId, "company" => $companyId)) : null;
            $sa = $saId > 0 ? $db->getRepository(SampleAcceptance::class)->findOneBy(array(
                "sampleAcceptanceId" => $saId, "company" => $companyId)) : null;

            if ($result === null || $sa === null) {
                $resCode = 1;
                $resMsg = "Sonuç ya da kabul bulunamadı.";
            } elseif ($result->getSampleAcceptance() !== null) {
                $resCode = 3;
                $resMsg = "Bu sonuç zaten bir kabule bağlı.";
            } elseif (!in_array($sa->getStatus(), array(SampleAcceptance::STATUS_WAITING, SampleAcceptance::STATUS_RESULTED, SampleAcceptance::STATUS_RERUN), true)) {
                $resCode = 3;
                $resMsg = "Bu kabule sonuç bağlanamaz (durum: " . $sa->getStatus() . ").";
            } elseif ($note === "") {
                $resCode = 1;
                $resMsg = "Eşleştirme gerekçesi girin.";
            } else {
                $user = $db->getReference("BIO\MNG\AppUser", (int) $_SESSION["USER_ID"]);
                $result->linkTo($sa, DeviceResult::MATCH_MANUAL, $user, mb_substr($note, 0, 200));
                $db->flush();

                $resObject = array("SAMPLE_ACCEPTANCE_ID" => $sa->getSampleAcceptanceId(), "status" => $sa->getStatus());
                $resMsg = "Cihaz sonucu " . $result->getSampleId() . " → kabul " . $sa->getBarcode() . " ile eşleştirildi.";
                $resCode = 0;
            }
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Onaylananlar sekmesinde seçilen numunelerin geçerli rapor PDF'leri (Tenay'a toplu yükleme için).
    // Tek rapor → PDF; birden fazla → ZIP (içinde her rapor kendi adıyla: SampleID-tarih.pdf).
    // PDF'i arşivde olmayanlar (PDF arşivinden önce onaylananlar) atlanır ve barkodları bildirilir.
    case "getReportDownload":
        $execType = ET_CUSTOM;
        try{
            $ids = array_values(array_unique(array_filter(array_map("intval", explode(",", (string) ($_POST["IDS"] ?? ""))))));
            if (!$ids) {
                $resCode = 1;
                $resMsg = "İndirilecek raporları seçin.";
                break;
            }
            if (count($ids) > 300) {
                $resCode = 1;
                $resMsg = "Bir seferde en fazla 300 rapor indirilebilir.";
                break;
            }
            $files = $db->createQuery("
                SELECT f, rp, sa FROM BIO\BCR\SampleReportFile f
                  JOIN f.sampleReport rp JOIN rp.sampleAcceptance sa
                 WHERE sa.sampleAcceptanceId IN (:IDS) AND IDENTITY(rp.company) = :COMPANY_ID
                   AND rp.supersededAt IS NULL AND rp.recordFl > 0
                 ORDER BY sa.barcode ASC
            ")->setParameters(array("IDS" => $ids, "COMPANY_ID" => (int) $_SESSION["COMPANY_ID"]))->getResult();

            $items = array();
            $found = array();
            $broken = array();
            foreach ($files as $f) {
                $rp = $f->getSampleReport();
                $sa = $rp->getSampleAcceptance();
                $found[$sa->getSampleAcceptanceId()] = true;
                if (!$f->isIntact()) { $broken[] = $sa->getBarcode(); continue; }   // SHA-256 tutmuyor: verme
                $items[] = array(
                    "name"  => \Biolab\Core\Hb\HbReportPdf::fileName($sa->getBarcode(), $rp->getApprovedAt(), $rp->getRevisionNo()),
                    "bytes" => $f->getContent(),
                );
            }
            $missing = array_values(array_diff($ids, array_keys($found)));
            $skipped = $missing ? $db->createQuery("
                SELECT sa.barcode FROM BIO\BCR\SampleAcceptance sa
                 WHERE sa.sampleAcceptanceId IN (:IDS) AND IDENTITY(sa.company) = :COMPANY_ID
            ")->setParameters(array("IDS" => $missing, "COMPANY_ID" => (int) $_SESSION["COMPANY_ID"]))->getSingleColumnResult() : array();

            if (!$items) {
                $resCode = 1;
                $resMsg = "Seçilen numunelerin arşivde rapor PDF'i yok"
                        . ($skipped ? " (" . implode(", ", $skipped) . ")" : "") . ". Gerekirse uzman onay ekranından düzeltme yapılarak PDF üretilir.";
                break;
            }

            if (count($items) === 1) {
                $fileName = $items[0]["name"];
                $token = \Biolab\Core\Http\PdfHandoff::stash($items[0]["bytes"], $fileName);
                $url = "/api/pdf.php?t=" . $token . "&dl=1";
            } else {
                $tmp = tempnam(sys_get_temp_dir(), "hbz");
                $zip = new ZipArchive();
                if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException("ZIP dosyası oluşturulamadı.");
                }
                foreach ($items as $it) $zip->addFromString($it["name"], $it["bytes"]);
                $zip->close();
                $bytes = (string) file_get_contents($tmp);
                @unlink($tmp);
                $fileName = "HbRaporlar-" . date("d.m.Y-Hi") . "-" . count($items) . "adet.zip";
                $token = \Biolab\Core\Http\PdfHandoff::stash($bytes, $fileName, "application/zip");
                $url = "/api/pdf.php?t=" . $token;
            }

            $resObject = array(
                "url"      => $url,
                "fileName" => $fileName,
                "count"    => count($items),
                "skipped"  => $skipped,
                "broken"   => $broken,
            );
            $resCode = 0;
            $resMsg = count($items) . " rapor indiriliyor";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getWorkList":
        $execType = ET_DEFAULT;
        try{
            $companyId = (int) $_SESSION["COMPANY_ID"];
            $dateFrom  = DateTimeImmutable::createFromFormat("!d.m.Y", (string) ($_POST["DATE_FROM"] ?? "")) ?: new DateTimeImmutable("today -7 days");
            $dateTo    = DateTimeImmutable::createFromFormat("!d.m.Y", (string) ($_POST["DATE_TO"] ?? "")) ?: new DateTimeImmutable("today");
            $dateToEnd = $dateTo->modify("+1 day");

            // Kabul edilmiş numuneler (tarih aralığında) ve çalışmaları
            $samples = $db->createQuery("
                SELECT sa, g
                  FROM BIO\BCR\SampleAcceptance sa
                  JOIN sa.gender g
                 WHERE IDENTITY(sa.company) = :COMPANY_ID
                   AND sa.acceptedAt >= :FROM AND sa.acceptedAt < :TO
                   AND sa.recordFl > 0 AND sa.activeFl = 'Y'
                 ORDER BY sa.acceptedAt DESC
            ")->setParameters(array("COMPANY_ID" => $companyId, "FROM" => $dateFrom, "TO" => $dateToEnd))
              ->getResult();

            $runsBySample = array();
            if ($samples) {
                $runs = $db->createQuery("
                    SELECT r FROM BIO\BCR\DeviceResult r
                     WHERE r.sampleAcceptance IN (:IDS) 
                     AND r.recordFl > 0
                     ORDER BY r.injectionDate ASC, r.injectionNo ASC
                ")->setParameter("IDS", $samples)->getResult();
                $unknownMap = wlAbnormalPeakMap($db, $runs);
                foreach ($runs as $r) {
                    $runsBySample[$r->getSampleAcceptance()->getSampleAcceptanceId()][] = wlRunToArray($r, $unknownMap);
                }
            }
            $reportBlocks = wlCurrentReportBlocks($db, $samples);

            $rows = array();
            foreach ($samples as $s) {
                $runs = $runsBySample[$s->getSampleAcceptanceId()] ?? array();
                // Yorum bloğu: onaylıysa raporda seçilen, değilse son çalışmaya göre öneri (ekran akışı: "önerilen yorum bloğu her satırda")
                if (isset($reportBlocks[$s->getSampleAcceptanceId()])) {
                    $block = array("text" => $reportBlocks[$s->getSampleAcceptanceId()], "kind" => "approved");
                } else {
                    $block = $runs ? wlSuggestBlock($s, end($runs), count($runs)) : null;
                }
                $rows[] = array(
                    "sampleAcceptanceId" => $s->getSampleAcceptanceId(),
                    "barcode"            => $s->getBarcode(),
                    "barcodeManual"      => $s->isBarcodeManual(),
                    "patientName"        => $s->getPatientNameSurname(),
                    "fileNo"             => $s->getFileNo(),
                    "gender"             => $s->getGender()->getGender(),
                    "ageYears"           => intdiv($s->getAgeInMonths(), 12),
                    "ageMonths"          => $s->getAgeInMonths(),
                    "institution"        => $s->getInstitution(),
                    "acceptedAt"         => $s->getAcceptedAt()->format("Y-m-d H:i:s"),
                    "status"             => $s->getStatus(),
                    "runCount"           => count($runs),
                    "lastRun"            => $runs ? end($runs) : null,
                    "runs"               => $runs,
                    "block"              => $block,
                );
            }

            // Kabulü olmayan sonuçlar (çalışma tarihine göre)
            $unmatched = $db->createQuery("
                SELECT r FROM BIO\BCR\DeviceResult r
                 WHERE IDENTITY(r.company) = :COMPANY_ID
                   AND r.sampleAcceptance IS NULL
                   AND r.injectionDate >= :FROM AND r.injectionDate < :TO
                   AND r.recordFl > 0
                 ORDER BY r.injectionDate DESC, r.injectionNo DESC
            ")->setParameters(array("COMPANY_ID" => $companyId, "FROM" => $dateFrom, "TO" => $dateToEnd))
              ->getResult();

            // Olası yazım hatası önerisi: sonucu gelmemiş kabullerde 1 karakter farklı barkod
            $waitingBarcodes = $db->createQuery("
                SELECT sa.barcode FROM BIO\BCR\SampleAcceptance sa
                 WHERE IDENTITY(sa.company) = :COMPANY_ID AND sa.status = :WAITING
                   AND sa.acceptedAt >= :SINCE AND sa.recordFl > 0
            ")->setParameters(array("COMPANY_ID" => $companyId, "WAITING" => SampleAcceptance::STATUS_WAITING,
                                    "SINCE" => new DateTimeImmutable("today -30 days")))
              ->getSingleColumnResult();

            $unmatchedRows = array();
            $unknownMapUm = wlAbnormalPeakMap($db, $unmatched);
            foreach ($unmatched as $r) {
                $row = wlRunToArray($r, $unknownMapUm);
                $row["sampleId"]   = $r->getSampleId();
                $row["suggestion"] = wlSuggestBarcode($r->getSampleId(), $waitingBarcodes);
                $unmatchedRows[] = $row;
            }

            // Tüm sonuçlar (çalışma tarihine göre): kabulü olan/olmayan, cihazdan gelen her çalışma
            $allRuns = $db->createQuery("
                SELECT r, sa FROM BIO\BCR\DeviceResult r
                  LEFT JOIN r.sampleAcceptance sa
                 WHERE IDENTITY(r.company) = :COMPANY_ID
                   AND r.injectionDate >= :FROM AND r.injectionDate < :TO
                   AND r.recordFl > 0
                 ORDER BY r.injectionDate DESC, r.injectionNo DESC
            ")->setParameters(array("COMPANY_ID" => $companyId, "FROM" => $dateFrom, "TO" => $dateToEnd))
              ->getResult();

            $allRows = array();
            $unknownMapAll = wlAbnormalPeakMap($db, $allRuns);
            foreach ($allRuns as $r) {
                $row = wlRunToArray($r, $unknownMapAll);
                $sa  = $r->getSampleAcceptance();
                $row["sampleAcceptanceId"] = $sa?->getSampleAcceptanceId();
                $row["barcode"]            = $sa?->getBarcode();
                $row["patientName"]        = $sa?->getPatientNameSurname();
                $row["status"]             = $sa?->getStatus();
                $allRows[] = $row;
            }

            $dtResponse = array(
                "samples"   => $rows,
                "unmatched" => $unmatchedRows,
                "all"       => $allRows,
                "dateFrom"  => $dateFrom->format("d.m.Y"),
                "dateTo"    => $dateTo->format("d.m.Y"),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

/* ------------------------------------------------------------------ */
/*  Yardımcılar (wl = work list)                                        */
/* ------------------------------------------------------------------ */

// Son çekme bitmemişse ve çok eski değilse "sürüyor" sayılır
function wlIsRunning(?DeviceFetchLog $last, array $d10): bool
{
    return $last !== null
        && $last->getFinishedAt() === null
        && $last->getStartedAt() > new DateTimeImmutable("-" . (int) $d10["running_timeout"] . " seconds");
}

// diaweb.cgi?page=result&test=…&StartDate=MM/DD/YYYY&EndDate=MM/DD/YYYY
function wlFetchResultList(array $d10, string $testCode, DateTimeImmutable $from, DateTimeImmutable $to): string
{
    $client = HttpClient::create(array("timeout" => (float) $d10["timeout"], "max_duration" => (float) $d10["timeout"]));
    $response = $client->request("GET", $d10["url"], array("query" => array(
        "page"      => "result",
        "test"      => $testCode,
        "StartDate" => $from->format("m/d/Y"),
        "EndDate"   => $to->format("m/d/Y"),
    )));
    $html = $response->getContent();   // 200 dışı yanıtta hata fırlatır
    if (!mb_check_encoding($html, "UTF-8")) {
        $html = mb_convert_encoding($html, "UTF-8", "Windows-1252");
    }
    return $html;
}

/**
 * Sonuç listesindeki satırları çözer. Her satırın onay kutusu değeri:
 *   "SAMPLE_ID rackPos-injNo-gün-ay-yıl-R"   örn. "11695150 2-6-9-10-2026-R"
 * Kolonlar: Select | RACK # | INJ # | SAMPLE ID | F | A1c | A2 | Comment
 */
function wlParseResultList(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $rows = array();
    foreach ((new DOMXPath($doc))->query('//input[@type="checkbox" and starts-with(@name, "f")]') as $input) {
        $value = trim($input->getAttribute("value"));
        if (!preg_match('/^(\S+)\s+(\d+)-(\d+)-(\d+)-(\d+)-(\d{4})-\w+$/', $value, $m)) {
            continue;
        }
        $tr = $input;
        while ($tr !== null && strtolower($tr->nodeName) !== "tr") {
            $tr = $tr->parentNode;
        }
        $cells = array();
        if ($tr !== null) {
            foreach ($tr->childNodes as $cell) {
                if (in_array(strtolower($cell->nodeName), array("td", "th"), true)) {
                    $text = trim(str_replace("\xC2\xA0", " ", $cell->textContent));
                    $cells[] = $text === "" ? null : $text;
                }
            }
        }
        $rows[] = array(
            "sampleId"      => $m[1],
            "rackPosition"  => (int) $m[2],
            "injectionNo"   => (int) $m[3],
            "injectionDate" => new DateTimeImmutable(sprintf("%04d-%02d-%02d", $m[6], $m[5], $m[4])),
            "deviceKey"     => $value,
            "f"             => $cells[4] ?? null,
            "a1c"           => $cells[5] ?? null,
            "a2"            => $cells[6] ?? null,
            "comment"       => $cells[7] ?? null,
        );
    }
    return $rows;
}

// Kabulü olmayan sonuçları barkodla kabule bağlar; kabul "Sonuç bekleniyor" ise "Sonuç geldi" olur
function wlLinkResults($db, int $companyId): int
{
    $unmatched = $db->createQuery("
        SELECT r FROM BIO\BCR\DeviceResult r
         WHERE IDENTITY(r.company) = :COMPANY_ID AND r.sampleAcceptance IS NULL AND r.recordFl > 0
    ")->setParameter("COMPANY_ID", $companyId)->getResult();

    $linked = 0;
    foreach ($unmatched as $r) {
        // Silinmiş/pasif kabule bağlanırsa sonuç hiçbir sekmede görünmez; yalnız geçerli kabuller
        $sa = $db->createQuery("
            SELECT sa FROM BIO\BCR\SampleAcceptance sa
             WHERE sa.barcode = :BARCODE AND IDENTITY(sa.company) = :COMPANY_ID
               AND sa.recordFl > 0 AND sa.activeFl = 'Y'
             ORDER BY sa.acceptedAt DESC
        ")->setParameters(array("BARCODE" => $r->getSampleId(), "COMPANY_ID" => $companyId))
          ->setMaxResults(1)->getOneOrNullResult();
        if ($sa === null) {
            continue;
        }
        $r->linkTo($sa, DeviceResult::MATCH_AUTO);   // barkod aynı: otomatik eşleşme (durum da güncellenir)
        $linked++;
    }
    $db->flush();
    return $linked;
}

// Verilen çalışmaların anormal piklerini (Unknown, S-Window, D-Window ve beklenmeyen adlar) toplu çeker:
// [deviceResultId => [["name"=>..., "rt"=>..., "areaPct"=>...], ...]]. Hangi pikin anormal olduğu HbReportRules'ta.
function wlAbnormalPeakMap($db, array $results): array
{
    $ids = array();
    foreach ($results as $r) {
        if ($r->isReportFetched()) $ids[] = $r->getDeviceResultId();
    }
    if (!$ids) return array();

    $peaks = $db->createQuery("
        SELECT p FROM BIO\BCR\DeviceResultPeak p
         WHERE p.deviceResult IN (:IDS)
         ORDER BY p.sortNo ASC
    ")->setParameter("IDS", $ids)->getResult();

    $map = array();
    foreach ($peaks as $p) {
        if (!HbReportRules::isAbnormalPeak($p->getPeakName())) continue;
        $map[$p->getDeviceResult()->getDeviceResultId()][] = array(
            "name"    => $p->getPeakName(),
            "rt"      => $p->getRetentionTime(),
            "areaPct" => $p->getAreaPct(),
        );
    }
    return $map;
}

// Onaylı numunelerin geçerli rapor revizyonundaki bloklar: [sampleAcceptanceId => "C3+V1"]
function wlCurrentReportBlocks($db, array $samples): array
{
    if (!$samples) return array();
    $rows = $db->createQuery("
        SELECT IDENTITY(rp.sampleAcceptance) AS saId, rp.selectedBlocks FROM BIO\BCR\SampleReport rp
         WHERE rp.sampleAcceptance IN (:SAMPLES) AND rp.supersededAt IS NULL AND rp.recordFl > 0
    ")->setParameter("SAMPLES", $samples)->getArrayResult();
    $map = array();
    foreach ($rows as $row) $map[(int) $row["saId"]] = $row["selectedBlocks"] ?? "";
    return $map;
}

// Son çalışmaya göre blok önerisi (iş listesi satırı için). Uzman onay ekranında çalışmayı değiştirebilir.
// $run: wlRunToArray çıktısı (unknownPeaks = anormal pikler)
function wlSuggestBlock(SampleAcceptance $s, array $run, int $runCount): ?array
{
    if (!$run["reportFetched"]) return null;
    $ev = HbReportRules::evaluate(array(
        "ageDays"   => $s->getAgeInDays(),
        "ageMonths" => $s->getAgeInMonths(),
        "a2"        => $run["a2"],
        "f"         => $run["f"],
        "peaks"     => $run["unknownPeaks"],
        "runCount"  => $runCount,
    ));
    $manual = $ev["ageBlock"]["code"] === null || ($ev["hasVariant"] && $ev["vBlock"]["code"] === null);
    return array(
        "text" => $ev["suggestedBlocks"] ?? "",
        "kind" => $manual ? "manual" : "suggested",
    );
}

function wlRunToArray(DeviceResult $r, array $unknownMap = array()): array
{
    $injectedAt = $r->getInjectedAt();
    return array(
        "injectedAt"     => $injectedAt ? $injectedAt->format("Y-m-d H:i") : null,
        "method"         => $r->getMethod(),
        "totalArea"      => $r->getTotalArea(),
        "reportFetched"  => $r->isReportFetched(),
        // Rapor alındıysa da dolu olabilir: pik tablosu okunamadıysa uyarı olarak kalır
        "reportError"    => $r->getReportError(),
        // 3 başarısız denemeden sonra otomatik denenmez
        "reportGaveUp"   => !$r->isReportFetched() && $r->getReportAttempts() >= 3,
        "unknownPeaks"   => $unknownMap[$r->getDeviceResultId()] ?? array(),
        "deviceResultId" => $r->getDeviceResultId(),
        "injectionDate"  => $r->getInjectionDate()->format("Y-m-d"),
        "injectionNo"    => $r->getInjectionNo(),
        "rackPosition"   => $r->getRackPosition(),
        "f"              => $r->getFValue(),
        "a1c"            => $r->getA1cValue(),
        "a2"             => $r->getA2Value(),
        "comment"        => $r->getDeviceComment(),
        "sampleId"       => $r->getSampleId(),
        "matchType"      => $r->getMatchType(),   // AUTO / MANUAL
        "matchNote"      => $r->getMatchNote(),
    );
}

// Aynı uzunlukta, tek karakter farklı ya da yan yana iki karakteri yer değiştirmiş barkod
function wlSuggestBarcode(string $sampleId, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (strlen($c) !== strlen($sampleId) || $c === $sampleId) {
            continue;
        }
        if (levenshtein($c, $sampleId) === 1) {
            return $c;
        }
        $diff = array_keys(array_diff_assoc(str_split($c), str_split($sampleId)));
        if (count($diff) === 2 && $diff[1] === $diff[0] + 1
            && $c[$diff[0]] === $sampleId[$diff[1]] && $c[$diff[1]] === $sampleId[$diff[0]]) {
            return $c;
        }
    }
    return null;
}

/* ------------------------------------------------------------------ */
/*  Cihaz raporu (page=pdf)                                             */
/* ------------------------------------------------------------------ */

/**
 * Tek bir çalışmanın raporunu cihazdan alır.
 * Cihaz raporu kendi diskinde PDF olarak üretip ona yönlendirir (302); istemci yönlendirmeyi izler.
 * @return array{pdf:string, fileName:?string}
 */
function wlFetchReportPdf(array $d10, string $testCode, string $deviceKey): array
{
    $client = HttpClient::create(array("timeout" => (float) $d10["timeout"], "max_duration" => (float) $d10["timeout"]));
    $response = $client->request("GET", $d10["url"], array("query" => array(
        "page"   => "pdf",
        "test"   => $testCode,
        "nbfile" => 1,
        "f0"     => $deviceKey,
    )));
    $pdf = $response->getContent();
    if (strncmp($pdf, "%PDF", 4) !== 0) {
        throw new RuntimeException("Cihaz PDF yerine farklı bir yanıt döndü (" . strlen($pdf) . " bayt).");
    }
    $path = (string) parse_url((string) $response->getInfo("url"), PHP_URL_PATH);
    return array("pdf" => $pdf, "fileName" => $path !== "" ? basename($path) : null);
}

/**
 * D-10 rapor PDF'ini (htmldoc çıktısı) çözer. Metin sıkıştırılmış içerik akışında (metin)Tj komutları olarak,
 * kromatogram ise renk paletli bir resim (XObject) olarak durur.
 * @return array{sampleId:?string, injectedAt:?DateTimeImmutable, injectionNo:?int, rackPosition:?int, method:?string,
 *               peaks:array, totalArea:?int, concentrations:array, chromatogramPng:?string}
 */
function wlParseReportPdf(string $pdf): array
{
    $texts = array();
    $png = null;
    $peakError = null;

    // PDF nesnelerini dolaş: akışı olanları aç
    preg_match_all('/(\d+) 0 obj/', $pdf, $objs, PREG_OFFSET_CAPTURE);
    foreach ($objs[0] as $hit) {
        $start = $hit[1];
        $end = strpos($pdf, "endobj", $start);
        if ($end === false) { continue; }
        $seg = substr($pdf, $start, $end - $start);
        $p = strpos($seg, "stream");
        if ($p === false) { continue; }
        $dict = substr($seg, 0, $p);
        if (!preg_match('/\/Length (\d+)/', $dict, $len)) { continue; }
        $ds = $p + 6;
        if (($seg[$ds] ?? "") === "\r") { $ds++; }
        if (($seg[$ds] ?? "") === "\n") { $ds++; }
        $raw = substr($seg, $ds, (int) $len[1]);
        $data = str_contains($dict, "FlateDecode") ? @gzuncompress($raw) : $raw;
        if ($data === false) { continue; }

        if (str_contains($dict, "/Subtype/Image")) {
            $png = $png ?? wlIndexedImageToPng($dict, $data);
        } elseif (preg_match_all('/\(((?:\\.|[^\\)])*)\)\s*Tj/s', $data, $m)) {
            foreach ($m[1] as $t) {
                $texts[] = trim(stripcslashes($t));
            }
        }
    }

    $after = function (string $label) use ($texts): ?string {
        $i = array_search($label, $texts, true);
        return $i !== false && isset($texts[$i + 1]) ? $texts[$i + 1] : null;
    };
    $prefixed = function (string $prefix) use ($texts): ?string {
        foreach ($texts as $t) {
            if (stripos($t, $prefix) === 0) { return trim(substr($t, strlen($prefix))); }
        }
        return null;
    };

    $injected = $after("Injection date");
    $injectedAt = $injected ? DateTimeImmutable::createFromFormat("!m/d/Y H:i", $injected) : false;

    // Pik tablosu: "Area %" başlığından "Total Area:" satırına kadar 5'li gruplar
    $peaks = array();
    $h = array_search("Area %", $texts, true);
    $t = array_search("Total Area:", $texts, true);
    if ($h !== false && $t !== false && $t > $h) {
        $cells = array_slice($texts, $h + 1, $t - $h - 1);
        foreach (array_chunk($cells, 5) as $i => $c) {
            // Kayma kontrolü: R.time sayı değilse hücreler kaymıştır; yanlış değer kaydetmek yerine tabloyu bırak
            if (count($c) < 5 || !is_numeric($c[1]) || !ctype_digit($c[3])) {
                $peaks = array();
                $peakError = "Pik tablosu okunamadı (beklenmeyen biçim)";
                break;
            }
            $peaks[] = array(
                "sortNo"        => $i + 1,
                "peakName"      => $c[0],
                "retentionTime" => $c[1],
                "height"        => ctype_digit($c[2]) ? (int) $c[2] : null,
                "area"          => ctype_digit($c[3]) ? (int) $c[3] : null,
                "areaPct"       => $c[4],
            );
        }
    }

    // Konsantrasyon: "% F" → "0.8" gibi çiftler
    $conc = array();
    $c = array_search("Concentration:", $texts, true);
    if ($c !== false) {
        for ($i = $c + 1; $i + 1 < count($texts); $i += 2) {
            if (strpos($texts[$i], "%") !== 0) { break; }
            $conc[trim(substr($texts[$i], 1))] = $texts[$i + 1];
        }
    }

    $total = $after("Total Area:");
    $injNo = $prefixed("Injection #:");
    $rack  = $prefixed("Rack position:");

    return array(
        "sampleId"        => $after("Sample ID:"),
        "injectedAt"      => $injectedAt ?: null,
        "injectionNo"     => is_numeric($injNo) ? (int) $injNo : null,
        "rackPosition"    => is_numeric($rack) ? (int) $rack : null,
        "method"          => $prefixed("Method:"),
        "peaks"           => $peaks,
        "totalArea"       => $total !== null && ctype_digit($total) ? (int) $total : null,
        "concentrations"  => $conc,
        "chromatogramPng" => $png,
        "peakError"       => $peakError,
    );
}

// Paletli PDF resmini (BitsPerComponent 1/2/4/8, /Indexed/DeviceRGB) PNG'ye çevirir
function wlIndexedImageToPng(string $dict, string $bits): ?string
{
    if (!preg_match('/\/Width (\d+)/', $dict, $w) || !preg_match('/\/Height (\d+)/', $dict, $h)
        || !preg_match('/\/BitsPerComponent (\d+)/', $dict, $b) || !preg_match('/<([0-9A-Fa-f]+)>/', $dict, $pal)) {
        return null;
    }
    $W = (int) $w[1]; $H = (int) $h[1]; $B = (int) $b[1];
    $rowBytes = intdiv($W * $B + 7, 8);
    if (strlen($bits) < $rowBytes * $H) { return null; }

    $img = imagecreatetruecolor($W, $H);
    $colors = array();
    foreach (str_split((string) hex2bin($pal[1]), 3) as $i => $rgb) {
        $colors[$i] = imagecolorallocate($img, ord($rgb[0]), ord($rgb[1] ?? "\0"), ord($rgb[2] ?? "\0"));
    }
    $mask = (1 << $B) - 1;
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $byte = ord($bits[$y * $rowBytes + intdiv($x * $B, 8)]);
            $idx = ($byte >> (8 - $B - (($x * $B) % 8))) & $mask;
            imagesetpixel($img, $x, $y, $colors[$idx] ?? $colors[0]);
        }
    }
    ob_start();
    imagepng($img);
    imagedestroy($img);
    return (string) ob_get_clean();
}

// Raporu henüz alınmamış (ve 3'ten az başarısız denemesi olan) çalışmalar
function wlPendingReportIds($db, int $companyId): array
{
    return array_map("intval", $db->createQuery("
        SELECT r.deviceResultId FROM BIO\BCR\DeviceResult r
         WHERE IDENTITY(r.company) = :COMPANY_ID
           AND r.reportFetchedAt IS NULL AND r.reportAttempts < 3 AND r.recordFl > 0
         ORDER BY r.injectionDate ASC, r.injectionNo ASC
    ")->setParameter("COMPANY_ID", $companyId)->getSingleColumnResult());
}
