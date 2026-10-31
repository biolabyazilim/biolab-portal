<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * KVKK Yönetimi uygulamasının rolü, ekranları ve menüsü (başlangıç verisi).
 * Kayıtlar kod ile bağlanır (ROLE_CODE, FORM_CODE, MENU_CODE); kimlik numarası sabit yazılmaz.
 *   Menü: KVKK (kök, MENU_CODE = biolab-kvkk) → Menü ağacı, Belgeler
 *   Rol:  KVKK_ADMIN (İdari İşler / İK kullanıcılarına verilir) → iki ekran
 * ssadmin'e KVKK_ADMIN verilir (test için); diğer kullanıcılara rol yönetimden atanır.
 */
final class Version20261031080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'KVK: KVKK_ADMIN rolü, Menü ağacı ve Belgeler ekranları, biolab-kvkk menüsü; ssadmin → KVKK_ADMIN';
    }

    public function up(Schema $schema): void
    {
        // Rol
        $this->addSql("INSERT INTO mng_role (ROLE_CODE, ROLE_NAME, ROLE_NAME_EN, APP_CODE) VALUES
            ('KVKK_ADMIN', N'KVKK yöneticisi', 'KVKK administrator', 'biolab-kvkk')");

        // Ekranlar
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('KVKK_NODE',     N'KVKK menü ağacı', 'KVKK menu tree', 'apps/biolab-kvkk/kvkk_node/kvkkNode-1.0.0.html'),
            ('KVKK_DOCUMENT', N'KVKK belgeleri',  'KVKK documents', 'apps/biolab-kvkk/kvkk_document/kvkkDocument-1.0.0.html')");

        // Menü: KVKK (kök) → Menü ağacı, Belgeler
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER) VALUES
            ('biolab-kvkk', NULL, NULL, N'KVKK', 'KVKK', 'fas fa-user-shield', 0)");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Menü ağacı', 'Menu tree', 'fas fa-sitemap', 10
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'KVKK_NODE'
             WHERE r.MENU_CODE = 'biolab-kvkk'");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Belgeler', 'Documents', 'far fa-file-pdf', 20
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'KVKK_DOCUMENT'
             WHERE r.MENU_CODE = 'biolab-kvkk'");

        // Rol → ekran
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE = 'KVKK_ADMIN' AND f.FORM_CODE IN ('KVKK_NODE', 'KVKK_DOCUMENT')");

        // ssadmin → KVKK yöneticisi (bağlı olduğu bütün şirketlerde); yoksa satır eklenmez
        $this->addSql("INSERT INTO mng_user_role (USER_ID, ROLE_ID, COMPANY_ID)
            SELECT u.USER_ID, r.ROLE_ID, uc.COMPANY_ID
              FROM mng_user u
              JOIN mng_user_company uc ON uc.USER_ID = u.USER_ID
              JOIN mng_role r ON r.ROLE_CODE = 'KVKK_ADMIN'
             WHERE u.USERNAME = 'ssadmin'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE ur FROM mng_user_role ur JOIN mng_role r ON r.ROLE_ID = ur.ROLE_ID WHERE r.ROLE_CODE = 'KVKK_ADMIN'");
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE IN ('KVKK_NODE', 'KVKK_DOCUMENT')");
        // Önce ekran satırları, en son kök (FK sırası)
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_menu r ON r.MENU_ID = m.PARENT_MENU_ID WHERE r.MENU_CODE = 'biolab-kvkk'");
        $this->addSql("DELETE FROM mng_menu WHERE MENU_CODE = 'biolab-kvkk'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE IN ('KVKK_NODE', 'KVKK_DOCUMENT')");
        $this->addSql("DELETE FROM mng_role WHERE ROLE_CODE = 'KVKK_ADMIN'");
    }
}
