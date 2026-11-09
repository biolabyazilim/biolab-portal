<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Biolab Yönetim (apps/biolab_definitions) menüsü ve ekranları.
 *   Biolab Yönetim (kök, MENU_CODE = biolab_definitions)
 *    ├─ Tanımlamalar (DEF_GRP_DEFINITIONS) → Şirketler (DEF_COMPANY)
 *    └─ Sistem (DEF_GRP_SYSTEM)            → Menü Tanımlama (DEF_MENU), Form Tanımlama (DEF_FORM)
 * Ekranların hepsi DEF_ADMIN rolüne verilir.
 *
 * Gruplar, Şirketler ve Menü Tanımlama geliştirme veritabanına önce elle (SSMS) eklendi; bu yüzden her kayıt
 * yoksa eklenir (IF NOT EXISTS). Geliştirmede yalnızca Form Tanımlama eklenir, sunucuda hepsi oluşur.
 */
final class Version20261106140000 extends AbstractMigration
{
    private const FORMS = ['DEF_COMPANY', 'DEF_MENU', 'DEF_FORM'];

    public function getDescription(): string
    {
        return 'DEF: Biolab Yönetim menü grupları (Tanımlamalar, Sistem); Şirketler, Menü Tanımlama, Form Tanımlama ekranları → DEF_ADMIN';
    }

    public function up(Schema $schema): void
    {
        // Kök adı kart adıyla aynı (grup "Tanımlamalar" ile karışmasın)
        $this->addSql("UPDATE mng_menu SET MENU_NAME = N'Biolab Yönetim', MENU_NAME_EN = 'Biolab Management'
             WHERE MENU_CODE = 'biolab_definitions'");

        // Gruplar: kökün altına
        $this->addGroup('DEF_GRP_DEFINITIONS', 'Tanımlamalar', 'Definitions', 'fas fa-cogs', 10);
        $this->addGroup('DEF_GRP_SYSTEM', 'Sistem', 'System', 'fas fa-shield-alt', 20);

        // Ekranlar
        $this->addForm('DEF_COMPANY', 'Şirketler', 'Companies', 'apps/biolab_definitions/mng_company-1.0.0.html');
        $this->addForm('DEF_MENU', 'Menü Tanımlama', 'Menu definitions', 'apps/biolab_definitions/menu-1.0.0.html');
        $this->addForm('DEF_FORM', 'Form Tanımlama', 'Form definitions', 'apps/biolab_definitions/mng_form-1.0.0.html');

        // Menü satırları: grupların altına
        $this->addMenuItem('DEF_GRP_DEFINITIONS', 'DEF_COMPANY', 'Şirketler', 'Companies', 'fas fa-building', 10);
        $this->addMenuItem('DEF_GRP_SYSTEM', 'DEF_MENU', 'Menü Tanımlama', 'Menu definitions', 'fas fa-sitemap', 10);
        $this->addMenuItem('DEF_GRP_SYSTEM', 'DEF_FORM', 'Form Tanımlama', 'Form definitions', 'fas fa-window-maximize', 20);

        // Rol → ekran
        foreach (self::FORMS as $formCode) {
            $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
                SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
                 WHERE r.ROLE_CODE = 'DEF_ADMIN' AND f.FORM_CODE = '$formCode'
                   AND NOT EXISTS (SELECT 1 FROM mng_role_form x WHERE x.ROLE_ID = r.ROLE_ID AND x.FORM_ID = f.FORM_ID)");
        }
    }

    public function down(Schema $schema): void
    {
        $in = "'" . implode("', '", self::FORMS) . "'";
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE IN ($in)");
        // Önce ekran satırları, sonra gruplar (FK sırası)
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE IN ($in)");
        $this->addSql("DELETE FROM mng_menu WHERE MENU_CODE IN ('DEF_GRP_DEFINITIONS', 'DEF_GRP_SYSTEM')");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE IN ($in)");
        $this->addSql("UPDATE mng_menu SET MENU_NAME = N'Tanımlamalar', MENU_NAME_EN = 'Definitions'
             WHERE MENU_CODE = 'biolab_definitions'");
    }

    private function addGroup(string $code, string $name, string $nameEn, string $icon, int $order): void
    {
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT '$code', r.MENU_ID, NULL, N'$name', '$nameEn', '$icon', $order
              FROM mng_menu r
             WHERE r.MENU_CODE = 'biolab_definitions'
               AND NOT EXISTS (SELECT 1 FROM mng_menu x WHERE x.MENU_CODE = '$code')");
    }

    private function addForm(string $code, string $name, string $nameEn, string $path): void
    {
        $this->addSql("IF NOT EXISTS (SELECT 1 FROM mng_form WHERE FORM_CODE = '$code')
            INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES ('$code', N'$name', '$nameEn', '$path')");
    }

    private function addMenuItem(string $groupCode, string $formCode, string $name, string $nameEn, string $icon, int $order): void
    {
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, g.MENU_ID, f.FORM_ID, N'$name', '$nameEn', '$icon', $order
              FROM mng_menu g JOIN mng_form f ON f.FORM_CODE = '$formCode'
             WHERE g.MENU_CODE = '$groupCode'
               AND NOT EXISTS (SELECT 1 FROM mng_menu x WHERE x.FORM_ID = f.FORM_ID)");
    }
}
