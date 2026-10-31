<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reçete: mlyt_recipe_item + "Test maliyetleri" ekranının form / menü / yetki kaydı.
 * diff ile üretildi; elle eklenenler: ekran kaydı (up sonu) ve down() düzeni
 * (önce ekran kaydı, sonra tablo; DBAL'in ürettiği "CREATE SCHEMA db_owner..." satırları çıkarıldı).
 */
final class Version20261026144848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: mlyt_recipe_item (test reçetesi); Test maliyetleri ekranı (MLYT_TEST_COST) menüsü ve yetkisi';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mlyt_recipe_item (RECIPE_ITEM_ID INT IDENTITY NOT NULL, QUANTITY NUMERIC(12, 4) NOT NULL, CONSUMPTION NVARCHAR(10) NOT NULL, SORT_NO SMALLINT NOT NULL, NOTE NVARCHAR(300) COLLATE Turkish_CI_AS, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, TEST_ID INT NOT NULL, MATERIAL_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (RECIPE_ITEM_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_recipe_item ON mlyt_recipe_item (TEST_ID, MATERIAL_ID, CONSUMPTION) WHERE TEST_ID IS NOT NULL AND MATERIAL_ID IS NOT NULL AND CONSUMPTION IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_A06739CB1955BCA3 ON mlyt_recipe_item (TEST_ID)');
        $this->addSql('CREATE INDEX IDX_A06739CB56467867 ON mlyt_recipe_item (MATERIAL_ID)');
        $this->addSql('CREATE INDEX IDX_A06739CB1FFD8595 ON mlyt_recipe_item (C_BY)');
        $this->addSql('CREATE INDEX IDX_A06739CB6A8F8DD6 ON mlyt_recipe_item (U_BY)');
        $this->addSql('CREATE INDEX IDX_A06739CB822ABD2C ON mlyt_recipe_item (D_BY)');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT [DF_mlyt_recipe_item_CONSUMPTION] DEFAULT \'PATIENT\' FOR CONSUMPTION');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT [DF_mlyt_recipe_item_SORT_NO] DEFAULT 0 FOR SORT_NO');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT [DF_mlyt_recipe_item_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT [DF_mlyt_recipe_item_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT [DF_mlyt_recipe_item_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT FK_A06739CB1955BCA3 FOREIGN KEY (TEST_ID) REFERENCES mlyt_test (TEST_ID)');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT FK_A06739CB56467867 FOREIGN KEY (MATERIAL_ID) REFERENCES mlyt_material (MATERIAL_ID)');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT FK_A06739CB1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT FK_A06739CB6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_recipe_item ADD CONSTRAINT FK_A06739CB822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');

        // "Test maliyetleri" ekranı: Maliyet → Test maliyetleri (Tanımlar'dan sonra, kök altında); uzman ve yönetici görür
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('MLYT_TEST_COST', N'Test maliyetleri', 'Test costs', 'apps/biolab-maliyet/test_cost/testCost-1.0.0.html')");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Test maliyetleri', 'Test costs', 'fas fa-calculator', 20
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'MLYT_TEST_COST'
             WHERE r.MENU_CODE = 'biolab-maliyet'");
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE IN ('MLYT_EXPERT', 'MLYT_MANAGER') AND f.FORM_CODE = 'MLYT_TEST_COST'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'MLYT_TEST_COST'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'MLYT_TEST_COST'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'MLYT_TEST_COST'");

        $this->addSql('ALTER TABLE mlyt_recipe_item DROP CONSTRAINT FK_A06739CB1955BCA3');
        $this->addSql('ALTER TABLE mlyt_recipe_item DROP CONSTRAINT FK_A06739CB56467867');
        $this->addSql('ALTER TABLE mlyt_recipe_item DROP CONSTRAINT FK_A06739CB1FFD8595');
        $this->addSql('ALTER TABLE mlyt_recipe_item DROP CONSTRAINT FK_A06739CB6A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_recipe_item DROP CONSTRAINT FK_A06739CB822ABD2C');
        $this->addSql('DROP TABLE mlyt_recipe_item');
    }
}
