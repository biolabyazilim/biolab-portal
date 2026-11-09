<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bedelli malzeme: mlyt_material.BILLABLE_PRICE (TL / kullanım birimi). Doktorlara ürün bazlı fiyatlanan malzeme
 * (numune kabı vb.) bir kere tanımlanan bu bedelle değerlendirilir; maliyet çalışmasının fiyat ve kurlarına bakılmaz.
 */
final class Version20261109130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: bedelli malzemenin sabit bedeli (BILLABLE_PRICE)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_material ADD BILLABLE_PRICE NUMERIC(12, 4)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_material DROP COLUMN BILLABLE_PRICE');
    }
}
