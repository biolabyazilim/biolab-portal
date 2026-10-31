<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Malzeme kategorileri tabloya taşındı: mlyt_material.CATEGORY (metin) → CATEGORY_ID (mlyt_material_category).
 * diff ile üretildi; elle düzenlendi:
 *   - kategoriler eklenir ve mevcut malzemelerin metin kategorisi koda göre eşlenir (tabloda kayıt varsa da çalışır)
 *   - down() sırası düzeltildi (önce FK, sonra tablo) ve DBAL'in ürettiği "CREATE SCHEMA db_owner..." satırları çıkarıldı
 * İndeks / FK adları diff'in ürettiği gibi bırakıldı ki sonraki diff fark görmesin.
 */
final class Version20261024091353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: mlyt_material_category tablosu ve başlangıç kategorileri; mlyt_material.CATEGORY → CATEGORY_ID';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mlyt_material_category (CATEGORY_ID INT IDENTITY NOT NULL, CATEGORY_CODE NVARCHAR(20) NOT NULL, CATEGORY_NAME NVARCHAR(100) COLLATE Turkish_CI_AS NOT NULL, CATEGORY_NAME_EN NVARCHAR(100), SORT_NO SMALLINT NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (CATEGORY_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1E2EA161B03673E ON mlyt_material_category (CATEGORY_CODE) WHERE CATEGORY_CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_1E2EA161FFD8595 ON mlyt_material_category (C_BY)');
        $this->addSql('CREATE INDEX IDX_1E2EA166A8F8DD6 ON mlyt_material_category (U_BY)');
        $this->addSql('CREATE INDEX IDX_1E2EA16822ABD2C ON mlyt_material_category (D_BY)');
        $this->addSql('ALTER TABLE mlyt_material_category ADD CONSTRAINT [DF_mlyt_material_category_SORT_NO] DEFAULT 0 FOR SORT_NO');
        $this->addSql('ALTER TABLE mlyt_material_category ADD CONSTRAINT [DF_mlyt_material_category_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_material_category ADD CONSTRAINT [DF_mlyt_material_category_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_material_category ADD CONSTRAINT [DF_mlyt_material_category_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mlyt_material_category ADD CONSTRAINT FK_1E2EA161FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material_category ADD CONSTRAINT FK_1E2EA166A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material_category ADD CONSTRAINT FK_1E2EA16822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');

        // Başlangıç kategorileri (önceden ekranda sabit dizi olarak duranlar; kodlar aynı kaldı)
        $this->addSql("INSERT INTO mlyt_material_category (CATEGORY_CODE, CATEGORY_NAME, CATEGORY_NAME_EN, SORT_NO) VALUES
            ('KIT',        N'Kit',                  'Kit',                  10),
            ('CONSUMABLE', N'Sarf',                 'Consumable',           20),
            ('MEDIA',      N'Besiyeri',             'Culture medium',       30),
            ('CHEMICAL',   N'Boya / kimyasal',      'Stain / chemical',     40),
            ('CONTROL',    N'Kontrol / kalibratör', 'Control / calibrator', 50),
            ('SERVICE',    N'Hizmet',               'Service',              60),
            ('OTHER',      N'Diğer',                'Other',                90)");

        // Kolon önce boş bırakılabilir eklenir, eski metin değerinden doldurulur, sonra zorunlu yapılır
        $this->addSql('ALTER TABLE mlyt_material ADD CATEGORY_ID INT NULL');
        $this->addSql("UPDATE m SET m.CATEGORY_ID = COALESCE(c.CATEGORY_ID, o.CATEGORY_ID)
                         FROM mlyt_material m
                         LEFT JOIN mlyt_material_category c ON c.CATEGORY_CODE = m.CATEGORY
                         CROSS JOIN mlyt_material_category o
                        WHERE o.CATEGORY_CODE = 'OTHER'");
        $this->addSql('ALTER TABLE mlyt_material ALTER COLUMN CATEGORY_ID INT NOT NULL');
        $this->addSql('ALTER TABLE mlyt_material DROP CONSTRAINT [DF_mlyt_material_CATEGORY]');
        $this->addSql('ALTER TABLE mlyt_material DROP COLUMN CATEGORY');
        $this->addSql('ALTER TABLE mlyt_material ADD CONSTRAINT FK_A549B0E4A70849EA FOREIGN KEY (CATEGORY_ID) REFERENCES mlyt_material_category (CATEGORY_ID)');
        $this->addSql('CREATE INDEX IDX_A549B0E4A70849EA ON mlyt_material (CATEGORY_ID)');
    }

    public function down(Schema $schema): void
    {
        // Metin kolonu geri gelir, kod üzerinden doldurulur; sonra bağlantı ve kategori tablosu kaldırılır
        $this->addSql('ALTER TABLE mlyt_material ADD CATEGORY NVARCHAR(20) NOT NULL CONSTRAINT [DF_mlyt_material_CATEGORY] DEFAULT \'OTHER\'');
        $this->addSql('UPDATE m SET m.CATEGORY = c.CATEGORY_CODE FROM mlyt_material m JOIN mlyt_material_category c ON c.CATEGORY_ID = m.CATEGORY_ID');
        $this->addSql('ALTER TABLE mlyt_material DROP CONSTRAINT FK_A549B0E4A70849EA');
        $this->addSql('DROP INDEX IDX_A549B0E4A70849EA ON mlyt_material');
        $this->addSql('ALTER TABLE mlyt_material DROP COLUMN CATEGORY_ID');
        $this->addSql('ALTER TABLE mlyt_material_category DROP CONSTRAINT FK_1E2EA161FFD8595');
        $this->addSql('ALTER TABLE mlyt_material_category DROP CONSTRAINT FK_1E2EA166A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_material_category DROP CONSTRAINT FK_1E2EA16822ABD2C');
        $this->addSql('DROP TABLE mlyt_material_category');
    }
}
