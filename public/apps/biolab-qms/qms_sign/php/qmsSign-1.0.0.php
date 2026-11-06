<?php
/**
 * Biolab QMS — İmzalarım veri kaynağı
 *
 * Yalnızca oturumdaki kullanıcının sırası gelmiş (ACTIVE) imza adımları listelenir; başkası adına imza atılamaz.
 *
 *   getList    → imzamı bekleyenler (açıldı mı: belge görüntülendi kaydı var mı)
 *   getDetail  → SIGN_STEP_ID: doküman bilgisi + güncel imza turunun akışı
 *   getSigned  → imzaladıklarım: kullanıcının attığı imzalar ve belgenin bugünkü durumu (son QMS_SIGN_SIGNED_MAX kayıt)
 *   getSignedDetail → REVISION_ID: imzaladığı revizyonun bilgisi ve akışı (salt okunur; imza alanı yok)
 *                ikisinde de imzalı PDF indirilebilir (api/qms-file.php?download=1; imzacı akışta olduğu için yetkili)
 *   setSign    → SIGN_STEP_IDS[] + PASSWORD + SIGNATURE (PNG, data URL) + STROKES (x,y,t;… | …)
 *                aynı imza ve şifreyle birden çok belge imzalanabilir; her belge ayrı transaction'da işlenir
 *   setReject  → SIGN_STEP_ID + PASSWORD + REJECT_NOTE: revizyon taslağa döner, turun kalan adımları kapanır
 *
 * İmza anında:
 *   - şifre yeniden sorulur (AUTH_METHOD = PASSWORD; SMS doğrulama ileride eklenecek)
 *   - belge açılmış olmalı (api/qms-file.php'nin yazdığı VIEW kaydı)
 *   - imzacının adı, unvanı ve pozisyonu o anki haliyle qms_signature'a kopyalanır; kayıt hash zincirine bağlanır
 *   - PDF'in sonuna onay sayfası eklenir (Biolab\Core\Qms\Sealer); HASH_BEFORE / HASH_AFTER mühürden önceki / sonraki dosya
 *   - sıradaki adım açılır; son imzada revizyon yürürlüğe girer, önceki yürürlükteki revizyon geçersiz olur
 */

global $db, $dbm, $lm, $query, $execType, $resCode, $resMsg, $dtResponse, $resObject, $totalRecords, $resourceFile;

use Biolab\Core\Qms\AuditLogger;
use Biolab\Core\Qms\FileStorage;
use Biolab\Core\Qms\HashChain;
use Biolab\Core\Qms\Sealer;
use Doctrine\DBAL\LockMode;
use QMS\DOC\Document;
use QMS\DOC\Revision;
use QMS\MNG\AppUser;
use QMS\ORG\UserAssignment;
use QMS\SIGN\Signature;
use QMS\SIGN\SignStep;

$db = $dbm->getDB();
$resourceFile = "qms_sign/qmsSign-1.0.0.json";
$lm->loadLanguageResource();
$lang = $lm->lang;
$accessType = AT_PRIVATE;
checkAuthorization();

$companyId = (int) $_SESSION["COMPANY_ID"];
$sessionUserId = (int) $_SESSION["USER_ID"];

const QMS_SIGN_MAX_IMAGE   = 400000;   // imza resmi (bayt)
const QMS_SIGN_MAX_STROKES = 300000;   // çizim verisi (karakter)
const QMS_SIGN_MIN_POINTS  = 10;       // boş ya da tek nokta imza kabul edilmez
const QMS_SIGN_SIGNED_MAX  = 500;      // imzaladıklarım listesinde en fazla kayıt

