<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kitle birlikte kullanılan malzeme: miktar test başı (TEST) ya da kit başına ambalaj adedi (KIT).
 * Mevcut satırlar TEST kalır (davranış değişmez).
 */
final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: mlyt_material_companion.BASIS (TEST / KIT: kit başına kalibratör, kontrol)';
    }

    public function up(Schema $schema): void
    {
        // Varsayılan kısıt adı verilir (SQL Server kendi adını üretmesin; down() bu adla siler)
        $this->addSql('ALTER TABLE mlyt_material_companion ADD BASIS NVARCHAR(10) NOT NULL CONSTRAINT [DF_mlyt_material_companion_BASIS] DEFAULT \'TEST\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_material_companion DROP CONSTRAINT [DF_mlyt_material_companion_BASIS]');
        $this->addSql('ALTER TABLE mlyt_material_companion DROP COLUMN BASIS');
    }
}
