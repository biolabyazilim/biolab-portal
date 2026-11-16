<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Görev saat aralığı: PLANNED_AT (başlangıç) – PLANNED_END_AT (bitiş). Vardım başlangıçtan, Tamamladım bitişten önce
 * basılamaz; gerekçeyle erken bitirme DONE_EARLY / EARLY_REASON. Eski görevlerde PLANNED_END_AT boş → kural yok.
 */
final class Version20261113121823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: sht_task saat aralığı (PLANNED_END_AT) ve erken bitirme (DONE_EARLY, EARLY_REASON)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_task ADD PLANNED_END_AT DATETIME2(6)');
        $this->addSql('ALTER TABLE sht_task ADD DONE_EARLY NCHAR(1) NOT NULL CONSTRAINT [DF_sht_task_DONE_EARLY] DEFAULT \'N\'');
        $this->addSql('ALTER TABLE sht_task ADD EARLY_REASON NVARCHAR(500) COLLATE Turkish_CI_AS');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_task DROP COLUMN PLANNED_END_AT');
        $this->addSql('ALTER TABLE sht_task DROP CONSTRAINT [DF_sht_task_DONE_EARLY]');
        $this->addSql('ALTER TABLE sht_task DROP COLUMN DONE_EARLY');
        $this->addSql('ALTER TABLE sht_task DROP COLUMN EARLY_REASON');
    }
}
