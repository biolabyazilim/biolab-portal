<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Biolab Yönetim — Rol Tanımlama ekranı (DEF_ROLE): Sistem grubuna, Menü ve Form Tanımlama'nın yanına; DEF_ADMIN'e verilir.
 * Rol yetki yapısını değiştirdiği için Sistem grubunda durur (Tanımlamalar grubundaki ekranlar uygulama yöneticilerine verilebilir).
 */
final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DEF: Rol Tanımlama ekranı (DEF_ROLE) → Sistem grubu, DEF_ADMIN';
    }

    public function up(Schema $schema): void
    {
        // Ekran
        $this->addSql("IF NOT EXISTS (SELECT 1 FROM mng_form WHERE FORM_CODE = 'DEF_ROLE')
            INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH)
            VALUES ('DEF_ROLE', N'Rol Tanımlama', 'Role Definition', 'apps/biolab_definitions/mng_role-1.0.0.html')");

        // Menü satırı: Sistem grubunun altına (Menü Tanımlama 10, Form Tanımlama 20)
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, g.MENU_ID, f.FORM_ID, N'Rol Tanımlama', 'Role Definition', 'fas fa-user-tag', 30
              FROM mng_menu g JOIN mng_form f ON f.FORM_CODE = 'DEF_ROLE'
             WHERE g.MENU_CODE = 'DEF_GRP_SYSTEM'
               AND NOT EXISTS (SELECT 1 FROM mng_menu x WHERE x.FORM_ID = f.FORM_ID AND x.RECORD_FL > 0)");

        // Rol → ekran
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE = 'DEF_ADMIN' AND f.FORM_CODE = 'DEF_ROLE'
               AND NOT EXISTS (SELECT 1 FROM mng_role_form x WHERE x.ROLE_ID = r.ROLE_ID AND x.FORM_ID = f.FORM_ID)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'DEF_ROLE'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'DEF_ROLE'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'DEF_ROLE'");
    }
}
