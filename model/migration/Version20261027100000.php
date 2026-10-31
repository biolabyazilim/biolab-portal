<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Uygulama yetkisi: mng_role.APP_CODE. Kullanıcı bir uygulamaya ancak o uygulamanın en az bir rolüne sahipse girer.
 * - Maliyet rolleri (MLYT_*) → biolab-maliyet
 * - HB_USER rolü (hb-elektroforez) açılır ve bugün HB'yi kullanan kimse kilitlenmesin diye
 *   mevcut aktif kullanıcılara (bağlı oldukları bütün şirketlerde) verilir. HB'ye girmemesi gerekenlerden sonra kaldırılır.
 */
final class Version20261027100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MNG: mng_role.APP_CODE (uygulama yetkisi); MLYT_* → biolab-maliyet; HB_USER rolü ve mevcut kullanıcılara atanması';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mng_role ADD APP_CODE NVARCHAR(50)');

        $this->addSql("UPDATE mng_role SET APP_CODE = 'biolab-maliyet' WHERE ROLE_CODE LIKE 'MLYT[_]%'");

        $this->addSql("INSERT INTO mng_role (ROLE_CODE, ROLE_NAME, ROLE_NAME_EN, APP_CODE) VALUES
            ('HB_USER', N'HB Elektroforez kullanıcısı', 'HB Electrophoresis user', 'hb-elektroforez')");

        $this->addSql("INSERT INTO mng_user_role (USER_ID, ROLE_ID, COMPANY_ID)
            SELECT uc.USER_ID, r.ROLE_ID, uc.COMPANY_ID
              FROM mng_user_company uc
              JOIN mng_user u ON u.USER_ID = uc.USER_ID
              JOIN mng_role r ON r.ROLE_CODE = 'HB_USER'
             WHERE u.ACTIVE_FL = 'Y' AND u.RECORD_FL > 0
               AND uc.ACTIVE_FL = 'Y' AND uc.RECORD_FL > 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE ur FROM mng_user_role ur JOIN mng_role r ON r.ROLE_ID = ur.ROLE_ID WHERE r.ROLE_CODE = 'HB_USER'");
        $this->addSql("DELETE FROM mng_role WHERE ROLE_CODE = 'HB_USER'");
        $this->addSql('ALTER TABLE mng_role DROP COLUMN APP_CODE');
    }
}
