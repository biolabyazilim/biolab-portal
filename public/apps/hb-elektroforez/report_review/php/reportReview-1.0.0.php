<?php
/**
 * HbRapor — Uzman onay ekranı veri kaynağı
 *
 *   getReview      → numune, çalışmaları (pikler + her çalışmanın değerlendirmesi), rapor revizyonları, blok listesi
 *   getEvaluation  → seçilen çalışma + uzmanın girdileri (HbA, bloklar) için kurallar ve yorum taslağı
 *   getFile        → kromatogram PNG'si / cihazın orijinal PDF'i (base64)
 *   setApprove     → onay: yeni rapor revizyonu (ilk onay ya da düzeltme)
 *   setRerun       → tekrar çalışma iste
 *
 * Kurallar tek yerde: Biolab\Core\Hb\HbReportRules (docs/hb-rapor-kurallari.md).
 * Onayda kurallar sunucuda yeniden çalıştırılır; ekranın gönderdiğine güvenilmez.
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject;

use Biolab\Core\Hb\HbReportPdf;
use Biolab\Core\Hb\HbReportRules;
use BIO\BCR\DeviceResult;
use BIO\BCR\DeviceResultFile;
use BIO\BCR\HbCommentBlock;
use BIO\BCR\SampleAcceptance;
use BIO\BCR\SampleReport;
use BIO\BCR\SampleReportFile;

$db = $dbm->getDB();
$resourceFile = "report_review/reportReview-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

switch ($query) {

    case "getReview":
        $execType = ET_DEFAULT;
        try{
            $sa = rrFindSample($db, (int) ($_POST["ID"] ?? 0));
            if ($sa === null) {
                $resCode = 1;
                $resMsg = "Numune bulunamadı.";
                break;
            }

            $runs = rrRuns($db, $sa);
            $peaks = rrPeaks($db, $runs);
            $files = rrFileTypes($db, $runs);

            $runRows = array();
            foreach ($runs as $r) {
                $id = $r->getDeviceResultId();
                $ev = HbReportRules::evaluate(rrRuleInput($sa, $r, $peaks[$id] ?? array(), count($runs)));
                $injectedAt = $r->getInjectedAt();
                $runRows[] = array(
                    "deviceResultId"  => $id,
                    "injectionDate"   => $r->getInjectionDate()->format("Y-m-d"),
                    "injectionNo"     => $r->getInjectionNo(),
                    "injectedAt"      => $injectedAt ? $injectedAt->format("Y-m-d H:i") : null,
                    "a2"              => $r->getA2Value(),
                    "f"               => $r->getFValue(),
                    "a1c"             => $r->getA1cValue(),
                    "comment"         => $r->getDeviceComment(),
                    "method"          => $r->getMethod(),
                    "totalArea"       => $r->getTotalArea(),
                    "reportFetched"   => $r->isReportFetched(),
                    "reportError"     => $r->getReportError(),
                    "matchType"       => $r->getMatchType(),
                    "sampleId"        => $r->getSampleId(),
                    "peaks"           => $peaks[$id] ?? array(),
                    "hasChromatogram" => in_array(DeviceResultFile::TYPE_CHROMATOGRAM_PNG, $files[$id] ?? array(), true),
                    "hasPdf"          => in_array(DeviceResultFile::TYPE_DEVICE_PDF, $files[$id] ?? array(), true),
                    "suggestedBlocks" => $ev["suggestedBlocks"],
                    "abnormalPeaks"   => $ev["abnormalPeaks"],
                );
            }

            $reports = $db->createQuery("
                SELECT rp FROM BIO\BCR\SampleReport rp
                 WHERE rp.sampleAcceptance = :SA AND rp.recordFl > 0
                 ORDER BY rp.revisionNo DESC
            ")->setParameter("SA", $sa)->getResult();

            $blocks = $db->createQuery("
                SELECT b FROM BIO\BCR\HbCommentBlock b
                 WHERE b.recordFl > 0 AND b.activeFl = 'Y'
                 ORDER BY b.sortNo ASC
            ")->getResult();

            $dtResponse = array(
                "sample"  => rrSampleToArray($sa),
                "runs"    => $runRows,
                "reports" => array_map("rrReportToArray", $reports),
                "blocks"  => array_map(fn(HbCommentBlock $b) => array(
                    "code"     => $b->getCode(),
                    "group"    => $b->getGroupCode(),
                    "type"     => $b->getBlockType(),
                    "title"    => $b->getTitle(),
                    "criteria" => $b->getCriteria(),
                ), $blocks),
                "hbsHbdOptions" => array(HbReportRules::HBSHBD_NOT_DETECTED, HbReportRules::HBSHBD_VERIFY),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Ekrandaki her değişiklikte (çalışma, HbA, blok seçimi) çağrılır: uyarılar ve yorum taslağı sunucuda hesaplanır
    case "getEvaluation":
        $execType = ET_CUSTOM;
        try{
            $sa = rrFindSample($db, (int) ($_POST["ID"] ?? 0));
            $run = $sa ? rrFindRun($db, $sa, (int) ($_POST["DEVICE_RESULT_ID"] ?? 0)) : null;
            if ($run === null) {
                $resCode = 1;
                $resMsg = "Çalışma bulunamadı.";
                break;
            }
            $ev = rrEvaluate($db, $sa, $run, rrPostNumber("HBA"));
            $chosen = rrChosenBlocks($ev);
            $resObject = array(
                "evaluation"   => $ev,
                "chosen"       => $chosen,
                "draftComment" => rrCompose($db, $chosen, $ev["hasVariant"]),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getFile":
        $execType = ET_CUSTOM;
        try{
            $type = (string) ($_POST["TYPE"] ?? "");
            if (!in_array($type, array(DeviceResultFile::TYPE_CHROMATOGRAM_PNG, DeviceResultFile::TYPE_DEVICE_PDF), true)) {
                $resCode = 1;
                $resMsg = "Geçersiz dosya türü.";
                break;
            }
            $file = $db->createQuery("
                SELECT f FROM BIO\BCR\DeviceResultFile f JOIN f.deviceResult r
                 WHERE r.deviceResultId = :ID AND IDENTITY(r.company) = :COMPANY_ID AND f.fileType = :TYPE
            ")->setParameters(array(
                "ID" => (int) ($_POST["DEVICE_RESULT_ID"] ?? 0),
                "COMPANY_ID" => (int) $_SESSION["COMPANY_ID"],
                "TYPE" => $type,
            ))->getOneOrNullResult();
            if ($file === null) {
                $resCode = 1;
                $resMsg = "Dosya bulunamadı.";
                break;
            }
            $resObject = array(
                "mimeType" => $file->getMimeType(),
                "fileName" => $file->getFileName(),
                "base64"   => base64_encode($file->getContent()),
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Rapor önizleme: ekrandaki seçimlerle "TASLAK" filigranlı PDF. Kaydedilmez.
    case "getPreview":
        $execType = ET_CUSTOM;
        try{
            $sa = rrFindSample($db, (int) ($_POST["ID"] ?? 0));
            $run = $sa ? rrFindRun($db, $sa, (int) ($_POST["DEVICE_RESULT_ID"] ?? 0)) : null;
            if ($run === null) {
                $resCode = 1;
                $resMsg = "Önizleme için çalışma seçin.";
                break;
            }
            if (!$run->isReportFetched()) {
                $resCode = 1;
                $resMsg = "Bu çalışmanın cihaz raporu henüz alınmadı.";
                break;
            }
            $ev = rrEvaluate($db, $sa, $run, rrPostNumber("HBA"));
            $comment = trim(str_replace("\r\n", "\n", (string) ($_POST["COMMENT"] ?? "")));
            if ($comment === "") {
                $comment = rrCompose($db, rrChosenBlocks($ev), $ev["hasVariant"]);
            }
            $hbsHbd = trim((string) ($_POST["HBS_HBD"] ?? "")) ?: ($ev["hbsHbd"]["suggested"] ?? HbReportRules::HBSHBD_VERIFY);
            $current = rrCurrentReport($db, $sa);
            $revisionNo = $current ? $current->getRevisionNo() + 1 : 1;

            $previewName = HbReportPdf::fileName($sa->getBarcode(), new DateTimeImmutable(), $revisionNo, true);
            $pdfBytes = HbReportPdf::render(
                rrPdfData($db, $sa, $run, $ev, $hbsHbd, $comment, (string) ($_POST["EXPERT_NOTE"] ?? ""), $revisionNo, null, null, null) + array("title" => basename($previewName, ".pdf")),
                true,
                rrDevicePageForReport($db, $run)
            );
            $resObject = array(
                "mimeType" => "application/pdf",
                "fileName" => $previewName,
                "base64"   => base64_encode($pdfBytes),
                "url"      => "/api/pdf.php?t=" . \Biolab\Core\Http\PdfHandoff::stash($pdfBytes, $previewName),   // yeni sekmede adıyla açılır
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Throwable $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Arşivdeki rapor PDF'i (onay anında üretilen; yeniden üretilmez)
    case "getReportPdf":
        $execType = ET_CUSTOM;
        try{
            $file = $db->createQuery("
                SELECT f FROM BIO\BCR\SampleReportFile f JOIN f.sampleReport rp
                 WHERE rp.sampleReportId = :ID AND IDENTITY(rp.company) = :COMPANY_ID
            ")->setParameters(array(
                "ID" => (int) ($_POST["SAMPLE_REPORT_ID"] ?? 0),
                "COMPANY_ID" => (int) $_SESSION["COMPANY_ID"],
            ))->getOneOrNullResult();
            if ($file === null) {
                $resCode = 1;
                $resMsg = "Bu revizyonun rapor PDF'i yok.";
                break;
            }
            if (!$file->isIntact()) {
                $resCode = 2;
                $resMsg = "Arşivdeki rapor dosyası doğrulanamadı (SHA-256 uyuşmuyor). Sistem yöneticisine bildirin.";
                break;
            }
            $resObject = array(
                "mimeType" => "application/pdf",
                "fileName" => $pdfName = \Biolab\Core\Hb\HbReportPdf::fileName($file->getSampleReport()->getSampleAcceptance()->getBarcode(), $file->getSampleReport()->getApprovedAt(), $file->getSampleReport()->getRevisionNo()),
                "base64"   => base64_encode($file->getContent()),
                "url"      => "/api/pdf.php?t=" . \Biolab\Core\Http\PdfHandoff::stash($file->getContent(), $pdfName),   // yeni sekmede adıyla açılır
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setApprove":
        $execType = ET_SAVE;
        try{
            $sa = rrFindSample($db, (int) ($_POST["ID"] ?? 0));
            if ($sa === null) {
                $resCode = 1;
                $resMsg = "Numune bulunamadı.";
                break;
            }
            $current = rrCurrentReport($db, $sa);
            $isRevision = $current !== null;
            $revisionReason = trim((string) ($_POST["REVISION_REASON"] ?? ""));

            if (!$isRevision && !$sa->canApprove()) {
                $resCode = 3;
                $resMsg = "Bu numune onaylanamaz (durum: " . $sa->getStatus() . ").";
                break;
            }
            if ($isRevision && !$sa->canRevise()) {
                $resCode = 3;
                $resMsg = "Bu numunede düzeltme yapılamaz (durum: " . $sa->getStatus() . ").";
                break;
            }
            if ($isRevision && $revisionReason === "") {
                $resCode = 1;
                $resMsg = "Onaylı raporda düzeltme için gerekçe girin.";
                break;
            }

            $run = rrFindRun($db, $sa, (int) ($_POST["DEVICE_RESULT_ID"] ?? 0));
            if ($run === null) {
                $resCode = 1;
                $resMsg = "Raporlanacak çalışmayı seçin.";
                break;
            }
            if (!$run->isReportFetched()) {
                $resCode = 1;
                $resMsg = "Bu çalışmanın cihaz raporu alınmadı; onaylanamaz.";
                break;
            }

            $hbaManual = rrPostNumber("HBA");
            $ev = rrEvaluate($db, $sa, $run, $hbaManual);
            $chosen = rrChosenBlocks($ev);
            $hbsHbd = trim((string) ($_POST["HBS_HBD"] ?? ""));
            $comment = trim(str_replace("\r\n", "\n", (string) ($_POST["COMMENT"] ?? "")));
            $expertNote = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST["EXPERT_NOTE"] ?? ""))), 0, 4000);
            // Algoritma yorumu seçilen blokların taslağından farklıysa "uzman blok metnini düzenledi" olarak işaretlenir
            $commentEdited = rrNormText($comment) !== rrNormText(rrCompose($db, $chosen, $ev["hasVariant"]));

            // Sunucu tarafı denetim: ekranın gönderdiğine değil kurallara göre
            $error = rrApproveError($db, $ev, $chosen, $hbaManual, $hbsHbd, $comment, (string) ($_POST["CONFIRMED"] ?? "0") === "1");
            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
                break;
            }

            $user = $db->getReference("BIO\MNG\AppUser", (int) $_SESSION["USER_ID"]);
            $report = new SampleReport($sa, $run, $isRevision ? $current->getRevisionNo() + 1 : 1, $user);
            $report->setAge($ev["ageGroup"], $ev["ageMonths"])
                   ->setValues($ev["a2"], $ev["f"], $run->getFValue(), $ev["hba"], $ev["hbaCalc"], $ev["hbaDraft"], $ev["a0"])
                   ->setHbsHbdText(mb_substr($hbsHbd, 0, 100))
                   ->setBlocks($ev["suggestedBlocks"], rrBlocksText($chosen))
                   ->setCommentText($comment)
                   ->setCommentEdited($commentEdited)
                   ->setExpertNote($expertNote)
                   ->setWarnings($ev["warnings"])
                   ->setRevisionReason($isRevision ? mb_substr($revisionReason, 0, 300) : null);

            // Rapor PDF'i kayıttan önce üretilir: üretilemezse onay da kaydedilmez
            $revisionNote = $isRevision
                ? "Bu rapor, " . $current->getApprovedAt()->format("d.m.Y H:i") . " tarihli " . $current->getRevisionNo()
                  . ". revizyonun yerine geçer. Düzeltme gerekçesi: " . mb_substr($revisionReason, 0, 300)
                : null;
            $approverEntity = $db->find("BIO\MNG\AppUser", (int) $_SESSION["USER_ID"]);
            $pdfBytes = HbReportPdf::render(
                rrPdfData($db, $sa, $run, $ev, $hbsHbd, $comment, $expertNote, $report->getRevisionNo(), $revisionNote,
                          $approverEntity ? $approverEntity->getDisplayName() : null, $report->getApprovedAt()->format("d.m.Y H:i"))
                    + array("title" => basename(HbReportPdf::fileName($sa->getBarcode(), $report->getApprovedAt(), $report->getRevisionNo()), ".pdf")),
                false,
                rrDevicePageForReport($db, $run)
            );
            $pdfFile = new SampleReportFile($report, HbReportPdf::fileName($sa->getBarcode(), $report->getApprovedAt(), $report->getRevisionNo()), $pdfBytes);

            $db->wrapInTransaction(function () use ($db, $sa, $current, $report, $isRevision, $pdfFile) {
                if ($isRevision) {
                    $current->supersede();
                    $db->flush();   // eski revizyon önce kapanır
                } else {
                    $sa->approve();
                }
                $sa->markReported();   // PDF arşive yazıldı: Raporlandı
                $db->persist($report);
                $db->persist($pdfFile);
                $db->flush();
            });

            $resObject = array("sampleReportId" => $report->getSampleReportId(), "revisionNo" => $report->getRevisionNo(), "status" => $sa->getStatus());
            $resMsg = ($isRevision ? "Düzeltme kaydedildi (revizyon " . $report->getRevisionNo() . ")" : "Onaylandı: " . $sa->getBarcode()) . " · rapor PDF'i oluşturuldu.";
            $resCode = 0;
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setRerun":
        $execType = ET_SAVE;
        try{
            $sa = rrFindSample($db, (int) ($_POST["ID"] ?? 0));
            $reason = trim((string) ($_POST["REASON"] ?? ""));
            if ($sa === null) {
                $resCode = 1;
                $resMsg = "Numune bulunamadı.";
            } elseif ($reason === "") {
                $resCode = 1;
                $resMsg = "Tekrar çalışma gerekçesi girin.";
            } elseif (!$sa->canRequestRerun()) {
                $resCode = 3;
                $resMsg = "Bu numune için tekrar çalışma istenemez (durum: " . $sa->getStatus() . ").";
            } else {
                $user = $db->getReference("BIO\MNG\AppUser", (int) $_SESSION["USER_ID"]);
                $sa->requestRerun(mb_substr($reason, 0, 200), $user);
                $db->flush();
                $resObject = array("status" => $sa->getStatus());
                $resMsg = "Tekrar çalışma istendi: " . $sa->getBarcode();
                $resCode = 0;
            }
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

/* ------------------------------------------------------------------ */
/*  Yardımcılar (rr = report review)                                    */
/* ------------------------------------------------------------------ */

