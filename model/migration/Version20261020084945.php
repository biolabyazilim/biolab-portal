<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020084945 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'uzman onayı: hb_comment_block, sample_report (revizyonlu), sample_acceptance RERUN_*';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hb_comment_block (HB_COMMENT_BLOCK_ID INT IDENTITY NOT NULL, CODE NVARCHAR(10) NOT NULL, GROUP_CODE NCHAR(1) NOT NULL, BLOCK_TYPE NVARCHAR(10) NOT NULL, TITLE NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, CRITERIA NVARCHAR(300) COLLATE Turkish_CI_AS, BODY VARCHAR(MAX) COLLATE Turkish_100_CI_AS_SC_UTF8 NOT NULL, SORT_NO SMALLINT NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (HB_COMMENT_BLOCK_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_183B9BD54180DD2C ON hb_comment_block (CODE) WHERE CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_183B9BD51FFD8595 ON hb_comment_block (C_BY)');
        $this->addSql('CREATE INDEX IDX_183B9BD56A8F8DD6 ON hb_comment_block (U_BY)');
        $this->addSql('CREATE INDEX IDX_183B9BD5822ABD2C ON hb_comment_block (D_BY)');
        $this->addSql('ALTER TABLE hb_comment_block ADD CONSTRAINT [DF_hb_comment_block_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE hb_comment_block ADD CONSTRAINT [DF_hb_comment_block_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE hb_comment_block ADD CONSTRAINT [DF_hb_comment_block_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE sample_report (SAMPLE_REPORT_ID BIGINT IDENTITY NOT NULL, REVISION_NO SMALLINT NOT NULL, REVISION_REASON NVARCHAR(300) COLLATE Turkish_CI_AS, AGE_GROUP NCHAR(1) NOT NULL, AGE_MONTHS SMALLINT NOT NULL, HBA2_VALUE NUMERIC(5, 1), HBF_VALUE NUMERIC(5, 1), HBF_DEVICE_VALUE NVARCHAR(20), HBA_VALUE NUMERIC(5, 1), HBA_CALCULATED NUMERIC(5, 1), HBA_MANUAL_FL NCHAR(1) NOT NULL, A0_VALUE NVARCHAR(20), HBS_HBD_TEXT NVARCHAR(100) COLLATE Turkish_CI_AS NOT NULL, SUGGESTED_BLOCKS NVARCHAR(50), SELECTED_BLOCKS NVARCHAR(50), COMMENT_TEXT VARCHAR(MAX) COLLATE Turkish_100_CI_AS_SC_UTF8 NOT NULL, WARNINGS_JSON VARCHAR(MAX), APPROVED_AT DATETIME2(6) NOT NULL, SUPERSEDED_AT DATETIME2(6), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, SAMPLE_ACCEPTANCE_ID BIGINT NOT NULL, DEVICE_RESULT_ID BIGINT NOT NULL, APPROVED_BY BIGINT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (SAMPLE_REPORT_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_sample_report_REVISION ON sample_report (SAMPLE_ACCEPTANCE_ID, REVISION_NO) WHERE SAMPLE_ACCEPTANCE_ID IS NOT NULL AND REVISION_NO IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_5498BB1EA4494109 ON sample_report (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_5498BB1ED9AC8C8 ON sample_report (SAMPLE_ACCEPTANCE_ID)');
        $this->addSql('CREATE INDEX IDX_5498BB1E3F85D83C ON sample_report (DEVICE_RESULT_ID)');
        $this->addSql('CREATE INDEX IDX_5498BB1EFBED1F35 ON sample_report (APPROVED_BY)');
        $this->addSql('CREATE INDEX IDX_5498BB1E1FFD8595 ON sample_report (C_BY)');
        $this->addSql('CREATE INDEX IDX_5498BB1E6A8F8DD6 ON sample_report (U_BY)');
        $this->addSql('CREATE INDEX IDX_5498BB1E822ABD2C ON sample_report (D_BY)');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT [DF_sample_report_HBA_MANUAL_FL] DEFAULT \'N\' FOR HBA_MANUAL_FL');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT [DF_sample_report_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT [DF_sample_report_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT [DF_sample_report_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE hb_comment_block ADD CONSTRAINT FK_183B9BD51FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE hb_comment_block ADD CONSTRAINT FK_183B9BD56A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE hb_comment_block ADD CONSTRAINT FK_183B9BD5822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT FK_5498BB1EA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT FK_5498BB1ED9AC8C8 FOREIGN KEY (SAMPLE_ACCEPTANCE_ID) REFERENCES sample_acceptance (SAMPLE_ACCEPTANCE_ID)');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT FK_5498BB1E3F85D83C FOREIGN KEY (DEVICE_RESULT_ID) REFERENCES device_result (DEVICE_RESULT_ID)');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT FK_5498BB1EFBED1F35 FOREIGN KEY (APPROVED_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT FK_5498BB1E1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT FK_5498BB1E6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sample_report ADD CONSTRAINT FK_5498BB1E822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sample_acceptance ADD RERUN_REASON NVARCHAR(200) COLLATE Turkish_CI_AS');
        $this->addSql('ALTER TABLE sample_acceptance ADD RERUN_AT DATETIME2(6)');
        $this->addSql('ALTER TABLE sample_acceptance ADD RERUN_BY BIGINT');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT FK_9BC32BAD19741A1F FOREIGN KEY (RERUN_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('CREATE INDEX IDX_9BC32BAD19741A1F ON sample_acceptance (RERUN_BY)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hb_comment_block DROP CONSTRAINT FK_183B9BD51FFD8595');
        $this->addSql('ALTER TABLE hb_comment_block DROP CONSTRAINT FK_183B9BD56A8F8DD6');
        $this->addSql('ALTER TABLE hb_comment_block DROP CONSTRAINT FK_183B9BD5822ABD2C');
        $this->addSql('ALTER TABLE sample_report DROP CONSTRAINT FK_5498BB1EA4494109');
        $this->addSql('ALTER TABLE sample_report DROP CONSTRAINT FK_5498BB1ED9AC8C8');
        $this->addSql('ALTER TABLE sample_report DROP CONSTRAINT FK_5498BB1E3F85D83C');
        $this->addSql('ALTER TABLE sample_report DROP CONSTRAINT FK_5498BB1EFBED1F35');
        $this->addSql('ALTER TABLE sample_report DROP CONSTRAINT FK_5498BB1E1FFD8595');
        $this->addSql('ALTER TABLE sample_report DROP CONSTRAINT FK_5498BB1E6A8F8DD6');
        $this->addSql('ALTER TABLE sample_report DROP CONSTRAINT FK_5498BB1E822ABD2C');
        $this->addSql('DROP TABLE hb_comment_block');
        $this->addSql('DROP TABLE sample_report');
        $this->addSql('ALTER TABLE sample_acceptance DROP CONSTRAINT FK_9BC32BAD19741A1F');
        $this->addSql('DROP INDEX IDX_9BC32BAD19741A1F ON sample_acceptance');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN RERUN_REASON');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN RERUN_AT');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN RERUN_BY');
    }
}
