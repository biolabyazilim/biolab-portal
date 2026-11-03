<?php
declare(strict_types=1);

namespace Biolab\Model\Common\Qms;

use QMS\MNG\AppUser;
use QMS\MNG\Company;
use Doctrine\ORM\Event\OnFlushEventArgs;

/**
 * Biolab\Model\Common\AuditListener'ın QMS karşılığı (QMS EntityManager'ına bağlanır, bootstrap-qms.php).
 * "use QmsAuditTrait;" olan entity'lerde C_BY/C_AT, U_BY/U_AT, D_BY/D_AT alanlarını doldurur;
 * COMPANY_ID alanı boşsa oturumdaki şirketi yazar.
 * İmza ve denetim kaydı tabloları (qms_signature, qms_audit_log, qms_read_ack) bu trait'i kullanmaz:
 * yalnızca eklenir, hiç güncellenmez.
 */
final class QmsAuditListener
{
    /** @param \Closure(): ?int $currentUserId */
    public function __construct(private \Closure $currentUserId) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em      = $args->getObjectManager();
        $uow     = $em->getUnitOfWork();
        $id      = ($this->currentUserId)();
        $user    = $id ? $em->getReference(AppUser::class, $id) : null;   // sorgu atmaz
        $company = isset($_SESSION['COMPANY_ID']) ? $em->getReference(Company::class, (int) $_SESSION['COMPANY_ID']) : null;
        $now     = new \DateTimeImmutable();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!self::usesAudit($entity)) {
                continue;
            }
            $entity->auditCreate($user, $now);
            // Modelde COMPANY_ID alanı (setCompany) varsa ve boşsa oturumdaki şirket yazılır
            if ($company && method_exists($entity, 'setCompany') && $entity->getCompany() === null) {
                $entity->setCompany($company);
            }
            $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($entity::class), $entity);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!self::usesAudit($entity)) {
                continue;
            }
            $entity->auditUpdate($user, $now);
            $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($entity::class), $entity);
        }
    }

    // Modelde "use QmsAuditTrait;" var mı?
    private static function usesAudit(object $entity): bool
    {
        return in_array(QmsAuditTrait::class, class_uses($entity), true);
    }
}