switch ($query) {

    case "getList":
        $execType = ET_DEFAULT;
        try{
            $rows = $db->createQuery("
                SELECT s.signStepId stepId, s.signRole, s.activatedAt, r.revisionId, r.revisionNo,
                       d.docCode, d.variantName, d.docTitle, t.docTypeName
                  FROM QMS\SIGN\SignStep s JOIN s.revision r JOIN r.document d JOIN d.docType t
                 WHERE IDENTITY(s.user) = :USER_ID AND s.stepStatus = 'ACTIVE' AND s.recordFl > 0
                   AND s.signRound = r.signRound AND r.revStatus = 'SIGNING' AND IDENTITY(d.company) = :COMPANY_ID
                 ORDER BY s.activatedAt, d.docCode")
                ->setParameters(array("USER_ID" => $sessionUserId, "COMPANY_ID" => $companyId))->getArrayResult();
            // Açıldı mı: api/qms-file.php'nin yazdığı "görüntülendi" kayıtları
            $viewed = $rows ? array_flip(array_map("intval", array_column($db->createQuery("
                SELECT DISTINCT l.entityId FROM QMS\LOG\AuditLog l
                 WHERE l.entityName = 'qms_sign_step' AND l.action = 'VIEW' AND IDENTITY(l.user) = :USER_ID AND l.entityId IN (:IDS)")
                ->setParameters(array("USER_ID" => $sessionUserId, "IDS" => array_column($rows, "stepId")))->getArrayResult(), "entityId"))) : array();
            $dtResponse = array_map(fn ($r) => array(
                "stepId"      => $r["stepId"],
                "signRole"    => $r["signRole"],
                "activatedAt" => $r["activatedAt"]?->format("d.m.Y H:i"),
                "revisionId"  => $r["revisionId"],
                "revisionNo"  => $r["revisionNo"],
                "docCode"     => $r["docCode"],
                "variantName" => $r["variantName"],
                "docTitle"    => $r["docTitle"],
                "docTypeName" => $r["docTypeName"],
                "viewed"      => isset($viewed[$r["stepId"]]) ? "Y" : "N",
            ), $rows);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "getDetail":
        $execType = ET_DEFAULT;
        try{
            $step = qmsSignStep($db, (int) ($_POST["SIGN_STEP_ID"] ?? 0), $sessionUserId, $companyId);
            if ($step === null) {
                $resCode = 21;
                $resMsg = $lang["ERR_NOT_YOURS"];
            } else {
                $resObject = qmsSignDetail($db, $step->getRevision()) + array(
                    "stepId"     => $step->getSignStepId(),
                    "signRole"   => $step->getSignRole(),
                    "stepStatus" => $step->getStepStatus(),
                );
                $resCode = 0;
                $resMsg = "Ok";
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // Kullanıcının attığı imzalar; belgenin bugünkü durumu ve imzadaysa sıranın hangi adımda olduğu ile
    case "getSigned":
        $execType = ET_DEFAULT;
        try{
            $rows = $db->createQuery("
                SELECT sg.signatureId, sg.signRole, sg.signedAt, r.revisionId, r.revisionNo, r.revStatus, r.signedFilePath,
                       d.docCode, d.variantName, d.docTitle
                  FROM QMS\SIGN\Signature sg JOIN sg.revision r JOIN r.document d
                 WHERE IDENTITY(sg.user) = :USER_ID AND IDENTITY(d.company) = :COMPANY_ID
                 ORDER BY sg.signatureId DESC")
                ->setParameters(array("USER_ID" => $sessionUserId, "COMPANY_ID" => $companyId))
                ->setMaxResults(QMS_SIGN_SIGNED_MAX)->getArrayResult();
            // İmzadaki revizyonlarda sırası gelen adım
            $waiting = array();
            if ($rows) {
                foreach ($db->createQuery("
                    SELECT r.revisionId, a.signRole FROM QMS\SIGN\SignStep a JOIN a.revision r
                     WHERE r.revisionId IN (:IDS) AND a.signRound = r.signRound AND a.stepStatus = 'ACTIVE'")
                    ->setParameter("IDS", array_unique(array_column($rows, "revisionId")))->getArrayResult() as $a) {
                    $waiting[$a["revisionId"]] = $a["signRole"];
                }
            }
            $dtResponse = array_map(fn ($r) => array(
                "signatureId" => $r["signatureId"],
                "signRole"    => $r["signRole"],
                "signedAt"    => $r["signedAt"]->format("d.m.Y H:i"),
                "revisionId"  => $r["revisionId"],
                "revisionNo"  => $r["revisionNo"],
                "revStatus"   => $r["revStatus"],
                "docCode"     => $r["docCode"],
                "variantName" => $r["variantName"],
                "docTitle"    => $r["docTitle"],
                "waitingRole" => $waiting[$r["revisionId"]] ?? null,
                // İndirme: onay sayfası belgeye eklendiyse tek dosya, eklenemediyse (cert-…) ayrı ikinci dosya
                "certOnly"    => $r["signedFilePath"] !== null && str_contains(basename($r["signedFilePath"]), "cert-"),
            ), $rows);
            $resCode = 0;
            $resMsg = "Ok";
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    // İmzaladığı revizyonu açar (salt okunur). Yalnızca o revizyonda imzası olan kullanıcı görür.
    case "getSignedDetail":
        $execType = ET_DEFAULT;
        try{
            $rev = $db->find(Revision::class, (int) ($_POST["REVISION_ID"] ?? 0));
            $mine = $rev !== null && $rev->getDocument()->getCompany()?->getCompanyId() === $companyId
                && (int) $db->createQuery("SELECT COUNT(sg.signatureId) FROM QMS\SIGN\Signature sg WHERE sg.revision = :REV AND IDENTITY(sg.user) = :USER_ID")
                    ->setParameters(array("REV" => $rev, "USER_ID" => $sessionUserId))->getSingleScalarResult() > 0;
            if (!$mine) {
                $resCode = 21;
                $resMsg = $lang["ERR_NOT_YOURS"];
            } else {
                $resObject = qmsSignDetail($db, $rev) + array("readOnly" => true, "revStatus" => $rev->getRevStatus());
                $resCode = 0;
                $resMsg = "Ok";
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setSign":
        $execType = ET_SAVE;
        try{
            $ids      = array_values(array_unique(array_filter(array_map("intval", (array) ($_POST["SIGN_STEP_IDS"] ?? array())))));
            $password = (string) ($_POST["PASSWORD"] ?? "");
            $image    = qmsSignImage((string) ($_POST["SIGNATURE"] ?? ""));
            $strokes  = qmsSignStrokes((string) ($_POST["STROKES"] ?? ""));
            $me       = $db->find(AppUser::class, $sessionUserId);

            $error = null;
            if (!$ids) {
                $error = $lang["ERR_NO_STEP"];
            } elseif ($image === null || $strokes === null) {
                $error = $lang["ERR_SIGNATURE"];
            } elseif ($me === null || !$me->verifyPassword($password)) {
                $error = $lang["ERR_PASSWORD"];
                qmsSignLogAuthFail($db, $ids);
            } elseif (($snapshot = qmsSignSnapshot($db, $me, $companyId)) === null) {
                $error = $lang["ERR_NO_ASSIGNMENT"];
            }

            if ($error !== null) {
                $resCode = 1;
                $resMsg = $error;
            } else {
                $signed = array();
                $failed = array();
                foreach ($ids as $id) {
                    $msg = qmsSignOne($db, $id, $sessionUserId, $companyId, $snapshot, $image, $strokes, $lang);
                    if ($msg === null) {
                        $signed[] = $id;
                    } else {
                        $failed[] = array("stepId" => $id, "message" => $msg);
                    }
                }
                $resObject = array("SIGNED" => $signed, "FAILED" => $failed);
                $resCode = $signed ? 0 : 1;
                $resMsg = $signed
                    ? sprintf($lang["SIGN_OK"], count($signed)) . ($failed ? " " . sprintf($lang["SIGN_PARTIAL"], count($failed)) : "")
                    : implode(" ", array_unique(array_column($failed, "message")));
            }
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;

    case "setReject":
        $execType = ET_SAVE;
        try{
            $id   = (int) ($_POST["SIGN_STEP_ID"] ?? 0);
            $note = trim((string) ($_POST["REJECT_NOTE"] ?? ""));
            $me   = $db->find(AppUser::class, $sessionUserId);

            $error = null;
            if (mb_strlen($note) < 5 || mb_strlen($note) > 1000) {
                $error = $lang["ERR_REJECT_NOTE"];
            } elseif ($me === null || !$me->verifyPassword((string) ($_POST["PASSWORD"] ?? ""))) {
                $error = $lang["ERR_PASSWORD"];
                qmsSignLogAuthFail($db, array($id));
            }
            if ($error === null) {
                $error = $db->wrapInTransaction(function () use ($db, $id, $sessionUserId, $companyId, $note, $lang) {
                    $step = qmsSignLockStep($db, $id, $sessionUserId, $companyId);
                    if ($step === null) {
                        return $lang["ERR_NOT_YOURS"];
                    }
                    $now = new DateTimeImmutable();
                    $rev = $step->getRevision();
                    $step->reject($note, $now);
                    foreach (qmsSignRoundSteps($db, $rev) as $other) {
                        if ($other !== $step && in_array($other->getStepStatus(), array(SignStep::WAITING, SignStep::ACTIVE), true)) {
                            $other->void($now);
                        }
                    }
                    $round = $rev->getSignRound();
                    $rev->returnToDraft();
                    $db->flush();
                    AuditLogger::log($db, "qms_revision", $rev->getRevisionId(), "REJECT", array(
                        "stepId" => $step->getSignStepId(), "signRole" => $step->getSignRole(), "signRound" => $round, "note" => $note));
                    return null;
                });
            }
            $resCode = $error === null ? 0 : 1;
            $resMsg = $error ?? $lang["REJECT_OK"];
        }catch(Exception $exMain){
            $resCode = 100;//Console Log Error
            $resMsg = $exMain->getCode()." Line=".$exMain->getLine()." " . $exMain->getMessage();
        }
        break;
}

/**
 * Tek belgenin imzası (kendi transaction'ında). Hata metni ya da başarıda null döner.
 * Revizyon satırı kilitlenir: aynı belgeyi aynı anda iki kişi imzalayamaz, adım iki kez imzalanamaz.
 */
function qmsSignOne($db, int $stepId, int $userId, int $companyId, array $snapshot, array $image, string $strokes, array $lang): ?string
{
    $stored = array();
    try {
        return $db->wrapInTransaction(function () use ($db, $stepId, $userId, $companyId, $snapshot, $image, $strokes, $lang, &$stored) {
            $step = qmsSignLockStep($db, $stepId, $userId, $companyId);
            if ($step === null) {
                return $lang["ERR_NOT_YOURS"];
            }
            if (!qmsSignViewed($db, $step)) {
                return $lang["ERR_NOT_VIEWED"];
            }
            $rev  = $step->getRevision();
            $doc  = $rev->getDocument();
            $now  = new DateTimeImmutable();
            $dir  = "doc/" . $doc->getDocumentId() . "/r" . $rev->getRevisionId();
            $hashBefore = $rev->getSignedHash() ?? $rev->getPdfHash();

            // İmza resmi: doc/{doküman}/r{revizyon}/sig{adım}-… ; onay sayfası her imzada baştan üretilir
            $imagePath = FileStorage::putContent($image["bytes"], $dir, "sig" . $step->getSignStepId(), "png");
            $stored[] = $imagePath;

            $flow = array();
            foreach (qmsSignRoundSteps($db, $rev) as $s) {
                $sig = $s === $step ? null : $db->getRepository(Signature::class)->findOneBy(array("signStep" => $s));
                $isMe = $s === $step;
                $flow[] = array(
                    "role"       => $s->getSignRole(),
                    "name"       => $isMe ? $snapshot["name"] : ($sig?->getSignerName() ?? $s->getUser()->getFullName()),
                    "title"      => $isMe ? $snapshot["title"] : ($sig?->getSignerTitle() ?? $s->getUser()->getTitle()),
                    "position"   => $isMe ? $snapshot["position"] : ($sig?->getSignerPosition() ?? ""),
                    "status"     => $isMe ? SignStep::SIGNED : $s->getStepStatus(),
                    "signedAt"   => $isMe ? $now : $sig?->getSignedAt(),
                    "imagePng"   => $isMe ? $image["bytes"] : qmsSignStoredImage($db, $s, $dir),
                    "recordHash" => $sig?->getRecordHash(),
                    "stepId"     => $s->getSignStepId(),
                );
            }

            // Kayıt hash'i mühürden sonra bilinir; onay sayfasında bu imzanın hash'i bir sonraki imzada görünür
            $cert   = Sealer::certificate(qmsSignDocInfo($rev), $flow);
            $sealed = Sealer::merge(FileStorage::absolute($rev->getPdfFilePath()), $cert);
            $signedPath = $sealed !== null
                ? FileStorage::putContent($sealed, $dir, "sealed", "pdf")
                : FileStorage::putContent($cert, $dir, "cert", "pdf");
            $stored[] = $signedPath;
            $hashAfter = hash_file("sha256", FileStorage::absolute($signedPath));

            $ip = $_SERVER["REMOTE_ADDR"] ?? null;
            $prev = HashChain::lastSignatureHash($db);
            $fields = array("stepId" => $step->getSignStepId(), "revisionId" => $rev->getRevisionId(), "userId" => $userId,
                            "role" => $step->getSignRole(), "signedAt" => $now, "authMethod" => Signature::AUTH_PASSWORD,
                            "hashBefore" => $hashBefore, "hashAfter" => $hashAfter, "imageHash" => $image["hash"],
                            "strokes" => $strokes, "ip" => $ip);
            $recordHash = HashChain::signatureHash($prev, $fields);

            $step->markSigned($now);
            $db->persist(new Signature($step, $snapshot, $now, Signature::AUTH_PASSWORD, $hashBefore, $hashAfter, $image["hash"],
                                       $strokes, $ip, $_SERVER["HTTP_USER_AGENT"] ?? null, $prev, $recordHash));
            $rev->setSigned($signedPath, $hashAfter);

            // Sıradaki adım ya da yürürlük
            $next = null;
            foreach (qmsSignRoundSteps($db, $rev) as $s) {
                if ($s->getStepStatus() === SignStep::WAITING) {
                    $next = $s;
                    break;
                }
            }
            $effective = false;
            if ($next !== null) {
                $next->activate($now);
            } else {
                $today = new DateTimeImmutable("today");
                foreach ($db->getRepository(Revision::class)->findBy(array("document" => $doc, "revStatus" => Revision::EFFECTIVE)) as $old) {
                    if ($old !== $rev) {
                        $old->makeObsolete($today);
                    }
                }
                $rev->makeEffective($today, $doc->getDocType()->getReviewMonths());
                $doc->publish($rev, $today);
                $effective = true;
            }
            $db->flush();

            AuditLogger::log($db, "qms_revision", $rev->getRevisionId(), "SIGN", array(
                "stepId" => $step->getSignStepId(), "signRole" => $step->getSignRole(), "hashBefore" => $hashBefore,
                "hashAfter" => $hashAfter, "sealed" => $sealed !== null, "nextStepId" => $next?->getSignStepId()));
            if ($effective) {
                AuditLogger::log($db, "qms_revision", $rev->getRevisionId(), "EFFECTIVE", array(
                    "documentId" => $doc->getDocumentId(), "revisionNo" => $rev->getRevisionNo(),
                    "nextReviewDate" => $rev->getNextReviewDate()?->format("Y-m-d")));
            }
            $stored = array();
            return null;
        });
    } catch (Throwable $e) {
        error_log("[QMS sign] " . $e);
        return $lang["ERR_SIGN_FAILED"];
    } finally {
        // Kayıt geri alındıysa depoya yazılan resim ve mühürlü dosya silinir
        foreach ($stored as $p) {
            FileStorage::delete($p);
        }
    }
}

// Adım oturumdaki kullanıcınınsa, sırası gelmişse ve revizyon imzadaysa döner (ekran için kilitsiz)
function qmsSignStep($db, int $stepId, int $userId, int $companyId): ?SignStep
{
    $step = $stepId > 0 ? $db->find(SignStep::class, $stepId) : null;
    if ($step === null || $step->isDeleted() || $step->getUser()->getUserId() !== $userId || !$step->isActive()) {
        return null;
    }
    $rev = $step->getRevision();
    if ($rev->getRevStatus() !== Revision::SIGNING || $rev->getSignRound() !== $step->getSignRound()
        || $rev->getDocument()->getCompany()?->getCompanyId() !== $companyId) {
        return null;
    }
    return $step;
}

// Aynı adım, revizyon satırı kilitlenerek (transaction içinde); kilitten sonra güncel durum yeniden okunur
function qmsSignLockStep($db, int $stepId, int $userId, int $companyId): ?SignStep
{
    $step = $stepId > 0 ? $db->find(SignStep::class, $stepId) : null;
    if ($step === null) {
        return null;
    }
    // Karamsar kilit (SQL Server: WITH (UPDLOCK, ROWLOCK)); transaction bitene kadar tutulur
    $db->lock($step->getRevision(), LockMode::PESSIMISTIC_WRITE);
    $db->refresh($step->getRevision());
    $db->refresh($step);
    return qmsSignStep($db, $stepId, $userId, $companyId);
}

// Revizyonun güncel imza turundaki adımlar (sırasıyla)
function qmsSignRoundSteps($db, Revision $rev): array
{
    return $db->getRepository(SignStep::class)->findBy(array("revision" => $rev, "signRound" => $rev->getSignRound()), array("stepOrder" => "ASC"));
}

// İmzacı bu adım için belgeyi açtı mı (api/qms-file.php'nin VIEW kaydı)
function qmsSignViewed($db, SignStep $step): bool
{
    return (int) $db->createQuery("
        SELECT COUNT(l.logId) FROM QMS\LOG\AuditLog l
         WHERE l.entityName = 'qms_sign_step' AND l.entityId = :ID AND l.action = 'VIEW' AND l.user = :USER")
        ->setParameters(array("ID" => $step->getSignStepId(), "USER" => $step->getUser()))->getSingleScalarResult() > 0;
}

// İmzalanmış önceki adımın resmi (depoda doc/…/r…/sig{adım}-….png)
function qmsSignStoredImage($db, SignStep $step, string $dir): ?string
{
    if ($step->getStepStatus() !== SignStep::SIGNED) {
        return null;
    }
    $files = glob(FileStorage::absolute($dir) . "/sig" . $step->getSignStepId() . "-*.png") ?: array();
    return $files ? (string) file_get_contents($files[0]) : null;
}

// İmza anındaki ad, unvan ve pozisyon; güncel görevi olmayan imza atamaz
function qmsSignSnapshot($db, AppUser $user, int $companyId): ?array
{
    $a = $db->getRepository(UserAssignment::class)->findOneBy(array("user" => $user, "company" => $companyId, "validTo" => null));
    if ($a === null) {
        return null;
    }
    return array("name" => $user->getFullName(), "title" => $user->getTitle() ?: null, "position" => $a->getPosition()->getPositionName());
}

function qmsSignDocInfo(Revision $rev): array
{
    $doc = $rev->getDocument();
    return array("code" => $doc->getDocCode(), "variant" => $doc->getVariantName(), "title" => $doc->getDocTitle(),
                 "revisionNo" => $rev->getRevisionNo(), "type" => $doc->getDocType()->getDocTypeName(),
                 "owner" => $doc->getOwnerDepartment()->getDepartmentName(), "pdfHash" => (string) $rev->getPdfHash());
}

// Ekrandaki belge başlığı: doküman, revizyon, yükleyen, onay sayfası ayrı mı
function qmsSignDetail($db, Revision $rev): array
{
    $doc = $rev->getDocument();
    return array(
        "revisionId"   => $rev->getRevisionId(),
        "docCode"      => $doc->getDocCode(),
        "variantName"  => $doc->getVariantName(),
        "docTitle"     => $doc->getDocTitle(),
        "revisionNo"   => $rev->getRevisionNo(),
        "docTypeName"  => $doc->getDocType()->getDocTypeName(),
        "ownerName"    => $doc->getOwnerDepartment()->getDepartmentName(),
        "changeReason" => $rev->getChangeReason(),
        "uploadedBy"   => $rev->getUploadedBy()->getFullName(),
        "certOnly"     => $rev->getSignedFilePath() !== null && str_contains(basename($rev->getSignedFilePath()), "cert-"),
        "flow"         => qmsSignFlow($db, $rev),
    );
}

// Ekranda gösterilen akış: rol, kişi, durum, zaman
function qmsSignFlow($db, Revision $rev): array
{
    $out = array();
    foreach (qmsSignRoundSteps($db, $rev) as $s) {
        $sig = $s->getStepStatus() === SignStep::SIGNED ? $db->getRepository(Signature::class)->findOneBy(array("signStep" => $s)) : null;
        $out[] = array(
            "role"     => $s->getSignRole(),
            "name"     => trim(($s->getUser()->getTitle() ? $s->getUser()->getTitle() . " " : "") . $s->getUser()->getFullName()),
            "status"   => $s->getStepStatus(),
            "signedAt" => $sig?->getSignedAt()->format("d.m.Y H:i"),
        );
    }
    return $out;
}

// "data:image/png;base64,…" → PNG baytları ve SHA-256'sı; geçersizse null
function qmsSignImage(string $dataUrl): ?array
{
    if (!str_starts_with($dataUrl, "data:image/png;base64,")) {
        return null;
    }
    $bytes = base64_decode(substr($dataUrl, 22), true);
    if ($bytes === false || strlen($bytes) > QMS_SIGN_MAX_IMAGE) {
        return null;
    }
    $info = getimagesizefromstring($bytes);
    if ($info === false || $info[2] !== IMAGETYPE_PNG) {
        return null;
    }
    return array("bytes" => $bytes, "hash" => hash("sha256", $bytes));
}

/**
 * Çizim verisi: "x,y,t;x,y,t|x,y,t;…" (her | yeni bir çizgi; t = çizime başladıktan sonraki ms).
 * JSON olarak saklanır: [[[x,y,t],…],…]. Çok az nokta varsa (boş ya da tek tık) null.
 */
function qmsSignStrokes(string $raw): ?string
{
    if ($raw === "" || strlen($raw) > QMS_SIGN_MAX_STROKES || !preg_match('/^[0-9.,;|-]+$/', $raw)) {
        return null;
    }
    $lines = array();
    $points = 0;
    foreach (explode("|", $raw) as $line) {
        $pts = array();
        foreach (explode(";", $line) as $p) {
            $v = explode(",", $p);
            if (count($v) !== 3) {
                return null;
            }
            $pts[] = array((float) $v[0], (float) $v[1], (int) $v[2]);
        }
        $points += count($pts);
        $lines[] = $pts;
    }
    return $points >= QMS_SIGN_MIN_POINTS ? json_encode($lines) : null;
}

// Yanlış şifre denemesi denetim kaydına yazılır (kayıt kendi transaction'ında)
function qmsSignLogAuthFail($db, array $stepIds): void
{
    $db->wrapInTransaction(fn () => AuditLogger::log($db, "qms_sign_step", $stepIds[0] ?? null, "AUTH_FAIL", array("stepIds" => $stepIds)));
}
