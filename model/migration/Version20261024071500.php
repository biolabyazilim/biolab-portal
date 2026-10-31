<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Maliyet uygulamasının rolleri, ekranları ve menüsü (başlangıç verisi).
 * Kayıtlar kod ile bağlanır (ROLE_CODE, FORM_CODE, MENU_CODE); kimlik numarası sabit yazılmaz.
 * Yeni ekran eklendikçe aynı kalıpla yeni bir migration'da mng_form + mng_menu + mng_role_form satırı eklenir.
 */
final class Version20261024071500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: maliyet rolleri, Malzemeler ekranı, menü ağacı; ssadmin → Maliyet uzmanı';
    }

    public function up(Schema $schema): void
    {
        // Roller
        $this->addSql("INSERT INTO mng_role (ROLE_CODE, ROLE_NAME, ROLE_NAME_EN) VALUES
            ('MLYT_EXPERT',  N'Maliyet uzmanı',        'Cost specialist'),
            ('MLYT_MANAGER', N'Maliyet yöneticisi',    'Cost manager'),
            ('MLYT_LAB',     N'Laboratuvar sorumlusu', 'Laboratory supervisor')");

        // Ekranlar
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('MLYT_MATERIAL', N'Malzemeler', 'Materials', 'apps/biolab-maliyet/material/material-1.0.0.html')");

        // Menü: Maliyet (kök) → Tanımlar (grup) → Malzemeler (ekran)
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER) VALUES
            ('biolab-maliyet', NULL, NULL, N'Maliyet', 'Cost', 'fas fa-calculator', 0)");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, m.MENU_ID, NULL, N'Tanımlar', 'Definitions', 'fas fa-database', 10
              FROM mng_menu m WHERE m.MENU_CODE = 'biolab-maliyet'");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, g.MENU_ID, f.FORM_ID, N'Malzemeler', 'Materials', 'fas fa-boxes', 10
              FROM mng_menu g
              JOIN mng_menu r ON r.MENU_ID = g.PARENT_MENU_ID AND r.MENU_CODE = 'biolab-maliyet'
              JOIN mng_form f ON f.FORM_CODE = 'MLYT_MATERIAL'
             WHERE g.MENU_NAME = N'Tanımlar'");

        // Rol → ekran: uzman ve yönetici malzemeleri görür; lab sorumlusu bildirim ekranı gelince eklenecek
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE IN ('MLYT_EXPERT', 'MLYT_MANAGER') AND f.FORM_CODE = 'MLYT_MATERIAL'");

        // ssadmin → Maliyet uzmanı (bağlı olduğu bütün şirketlerde); yoksa satır eklenmez
        $this->addSql("INSERT INTO mng_user_role (USER_ID, ROLE_ID, COMPANY_ID)
            SELECT u.USER_ID, r.ROLE_ID, uc.COMPANY_ID
              FROM mng_user u
              JOIN mng_user_company uc ON uc.USER_ID = u.USER_ID
              JOIN mng_role r ON r.ROLE_CODE = 'MLYT_EXPERT'
             WHERE u.USERNAME = 'ssadmin'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE ur FROM mng_user_role ur JOIN mng_role r ON r.ROLE_ID = ur.ROLE_ID WHERE r.ROLE_CODE LIKE 'MLYT[_]%'");
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE LIKE 'MLYT[_]%'");
        // Önce ekran satırları, sonra gruplar, en son kök (FK sırası)
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE LIKE 'MLYT[_]%'");
        $this->addSql("DELETE g FROM mng_menu g JOIN mng_menu r ON r.MENU_ID = g.PARENT_MENU_ID WHERE r.MENU_CODE = 'biolab-maliyet'");
        $this->addSql("DELETE FROM mng_menu WHERE MENU_CODE = 'biolab-maliyet'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE LIKE 'MLYT[_]%'");
        $this->addSql("DELETE FROM mng_role WHERE ROLE_CODE LIKE 'MLYT[_]%'");
    }
}
