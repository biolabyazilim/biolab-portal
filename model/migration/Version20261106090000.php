<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tanımlamalar uygulamasına (apps/biolab_definitions) giriş için gereken başlangıç verisi.
 *   Rol:  DEF_ADMIN (APP_CODE = biolab_definitions) — giriş bu rolle açılır
 *   Menü: Tanımlamalar (kök, MENU_CODE = biolab_definitions) — ekranlar ve rol → ekran bağları ekranlar yazıldıkça eklenir
 * ssadmin'e DEF_ADMIN verilir (test için).
 */
final class Version20261106090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DEF: DEF_ADMIN rolü, biolab_definitions menü kökü; ssadmin → DEF_ADMIN';
    }

    public function up(Schema $schema): void
    {
        // Rol
        $this->addSql("INSERT INTO mng_role (ROLE_CODE, ROLE_NAME, ROLE_NAME_EN, APP_CODE) VALUES
            ('DEF_ADMIN', N'Tanımlama yöneticisi', 'Definitions administrator', 'biolab_definitions')");

        // Menü kökü (ekranlar bunun altına eklenir)
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER) VALUES
            ('biolab_definitions', NULL, NULL, N'Tanımlamalar', 'Definitions', 'fas fa-cogs', 0)");

        // ssadmin → Tanımlama yöneticisi (bağlı olduğu bütün şirketlerde); yoksa satır eklenmez
        $this->addSql("INSERT INTO mng_user_role (USER_ID, ROLE_ID, COMPANY_ID)
            SELECT u.USER_ID, r.ROLE_ID, uc.COMPANY_ID
              FROM mng_user u
              JOIN mng_user_company uc ON uc.USER_ID = u.USER_ID
              JOIN mng_role r ON r.ROLE_CODE = 'DEF_ADMIN'
             WHERE u.USERNAME = 'ssadmin'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE ur FROM mng_user_role ur JOIN mng_role r ON r.ROLE_ID = ur.ROLE_ID WHERE r.ROLE_CODE = 'DEF_ADMIN'");
        $this->addSql("DELETE FROM mng_menu WHERE MENU_CODE = 'biolab_definitions'");
        $this->addSql("DELETE FROM mng_role WHERE ROLE_CODE = 'DEF_ADMIN'");
    }
}
