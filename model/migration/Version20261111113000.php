<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saha takip mobil uygulamasının rolü (başlangıç verisi).
 *   Rol: SHT_PERSONEL (APP_CODE = biolab-saha-takip) → mobil uygulamaya giriş yetkisi.
 *        Ekranı yok; yalnız api/saha.php girişinde AuthService::checkCredentials(…, 'biolab-saha-takip') bakar.
 * Yönetici rolü ve web ekranları, yönetici paneli yazılınca ayrı migration'da eklenecek.
 * Kullanıcılara rol yönetimden atanır; ayrıca sht_personel kaydı (personel kodu) gerekir.
 */
final class Version20261111113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: SHT_PERSONEL rolü (biolab-saha-takip mobil girişi)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO mng_role (ROLE_CODE, ROLE_NAME, ROLE_NAME_EN, APP_CODE) VALUES
            ('SHT_PERSONEL', N'Saha personeli', 'Field staff', 'biolab-saha-takip')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE ur FROM mng_user_role ur JOIN mng_role r ON r.ROLE_ID = ur.ROLE_ID WHERE r.ROLE_CODE = 'SHT_PERSONEL'");
        $this->addSql("DELETE FROM mng_role WHERE ROLE_CODE = 'SHT_PERSONEL'");
    }
}
