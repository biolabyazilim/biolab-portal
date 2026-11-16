<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Geçmiş kaydı tutulmaya başlamadan önce iptal edilmiş görevler: iptal eden (CANCELLED_BY) son değiştirenden (U_BY)
 * doldurulur ve geçmişe "İptal edildi" satırı eklenir. İptal edilmiş görev artık düzenlenemediği için son değişiklik
 * iptalin kendisidir. Kaynak bilinmediği için SOURCE = WEB_TASKS, NOTE ile işaretlenir.
 */
final class Version20261113121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: eski iptallerde CANCELLED_BY = U_BY ve sht_task_log satırı';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE sht_task SET CANCELLED_BY = U_BY WHERE STATUS = 'CANCELLED' AND CANCELLED_BY IS NULL AND U_BY IS NOT NULL");
        $this->addSql("
            INSERT INTO sht_task_log (COMPANY_ID, TASK_ID, ACTION, STATUS_FROM, STATUS_TO, PERSONEL_FROM_ID, PERSONEL_TO_ID,
                                      SOURCE, EVENT_AT, NOTE, C_BY, C_AT, ACTIVE_FL, RECORD_FL)
            SELECT t.COMPANY_ID, t.TASK_ID, 'CANCELLED', NULL, 'CANCELLED', t.PERSONEL_ID, t.PERSONEL_ID,
                   'WEB_TASKS', COALESCE(t.CANCELLED_AT, t.U_AT), N'Geçmiş kaydı öncesi iptal (sonradan eklendi)',
                   t.CANCELLED_BY, COALESCE(t.CANCELLED_AT, t.U_AT), 'Y', 1
              FROM sht_task t
             WHERE t.STATUS = 'CANCELLED'
               AND NOT EXISTS (SELECT 1 FROM sht_task_log l WHERE l.TASK_ID = t.TASK_ID AND l.ACTION = 'CANCELLED')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sht_task_log WHERE ACTION = 'CANCELLED' AND NOTE = N'Geçmiş kaydı öncesi iptal (sonradan eklendi)'");
    }
}
