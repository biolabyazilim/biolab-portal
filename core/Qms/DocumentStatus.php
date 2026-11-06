<?php
declare(strict_types=1);

namespace Biolab\Core\Qms;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Doküman listelerinde (Form doldur, Ana liste) birden çok dokümanın durum bilgisi, doküman sayısı kadar sorgu atmadan:
 *   pending()      imzadaki ya da reddedilmiş (taslak) son revizyon ve sırası gelen imza adımı
 *   recordCounts() doldurulmuş form (kayıt) sayısı
 *   lastFilled()   kullanıcının formu son doldurduğu zaman
 */
final class DocumentStatus
{
    /** @return array<int, array{revisionId:int, revisionNo:int, revStatus:string, waitingRole:?string}> doküman no → son açık revizyon */
    public static function pending(EntityManagerInterface $em, array $documentIds): array
    {
        if (!$documentIds) {
            return [];
        }
        $out = [];
        // En yeni revizyon önce gelir; dokümanın ilk satırı son açık revizyonudur
        foreach ($em->createQuery("
            SELECT IDENTITY(p.document) documentId, p.revisionId, p.revisionNo, p.revStatus
              FROM QMS\DOC\Revision p
             WHERE IDENTITY(p.document) IN (:IDS) AND p.revStatus IN ('SIGNING', 'DRAFT') AND p.recordFl > 0
             ORDER BY p.revisionId DESC")
            ->setParameter('IDS', array_values(array_unique($documentIds)))->getArrayResult() as $p) {
            $out[(int) $p['documentId']] ??= ['revisionId' => (int) $p['revisionId'], 'revisionNo' => (int) $p['revisionNo'],
                                               'revStatus' => $p['revStatus'], 'waitingRole' => null];
        }
        if ($out) {
            $byRevision = [];   // revizyon no → doküman no
            foreach ($out as $docId => $p) {
                $byRevision[$p['revisionId']] = $docId;
            }
            foreach ($em->createQuery("
                SELECT r.revisionId, s.signRole FROM QMS\SIGN\SignStep s JOIN s.revision r
                 WHERE r.revisionId IN (:IDS) AND s.signRound = r.signRound AND s.stepStatus = 'ACTIVE'")
                ->setParameter('IDS', array_keys($byRevision))->getArrayResult() as $s) {
                $out[$byRevision[$s['revisionId']]]['waitingRole'] = $s['signRole'];
            }
        }
        return $out;
    }

    /** @return array<int, int> doküman no → kayıt sayısı */
    public static function recordCounts(EntityManagerInterface $em, array $documentIds): array
    {
        if (!$documentIds) {
            return [];
        }
        $out = [];
        foreach ($em->createQuery("
            SELECT IDENTITY(fr.document) documentId, COUNT(fr.formRecordId) n
              FROM QMS\REC\FormRecord fr WHERE IDENTITY(fr.document) IN (:IDS) GROUP BY fr.document")
            ->setParameter('IDS', array_values(array_unique($documentIds)))->getArrayResult() as $r) {
            $out[(int) $r['documentId']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<int, \DateTimeImmutable> doküman no → kullanıcının son doldurma zamanı */
    public static function lastFilled(EntityManagerInterface $em, array $documentIds, int $userId): array
    {
        if (!$documentIds) {
            return [];
        }
        $out = [];
        foreach ($em->createQuery("
            SELECT IDENTITY(fr.document) documentId, MAX(fr.filledAt) lastFilled
              FROM QMS\REC\FormRecord fr WHERE IDENTITY(fr.document) IN (:IDS) AND IDENTITY(fr.user) = :USER_ID GROUP BY fr.document")
            ->setParameters(['IDS' => array_values(array_unique($documentIds)), 'USER_ID' => $userId])->getArrayResult() as $r) {
            // MAX() karışık sonuçta metin döner
            $out[(int) $r['documentId']] = new \DateTimeImmutable((string) $r['lastFilled']);
        }
        return $out;
    }
}
