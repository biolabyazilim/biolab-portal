<?php
declare(strict_types=1);

namespace Biolab\Core\Qms;

use Doctrine\ORM\EntityManagerInterface;

/**
 * İmza kayıtlarının (qms_signature) hash zinciri. Her kaydın RECORD_HASH'i önceki kaydın RECORD_HASH'i ile kendi alanlarından
 * hesaplanır; araya kayıt sokulursa, bir kayıt değiştirilir ya da silinirse zincir o noktada kırılır.
 * Önceki hash UPDLOCK/HOLDLOCK ile okunur: aynı anda atılan iki imza aynı önceki hash'e bağlanamaz (transaction içinde çağrılmalı).
 */
final class HashChain
{
    public static function lastSignatureHash(EntityManagerInterface $em): ?string
    {
        $conn = $em->getConnection();
        if (!$conn->isTransactionActive()) {
            throw new \LogicException('İmza zinciri transaction içinde okunmalı.');
        }
        $prev = $conn->fetchOne('SELECT TOP 1 RECORD_HASH FROM qms_signature WITH (UPDLOCK, HOLDLOCK) ORDER BY SIGNATURE_ID DESC');
        return $prev === false || $prev === null ? null : trim((string) $prev);
    }

    /**
     * @param array{stepId:int, revisionId:int, userId:int, role:string, signedAt:\DateTimeInterface, authMethod:string,
     *              hashBefore:string, hashAfter:string, imageHash:string, strokes:string, ip:?string} $f
     */
    public static function signatureHash(?string $prev, array $f): string
    {
        return hash('sha256', implode('|', [
            $prev ?? '',
            $f['stepId'], $f['revisionId'], $f['userId'], $f['role'],
            $f['signedAt']->format('Y-m-d H:i:s.u'),
            $f['authMethod'], $f['hashBefore'], $f['hashAfter'], $f['imageHash'],
            hash('sha256', $f['strokes']),
            $f['ip'] ?? '',
        ]));
    }

    /** Bütün imza zincirini baştan doğrular → ['checked' => N, 'brokenAt' => SIGNATURE_ID|null] */
    public static function verifySignatures(EntityManagerInterface $em): array
    {
        $prev = null;
        $checked = 0;
        foreach ($em->getConnection()->iterateAssociative('
            SELECT s.SIGNATURE_ID, s.SIGN_STEP_ID, s.REVISION_ID, s.USER_ID, s.SIGN_ROLE, s.SIGNED_AT, s.AUTH_METHOD,
                   s.HASH_BEFORE, s.HASH_AFTER, s.IMAGE_HASH, s.STROKES, s.IP_ADDRESS, s.PREV_HASH, s.RECORD_HASH
              FROM qms_signature s ORDER BY s.SIGNATURE_ID') as $r) {
            $expected = self::signatureHash($prev, [
                'stepId' => (int) $r['SIGN_STEP_ID'], 'revisionId' => (int) $r['REVISION_ID'], 'userId' => (int) $r['USER_ID'],
                'role' => $r['SIGN_ROLE'], 'signedAt' => new \DateTimeImmutable((string) $r['SIGNED_AT']),
                'authMethod' => $r['AUTH_METHOD'], 'hashBefore' => trim((string) $r['HASH_BEFORE']), 'hashAfter' => trim((string) $r['HASH_AFTER']),
                'imageHash' => trim((string) $r['IMAGE_HASH']), 'strokes' => (string) $r['STROKES'], 'ip' => $r['IP_ADDRESS'],
            ]);
            if (trim((string) $r['PREV_HASH']) !== ($prev ?? '') || trim((string) $r['RECORD_HASH']) !== $expected) {
                return ['checked' => $checked, 'brokenAt' => (int) $r['SIGNATURE_ID']];
            }
            $prev = $expected;
            $checked++;
        }
        return ['checked' => $checked, 'brokenAt' => null];
    }
}
