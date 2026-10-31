<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Test kataloğu: mlyt_service_group, mlyt_test, mlyt_test_component + "Test tanımları" ekranının form / menü / yetki kaydı.
 * diff ile üretildi; elle eklenenler: ekran kaydı (up sonu) ve down() düzeni
 * (önce ekran kaydı, sonra tablolar; DBAL'in ürettiği "CREATE SCHEMA db_owner..." satırları çıkarıldı).
 */
final class Version20261026083935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: mlyt_service_group, mlyt_test, mlyt_test_component; Test tanımları ekranı (MLYT_TEST) menüsü ve yetkisi';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mlyt_service_group (SERVICE_GROUP_ID INT IDENTITY NOT NULL, SERVICE_GROUP_NAME NVARCHAR(100) COLLATE Turkish_CI_AS NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (SERVICE_GROUP_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_service_group_NAME ON mlyt_service_group (COMPANY_ID, SERVICE_GROUP_NAME) WHERE COMPANY_ID IS NOT NULL AND SERVICE_GROUP_NAME IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_A1E2656AA4494109 ON mlyt_service_group (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_A1E2656A1FFD8595 ON mlyt_service_group (C_BY)');
        $this->addSql('CREATE INDEX IDX_A1E2656A6A8F8DD6 ON mlyt_service_group (U_BY)');
        $this->addSql('CREATE INDEX IDX_A1E2656A822ABD2C ON mlyt_service_group (D_BY)');
        $this->addSql('ALTER TABLE mlyt_service_group ADD CONSTRAINT [DF_mlyt_service_group_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_service_group ADD CONSTRAINT [DF_mlyt_service_group_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_service_group ADD CONSTRAINT [DF_mlyt_service_group_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mlyt_test (TEST_ID INT IDENTITY NOT NULL, TEST_CODE NVARCHAR(20) NOT NULL, TEST_NAME NVARCHAR(500) COLLATE Turkish_CI_AS NOT NULL, LAB_UNIT NVARCHAR(20) NOT NULL, COST_METHOD NVARCHAR(20) NOT NULL, PATHOLOGY_LEVEL SMALLINT, SUT_CODE NVARCHAR(10), SAMPLE_TYPE NVARCHAR(50) COLLATE Turkish_CI_AS, RESPONSIBLE_NAME NVARCHAR(100) COLLATE Turkish_CI_AS, SOURCE_SUPPLIER_TEXT NVARCHAR(150) COLLATE Turkish_CI_AS, SOURCE_LEVEL_TEXT NVARCHAR(50) COLLATE Turkish_CI_AS, SOURCE_MATERIAL_TEXT NVARCHAR(300) COLLATE Turkish_CI_AS, NOTE NVARCHAR(500) COLLATE Turkish_CI_AS, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, SERVICE_GROUP_ID INT NOT NULL, SUPPLIER_ID INT, PARENT_TEST_ID INT, RESPONSIBLE_USER_ID BIGINT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (TEST_ID))');
        $this->addSql('CREATE INDEX IX_mlyt_test_SUT ON mlyt_test (SUT_CODE)');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_test_CODE ON mlyt_test (COMPANY_ID, TEST_CODE) WHERE COMPANY_ID IS NOT NULL AND TEST_CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_665AE48CA4494109 ON mlyt_test (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_665AE48CC94C79E7 ON mlyt_test (SERVICE_GROUP_ID)');
        $this->addSql('CREATE INDEX IDX_665AE48C9F93B984 ON mlyt_test (SUPPLIER_ID)');
        $this->addSql('CREATE INDEX IDX_665AE48C46062E94 ON mlyt_test (PARENT_TEST_ID)');
        $this->addSql('CREATE INDEX IDX_665AE48C5D25FE2E ON mlyt_test (RESPONSIBLE_USER_ID)');
        $this->addSql('CREATE INDEX IDX_665AE48C1FFD8595 ON mlyt_test (C_BY)');
        $this->addSql('CREATE INDEX IDX_665AE48C6A8F8DD6 ON mlyt_test (U_BY)');
        $this->addSql('CREATE INDEX IDX_665AE48C822ABD2C ON mlyt_test (D_BY)');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT [DF_mlyt_test_LAB_UNIT] DEFAULT \'BIOLAB\' FOR LAB_UNIT');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT [DF_mlyt_test_COST_METHOD] DEFAULT \'UNDEFINED\' FOR COST_METHOD');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT [DF_mlyt_test_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT [DF_mlyt_test_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT [DF_mlyt_test_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mlyt_test_component (TEST_COMPONENT_ID INT IDENTITY NOT NULL, QUANTITY NUMERIC(9, 3) NOT NULL, APPLY_RATE NUMERIC(5, 2) NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, TEST_ID INT NOT NULL, COMPONENT_TEST_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (TEST_COMPONENT_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_test_component ON mlyt_test_component (TEST_ID, COMPONENT_TEST_ID) WHERE TEST_ID IS NOT NULL AND COMPONENT_TEST_ID IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_B301AFF31955BCA3 ON mlyt_test_component (TEST_ID)');
        $this->addSql('CREATE INDEX IDX_B301AFF3351BACE2 ON mlyt_test_component (COMPONENT_TEST_ID)');
        $this->addSql('CREATE INDEX IDX_B301AFF31FFD8595 ON mlyt_test_component (C_BY)');
        $this->addSql('CREATE INDEX IDX_B301AFF36A8F8DD6 ON mlyt_test_component (U_BY)');
        $this->addSql('CREATE INDEX IDX_B301AFF3822ABD2C ON mlyt_test_component (D_BY)');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT [DF_mlyt_test_component_QUANTITY] DEFAULT 1 FOR QUANTITY');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT [DF_mlyt_test_component_APPLY_RATE] DEFAULT 100 FOR APPLY_RATE');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT [DF_mlyt_test_component_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT [DF_mlyt_test_component_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT [DF_mlyt_test_component_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mlyt_service_group ADD CONSTRAINT FK_A1E2656AA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE mlyt_service_group ADD CONSTRAINT FK_A1E2656A1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_service_group ADD CONSTRAINT FK_A1E2656A6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_service_group ADD CONSTRAINT FK_A1E2656A822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT FK_665AE48CA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT FK_665AE48CC94C79E7 FOREIGN KEY (SERVICE_GROUP_ID) REFERENCES mlyt_service_group (SERVICE_GROUP_ID)');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT FK_665AE48C9F93B984 FOREIGN KEY (SUPPLIER_ID) REFERENCES mlyt_supplier (SUPPLIER_ID)');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT FK_665AE48C46062E94 FOREIGN KEY (PARENT_TEST_ID) REFERENCES mlyt_test (TEST_ID)');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT FK_665AE48C5D25FE2E FOREIGN KEY (RESPONSIBLE_USER_ID) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT FK_665AE48C1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT FK_665AE48C6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_test ADD CONSTRAINT FK_665AE48C822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT FK_B301AFF31955BCA3 FOREIGN KEY (TEST_ID) REFERENCES mlyt_test (TEST_ID)');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT FK_B301AFF3351BACE2 FOREIGN KEY (COMPONENT_TEST_ID) REFERENCES mlyt_test (TEST_ID)');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT FK_B301AFF31FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT FK_B301AFF36A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_test_component ADD CONSTRAINT FK_B301AFF3822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');

        // "Test tanımları" ekranı: Maliyet → Tanımlar → Test tanımları (Malzemeler'den sonra); uzman ve yönetici görür
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('MLYT_TEST', N'Test tanımları', 'Tests', 'apps/biolab-maliyet/test/test-1.0.0.html')");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, g.MENU_ID, f.FORM_ID, N'Test tanımları', 'Tests', 'fas fa-vials', 20
              FROM mng_menu g
              JOIN mng_menu r ON r.MENU_ID = g.PARENT_MENU_ID AND r.MENU_CODE = 'biolab-maliyet'
              JOIN mng_form f ON f.FORM_CODE = 'MLYT_TEST'
             WHERE g.MENU_NAME = N'Tanımlar'");
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE IN ('MLYT_EXPERT', 'MLYT_MANAGER') AND f.FORM_CODE = 'MLYT_TEST'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'MLYT_TEST'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'MLYT_TEST'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'MLYT_TEST'");

        $this->addSql('ALTER TABLE mlyt_service_group DROP CONSTRAINT FK_A1E2656AA4494109');
        $this->addSql('ALTER TABLE mlyt_service_group DROP CONSTRAINT FK_A1E2656A1FFD8595');
        $this->addSql('ALTER TABLE mlyt_service_group DROP CONSTRAINT FK_A1E2656A6A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_service_group DROP CONSTRAINT FK_A1E2656A822ABD2C');
        $this->addSql('ALTER TABLE mlyt_test DROP CONSTRAINT FK_665AE48CA4494109');
        $this->addSql('ALTER TABLE mlyt_test DROP CONSTRAINT FK_665AE48CC94C79E7');
        $this->addSql('ALTER TABLE mlyt_test DROP CONSTRAINT FK_665AE48C9F93B984');
        $this->addSql('ALTER TABLE mlyt_test DROP CONSTRAINT FK_665AE48C46062E94');
        $this->addSql('ALTER TABLE mlyt_test DROP CONSTRAINT FK_665AE48C5D25FE2E');
        $this->addSql('ALTER TABLE mlyt_test DROP CONSTRAINT FK_665AE48C1FFD8595');
        $this->addSql('ALTER TABLE mlyt_test DROP CONSTRAINT FK_665AE48C6A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_test DROP CONSTRAINT FK_665AE48C822ABD2C');
        $this->addSql('ALTER TABLE mlyt_test_component DROP CONSTRAINT FK_B301AFF31955BCA3');
        $this->addSql('ALTER TABLE mlyt_test_component DROP CONSTRAINT FK_B301AFF3351BACE2');
        $this->addSql('ALTER TABLE mlyt_test_component DROP CONSTRAINT FK_B301AFF31FFD8595');
        $this->addSql('ALTER TABLE mlyt_test_component DROP CONSTRAINT FK_B301AFF36A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_test_component DROP CONSTRAINT FK_B301AFF3822ABD2C');
        $this->addSql('DROP TABLE mlyt_test_component');
        $this->addSql('DROP TABLE mlyt_test');
        $this->addSql('DROP TABLE mlyt_service_group');
    }
}
