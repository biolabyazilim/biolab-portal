<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Özet" (dashboard) ekranı: Maliyet → Özet (menünün en üstünde); uzman ve yönetici görür. Yeni tablo yok, salt okunur ekran.
 */
final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: Özet (dashboard) ekranı (mng_form, mng_menu, mng_role_form)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('MLYT_DASHBOARD', N'Özet', 'Overview', 'apps/biolab-maliyet/dashboard/dashboard-1.0.0.html')");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Özet', 'Overview', 'fas fa-chart-pie', 5
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'MLYT_DASHBOARD'
             WHERE r.MENU_CODE = 'biolab-maliyet'");
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE IN ('MLYT_EXPERT', 'MLYT_MANAGER') AND f.FORM_CODE = 'MLYT_DASHBOARD'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'MLYT_DASHBOARD'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'MLYT_DASHBOARD'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'MLYT_DASHBOARD'");
    }
}
