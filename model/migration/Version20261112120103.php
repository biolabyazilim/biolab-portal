<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Görev yol rotası: Yolda iken personelden firmaya OSRM sürüş rotası (Biolab\Core\Sht\RouteClient).
 */
final class Version20261112120103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: sht_task yol rotası alanları (ROUTE_*)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_task ADD ROUTE_POLYLINE VARCHAR(MAX)');
        $this->addSql('ALTER TABLE sht_task ADD ROUTE_DISTANCE_M INT');
        $this->addSql('ALTER TABLE sht_task ADD ROUTE_DURATION_S INT');
        $this->addSql('ALTER TABLE sht_task ADD ROUTE_AT DATETIME2(6)');
        $this->addSql('ALTER TABLE sht_task ADD ROUTE_COUNT SMALLINT NOT NULL CONSTRAINT [DF_sht_task_ROUTE_COUNT] DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_task DROP COLUMN ROUTE_POLYLINE');
        $this->addSql('ALTER TABLE sht_task DROP COLUMN ROUTE_DISTANCE_M');
        $this->addSql('ALTER TABLE sht_task DROP COLUMN ROUTE_DURATION_S');
        $this->addSql('ALTER TABLE sht_task DROP COLUMN ROUTE_AT');
        $this->addSql('ALTER TABLE sht_task DROP CONSTRAINT [DF_sht_task_ROUTE_COUNT]');
        $this->addSql('ALTER TABLE sht_task DROP COLUMN ROUTE_COUNT');
    }
}
