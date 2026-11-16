<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saha takip Aşama 2: ziyaret edilen firmalar (sht_customer) ve görevler (sht_task).
 * Görev akışı: NEW → ASSIGNED → ON_THE_WAY → ON_SITE → DONE (ya da CANCELLED). Ekran / menü: Version20261112090000.
 */
final class Version20261112081405 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: sht_customer (firmalar), sht_task (görevler)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sht_customer (CUSTOMER_ID INT IDENTITY NOT NULL, CUSTOMER_NAME NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, ADDRESS NVARCHAR(500) COLLATE Turkish_CI_AS, CITY NVARCHAR(50) COLLATE Turkish_CI_AS, LAT NUMERIC(9, 6), LON NUMERIC(9, 6), CONTACT_NAME NVARCHAR(100) COLLATE Turkish_CI_AS, PHONE NVARCHAR(20), ISG_KATIP_NO NVARCHAR(50), NOTE NVARCHAR(500) COLLATE Turkish_CI_AS, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (CUSTOMER_ID))');
        $this->addSql('CREATE INDEX IX_sht_customer_NAME ON sht_customer (COMPANY_ID, CUSTOMER_NAME)');
        $this->addSql('CREATE INDEX IDX_74441833A4494109 ON sht_customer (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_744418331FFD8595 ON sht_customer (C_BY)');
        $this->addSql('CREATE INDEX IDX_744418336A8F8DD6 ON sht_customer (U_BY)');
        $this->addSql('CREATE INDEX IDX_74441833822ABD2C ON sht_customer (D_BY)');
        $this->addSql('ALTER TABLE sht_customer ADD CONSTRAINT [DF_sht_customer_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE sht_customer ADD CONSTRAINT [DF_sht_customer_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE sht_customer ADD CONSTRAINT [DF_sht_customer_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE sht_task (TASK_ID INT IDENTITY NOT NULL, TITLE NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, DESCRIPTION NVARCHAR(1000) COLLATE Turkish_CI_AS, PLANNED_AT DATETIME2(6), STATUS NVARCHAR(15) NOT NULL, ASSIGNED_AT DATETIME2(6), ON_THE_WAY_AT DATETIME2(6), ARRIVED_AT DATETIME2(6), ARRIVE_LAT NUMERIC(9, 6), ARRIVE_LON NUMERIC(9, 6), ARRIVE_DISTANCE_M INT, DONE_AT DATETIME2(6), DONE_LAT NUMERIC(9, 6), DONE_LON NUMERIC(9, 6), DONE_NOTE NVARCHAR(1000) COLLATE Turkish_CI_AS, CANCELLED_AT DATETIME2(6), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, CUSTOMER_ID INT NOT NULL, PERSONEL_ID INT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (TASK_ID))');
        $this->addSql('CREATE INDEX IX_sht_task_PERSONEL_STATUS ON sht_task (PERSONEL_ID, STATUS)');
        $this->addSql('CREATE INDEX IX_sht_task_COMPANY_PLANNED ON sht_task (COMPANY_ID, PLANNED_AT)');
        $this->addSql('CREATE INDEX IDX_A66E586CA4494109 ON sht_task (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_A66E586C26DB17FB ON sht_task (CUSTOMER_ID)');
        $this->addSql('CREATE INDEX IDX_A66E586C1D8D7B81 ON sht_task (PERSONEL_ID)');
        $this->addSql('CREATE INDEX IDX_A66E586C1FFD8595 ON sht_task (C_BY)');
        $this->addSql('CREATE INDEX IDX_A66E586C6A8F8DD6 ON sht_task (U_BY)');
        $this->addSql('CREATE INDEX IDX_A66E586C822ABD2C ON sht_task (D_BY)');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT [DF_sht_task_STATUS] DEFAULT \'NEW\' FOR STATUS');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT [DF_sht_task_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT [DF_sht_task_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT [DF_sht_task_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE sht_customer ADD CONSTRAINT FK_74441833A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE sht_customer ADD CONSTRAINT FK_744418331FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_customer ADD CONSTRAINT FK_744418336A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_customer ADD CONSTRAINT FK_74441833822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT FK_A66E586CA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT FK_A66E586C26DB17FB FOREIGN KEY (CUSTOMER_ID) REFERENCES sht_customer (CUSTOMER_ID)');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT FK_A66E586C1D8D7B81 FOREIGN KEY (PERSONEL_ID) REFERENCES sht_personel (PERSONEL_ID)');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT FK_A66E586C1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT FK_A66E586C6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_task ADD CONSTRAINT FK_A66E586C822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_customer DROP CONSTRAINT FK_74441833A4494109');
        $this->addSql('ALTER TABLE sht_customer DROP CONSTRAINT FK_744418331FFD8595');
        $this->addSql('ALTER TABLE sht_customer DROP CONSTRAINT FK_744418336A8F8DD6');
        $this->addSql('ALTER TABLE sht_customer DROP CONSTRAINT FK_74441833822ABD2C');
        $this->addSql('ALTER TABLE sht_task DROP CONSTRAINT FK_A66E586CA4494109');
        $this->addSql('ALTER TABLE sht_task DROP CONSTRAINT FK_A66E586C26DB17FB');
        $this->addSql('ALTER TABLE sht_task DROP CONSTRAINT FK_A66E586C1D8D7B81');
        $this->addSql('ALTER TABLE sht_task DROP CONSTRAINT FK_A66E586C1FFD8595');
        $this->addSql('ALTER TABLE sht_task DROP CONSTRAINT FK_A66E586C6A8F8DD6');
        $this->addSql('ALTER TABLE sht_task DROP CONSTRAINT FK_A66E586C822ABD2C');
        $this->addSql('DROP TABLE sht_customer');
        $this->addSql('DROP TABLE sht_task');
    }
}
