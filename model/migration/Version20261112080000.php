<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saha Takip: Mesailer ekranı (başlangıç verisi).
 *   Menü: Saha Takip → Canlı harita (5) → Mesailer (8) → Personeller (10)
 *   Rol:  SHT_ADMIN → Mesailer ekranı
 */
final class Version20261112080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: Mesailer ekranı, menüsü, SHT_ADMIN → ekran';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('SHT_MESAI', N'Mesailer', 'Shifts', 'apps/biolab-saha-takip/mesai/mesai-1.0.0.html')");

        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Mesailer', 'Shifts', 'fas fa-business-time', 8
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'SHT_MESAI'
             WHERE r.MENU_CODE = 'biolab-saha-takip'");

        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE = 'SHT_ADMIN' AND f.FORM_CODE = 'SHT_MESAI'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'SHT_MESAI'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'SHT_MESAI'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'SHT_MESAI'");
    }
}
