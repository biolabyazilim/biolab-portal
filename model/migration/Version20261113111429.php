<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Görev bildirimi: telefonun Firebase (FCM) bildirim adresi mobil oturuma kaydedilir (Biolab\Core\Sht\PushService).
 */
final class Version20261113111429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: sht_device_token bildirim adresi (PUSH_TOKEN, PUSH_TOKEN_AT)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_device_token ADD PUSH_TOKEN NVARCHAR(400)');
        $this->addSql('ALTER TABLE sht_device_token ADD PUSH_TOKEN_AT DATETIME2(6)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_device_token DROP COLUMN PUSH_TOKEN');
        $this->addSql('ALTER TABLE sht_device_token DROP COLUMN PUSH_TOKEN_AT');
    }
}
