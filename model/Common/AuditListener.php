<?php
declare(strict_types=1);

namespace Biolab\Model\Common;

use BIO\MNG\AppUser;
use BIO\MNG\Company;
use Doctrine\ORM\Event\OnFlushEventArgs;

final class AuditListener
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

    // Modelde "use AuditTrait;" var mı?
    private static function usesAudit(object $entity): bool
    {
        return in_array(AuditTrait::class, class_uses($entity), true);
    }
}