function rrFindSample($db, int $id): ?SampleAcceptance
{
    if ($id <= 0) return null;
    return $db->getRepository(SampleAcceptance::class)->findOneBy(array(
        "sampleAcceptanceId" => $id,
        "company"            => (int) $_SESSION["COMPANY_ID"],
    ));
}

function rrFindRun($db, SampleAcceptance $sa, int $id): ?DeviceResult
{
    if ($id <= 0) return null;
    $r = $db->getRepository(DeviceResult::class)->findOneBy(array("deviceResultId" => $id, "sampleAcceptance" => $sa));
    return ($r !== null && !$r->isDeleted()) ? $r : null;
}

function rrRuns($db, SampleAcceptance $sa): array
{
    return $db->createQuery("
        SELECT r FROM BIO\BCR\DeviceResult r
         WHERE r.sampleAcceptance = :SA AND r.recordFl > 0
         ORDER BY r.injectionDate ASC, r.injectionNo ASC
    ")->setParameter("SA", $sa)->getResult();
}

// [deviceResultId => [ {name, rt, height, area, areaPct, abnormal} ]]
function rrPeaks($db, array $runs): array
{
    if (!$runs) return array();
    $rows = $db->createQuery("
        SELECT p FROM BIO\BCR\DeviceResultPeak p
         WHERE p.deviceResult IN (:RUNS)
         ORDER BY p.sortNo ASC
    ")->setParameter("RUNS", $runs)->getResult();
    $map = array();
    foreach ($rows as $p) {
        $map[$p->getDeviceResult()->getDeviceResultId()][] = array(
            "name"     => $p->getPeakName(),
            "rt"       => $p->getRetentionTime(),
            "height"   => $p->getHeight(),
            "area"     => $p->getArea(),
            "areaPct"  => $p->getAreaPct(),
            "abnormal" => HbReportRules::isAbnormalPeak($p->getPeakName()),
        );
    }
    return $map;
}

// [deviceResultId => [FILE_TYPE, ...]] (içerik okunmaz)
function rrFileTypes($db, array $runs): array
{
    if (!$runs) return array();
    $rows = $db->createQuery("
        SELECT IDENTITY(f.deviceResult) AS rid, f.fileType FROM BIO\BCR\DeviceResultFile f
         WHERE f.deviceResult IN (:RUNS)
    ")->setParameter("RUNS", $runs)->getArrayResult();
    $map = array();
    foreach ($rows as $row) $map[(int) $row["rid"]][] = $row["fileType"];
    return $map;
}

function rrRuleInput(SampleAcceptance $sa, DeviceResult $r, array $peaks, int $runCount, ?float $hbaManual = null): array
{
    return array(
        "ageDays"       => $sa->getAgeInDays(),
        "ageMonths"     => $sa->getAgeInMonths(),
        "a2"            => $r->getA2Value(),
        "f"             => $r->getFValue(),
        "peaks"         => array_map(fn($p) => array("name" => $p["name"], "areaPct" => $p["areaPct"], "rt" => $p["rt"]), $peaks),
        "deviceComment" => $r->getDeviceComment(),
        "hbaManual"     => $hbaManual,
        "runCount"      => $runCount,
    );
}

function rrEvaluate($db, SampleAcceptance $sa, DeviceResult $run, ?float $hbaManual): array
{
    $peaks = rrPeaks($db, array($run));
    $runCount = count(rrRuns($db, $sa));
    $ev = HbReportRules::evaluate(rrRuleInput($sa, $run, $peaks[$run->getDeviceResultId()] ?? array(), $runCount, $hbaManual));
    if (!$run->isReportFetched()) {
        array_unshift($ev["warnings"], array("code" => "NO_REPORT", "level" => "block", "text" => "Bu çalışmanın cihaz raporu (pikler, kromatogram) alınmadı; onaylanamaz."));
    }
    return $ev;
}

// Uzmanın seçtiği bloklar. Gönderilmediyse (boş) öneri kullanılır; "NONE" = blok yok.
function rrChosenBlocks(array $ev): array
{
    $pick = function (string $key, ?string $suggested) {
        $v = strtoupper(trim((string) ($_POST[$key] ?? "")));
        if ($v === "") return $suggested;
        return $v === "NONE" ? null : $v;
    };
    return array(
        "age"  => $pick("AGE_BLOCK", $ev["ageBlock"]["code"]),
        "note" => $pick("NOTE_BLOCK", $ev["noteBlock"]),
        "v"    => $pick("V_BLOCK", $ev["vBlock"]["code"]),
    );
}

function rrBlocksText(array $chosen): ?string
{
    $codes = array_values(array_filter(array($chosen["age"], $chosen["note"], $chosen["v"])));
    return $codes ? implode("+", $codes) : null;
}

function rrBlockBodies($db, array $codes): array
{
    $codes = array_values(array_filter($codes));
    if (!$codes) return array();
    $rows = $db->createQuery("
        SELECT b FROM BIO\BCR\HbCommentBlock b
         WHERE b.code IN (:CODES) AND b.recordFl > 0 AND b.activeFl = 'Y'
    ")->setParameter("CODES", $codes)->getResult();
    $map = array();
    foreach ($rows as $b) $map[$b->getCode()] = $b;
    return $map;
}

function rrCompose($db, array $chosen, bool $hasVariant): string
{
    $b = rrBlockBodies($db, array($chosen["age"], $chosen["note"], $chosen["v"]));
    $body = fn($code) => ($code !== null && isset($b[$code])) ? $b[$code]->getBody() : null;
    return HbReportRules::composeComment($body($chosen["age"]), $body($chosen["note"]), $body($chosen["v"]), $hasVariant);
}

// Onay denetimi: hata metni ya da null
function rrApproveError($db, array $ev, array $chosen, ?float $hbaManual, string $hbsHbd, string $comment, bool $confirmed): ?string
{
    foreach ($ev["warnings"] as $w) {
        if (in_array($w["code"], array("NO_REPORT", "NO_A2"), true)) return $w["text"];
    }
    if ($ev["hbaDraft"]) {
        if ($hbaManual === null) return "Anormal pik ≥ %5: raporlanacak HbA değerini girin (A0 kontrolü).";
        if ($hbaManual < 0 || $hbaManual > 100) return "HbA değeri 0–100 arasında olmalı.";
    }
    if ($hbsHbd === "") return "HbS / HbD sonucunu seçin.";

    $b = rrBlockBodies($db, array($chosen["age"], $chosen["note"], $chosen["v"]));
    if ($chosen["age"] === null) return "Yaş grubuna uygun yorum bloğunu seçin.";
    if (!isset($b[$chosen["age"]]) || $b[$chosen["age"]]->getBlockType() !== HbCommentBlock::TYPE_AGE) return "Geçersiz yorum bloğu: " . $chosen["age"];
    if ($b[$chosen["age"]]->getGroupCode() !== $ev["ageGroup"]) {
        return $chosen["age"] . " bloğu bu yaş grubuna (" . $ev["ageGroup"] . ") ait değil.";
    }
    if ($chosen["note"] !== null && (!isset($b[$chosen["note"]]) || $b[$chosen["note"]]->getBlockType() !== HbCommentBlock::TYPE_NOTE)) {
        return "Geçersiz ek not: " . $chosen["note"];
    }
    if ($chosen["v"] !== null && (!isset($b[$chosen["v"]]) || $b[$chosen["v"]]->getBlockType() !== HbCommentBlock::TYPE_VARIANT)) {
        return "Geçersiz varyant bloğu: " . $chosen["v"];
    }
    // Varyant varsa V bloğu açıkça seçilmeli ("V bloğu yok" da bir seçim)
    if ($ev["hasVariant"] && $ev["vBlock"]["code"] === null && trim((string) ($_POST["V_BLOCK"] ?? "")) === "") {
        return "Varyant bloğunu seçin (ya da \"V bloğu yok\").";
    }
    $hasConfirm = count(array_filter($ev["warnings"], fn($w) => $w["level"] !== "info")) > 0;
    if ($hasConfirm && !$confirmed) return "Uyarıları kontrol ettiğinizi işaretleyin.";
    if ($comment === "") return "Rapor yorumu boş olamaz.";
    return null;
}

// Rapor numarası: barkod + revizyon (ör. HB-11713441-R2)
function rrReportNo(SampleAcceptance $sa, int $revisionNo): string
{
    return "HB-" . $sa->getBarcode() . "-R" . $revisionNo;
}

// Raporun sonuna cihazın orijinal sayfası eklensin mi? (config.php → hb_report.append_device_page)
// 21.10.2026: laboratuvar kararıyla kaldırıldı; cihaz PDF'i arşivde kalır, onay ekranından açılır.
function rrDevicePageForReport($db, DeviceResult $run): ?string
{
    $cfg = (require __DIR__ . "/../../../../../config.php")["hb_report"];
    return !empty($cfg["append_device_page"]) ? rrDeviceFile($db, $run, DeviceResultFile::TYPE_DEVICE_PDF) : null;
}

function rrDeviceFile($db, DeviceResult $run, string $type): ?string
{
    $f = $db->getRepository(DeviceResultFile::class)->findOneBy(array("deviceResult" => $run, "fileType" => $type));
    return $f ? $f->getContent() : null;
}

/**
 * HbReportPdf::render() verisi. Değerler kurallardan ($ev) gelir; onayda da önizlemede de aynı yol kullanılır.
 * Referans aralıkları şimdilik algoritma belgesinden: HbA2 < 3.5; HbF ≥ 6 yaşta < 2.0, altında yaşa göre tablo.
 */
function rrPdfData($db, SampleAcceptance $sa, DeviceResult $run, array $ev, string $hbsHbd, string $comment, ?string $expertNote,
                   int $revisionNo, ?string $revisionNote, ?string $approver, ?string $approvedAt): array
{
    $lab = (require __DIR__ . "/../../../../../config.php")["hb_report"];
    $fmt = fn(?float $v) => $v === null ? null : number_format($v, 1, ".", "");
    $range = fn(array $r) => number_format($r[0], 1, ".", "") . " – " . number_format($r[1], 1, ".", "");
    // Bayrak: H = üst sınır ve üstü, L = alt sınırın altı (HbA2 3.5 sınırı kurallarla aynı: 3.5 "yüksek" sayılır)
    $flag = fn(?float $v, ?float $lo, ?float $hi, bool $hiInclusive) => $v === null ? null
        : (($hi !== null && ($hiInclusive ? $v >= $hi : $v > $hi)) ? "H" : (($lo !== null && $v < $lo) ? "L" : null));
    $peaks = rrPeaks($db, array($run))[$run->getDeviceResultId()] ?? array();

    // HbA: < 1 yaşta yazılmaz (fizyolojik olarak düşük)
    $hbaRef  = $ev["ageGroup"] === "A" ? "—" : $range($lab["refs"]["hba"]);
    $hbaFlag = $ev["ageGroup"] === "A" ? null : $flag($ev["hba"], $lab["refs"]["hba"][0], $lab["refs"]["hba"][1], false);
    $hba2Flag = $flag($ev["a2"], $lab["refs"]["hba2"][0], $lab["refs"]["hba2"][1], true);
    // HbF: ≥ 6 yaşta laboratuvar aralığı; altında yaşa göre tablo
    $ref = $ev["hbfRef"];
    if ($ev["ageGroup"] === "C") {
        $hbfRef  = $range($lab["refs"]["hbf"]);
        $hbfFlag = $flag($ev["f"], null, $lab["refs"]["hbf"][1], true);
    } elseif ($ev["ageGroup"] === "B" || $ref["lower"] === null) {
        $hbfRef  = "< " . number_format($ref["upper"], 1, ".", "");
        $hbfFlag = $flag($ev["f"], null, $ref["upper"], true);
    } else {
        $hbfRef  = $range(array($ref["lower"], $ref["upper"]));
        $hbfFlag = $flag($ev["f"], $ref["lower"], $ref["upper"], false);
    }
    $sample = rrSampleToArray($sa);
    $injectedAt = $run->getInjectedAt();

    return array(
        "lab"          => $lab,
        "logoPng"      => @file_get_contents(__DIR__ . "/../../../../lib/app/images/brand/biolab-logo.png") ?: null,
        "reportNo"     => rrReportNo($sa, $revisionNo),
        "revisionNo"   => $revisionNo,
        "revisionNote" => $revisionNote,
        "patient"      => array(
            "name"        => $sa->getPatientNameSurname(),
            "barcode"     => $sa->getBarcode(),
            "fileNo"      => $sa->getFileNo(),
            "gender"      => $sa->getGender()->getGender(),
            "birthDate"   => $sa->getBirthDate()->format("d.m.Y"),
            "ageText"     => $sample["ageText"],
            "institution" => $sa->getInstitution(),
            "acceptedAt"  => $sa->getAcceptedAt()->format("d.m.Y H:i"),
        ),
        "run"          => array(
            "injectedAt"  => $injectedAt ? $injectedAt->format("d.m.Y H:i") : $run->getInjectionDate()->format("d.m.Y"),
            "injectionNo" => $run->getInjectionNo(),
            "method"      => $run->getMethod(),
        ),
        "result"       => array(
            "hba"       => $fmt($ev["hba"]),
            "hba2"      => $fmt($ev["a2"]),
            "hbf"       => $fmt($ev["f"]),
            "hbfDevice" => $run->getFValue(),
            "hbsHbd"    => $hbsHbd,
            "hbaManual" => $ev["hbaDraft"],
            "a0"        => $ev["a0"],
        ),
        "refs"         => array("hba" => $hbaRef, "hba2" => $range($lab["refs"]["hba2"]), "hbf" => $hbfRef),
        "flags"        => array("hba" => $hbaFlag, "hba2" => $hba2Flag, "hbf" => $hbfFlag),
        "peaks"        => $peaks,
        "totalArea"    => $run->getTotalArea(),
        "chromatogramPng" => rrDeviceFile($db, $run, DeviceResultFile::TYPE_CHROMATOGRAM_PNG),
        "comment"      => $comment,
        "expertNote"   => ($expertNote !== null && trim($expertNote) !== "") ? trim($expertNote) : null,
        "approver"     => $approver,
        "approvedAt"   => $approvedAt,
    );
}

// Karşılaştırma için: boşluk ve satır sonu farkları değişiklik sayılmaz
function rrNormText(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function rrCurrentReport($db, SampleAcceptance $sa): ?SampleReport
{
    return $db->createQuery("
        SELECT rp FROM BIO\BCR\SampleReport rp
         WHERE rp.sampleAcceptance = :SA AND rp.supersededAt IS NULL AND rp.recordFl > 0
    ")->setParameter("SA", $sa)->setMaxResults(1)->getOneOrNullResult();
}

function rrPostNumber(string $key): ?float
{
    $v = str_replace(",", ".", trim((string) ($_POST[$key] ?? "")));
    return is_numeric($v) ? (float) $v : null;
}

function rrSampleToArray(SampleAcceptance $sa): array
{
    $months = $sa->getAgeInMonths();
    return array(
        "sampleAcceptanceId" => $sa->getSampleAcceptanceId(),
        "barcode"            => $sa->getBarcode(),
        "patientName"        => $sa->getPatientNameSurname(),
        "fileNo"             => $sa->getFileNo(),
        "gender"             => $sa->getGender()->getGender(),
        "birthDate"          => $sa->getBirthDate()->format("Y-m-d"),
        "ageMonths"          => $months,
        "ageText"            => $months >= 12 ? intdiv($months, 12) . " yaş" . ($months % 12 ? " " . ($months % 12) . " ay" : "") : $months . " ay",
        "ageGroup"           => HbReportRules::ageGroup($months),
        "institution"        => $sa->getInstitution(),
        "acceptedAt"         => $sa->getAcceptedAt()->format("Y-m-d H:i"),
        "status"             => $sa->getStatus(),
        "canApprove"         => $sa->canApprove(),
        "canRevise"          => $sa->canRevise(),
        "canRerun"           => $sa->canRequestRerun(),
        "rerunReason"        => $sa->getRerunReason(),
    );
}

function rrReportToArray(SampleReport $rp): array
{
    $by = $rp->getApprovedBy();
    return array(
        "sampleReportId"  => $rp->getSampleReportId(),
        "revisionNo"      => $rp->getRevisionNo(),
        "revisionReason"  => $rp->getRevisionReason(),
        "current"         => $rp->isCurrent(),
        "deviceResultId"  => $rp->getDeviceResult()->getDeviceResultId(),
        "hba2"            => $rp->getHba2Value(),
        "hbf"             => $rp->getHbfValue(),
        "hbfDevice"       => $rp->getHbfDeviceValue(),
        "hba"             => $rp->getHbaValue(),
        "hbaCalculated"   => $rp->getHbaCalculated(),
        "hbaManual"       => $rp->isHbaManual(),
        "a0"              => $rp->getA0Value(),
        "hbsHbd"          => $rp->getHbsHbdText(),
        "suggestedBlocks" => $rp->getSuggestedBlocks(),
        "selectedBlocks"  => $rp->getSelectedBlocks(),
        "comment"         => $rp->getCommentText(),
        "commentEdited"   => $rp->isCommentEdited(),
        "expertNote"      => $rp->getExpertNote(),
        "warnings"        => $rp->getWarnings(),
        "approvedBy"      => $by ? $by->getFullName() : null,
        "approvedAt"      => $rp->getApprovedAt()->format("Y-m-d H:i"),
    );
}
