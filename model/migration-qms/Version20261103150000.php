<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * İmza ekranı menüye "İmzalarım" adıyla girer (Doküman yükleme ile Dokümanlarım arasında, sıra 35).
 * Ekran ilk migration'da QMS_SIGN olarak tanımlandı; yetkisi QMS_USER rolünde (her kullanıcı kendi imzalarını görür).
 */
final class Version20261103150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'QMS: İmzalarım menüsü (QMS_SIGN)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE mng_form SET FORM_NAME = N'İmzalarım', FORM_NAME_EN = 'My signatures' WHERE FORM_CODE = 'QMS_SIGN'");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'İmzalarım', 'My signatures', 'fas fa-signature', 35
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'QMS_SIGN'
             WHERE r.MENU_CODE = 'biolab-qms'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'QMS_SIGN'");
        $this->addSql("UPDATE mng_form SET FORM_NAME = N'İmza', FORM_NAME_EN = 'Signature' WHERE FORM_CODE = 'QMS_SIGN'");
    }
}
