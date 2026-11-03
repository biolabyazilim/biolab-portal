<?php
declare(strict_types=1);

namespace Biolab\Core\Qms;

use QMS\LOG\AuditLog;
use QMS\MNG\AppUser;
use QMS\MNG\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * QMS denetim kaydı (qms_audit_log): kim, ne zaman, neye, ne yaptı.
 * Her kayıt bir öncekinin RECORD_HASH'ini taşır (hash zinciri); araya kayıt sokulur, bir kayıt değiştirilir
 * ya da silinirse zincir kırılır (verifyChain). Tablo ayrıca trigger ile UPDATE/DELETE'e kapalıdır.
 *
 * Kullanım (ekran php'sinde, kaydın kendisiyle aynı transaction'da):
 *   $db->wrapInTransaction(function () use ($db, $dept) {
 *       ... $db->flush();
 *       AuditLogger::log($db, 'qms_department', $dept->getDepartmentId(), 'CREATE', ['code' => ...]);
 *   });
 * Kullanıcı ve şirket oturumdan alınır (QMS oturumu).
 */
final class AuditLogger
{
    /** @param array<string,mixed> $detail */
    public static function log(EntityManagerInterface $em, string $entityName, ?int $entityId, string $action, array $detail = []): AuditLog
    {
        $conn = $em->getConnection();
        if (!$conn->isTransactionActive()) {
            throw new \LogicException('AuditLogger::log transaction içinde çağrılmalı.');
        }

        // Son kaydın hash'i; UPDLOCK + HOLDLOCK: aynı anda yazan iki istek aynı önceki hash'i alamaz
        $prevHash = $conn->fetchOne('SELECT TOP 1 RECORD_HASH FROM qms_audit_log WITH (UPDLOCK, HOLDLOCK) ORDER BY LOG_ID DESC');
        $prevHash = $prevHash !== false ? rtrim((string) $prevHash) : null;

        $userId    = isset($_SESSION['USER_ID']) ? (int) $_SESSION['USER_ID'] : null;
        $companyId = isset($_SESSION['COMPANY_ID']) ? (int) $_SESSION['COMPANY_ID'] : null;
        $ip        = $_SERVER['REMOTE_ADDR'] ?? null;
        $now       = new \DateTimeImmutable();
        $json      = $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $log = new AuditLog(
            $companyId ? $em->getReference(Company::class, $companyId) : null,
            $userId ? $em->getReference(AppUser::class, $userId) : null,
            $entityName,
            $entityId,
            $action,
            $json,
            $ip,
            $now,
            $prevHash,
            self::hash($prevHash, $companyId, $userId, $entityName, $entityId, $action, $json, $ip, $now),
        );
        $em->persist($log);
        $em->flush();

        return $log;
    }

    /**
     * Zinciri baştan sona yeniden hesaplar. Bozuk ilk kaydın LOG_ID'sini döndürür; sağlamsa null.
     * @return array{checked:int, brokenAt:?int}
     */
    public static function verifyChain(EntityManagerInterface $em): array
    {
        $rows = $em->getConnection()->iterateAssociative('
            SELECT LOG_ID, COMPANY_ID, USER_ID, ENTITY_NAME, ENTITY_ID, ACTION, DETAIL, IP_ADDRESS, CREATED_AT, PREV_HASH, RECORD_HASH
              FROM qms_audit_log ORDER BY LOG_ID
        ');
        $prev    = null;
        $checked = 0;
        foreach ($rows as $r) {
            $checked++;
            $expected = self::hash(
                $prev,
                $r['COMPANY_ID'] !== null ? (int) $r['COMPANY_ID'] : null,
                $r['USER_ID'] !== null ? (int) $r['USER_ID'] : null,
                $r['ENTITY_NAME'],
                $r['ENTITY_ID'] !== null ? (int) $r['ENTITY_ID'] : null,
                $r['ACTION'],
                $r['DETAIL'],
                $r['IP_ADDRESS'],
                new \DateTimeImmutable((string) $r['CREATED_AT']),
            );
            $prevStored = $r['PREV_HASH'] !== null ? rtrim((string) $r['PREV_HASH']) : null;
            if ($prevStored !== $prev || rtrim((string) $r['RECORD_HASH']) !== $expected) {
                return ['checked' => $checked, 'brokenAt' => (int) $r['LOG_ID']];
            }
            $prev = $expected;
        }
        return ['checked' => $checked, 'brokenAt' => null];
    }

    // Alanların sabit sırayla birleştirilmiş halinin SHA-256'sı; zaman mikrosaniyeye kadar (DATETIME2(6))
    private static function hash(?string $prevHash, ?int $companyId, ?int $userId, string $entityName, ?int $entityId,
                                 string $action, ?string $detail, ?string $ip, \DateTimeImmutable $at): string
    {
        return hash('sha256', implode('|', [
            $prevHash ?? '', $companyId ?? '', $userId ?? '', $entityName, $entityId ?? '',
            $action, $detail ?? '', $ip ?? '', $at->format('Y-m-d H:i:s.u'),
        ]));
    }
}
