<?php
declare(strict_types=1);

/**
 * Biolab QMS — doküman dosyası (dosyalar web kökü dışındaki depoda; tarayıcı yalnızca buradan alır)
 *   GET ?revision=ID&kind=current   imzaya giden / mühürlü PDF (varsayılan)
 *   GET ?revision=ID&kind=original  yüklenen PDF (onay sayfası olmadan)
 *   GET ?revision=ID&kind=cert      onay sayfası (PDF ile birleştirilemediyse ayrı dosya)
 *   GET ?revision=ID&kind=source    özgün Excel/Word (indirme)
 *   GET ?record=ID                  doldurulmuş form (qms_form_record)
 *   &download=1                     tarayıcıda açmak yerine indirir (PDF'ler varsayılan olarak tarayıcıda açılır)
 *
 * Yetki: kalite, yönetici, yükleyici ve denetçi rolleri her revizyonu; diğerleri imza akışında adı geçen revizyonu ve
 * dokümanın yürürlükteki revizyonunu açar (form doldurmak için; dağıtıma göre süzme Dokümanlarım ekranında eklenecek).
 * Doldurulmuş formu dolduran kişi ile kalite, yönetici ve denetçi açar.
 * Sırası gelmiş imzacı açtığında "belge görüntülendi" denetim kaydına yazılır; İmzalarım ekranı bu kayıt olmadan imzaya izin vermez.
 */

use Biolab\Core\Http\Session;
use Biolab\Core\Qms\AuditLogger;
use Biolab\Core\Qms\Auth\QmsAuthService;
use Biolab\Core\Qms\Auth\QmsFormAccess;
use Biolab\Core\Qms\FileStorage;
use QMS\DOC\Revision;
use QMS\REC\FormRecord;

$root = dirname(__DIR__, 2);   // public/api → proje kökü
require $root . '/vendor/autoload.php';

const QMS_FILE_ROLES_ALL     = ['QMS_ADMIN', 'QMS_QUALITY', 'QMS_UPLOAD', 'QMS_AUDITOR'];
const QMS_FILE_RECORD_ROLES  = ['QMS_ADMIN', 'QMS_QUALITY', 'QMS_AUDITOR'];

function qmsFileFail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

Session::start(Session::QMS_NAME);
$user = QmsAuthService::currentUser();
session_write_close();
if ($user === null) {
    qmsFileFail(401, 'Oturum yok.');
}

