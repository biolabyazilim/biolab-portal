<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aylık takip: numune listesinde Onay Tarih doluysa APPROVED_FL = Y (testler onaylanmış, kabul kaydı eksik; kayıp sayılmaz).
 */
final class Version20261118064425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: mlyt_lab_sample.APPROVED_FL (onaylanmış numune)';
    }

    public function up(Schema $schema): void
    {
        // Varsayılan kısıt adı verilir (SQL Server kendi adını üretmesin; down() bu adla siler)
        $this->addSql('ALTER TABLE mlyt_lab_sample ADD APPROVED_FL NCHAR(1) NOT NULL CONSTRAINT [DF_mlyt_lab_sample_APPROVED_FL] DEFAULT \'N\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_lab_sample DROP CONSTRAINT [DF_mlyt_lab_sample_APPROVED_FL]');
        $this->addSql('ALTER TABLE mlyt_lab_sample DROP COLUMN APPROVED_FL');
    }
}
