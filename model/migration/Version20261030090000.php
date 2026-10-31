<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Kit eşleştirme" ekranı: Maliyet → Tanımlar → Kit eşleştirme (Test tanımları'ndan sonra); uzman ve yönetici görür.
 * Kitleri testlere ad benzerliğiyle önerir, uzman onaylayınca reçeteye bağlar. Yeni tablo yok.
 */
final class Version20261030090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: Kit eşleştirme ekranı (mng_form, mng_menu, mng_role_form)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('MLYT_KIT_MATCH', N'Kit eşleştirme', 'Kit matching', 'apps/biolab-maliyet/kit_match/kitMatch-1.0.0.html')");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, g.MENU_ID, f.FORM_ID, N'Kit eşleştirme', 'Kit matching', 'fas fa-link', 30
              FROM mng_menu g
              JOIN mng_menu r ON r.MENU_ID = g.PARENT_MENU_ID AND r.MENU_CODE = 'biolab-maliyet'
              JOIN mng_form f ON f.FORM_CODE = 'MLYT_KIT_MATCH'
             WHERE g.MENU_NAME = N'Tanımlar'");
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE IN ('MLYT_EXPERT', 'MLYT_MANAGER') AND f.FORM_CODE = 'MLYT_KIT_MATCH'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'MLYT_KIT_MATCH'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'MLYT_KIT_MATCH'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'MLYT_KIT_MATCH'");
    }
}
