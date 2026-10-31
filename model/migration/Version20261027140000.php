<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * HB Elektroforez ekranları ekran yetkisine bağlanır (maliyetle aynı kalıp: mng_form + mng_menu + mng_role_form).
 * Menü kökünün MENU_CODE'u uygulama klasörü (hb-elektroforez); altındaki satırlar üstteki sekmelerdir.
 * Rapor inceleme sekme değildir (iş listesi / raporlardan açılır): ekranı var, menü satırı yok.
 * Ayarlar sekmesinin ekranı henüz yazılmadı; ekran gelince aynı kalıpla eklenir.
 * HB_USER bütün HB ekranlarını görür; daha dar bir rol gerekirse yeni rol + kendi ekranları eklenir.
 */
final class Version20261027140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HB: ekranlar (mng_form), sekme menüsü (mng_menu), HB_USER → bütün HB ekranları';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('HB_DASHBOARD',         N'Dashboard',       'Dashboard',         'apps/hb-elektroforez/dashboard/dashboard-1.0.0.html'),
            ('HB_SAMPLE_ACCEPTANCE', N'Numune kabul',    'Sample acceptance', 'apps/hb-elektroforez/sample_acceptance/sampleAcceptance-1.0.0.html'),
            ('HB_WORK_LIST',         N'İş listesi',      'Work list',         'apps/hb-elektroforez/work_list/workList-1.0.0.html'),
            ('HB_REPORT',            N'Raporlar',        'Reports',           'apps/hb-elektroforez/report/report-1.0.0.html'),
            ('HB_REPORT_REVIEW',     N'Rapor inceleme',  'Report review',     'apps/hb-elektroforez/report_review/reportReview-1.0.0.html')");

        // Menü: HB Elektroforez (kök) → sekmeler
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER) VALUES
            ('hb-elektroforez', NULL, NULL, N'HB Elektroforez', 'HB Electrophoresis', 'fas fa-vials', 0)");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, t.NAME, t.NAME_EN, t.ICON, t.ORD
              FROM mng_menu r
              JOIN (VALUES ('HB_DASHBOARD',         N'Dashboard',    'Dashboard',         'fas fa-home',         10),
                           ('HB_SAMPLE_ACCEPTANCE', N'Numune kabul', 'Sample acceptance', 'fas fa-vial',         20),
                           ('HB_WORK_LIST',         N'İş listesi',   'Work list',         'fas fa-tasks',        30),
                           ('HB_REPORT',            N'Raporlar',     'Reports',           'fas fa-file-medical', 40)
                   ) t (FORM_CODE, NAME, NAME_EN, ICON, ORD) ON 1 = 1
              JOIN mng_form f ON f.FORM_CODE = t.FORM_CODE
             WHERE r.MENU_CODE = 'hb-elektroforez' AND r.PARENT_MENU_ID IS NULL");

        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE = 'HB_USER' AND f.FORM_CODE LIKE 'HB[_]%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE LIKE 'HB[_]%'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE LIKE 'HB[_]%'");
        $this->addSql("DELETE FROM mng_menu WHERE MENU_CODE = 'hb-elektroforez' AND PARENT_MENU_ID IS NULL");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE LIKE 'HB[_]%'");
    }
}
