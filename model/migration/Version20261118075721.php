<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aylık takip: fire bildirim formu satırları (mlyt_fire_report; şube ve PCR formları).
 */
final class Version20261118075721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: fire bildirim satırları (mlyt_fire_report)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mlyt_fire_report (FIRE_ID INT IDENTITY NOT NULL, EVENT_DATE DATE NOT NULL, UNIT_NAME NVARCHAR(60) COLLATE Turkish_CI_AS NOT NULL, DEPOT_CODE INT, STOCK_CODE NVARCHAR(30), MATERIAL_TEXT NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, QUANTITY NUMERIC(14, 3) NOT NULL, MEASURE NVARCHAR(10) COLLATE Turkish_CI_AS, REASON NVARCHAR(60) COLLATE Turkish_CI_AS NOT NULL, RUN_NO NVARCHAR(40), AFFECTED_TESTS INT, RETESTED_FL NCHAR(1), LOT_NO NVARCHAR(60), NOTE NVARCHAR(300) COLLATE Turkish_CI_AS, FILE_ID INT NOT NULL, PRIMARY KEY (FIRE_ID))');
        $this->addSql('CREATE INDEX IX_mlyt_fire_report_file ON mlyt_fire_report (FILE_ID)');
        $this->addSql('ALTER TABLE mlyt_fire_report ADD CONSTRAINT FK_D85D8ED294C3C196 FOREIGN KEY (FILE_ID) REFERENCES mlyt_period_file (FILE_ID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_fire_report DROP CONSTRAINT FK_D85D8ED294C3C196');
        $this->addSql('DROP TABLE mlyt_fire_report');
    }
}
