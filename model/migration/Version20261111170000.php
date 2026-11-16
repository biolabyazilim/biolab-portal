<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saha Takip: Canlı harita ekranı (başlangıç verisi).
 *   Menü: Saha Takip → Canlı harita (en üstte; panele girince ilk açılan ekran) → Personeller
 *   Rol:  SHT_ADMIN → Canlı harita ekranı
 * Konum verisi tabloya yazılmaz; ekran Traccar API'sinden okur (config.php → traccar.url / traccar.token).
 */
final class Version20261111170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: Canlı harita ekranı, menüsü (Personeller\'in üstünde), SHT_ADMIN → ekran';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('SHT_HARITA', N'Canlı harita', 'Live map', 'apps/biolab-saha-takip/harita/harita-1.0.0.html')");

        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Canlı harita', 'Live map', 'fas fa-map-marked-alt', 5
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'SHT_HARITA'
             WHERE r.MENU_CODE = 'biolab-saha-takip'");

        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE = 'SHT_ADMIN' AND f.FORM_CODE = 'SHT_HARITA'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'SHT_HARITA'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'SHT_HARITA'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'SHT_HARITA'");
    }
}
