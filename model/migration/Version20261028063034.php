<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kit verimi: mlyt_material.YIELD_PCT (boşsa %100).
 * Kit, "Kit" kategorisinde bir malzemedir; fiyatı çalışmaya (mlyt_material_price), teste bağlantısı reçeteye
 * (mlyt_recipe_item) yazılır. Bu alan yalnız birim maliyeti verime böler: fiyat × kur ÷ (içerik × verim / 100).
 */
final class Version20261028063034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: malzeme (kit) verimi YIELD_PCT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_material ADD YIELD_PCT NUMERIC(5, 2)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_material DROP COLUMN YIELD_PCT');
    }
}
