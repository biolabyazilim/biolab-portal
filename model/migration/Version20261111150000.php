<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saha Takip yönetici web panelinin rolü, ekranı ve menüsü (başlangıç verisi).
 * Kayıtlar kod ile bağlanır (ROLE_CODE, FORM_CODE, MENU_CODE); kimlik numarası sabit yazılmaz.
 *   Menü: Saha Takip (kök, MENU_CODE = biolab-saha-takip) → Personeller
 *   Rol:  SHT_ADMIN (saha takip yöneticisi) → Personeller ekranı
 *         (SHT_PERSONEL = mobil giriş rolü, Version20261111113000; ekranı yok, web paneline giremez)
 * ssadmin'e SHT_ADMIN verilir (test için); diğer yöneticilere rol yönetimden atanır.
 */
final class Version20261111150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: SHT_ADMIN rolü, Personeller ekranı, biolab-saha-takip menüsü; ssadmin → SHT_ADMIN';
    }

    public function up(Schema $schema): void
    {
        // Rol
        $this->addSql("INSERT INTO mng_role (ROLE_CODE, ROLE_NAME, ROLE_NAME_EN, APP_CODE) VALUES
            ('SHT_ADMIN', N'Saha takip yöneticisi', 'Field tracking administrator', 'biolab-saha-takip')");

        // Ekran
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('SHT_PERSONEL', N'Saha personeli', 'Field staff', 'apps/biolab-saha-takip/personel/personel-1.0.0.html')");

        // Menü: Saha Takip (kök) → Personeller
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER) VALUES
            ('biolab-saha-takip', NULL, NULL, N'Saha Takip', 'Field Tracking', 'fas fa-map-marked-alt', 0)");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Personeller', 'Staff', 'fas fa-users', 10
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'SHT_PERSONEL'
             WHERE r.MENU_CODE = 'biolab-saha-takip'");

        // Rol → ekran
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE = 'SHT_ADMIN' AND f.FORM_CODE = 'SHT_PERSONEL'");

        // ssadmin → saha takip yöneticisi (bağlı olduğu bütün şirketlerde); yoksa satır eklenmez
        $this->addSql("INSERT INTO mng_user_role (USER_ID, ROLE_ID, COMPANY_ID)
            SELECT u.USER_ID, r.ROLE_ID, uc.COMPANY_ID
              FROM mng_user u
              JOIN mng_user_company uc ON uc.USER_ID = u.USER_ID
              JOIN mng_role r ON r.ROLE_CODE = 'SHT_ADMIN'
             WHERE u.USERNAME = 'ssadmin'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE ur FROM mng_user_role ur JOIN mng_role r ON r.ROLE_ID = ur.ROLE_ID WHERE r.ROLE_CODE = 'SHT_ADMIN'");
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'SHT_PERSONEL'");
        // Önce ekran satırları, en son kök (FK sırası)
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_menu r ON r.MENU_ID = m.PARENT_MENU_ID WHERE r.MENU_CODE = 'biolab-saha-takip'");
        $this->addSql("DELETE FROM mng_menu WHERE MENU_CODE = 'biolab-saha-takip'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'SHT_PERSONEL'");
        $this->addSql("DELETE FROM mng_role WHERE ROLE_CODE = 'SHT_ADMIN'");
    }
}
