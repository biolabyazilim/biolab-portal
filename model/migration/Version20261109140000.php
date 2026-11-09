<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * mlyt_study_supplier_rate.CURRENCY collation düzeltmesi.
 *
 * Sunucuda veritabanının varsayılan collation'ı Turkish_CI_AS; eski tablolar (mlyt_material_price, mlyt_study_rate)
 * Latin1_General_100_CI_AS_SC_UTF8 ile oluşmuştu. Yeni tablo varsayılanı aldığı için sr.CURRENCY = p.CURRENCY
 * karşılaştırması "Cannot resolve the collation conflict" hatası verdi. Kolon eski tablolarla aynı collation'a alınır
 * (geliştirmede zaten öyle; değişiklik yok). Kolon tekil indekste olduğu için indeks kaldırılıp yeniden oluşturulur.
 */
final class Version20261109140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: tedarikçi kuru para birimi kolonu eski tablolarla aynı collation (Latin1_General_100_CI_AS_SC_UTF8)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX UQ_mlyt_study_supplier_rate ON mlyt_study_supplier_rate');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate ALTER COLUMN CURRENCY NCHAR(3) COLLATE Latin1_General_100_CI_AS_SC_UTF8 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_study_supplier_rate ON mlyt_study_supplier_rate (STUDY_ID, SUPPLIER_ID, CURRENCY) WHERE STUDY_ID IS NOT NULL AND SUPPLIER_ID IS NOT NULL AND CURRENCY IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Geri almada collation değiştirilmez: eski hal sunucuya göre farklıydı ve hata veriyordu
    }
}
