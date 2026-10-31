<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019144821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'device_result (D-10 calismalari) ve device_fetch_log (cihazdan cekme kayitlari) tablolari';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE device_fetch_log (DEVICE_FETCH_LOG_ID BIGINT IDENTITY NOT NULL, TEST_CODE NVARCHAR(20) NOT NULL, DATE_FROM DATE NOT NULL, DATE_TO DATE NOT NULL, STARTED_AT DATETIME2(6) NOT NULL, FINISHED_AT DATETIME2(6), SUCCESS_FL NCHAR(1) NOT NULL, LIST_COUNT INT NOT NULL, NEW_COUNT INT NOT NULL, MESSAGE NVARCHAR(500), USER_ID BIGINT, PRIMARY KEY (DEVICE_FETCH_LOG_ID))');
        $this->addSql('ALTER TABLE device_fetch_log ADD CONSTRAINT [DF_device_fetch_log_SUCCESS_FL] DEFAULT \'N\' FOR SUCCESS_FL');
        $this->addSql('ALTER TABLE device_fetch_log ADD CONSTRAINT [DF_device_fetch_log_LIST_COUNT] DEFAULT 0 FOR LIST_COUNT');
        $this->addSql('ALTER TABLE device_fetch_log ADD CONSTRAINT [DF_device_fetch_log_NEW_COUNT] DEFAULT 0 FOR NEW_COUNT');
        $this->addSql('CREATE TABLE device_result (DEVICE_RESULT_ID BIGINT IDENTITY NOT NULL, SAMPLE_ID NVARCHAR(50) NOT NULL, TEST_CODE NVARCHAR(20) NOT NULL, INJECTION_DATE DATE NOT NULL, INJECTION_NO SMALLINT NOT NULL, RACK_POSITION SMALLINT, DEVICE_KEY NVARCHAR(100) NOT NULL, F_VALUE NVARCHAR(20), A1C_VALUE NVARCHAR(20), A2_VALUE NVARCHAR(20), DEVICE_COMMENT NVARCHAR(200), INJECTED_AT DATETIME2(6), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, SAMPLE_ACCEPTANCE_ID BIGINT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (DEVICE_RESULT_ID))');
        $this->addSql('CREATE INDEX IX_device_result_SAMPLE_ID ON device_result (SAMPLE_ID)');
        $this->addSql('CREATE UNIQUE INDEX UQ_device_result_INJECTION ON device_result (INJECTION_DATE, INJECTION_NO) WHERE INJECTION_DATE IS NOT NULL AND INJECTION_NO IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_C07AB008A4494109 ON device_result (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_C07AB008D9AC8C8 ON device_result (SAMPLE_ACCEPTANCE_ID)');
        $this->addSql('CREATE INDEX IDX_C07AB0081FFD8595 ON device_result (C_BY)');
        $this->addSql('CREATE INDEX IDX_C07AB0086A8F8DD6 ON device_result (U_BY)');
        $this->addSql('CREATE INDEX IDX_C07AB008822ABD2C ON device_result (D_BY)');
        $this->addSql('ALTER TABLE device_result ADD CONSTRAINT [DF_device_result_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE device_result ADD CONSTRAINT [DF_device_result_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE device_result ADD CONSTRAINT [DF_device_result_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE device_result ADD CONSTRAINT FK_C07AB008A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE device_result ADD CONSTRAINT FK_C07AB008D9AC8C8 FOREIGN KEY (SAMPLE_ACCEPTANCE_ID) REFERENCES sample_acceptance (SAMPLE_ACCEPTANCE_ID)');
        $this->addSql('ALTER TABLE device_result ADD CONSTRAINT FK_C07AB0081FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE device_result ADD CONSTRAINT FK_C07AB0086A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE device_result ADD CONSTRAINT FK_C07AB008822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE device_result DROP CONSTRAINT FK_C07AB008A4494109');
        $this->addSql('ALTER TABLE device_result DROP CONSTRAINT FK_C07AB008D9AC8C8');
        $this->addSql('ALTER TABLE device_result DROP CONSTRAINT FK_C07AB0081FFD8595');
        $this->addSql('ALTER TABLE device_result DROP CONSTRAINT FK_C07AB0086A8F8DD6');
        $this->addSql('ALTER TABLE device_result DROP CONSTRAINT FK_C07AB008822ABD2C');
        $this->addSql('DROP TABLE device_fetch_log');
        $this->addSql('DROP TABLE device_result');
    }
}
