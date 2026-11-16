<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Görev geçmişi (kim, ne zaman, ne yaptı): sht_task_log + sht_task.CANCELLED_BY (Biolab\Core\Sht\TaskHistory).
 */
final class Version20261113120051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: görev geçmişi sht_task_log, iptal eden sht_task.CANCELLED_BY';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sht_task_log (LOG_ID INT IDENTITY NOT NULL, ACTION NVARCHAR(20) NOT NULL, STATUS_FROM NVARCHAR(15), STATUS_TO NVARCHAR(15) NOT NULL, SOURCE NVARCHAR(10) NOT NULL, EVENT_AT DATETIME2(6) NOT NULL, NOTE NVARCHAR(1000) COLLATE Turkish_CI_AS, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, TASK_ID INT NOT NULL, PERSONEL_FROM_ID INT, PERSONEL_TO_ID INT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (LOG_ID))');
        $this->addSql('CREATE INDEX IX_sht_task_log_TASK ON sht_task_log (TASK_ID, EVENT_AT)');
        $this->addSql('CREATE INDEX IX_sht_task_log_COMPANY_ACTION ON sht_task_log (COMPANY_ID, ACTION, EVENT_AT)');
        $this->addSql('CREATE INDEX IDX_15C00678A4494109 ON sht_task_log (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_15C006788ABEB97C ON sht_task_log (TASK_ID)');
        $this->addSql('CREATE INDEX IDX_15C0067847EB6B32 ON sht_task_log (PERSONEL_FROM_ID)');
        $this->addSql('CREATE INDEX IDX_15C0067838EF3B62 ON sht_task_log (PERSONEL_TO_ID)');
        $this->addSql('CREATE INDEX IDX_15C006781FFD8595 ON sht_task_log (C_BY)');
        $this->addSql('CREATE INDEX IDX_15C006786A8F8DD6 ON sht_task_log (U_BY)');
        $this->addSql('CREATE INDEX IDX_15C00678822ABD2C ON sht_task_log (D_BY)');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT [DF_sht_task_log_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT [DF_sht_task_log_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT [DF_sht_task_log_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT FK_15C00678A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT FK_15C006788ABEB97C FOREIGN KEY (TASK_ID) REFERENCES sht_task (TASK_ID)');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT FK_15C0067847EB6B32 FOREIGN KEY (PERSONEL_FROM_ID) REFERENCES sht_personel (PERSONEL_ID)');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT FK_15C0067838EF3B62 FOREIGN KEY (PERSONEL_TO_ID) REFERENCES sht_personel (PERSONEL_ID)');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT FK_15C006781FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT FK_15C006786A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_task_log ADD CONSTRAINT FK_15C00678822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_task ADD CANCELLED_BY BIGINT');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT FK_A66E586CE5B3C10B FOREIGN KEY (CANCELLED_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('CREATE INDEX IDX_A66E586CE5B3C10B ON sht_task (CANCELLED_BY)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_task_log DROP CONSTRAINT FK_15C00678A4494109');
        $this->addSql('ALTER TABLE sht_task_log DROP CONSTRAINT FK_15C006788ABEB97C');
        $this->addSql('ALTER TABLE sht_task_log DROP CONSTRAINT FK_15C0067847EB6B32');
        $this->addSql('ALTER TABLE sht_task_log DROP CONSTRAINT FK_15C0067838EF3B62');
        $this->addSql('ALTER TABLE sht_task_log DROP CONSTRAINT FK_15C006781FFD8595');
        $this->addSql('ALTER TABLE sht_task_log DROP CONSTRAINT FK_15C006786A8F8DD6');
        $this->addSql('ALTER TABLE sht_task_log DROP CONSTRAINT FK_15C00678822ABD2C');
        $this->addSql('DROP TABLE sht_task_log');
        $this->addSql('ALTER TABLE sht_task DROP CONSTRAINT FK_A66E586CE5B3C10B');
        $this->addSql('DROP INDEX IDX_A66E586CE5B3C10B ON sht_task');
        $this->addSql('ALTER TABLE sht_task DROP COLUMN CANCELLED_BY');
    }
}
