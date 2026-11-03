<?php
/**
 * HbRapor — Raporlar (arşiv / arama) veri kaynağı
 *
 *   getBlockList   → blok filtresi seçenekleri
 *   getReportList  → geçerli rapor revizyonları: barkod / ad / dosya no, onay tarihi aralığı ve bloğa göre
 *   getRevisions   → bir numunenin tüm revizyonları (gerekçe, onaylayan, PDF)
 *   getReportPdf   → arşivdeki rapor PDF'i (onay anında üretilen; yeniden üretilmez, SHA-256 doğrulanır)
 *
 * Onaylı rapor değiştirilmez; düzeltme uzman onay ekranından yeni revizyon olarak yapılır.
 */

// Proxy bu dosyayı bir metodun içinde require eder: $resourceFile global olmazsa dil yükleyicisi (global $resourceFile) onu görmez
global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $resourceFile;

use BIO\BCR\SampleReport;

$db = $dbm->getDB();
$resourceFile = "report/report-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

switch ($query) {

    case "getBlockList":
        $execType = ET_DEFAULT;
        try{
            $dtResponse = $db->createQuery("
                SELECT b.code, b.groupCode, b.blockType, b.title FROM BIO\BCR\HbCommentBlock b
                 WHERE b.recordFl > 0 AND b.activeFl = 'Y' AND b.blockType <> 'NOTE'
                 ORDER BY b.sortNo ASC
            ")->getArrayResult();
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getReportList":
        $execType = ET_DEFAULT;
        try{
            $companyId = (int) $_SESSION["COMPANY_ID"];
            $q = trim((string) ($_POST["Q"] ?? ""));
            $dateFrom = DateTimeImmutable::createFromFormat("!d.m.Y", (string) ($_POST["DATE_FROM"] ?? "")) ?: new DateTimeImmutable("today -30 days");
            $dateTo   = DateTimeImmutable::createFromFormat("!d.m.Y", (string) ($_POST["DATE_TO"] ?? "")) ?: new DateTimeImmutable("today");
            $block    = strtoupper(trim((string) ($_POST["BLOCK"] ?? "")));

            $qb = $db->createQueryBuilder()
                ->select("rp", "sa", "g")
                ->from("BIO\BCR\SampleReport", "rp")
                ->join("rp.sampleAcceptance", "sa")
                ->join("sa.gender", "g")
                ->where("IDENTITY(rp.company) = :COMPANY_ID")
                ->andWhere("rp.supersededAt IS NULL AND rp.recordFl > 0")
                ->setParameter("COMPANY_ID", $companyId)
                ->orderBy("rp.approvedAt", "DESC")
                ->setMaxResults(500);

            // Barkod / ad / dosya no aranırken tarih aralığı uygulanmaz (eski raporu bulmak için)
            if ($q !== "") {
                $qb->andWhere("sa.barcode LIKE :Q OR sa.patientNameSurname LIKE :Q OR sa.fileNo LIKE :Q")
                   ->setParameter("Q", "%" . addcslashes($q, "%_[") . "%");
            } else {
                $qb->andWhere("rp.approvedAt >= :FROM AND rp.approvedAt < :TO")
                   ->setParameter("FROM", $dateFrom)
                   ->setParameter("TO", $dateTo->modify("+1 day"));
            }

            // Blok: "C2" gibi tek kod (SELECTED_BLOCKS "C2", "C2+V1", "C3+C3N+V2" biçiminde) ya da grup: "A" / "B" / "C" / "V"
            if (preg_match('/^[ABC]\d$|^V\d$/', $block)) {
                $qb->andWhere("rp.selectedBlocks = :B0 OR rp.selectedBlocks LIKE :B1 OR rp.selectedBlocks LIKE :B2 OR rp.selectedBlocks LIKE :B3")
                   ->setParameter("B0", $block)->setParameter("B1", $block . "+%")
                   ->setParameter("B2", "%+" . $block)->setParameter("B3", "%+" . $block . "+%");
            } elseif (in_array($block, array("A", "B", "C"), true)) {
                $qb->andWhere("rp.selectedBlocks LIKE :BG")->setParameter("BG", $block . "%");
            } elseif ($block === "V") {
                $qb->andWhere("rp.selectedBlocks LIKE :BV")->setParameter("BV", "%V%");
            }

            $reports = $qb->getQuery()->getResult();
            $revCounts = rpRevisionCounts($db, $reports);
            $pdfIds = rpPdfReportIds($db, $reports);

            $rows = array();
            foreach ($reports as $rp) {
                $sa = $rp->getSampleAcceptance();
                $by = $rp->getApprovedBy();
                $months = $rp->getAgeMonths();
                $rows[] = array(
                    "sampleReportId"     => $rp->getSampleReportId(),
                    "sampleAcceptanceId" => $sa->getSampleAcceptanceId(),
                    "barcode"            => $sa->getBarcode(),
                    "patientName"        => $sa->getPatientNameSurname(),
                    "fileNo"             => $sa->getFileNo(),
                    "gender"             => $sa->getGender()->getGender(),
                    "ageText"            => $months >= 12 ? intdiv($months, 12) . " yaş" : $months . " ay",
                    "ageGroup"           => $rp->getAgeGroup(),
                    "institution"        => $sa->getInstitution(),
                    "status"             => $sa->getStatus(),
                    "approvedAt"         => $rp->getApprovedAt()->format("Y-m-d H:i"),
                    "approvedBy"         => $by ? $by->getDisplayName() : null,
                    "revisionNo"         => $rp->getRevisionNo(),
                    "revisionCount"      => $revCounts[$sa->getSampleAcceptanceId()] ?? 1,
                    "blocks"             => $rp->getSelectedBlocks(),
                    "hba2"               => $rp->getHba2Value(),
                    "hbf"                => $rp->getHbfValue(),
                    "hba"                => $rp->getHbaValue(),
                    "hbaManual"          => $rp->isHbaManual(),
                    "hbsHbd"             => $rp->getHbsHbdText(),
                    "commentEdited"      => $rp->isCommentEdited(),
                    "hasExpertNote"      => $rp->getExpertNote() !== null,
                    "hasPdf"             => isset($pdfIds[$rp->getSampleReportId()]),
                );
            }

            $dtResponse = array(
                "rows"      => $rows,
                "limited"   => count($rows) >= 500,
                "dateFrom"  => $dateFrom->format("d.m.Y"),
                "dateTo"    => $dateTo->format("d.m.Y"),
                "dateUsed"  => $q === "",
            );
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getRevisions":
        $execType = ET_DEFAULT;
        try{
            $reports = $db->createQuery("
                SELECT rp FROM BIO\BCR\SampleReport rp JOIN rp.sampleAcceptance sa
                 WHERE sa.sampleAcceptanceId = :ID AND IDENTITY(rp.company) = :COMPANY_ID AND rp.recordFl > 0
                 ORDER BY rp.revisionNo DESC
            ")->setParameters(array("ID" => (int) ($_POST["ID"] ?? 0), "COMPANY_ID" => (int) $_SESSION["COMPANY_ID"]))
              ->getResult();
            $pdfIds = rpPdfReportIds($db, $reports);

            $dtResponse = array_map(function (SampleReport $rp) use ($pdfIds) {
                $by = $rp->getApprovedBy();
                return array(
                    "sampleReportId" => $rp->getSampleReportId(),
                    "revisionNo"     => $rp->getRevisionNo(),
                    "current"        => $rp->isCurrent(),
                    "revisionReason" => $rp->getRevisionReason(),
                    "approvedAt"     => $rp->getApprovedAt()->format("Y-m-d H:i"),
                    "approvedBy"     => $by ? $by->getDisplayName() : null,
                    "blocks"         => $rp->getSelectedBlocks(),
                    "hba2"           => $rp->getHba2Value(),
                    "hbf"            => $rp->getHbfValue(),
                    "hba"            => $rp->getHbaValue(),
                    "hbsHbd"         => $rp->getHbsHbdText(),
                    "commentEdited"  => $rp->isCommentEdited(),
                    "hasExpertNote"  => $rp->getExpertNote() !== null,
                    "hasPdf"         => isset($pdfIds[$rp->getSampleReportId()]),
                );
            }, $reports);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

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
                $resMsg = "Bu revizyonun rapor PDF'i yok (PDF arşivi eklenmeden önce onaylanmış olabilir).";
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
}

/* ------------------------------------------------------------------ */
/*  Yardımcılar (rp = report)                                           */
/* ------------------------------------------------------------------ */

// [sampleAcceptanceId => revizyon sayısı]
function rpRevisionCounts($db, array $reports): array
{
    if (!$reports) return array();
    $samples = array_map(fn(SampleReport $rp) => $rp->getSampleAcceptance(), $reports);
    $rows = $db->createQuery("
         SELECT IDENTITY(rp.sampleAcceptance) AS saId, 
         COUNT(rp.sampleReportId) AS n 
         FROM BIO\BCR\SampleReport rp
         WHERE rp.sampleAcceptance IN (:SAMPLES) AND rp.recordFl > 0
         GROUP BY rp.sampleAcceptance
    ")->setParameter("SAMPLES", $samples)->getArrayResult();
    $map = array();
    foreach ($rows as $row) $map[(int) $row["saId"]] = (int) $row["n"];
    return $map;
}

// PDF'i arşivde olan revizyonlar: [sampleReportId => true] (içerik okunmaz)
function rpPdfReportIds($db, array $reports): array
{
    if (!$reports) return array();
    $ids = $db->createQuery("
        SELECT IDENTITY(f.sampleReport) AS rid FROM BIO\BCR\SampleReportFile f
         WHERE f.sampleReport IN (:REPORTS)
    ")->setParameter("REPORTS", $reports)->getSingleColumnResult();
    return array_fill_keys(array_map('intval', $ids), true);
}