$disposition = ($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline';

try {
    /** @var \Doctrine\ORM\EntityManagerInterface $em */
    $em   = require $root . '/bootstrap-qms.php';
    $roles = (new QmsFormAccess($em))->roleCodes($user['userId'], $user['companyId']);

    // Doldurulmuş form
    if (isset($_GET['record'])) {
        $rec = $em->find(FormRecord::class, (int) $_GET['record']);
        $recDoc = $rec?->getDocument();
        if ($rec === null || $recDoc->getCompany()?->getCompanyId() !== $user['companyId'] || !is_file(FileStorage::absolute($rec->getFilePath()))) {
            qmsFileFail(404, 'Kayıt bulunamadı.');
        }
        if ($rec->getUser()->getUserId() !== $user['userId'] && !array_intersect($roles, QMS_FILE_RECORD_ROLES)) {
            qmsFileFail(403, 'Bu kaydı açma yetkiniz yok.');
        }
        $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', trim(($recDoc->getDocCode() ?? 'form') . ($recDoc->getVariantName() !== '' ? '-' . $recDoc->getVariantName() : '')))
              . '-kayit' . $rec->getFormRecordId() . '-' . $rec->getFilledAt()->format('Ymd-Hi') . '.pdf';
        $abs = FileStorage::absolute($rec->getFilePath());
        header('Content-Type: application/pdf');
        header("Content-Disposition: " . $disposition . "; filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
        exit;
    }

    $rev = $em->find(Revision::class, (int) ($_GET['revision'] ?? 0));
    $doc = $rev?->getDocument();
    if ($rev === null || $rev->isDeleted() || $doc->getCompany()?->getCompanyId() !== $user['companyId']) {
        qmsFileFail(404, 'Doküman bulunamadı.');
    }

    $isCurrent = $rev->getRevStatus() === Revision::EFFECTIVE && $doc->getCurrentRevision() === $rev;
    $inFlow = (int) $em->createQuery("SELECT COUNT(s.signStepId) FROM QMS\SIGN\SignStep s WHERE s.revision = :REV AND IDENTITY(s.user) = :USER_ID AND s.recordFl > 0")
        ->setParameters(['REV' => $rev, 'USER_ID' => $user['userId']])->getSingleScalarResult() > 0;
    if (!$inFlow && !$isCurrent && !array_intersect($roles, QMS_FILE_ROLES_ALL)) {
        qmsFileFail(403, 'Bu dokümanı açma yetkiniz yok.');
    }

    // Mühürlü dosya iki türlü olur: sealed-… (PDF + onay sayfası) ya da cert-… (birleştirilemedi, yalnızca onay sayfası)
    $signed = $rev->getSignedFilePath();
    $isCert = $signed !== null && str_contains(basename($signed), 'cert-');
    $kind = (string) ($_GET['kind'] ?? 'current');
    [$path, $type, $ext] = match ($kind) {
        'original' => [$rev->getPdfFilePath(), 'application/pdf', 'pdf'],
        'cert'     => [$isCert ? $signed : null, 'application/pdf', 'pdf'],
        'source'   => [$rev->getSourceFilePath(), 'application/octet-stream', pathinfo((string) $rev->getSourceFileName(), PATHINFO_EXTENSION)],
        default    => [($signed !== null && !$isCert) ? $signed : $rev->getPdfFilePath(), 'application/pdf', 'pdf'],
    };
    if ($path === null || !is_file(FileStorage::absolute($path))) {
        qmsFileFail(404, 'Dosya bulunamadı.');
    }

    // Sırası gelmiş imzacı belgeyi açtı: imza ekranı bu kaydı arar. İndirmek okumak sayılmaz, kayıt yazılmaz.
    $stepId = $disposition === 'attachment' ? null : $em->createQuery("
        SELECT s.signStepId FROM QMS\SIGN\SignStep s JOIN s.revision r
         WHERE s.revision = :REV AND s.signRound = r.signRound AND IDENTITY(s.user) = :USER_ID AND s.stepStatus = 'ACTIVE' AND s.recordFl > 0")
        ->setParameters(['REV' => $rev, 'USER_ID' => $user['userId']])->setMaxResults(1)->getArrayResult()[0]['signStepId'] ?? null;
    if ($stepId !== null && in_array($kind, ['current', 'cert'], true)) {
        // AuditLogger kullanıcıyı $_SESSION'dan okur; session_write_close sonrası dizi okunabilir kalır
        $em->wrapInTransaction(fn () => AuditLogger::log($em, 'qms_sign_step', (int) $stepId, 'VIEW', [
            'revisionId' => $rev->getRevisionId(), 'kind' => $kind,
            'fileHash' => $kind === 'cert' || ($signed !== null && !$isCert) ? $rev->getSignedHash() : $rev->getPdfHash(),
        ]));
    }

    $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', trim(($doc->getDocCode() ?? 'dokuman') . ($doc->getVariantName() !== '' ? '-' . $doc->getVariantName() : '')))
          . '-rev' . str_pad((string) $rev->getRevisionNo(), 2, '0', STR_PAD_LEFT) . ($kind === 'cert' ? '-onay' : '') . '.' . $ext;
    header('Content-Type: ' . $type);
    header('Content-Disposition: ' . ($kind === 'source' ? 'attachment' : $disposition) . "; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize(FileStorage::absolute((string) $path)));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile(FileStorage::absolute((string) $path));
} catch (\Throwable $e) {
    error_log('[QMS file] ' . $e);
    qmsFileFail(500, 'Dosya açılamadı.');
}
