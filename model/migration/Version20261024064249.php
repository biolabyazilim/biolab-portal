<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261024064249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mlyt_material (MATERIAL_ID INT IDENTITY NOT NULL, MATERIAL_NAME NVARCHAR(250) COLLATE Turkish_CI_AS NOT NULL, CATEGORY NVARCHAR(20) NOT NULL, USAGE_UNIT NVARCHAR(10) NOT NULL, SOURCE_ROW_NO SMALLINT, NOTE NVARCHAR(500) COLLATE Turkish_CI_AS, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, SUPPLIER_ID INT, MERGED_INTO_MATERIAL_ID INT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (MATERIAL_ID))');
        $this->addSql('CREATE INDEX IX_mlyt_material_NAME ON mlyt_material (COMPANY_ID, MATERIAL_NAME)');
        $this->addSql('CREATE INDEX IDX_A549B0E4A4494109 ON mlyt_material (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_A549B0E49F93B984 ON mlyt_material (SUPPLIER_ID)');
        $this->addSql('CREATE INDEX IDX_A549B0E4AF8D279D ON mlyt_material (MERGED_INTO_MATERIAL_ID)');
        $this->addSql('CREATE INDEX IDX_A549B0E41FFD8595 ON mlyt_material (C_BY)');
        $this->addSql('CREATE INDEX IDX_A549B0E46A8F8DD6 ON mlyt_material (U_BY)');
        $this->addSql('CREATE INDEX IDX_A549B0E4822ABD2C ON mlyt_material (D_BY)');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT [DF_mlyt_material_CATEGORY] DEFAULT \'OTHER\' FOR CATEGORY');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT [DF_mlyt_material_USAGE_UNIT] DEFAULT \'PIECE\' FOR USAGE_UNIT');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT [DF_mlyt_material_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT [DF_mlyt_material_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT [DF_mlyt_material_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mlyt_material_price (MATERIAL_PRICE_ID INT IDENTITY NOT NULL, PACKAGE_DESC NVARCHAR(100) COLLATE Turkish_CI_AS, PACKAGE_QTY NUMERIC(12, 3) NOT NULL, CURRENCY NCHAR(3) NOT NULL, PACKAGE_PRICE NUMERIC(14, 4) NOT NULL, VAT_INCLUDED_FL NCHAR(1) NOT NULL, NOTE NVARCHAR(500) COLLATE Turkish_CI_AS, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, STUDY_ID INT NOT NULL, MATERIAL_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (MATERIAL_PRICE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_material_price_MATERIAL ON mlyt_material_price (STUDY_ID, MATERIAL_ID) WHERE STUDY_ID IS NOT NULL AND MATERIAL_ID IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_E44D17F919CED945 ON mlyt_material_price (STUDY_ID)');
        $this->addSql('CREATE INDEX IDX_E44D17F956467867 ON mlyt_material_price (MATERIAL_ID)');
        $this->addSql('CREATE INDEX IDX_E44D17F91FFD8595 ON mlyt_material_price (C_BY)');
        $this->addSql('CREATE INDEX IDX_E44D17F96A8F8DD6 ON mlyt_material_price (U_BY)');
        $this->addSql('CREATE INDEX IDX_E44D17F9822ABD2C ON mlyt_material_price (D_BY)');
        $this->addSql('ALTER TABLE mlyt_material_price ADD CONSTRAINT [DF_mlyt_material_price_VAT_INCLUDED_FL] DEFAULT \'N\' FOR VAT_INCLUDED_FL');
        $this->addSql('ALTER TABLE mlyt_material_price ADD CONSTRAINT [DF_mlyt_material_price_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_material_price ADD CONSTRAINT [DF_mlyt_material_price_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_material_price ADD CONSTRAINT [DF_mlyt_material_price_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mlyt_study (STUDY_ID INT IDENTITY NOT NULL, STUDY_NAME NVARCHAR(150) COLLATE Turkish_CI_AS NOT NULL, RATE_DATE DATE NOT NULL, STATUS NVARCHAR(10) NOT NULL, WORK_DAYS_PER_MONTH SMALLINT NOT NULL, NOTE NVARCHAR(1000) COLLATE Turkish_CI_AS, APPROVED_AT DATETIME2(6), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, SOURCE_STUDY_ID INT, APPROVED_BY BIGINT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (STUDY_ID))');
        $this->addSql('CREATE INDEX IDX_B7931F3A4494109 ON mlyt_study (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_B7931F37DC1B5DB ON mlyt_study (SOURCE_STUDY_ID)');
        $this->addSql('CREATE INDEX IDX_B7931F3FBED1F35 ON mlyt_study (APPROVED_BY)');
        $this->addSql('CREATE INDEX IDX_B7931F31FFD8595 ON mlyt_study (C_BY)');
        $this->addSql('CREATE INDEX IDX_B7931F36A8F8DD6 ON mlyt_study (U_BY)');
        $this->addSql('CREATE INDEX IDX_B7931F3822ABD2C ON mlyt_study (D_BY)');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT [DF_mlyt_study_STATUS] DEFAULT \'DRAFT\' FOR STATUS');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT [DF_mlyt_study_WORK_DAYS_PER_MONTH] DEFAULT 26 FOR WORK_DAYS_PER_MONTH');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT [DF_mlyt_study_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT [DF_mlyt_study_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT [DF_mlyt_study_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mlyt_study_rate (STUDY_RATE_ID INT IDENTITY NOT NULL, CURRENCY NCHAR(3) NOT NULL, RATE NUMERIC(12, 4) NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, STUDY_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (STUDY_RATE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_study_rate_CURRENCY ON mlyt_study_rate (STUDY_ID, CURRENCY) WHERE STUDY_ID IS NOT NULL AND CURRENCY IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_7D1E707F19CED945 ON mlyt_study_rate (STUDY_ID)');
        $this->addSql('CREATE INDEX IDX_7D1E707F1FFD8595 ON mlyt_study_rate (C_BY)');
        $this->addSql('CREATE INDEX IDX_7D1E707F6A8F8DD6 ON mlyt_study_rate (U_BY)');
        $this->addSql('CREATE INDEX IDX_7D1E707F822ABD2C ON mlyt_study_rate (D_BY)');
        $this->addSql('ALTER TABLE mlyt_study_rate ADD CONSTRAINT [DF_mlyt_study_rate_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_study_rate ADD CONSTRAINT [DF_mlyt_study_rate_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_study_rate ADD CONSTRAINT [DF_mlyt_study_rate_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mlyt_supplier (SUPPLIER_ID INT IDENTITY NOT NULL, SUPPLIER_NAME NVARCHAR(150) COLLATE Turkish_CI_AS NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (SUPPLIER_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_supplier_NAME ON mlyt_supplier (COMPANY_ID, SUPPLIER_NAME) WHERE COMPANY_ID IS NOT NULL AND SUPPLIER_NAME IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_42DDA90FA4494109 ON mlyt_supplier (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_42DDA90F1FFD8595 ON mlyt_supplier (C_BY)');
        $this->addSql('CREATE INDEX IDX_42DDA90F6A8F8DD6 ON mlyt_supplier (U_BY)');
        $this->addSql('CREATE INDEX IDX_42DDA90F822ABD2C ON mlyt_supplier (D_BY)');
        $this->addSql('ALTER TABLE mlyt_supplier ADD CONSTRAINT [DF_mlyt_supplier_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_supplier ADD CONSTRAINT [DF_mlyt_supplier_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_supplier ADD CONSTRAINT [DF_mlyt_supplier_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT FK_A549B0E4A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT FK_A549B0E49F93B984 FOREIGN KEY (SUPPLIER_ID) REFERENCES mlyt_supplier (SUPPLIER_ID)');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT FK_A549B0E4AF8D279D FOREIGN KEY (MERGED_INTO_MATERIAL_ID) REFERENCES mlyt_material (MATERIAL_ID)');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT FK_A549B0E41FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT FK_A549B0E46A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT FK_A549B0E4822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material_price ADD CONSTRAINT FK_E44D17F919CED945 FOREIGN KEY (STUDY_ID) REFERENCES mlyt_study (STUDY_ID)');
        $this->addSql('ALTER TABLE mlyt_material_price ADD CONSTRAINT FK_E44D17F956467867 FOREIGN KEY (MATERIAL_ID) REFERENCES mlyt_material (MATERIAL_ID)');
        $this->addSql('ALTER TABLE mlyt_material_price ADD CONSTRAINT FK_E44D17F91FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material_price ADD CONSTRAINT FK_E44D17F96A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material_price ADD CONSTRAINT FK_E44D17F9822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT FK_B7931F3A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT FK_B7931F37DC1B5DB FOREIGN KEY (SOURCE_STUDY_ID) REFERENCES mlyt_study (STUDY_ID)');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT FK_B7931F3FBED1F35 FOREIGN KEY (APPROVED_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT FK_B7931F31FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT FK_B7931F36A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_study ADD CONSTRAINT FK_B7931F3822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_study_rate ADD CONSTRAINT FK_7D1E707F19CED945 FOREIGN KEY (STUDY_ID) REFERENCES mlyt_study (STUDY_ID)');
        $this->addSql('ALTER TABLE mlyt_study_rate ADD CONSTRAINT FK_7D1E707F1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_study_rate ADD CONSTRAINT FK_7D1E707F6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_study_rate ADD CONSTRAINT FK_7D1E707F822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_supplier ADD CONSTRAINT FK_42DDA90FA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE mlyt_supplier ADD CONSTRAINT FK_42DDA90F1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_supplier ADD CONSTRAINT FK_42DDA90F6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_supplier ADD CONSTRAINT FK_42DDA90F822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA db_accessadmin');
        $this->addSql('CREATE SCHEMA db_backupoperator');
        $this->addSql('CREATE SCHEMA db_datareader');
        $this->addSql('CREATE SCHEMA db_datawriter');
        $this->addSql('CREATE SCHEMA db_ddladmin');
        $this->addSql('CREATE SCHEMA db_denydatareader');
        $this->addSql('CREATE SCHEMA db_denydatawriter');
        $this->addSql('CREATE SCHEMA db_owner');
        $this->addSql('CREATE SCHEMA db_securityadmin');
        $this->addSql('ALTER TABLE mlyt_material DROP CONSTRAINT FK_A549B0E4A4494109');
        $this->addSql('ALTER TABLE mlyt_material DROP CONSTRAINT FK_A549B0E49F93B984');
        $this->addSql('ALTER TABLE mlyt_material DROP CONSTRAINT FK_A549B0E4AF8D279D');
        $this->addSql('ALTER TABLE mlyt_material DROP CONSTRAINT FK_A549B0E41FFD8595');
        $this->addSql('ALTER TABLE mlyt_material DROP CONSTRAINT FK_A549B0E46A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_material DROP CONSTRAINT FK_A549B0E4822ABD2C');
        $this->addSql('ALTER TABLE mlyt_material_price DROP CONSTRAINT FK_E44D17F919CED945');
        $this->addSql('ALTER TABLE mlyt_material_price DROP CONSTRAINT FK_E44D17F956467867');
        $this->addSql('ALTER TABLE mlyt_material_price DROP CONSTRAINT FK_E44D17F91FFD8595');
        $this->addSql('ALTER TABLE mlyt_material_price DROP CONSTRAINT FK_E44D17F96A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_material_price DROP CONSTRAINT FK_E44D17F9822ABD2C');
        $this->addSql('ALTER TABLE mlyt_study DROP CONSTRAINT FK_B7931F3A4494109');
        $this->addSql('ALTER TABLE mlyt_study DROP CONSTRAINT FK_B7931F37DC1B5DB');
        $this->addSql('ALTER TABLE mlyt_study DROP CONSTRAINT FK_B7931F3FBED1F35');
        $this->addSql('ALTER TABLE mlyt_study DROP CONSTRAINT FK_B7931F31FFD8595');
        $this->addSql('ALTER TABLE mlyt_study DROP CONSTRAINT FK_B7931F36A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_study DROP CONSTRAINT FK_B7931F3822ABD2C');
        $this->addSql('ALTER TABLE mlyt_study_rate DROP CONSTRAINT FK_7D1E707F19CED945');
        $this->addSql('ALTER TABLE mlyt_study_rate DROP CONSTRAINT FK_7D1E707F1FFD8595');
        $this->addSql('ALTER TABLE mlyt_study_rate DROP CONSTRAINT FK_7D1E707F6A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_study_rate DROP CONSTRAINT FK_7D1E707F822ABD2C');
        $this->addSql('ALTER TABLE mlyt_supplier DROP CONSTRAINT FK_42DDA90FA4494109');
        $this->addSql('ALTER TABLE mlyt_supplier DROP CONSTRAINT FK_42DDA90F1FFD8595');
        $this->addSql('ALTER TABLE mlyt_supplier DROP CONSTRAINT FK_42DDA90F6A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_supplier DROP CONSTRAINT FK_42DDA90F822ABD2C');
        $this->addSql('DROP TABLE mlyt_material');
        $this->addSql('DROP TABLE mlyt_material_price');
        $this->addSql('DROP TABLE mlyt_study');
        $this->addSql('DROP TABLE mlyt_study_rate');
        $this->addSql('DROP TABLE mlyt_supplier');
    }
}
