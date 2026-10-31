<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261024071422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MNG: mng_form, mng_menu, mng_role, mng_role_form, mng_user_role (rol → ekran yetkisi ve menü)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mng_form (FORM_ID INT IDENTITY NOT NULL, FORM_CODE NVARCHAR(50) NOT NULL, FORM_NAME NVARCHAR(150) COLLATE Turkish_CI_AS NOT NULL, FORM_NAME_EN NVARCHAR(150), FORM_PATH NVARCHAR(300) NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (FORM_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_293B181066370564 ON mng_form (FORM_CODE) WHERE FORM_CODE IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_293B18101A76BEF3 ON mng_form (FORM_PATH) WHERE FORM_PATH IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_293B18101FFD8595 ON mng_form (C_BY)');
        $this->addSql('CREATE INDEX IDX_293B18106A8F8DD6 ON mng_form (U_BY)');
        $this->addSql('CREATE INDEX IDX_293B1810822ABD2C ON mng_form (D_BY)');
        $this->addSql('ALTER TABLE mng_form ADD CONSTRAINT [DF_mng_form_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_form ADD CONSTRAINT [DF_mng_form_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_form ADD CONSTRAINT [DF_mng_form_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mng_menu (MENU_ID INT IDENTITY NOT NULL, MENU_CODE NVARCHAR(50), MENU_NAME NVARCHAR(100) COLLATE Turkish_CI_AS NOT NULL, MENU_NAME_EN NVARCHAR(100), MENU_ICON NVARCHAR(50), MENU_ORDER SMALLINT NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, PARENT_MENU_ID INT, FORM_ID INT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (MENU_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B6DFCC3A59F13D ON mng_menu (MENU_CODE) WHERE MENU_CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IX_mng_menu_PARENT ON mng_menu (PARENT_MENU_ID, MENU_ORDER)');
        $this->addSql('CREATE INDEX IDX_6B6DFCC948CC3DF ON mng_menu (PARENT_MENU_ID)');
        $this->addSql('CREATE INDEX IDX_6B6DFCC58FE2387 ON mng_menu (FORM_ID)');
        $this->addSql('CREATE INDEX IDX_6B6DFCC1FFD8595 ON mng_menu (C_BY)');
        $this->addSql('CREATE INDEX IDX_6B6DFCC6A8F8DD6 ON mng_menu (U_BY)');
        $this->addSql('CREATE INDEX IDX_6B6DFCC822ABD2C ON mng_menu (D_BY)');
        $this->addSql('ALTER TABLE mng_menu ADD CONSTRAINT [DF_mng_menu_MENU_ORDER] DEFAULT 0 FOR MENU_ORDER');
        $this->addSql('ALTER TABLE mng_menu ADD CONSTRAINT [DF_mng_menu_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_menu ADD CONSTRAINT [DF_mng_menu_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_menu ADD CONSTRAINT [DF_mng_menu_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mng_role (ROLE_ID INT IDENTITY NOT NULL, ROLE_CODE NVARCHAR(50) NOT NULL, ROLE_NAME NVARCHAR(150) COLLATE Turkish_CI_AS NOT NULL, ROLE_NAME_EN NVARCHAR(150), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (ROLE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2CDA6F354CA973E7 ON mng_role (ROLE_CODE) WHERE ROLE_CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_2CDA6F351FFD8595 ON mng_role (C_BY)');
        $this->addSql('CREATE INDEX IDX_2CDA6F356A8F8DD6 ON mng_role (U_BY)');
        $this->addSql('CREATE INDEX IDX_2CDA6F35822ABD2C ON mng_role (D_BY)');
        $this->addSql('ALTER TABLE mng_role ADD CONSTRAINT [DF_mng_role_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_role ADD CONSTRAINT [DF_mng_role_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_role ADD CONSTRAINT [DF_mng_role_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mng_role_form (ROLE_FORM_ID INT IDENTITY NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, ROLE_ID INT NOT NULL, FORM_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (ROLE_FORM_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mng_role_form_ROLE_FORM ON mng_role_form (ROLE_ID, FORM_ID) WHERE ROLE_ID IS NOT NULL AND FORM_ID IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_BC0686BFD10B9A56 ON mng_role_form (ROLE_ID)');
        $this->addSql('CREATE INDEX IDX_BC0686BF58FE2387 ON mng_role_form (FORM_ID)');
        $this->addSql('CREATE INDEX IDX_BC0686BF1FFD8595 ON mng_role_form (C_BY)');
        $this->addSql('CREATE INDEX IDX_BC0686BF6A8F8DD6 ON mng_role_form (U_BY)');
        $this->addSql('CREATE INDEX IDX_BC0686BF822ABD2C ON mng_role_form (D_BY)');
        $this->addSql('ALTER TABLE mng_role_form ADD CONSTRAINT [DF_mng_role_form_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_role_form ADD CONSTRAINT [DF_mng_role_form_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_role_form ADD CONSTRAINT [DF_mng_role_form_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mng_user_role (USER_ROLE_ID BIGINT IDENTITY NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, USER_ID BIGINT NOT NULL, ROLE_ID INT NOT NULL, COMPANY_ID BIGINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (USER_ROLE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mng_user_role_USER_ROLE ON mng_user_role (USER_ID, ROLE_ID, COMPANY_ID) WHERE USER_ID IS NOT NULL AND ROLE_ID IS NOT NULL AND COMPANY_ID IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_7DD9CFC7A0666B6F ON mng_user_role (USER_ID)');
        $this->addSql('CREATE INDEX IDX_7DD9CFC7D10B9A56 ON mng_user_role (ROLE_ID)');
        $this->addSql('CREATE INDEX IDX_7DD9CFC7A4494109 ON mng_user_role (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_7DD9CFC71FFD8595 ON mng_user_role (C_BY)');
        $this->addSql('CREATE INDEX IDX_7DD9CFC76A8F8DD6 ON mng_user_role (U_BY)');
        $this->addSql('CREATE INDEX IDX_7DD9CFC7822ABD2C ON mng_user_role (D_BY)');
        $this->addSql('ALTER TABLE mng_user_role ADD CONSTRAINT [DF_mng_user_role_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_user_role ADD CONSTRAINT [DF_mng_user_role_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_user_role ADD CONSTRAINT [DF_mng_user_role_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mng_form ADD CONSTRAINT FK_293B18101FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_form ADD CONSTRAINT FK_293B18106A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_form ADD CONSTRAINT FK_293B1810822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_menu ADD CONSTRAINT FK_6B6DFCC948CC3DF FOREIGN KEY (PARENT_MENU_ID) REFERENCES mng_menu (MENU_ID)');
        $this->addSql('ALTER TABLE mng_menu ADD CONSTRAINT FK_6B6DFCC58FE2387 FOREIGN KEY (FORM_ID) REFERENCES mng_form (FORM_ID)');
        $this->addSql('ALTER TABLE mng_menu ADD CONSTRAINT FK_6B6DFCC1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_menu ADD CONSTRAINT FK_6B6DFCC6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_menu ADD CONSTRAINT FK_6B6DFCC822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_role ADD CONSTRAINT FK_2CDA6F351FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_role ADD CONSTRAINT FK_2CDA6F356A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_role ADD CONSTRAINT FK_2CDA6F35822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_role_form ADD CONSTRAINT FK_BC0686BFD10B9A56 FOREIGN KEY (ROLE_ID) REFERENCES mng_role (ROLE_ID)');
        $this->addSql('ALTER TABLE mng_role_form ADD CONSTRAINT FK_BC0686BF58FE2387 FOREIGN KEY (FORM_ID) REFERENCES mng_form (FORM_ID)');
        $this->addSql('ALTER TABLE mng_role_form ADD CONSTRAINT FK_BC0686BF1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_role_form ADD CONSTRAINT FK_BC0686BF6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_role_form ADD CONSTRAINT FK_BC0686BF822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user_role ADD CONSTRAINT FK_7DD9CFC7A0666B6F FOREIGN KEY (USER_ID) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user_role ADD CONSTRAINT FK_7DD9CFC7D10B9A56 FOREIGN KEY (ROLE_ID) REFERENCES mng_role (ROLE_ID)');
        $this->addSql('ALTER TABLE mng_user_role ADD CONSTRAINT FK_7DD9CFC7A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE mng_user_role ADD CONSTRAINT FK_7DD9CFC71FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user_role ADD CONSTRAINT FK_7DD9CFC76A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user_role ADD CONSTRAINT FK_7DD9CFC7822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
    }

    public function down(Schema $schema): void
    {
        // DBAL'in SQL Server'da ürettiği "CREATE SCHEMA db_owner..." satırları çıkarıldı (geri almada hata veriyordu)
        $this->addSql('ALTER TABLE mng_form DROP CONSTRAINT FK_293B18101FFD8595');
        $this->addSql('ALTER TABLE mng_form DROP CONSTRAINT FK_293B18106A8F8DD6');
        $this->addSql('ALTER TABLE mng_form DROP CONSTRAINT FK_293B1810822ABD2C');
        $this->addSql('ALTER TABLE mng_menu DROP CONSTRAINT FK_6B6DFCC948CC3DF');
        $this->addSql('ALTER TABLE mng_menu DROP CONSTRAINT FK_6B6DFCC58FE2387');
        $this->addSql('ALTER TABLE mng_menu DROP CONSTRAINT FK_6B6DFCC1FFD8595');
        $this->addSql('ALTER TABLE mng_menu DROP CONSTRAINT FK_6B6DFCC6A8F8DD6');
        $this->addSql('ALTER TABLE mng_menu DROP CONSTRAINT FK_6B6DFCC822ABD2C');
        $this->addSql('ALTER TABLE mng_role DROP CONSTRAINT FK_2CDA6F351FFD8595');
        $this->addSql('ALTER TABLE mng_role DROP CONSTRAINT FK_2CDA6F356A8F8DD6');
        $this->addSql('ALTER TABLE mng_role DROP CONSTRAINT FK_2CDA6F35822ABD2C');
        $this->addSql('ALTER TABLE mng_role_form DROP CONSTRAINT FK_BC0686BFD10B9A56');
        $this->addSql('ALTER TABLE mng_role_form DROP CONSTRAINT FK_BC0686BF58FE2387');
        $this->addSql('ALTER TABLE mng_role_form DROP CONSTRAINT FK_BC0686BF1FFD8595');
        $this->addSql('ALTER TABLE mng_role_form DROP CONSTRAINT FK_BC0686BF6A8F8DD6');
        $this->addSql('ALTER TABLE mng_role_form DROP CONSTRAINT FK_BC0686BF822ABD2C');
        $this->addSql('ALTER TABLE mng_user_role DROP CONSTRAINT FK_7DD9CFC7A0666B6F');
        $this->addSql('ALTER TABLE mng_user_role DROP CONSTRAINT FK_7DD9CFC7D10B9A56');
        $this->addSql('ALTER TABLE mng_user_role DROP CONSTRAINT FK_7DD9CFC7A4494109');
        $this->addSql('ALTER TABLE mng_user_role DROP CONSTRAINT FK_7DD9CFC71FFD8595');
        $this->addSql('ALTER TABLE mng_user_role DROP CONSTRAINT FK_7DD9CFC76A8F8DD6');
        $this->addSql('ALTER TABLE mng_user_role DROP CONSTRAINT FK_7DD9CFC7822ABD2C');
        $this->addSql('DROP TABLE mng_form');
        $this->addSql('DROP TABLE mng_menu');
        $this->addSql('DROP TABLE mng_role');
        $this->addSql('DROP TABLE mng_role_form');
        $this->addSql('DROP TABLE mng_user_role');
    }
}
