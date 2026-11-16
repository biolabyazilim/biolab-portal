<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saha Takip: Görevler ve Firmalar ekranları (başlangıç verisi).
 *   Menü: Canlı harita (5) → Görevler (6) → Mesailer (8) → Personeller (10) → Firmalar (12)
 *   Rol:  SHT_ADMIN → iki ekran
 */
final class Version20261112090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: Görevler ve Firmalar ekranları, menüleri, SHT_ADMIN → ekranlar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('SHT_GOREV', N'Görevler', 'Tasks', 'apps/biolab-saha-takip/gorev/gorev-1.0.0.html'),
            ('SHT_FIRMA', N'Firmalar', 'Customers', 'apps/biolab-saha-takip/firma/firma-1.0.0.html')");

        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Görevler', 'Tasks', 'fas fa-tasks', 6
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'SHT_GOREV'
             WHERE r.MENU_CODE = 'biolab-saha-takip'");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Firmalar', 'Customers', 'fas fa-building', 12
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'SHT_FIRMA'
             WHERE r.MENU_CODE = 'biolab-saha-takip'");

        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE = 'SHT_ADMIN' AND f.FORM_CODE IN ('SHT_GOREV', 'SHT_FIRMA')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE IN ('SHT_GOREV', 'SHT_FIRMA')");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE IN ('SHT_GOREV', 'SHT_FIRMA')");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE IN ('SHT_GOREV', 'SHT_FIRMA')");
    }
}
