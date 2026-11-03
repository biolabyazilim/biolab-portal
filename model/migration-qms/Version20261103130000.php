<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * QMS Kullanıcılar ekranı: kullanıcı kartı, görev ataması (departman / pozisyon / lokasyon), roller, pasife alma.
 * Menüde Organizasyon'un hemen altında (sıra 15); yalnızca QMS_ADMIN rolü açar.
 */
final class Version20261103130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'QMS: Kullanıcılar ekranı (mng_form), menüsü ve QMS_ADMIN yetkisi';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('QMS_USER', N'Kullanıcılar', 'Users', 'apps/biolab-qms/qms_user/qmsUser-1.0.0.html')");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Kullanıcılar', 'Users', 'fas fa-users', 15
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'QMS_USER'
             WHERE r.MENU_CODE = 'biolab-qms'");
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE = 'QMS_ADMIN' AND f.FORM_CODE = 'QMS_USER'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'QMS_USER'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'QMS_USER'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'QMS_USER'");
    }
}
