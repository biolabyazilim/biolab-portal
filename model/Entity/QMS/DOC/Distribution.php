<?php
declare(strict_types=1);

namespace QMS\DOC;

/**
 * Dağıtım kuralının hedef türleri (qms_doc_type_distribution, qms_revision_distribution → SUBJECT_TYPE).
 *   ALL         şirketteki herkes (SUBJECT_ID boş)
 *   OWNER_DEPT  dokümanın sahibi departman (SUBJECT_ID boş; doküman tipi varsayılanında kullanılır)
 *   DEPARTMENT  qms_department.DEPARTMENT_ID
 *   POSITION    qms_position.POSITION_ID
 *   LOCATION    qms_location.LOCATION_ID
 *   USER        mng_user.USER_ID (istisna; gerekçe ister)
 * SUBJECT_ID türüne göre farklı tabloya baktığı için foreign key yoktur; kontrolü uygulama yapar.
 */
final class Distribution
{
    public const ALL        = 'ALL';
    public const OWNER_DEPT = 'OWNER_DEPT';
    public const DEPARTMENT = 'DEPARTMENT';
    public const POSITION   = 'POSITION';
    public const LOCATION   = 'LOCATION';
    public const USER       = 'USER';

    public const SUBJECT_TYPES = [self::ALL, self::OWNER_DEPT, self::DEPARTMENT, self::POSITION, self::LOCATION, self::USER];

    // SUBJECT_ID istemeyen türler
    public const NO_SUBJECT_ID = [self::ALL, self::OWNER_DEPT];

    public static function assertValid(string $subjectType, ?int $subjectId): void
    {
        if (!in_array($subjectType, self::SUBJECT_TYPES, true)) {
            throw new \InvalidArgumentException('Geçersiz dağıtım türü: ' . $subjectType);
        }
        $needsId = !in_array($subjectType, self::NO_SUBJECT_ID, true);
        if ($needsId !== ($subjectId !== null)) {
            throw new \InvalidArgumentException($needsId
                ? 'Dağıtım türü ' . $subjectType . ' için hedef seçilmeli.'
                : 'Dağıtım türü ' . $subjectType . ' hedef almaz.');
        }
    }
}
