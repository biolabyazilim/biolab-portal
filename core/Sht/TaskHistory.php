<?php
declare(strict_types=1);

namespace Biolab\Core\Sht;

use BIO\MNG\AppUser;
use BIO\SHT\Personel;
use BIO\SHT\Task;
use BIO\SHT\TaskLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Görev geçmişi (sht_task_log) yazma / okuma.
 *
 * Kullanım: görevi değiştirmeden ÖNCE eski durumu ve kişiyi al, değiştir, record() çağır, sonra flush:
 *   $from = $task->getStatus(); $prev = $task->getPersonel();
 *   $task->cancel($now, TaskHistory::currentUser($db));
 *   TaskHistory::record($db, $task, TaskLog::CANCELLED, TaskLog::SRC_MAP, $from, $prev);
 *   $db->flush();
 * Kayıt görevle aynı flush'ta yazılır (ikisi birlikte ya yazılır ya yazılmaz). Kim (C_BY) ve şirket
 * AuditListener ile oturumdaki kullanıcıdan gelir.
 */
final class TaskHistory
{
    /** Oturumdaki portal kullanıcısı (web: giriş yapan; mobil API: personelin kullanıcısı) */
    public static function currentUser(EntityManagerInterface $em): ?AppUser
    {
        $id = (int) ($_SESSION['USER_ID'] ?? 0);
        return $id > 0 ? $em->getReference(AppUser::class, $id) : null;
    }

    public static function record(EntityManagerInterface $em, Task $task, string $action, string $source,
                                  ?string $statusFrom, ?Personel $personelFrom,
                                  ?\DateTimeImmutable $at = null, ?string $note = null): TaskLog
    {
        $log = new TaskLog($task, $action, $source, $statusFrom, $personelFrom, $at ?? new \DateTimeImmutable(), $note);
        $em->persist($log);
        return $log;
    }

    /** Kişi değişikliğinin adı: ASSIGNED / REASSIGNED / UNASSIGNED; değişmediyse null */
    public static function assignmentAction(?Personel $prev, ?Personel $new): ?string
    {
        if ($prev === $new) {
            return null;
        }
        if ($prev === null) {
            return TaskLog::ASSIGNED;
        }
        return $new === null ? TaskLog::UNASSIGNED : TaskLog::REASSIGNED;
    }

    /**
     * Görevin bütün geçmişi, eskiden yeniye (web'de görev formundaki "Geçmiş").
     * @return list<array{eventAt:string, action:string, statusFrom:?string, statusTo:string, source:string,
     *                    byName:?string, personelFrom:?string, personelTo:?string, note:?string}>
     */
    public static function list(EntityManagerInterface $em, int $taskId, int $companyId): array
    {
        $rows = $em->createQuery("
            SELECT l.eventAt, l.cAt, l.action, l.statusFrom, l.statusTo, l.source, l.note,
                   cb.fullName byName, uf.fullName fromName, pf.personelCode fromCode, ut.fullName toName, pt.personelCode toCode
              FROM BIO\SHT\TaskLog l
              LEFT JOIN l.cBy cb
              LEFT JOIN l.personelFrom pf LEFT JOIN pf.user uf
              LEFT JOIN l.personelTo pt   LEFT JOIN pt.user ut
             WHERE IDENTITY(l.task) = :TASK_ID AND IDENTITY(l.company) = :COMPANY_ID AND l.recordFl > 0
             ORDER BY l.eventAt ASC, l.logId ASC
        ")->setParameters(['TASK_ID' => $taskId, 'COMPANY_ID' => $companyId])->getArrayResult();

        $who = fn (?string $n, ?string $c) => $n !== null ? $n . ($c ? ' (' . $c . ')' : '') : null;
        return array_map(fn (array $r) => [
            'eventAt'      => $r['eventAt']->format('Y-m-d H:i:s'),
            'loggedAt'     => $r['cAt']?->format('Y-m-d H:i:s'),
            'action'       => $r['action'],
            'statusFrom'   => $r['statusFrom'],
            'statusTo'     => $r['statusTo'],
            'source'       => $r['source'],
            'byName'       => $r['byName'],
            'personelFrom' => $who($r['fromName'], $r['fromCode']),
            'personelTo'   => $who($r['toName'], $r['toCode']),
            'note'         => $r['note'],
        ], $rows);
    }
}
