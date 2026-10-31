<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Uzmanın güncel Excel'ine göre:
 * - Kit verimi yüzde değil hasta sayısı: mlyt_material.YIELD_PCT kalkar, fiyata mlyt_material_price.PATIENT_QTY gelir
 *   (bir ambalajdan çıkan hasta; 96 testlik kitten 70). Girilmiş verim varsa hasta sayısına çevrilir: içerik × verim / 100.
 * - TL öngörü katsayısı: mlyt_study.TRY_FACTOR (TL fiyatlar bununla çarpılır; varsayılan 1).
 */
final class Version20261028090256 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: fiyata hasta sayısı (PATIENT_QTY), malzemeden verim (YIELD_PCT) kalkar; çalışmaya TL öngörü katsayısı (TRY_FACTOR)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_material_price ADD PATIENT_QTY NUMERIC(12, 3)');
        $this->addSql('UPDATE p SET PATIENT_QTY = ROUND(p.PACKAGE_QTY * m.YIELD_PCT / 100, 3)
                         FROM mlyt_material_price p JOIN mlyt_material m ON m.MATERIAL_ID = p.MATERIAL_ID
                        WHERE m.YIELD_PCT IS NOT NULL');
        $this->addSql('ALTER TABLE mlyt_material DROP COLUMN YIELD_PCT');
        $this->addSql('ALTER TABLE mlyt_study ADD TRY_FACTOR NUMERIC(8, 4) CONSTRAINT DF_mlyt_study_TRY_FACTOR DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_study DROP CONSTRAINT DF_mlyt_study_TRY_FACTOR');
        $this->addSql('ALTER TABLE mlyt_study DROP COLUMN TRY_FACTOR');
        $this->addSql('ALTER TABLE mlyt_material ADD YIELD_PCT NUMERIC(5, 2)');
        $this->addSql('ALTER TABLE mlyt_material_price DROP COLUMN PATIENT_QTY');
    }
}
