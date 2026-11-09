<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Uzmanın son versiyon Excel'ine göre (07.11.2026):
 * - mlyt_study_supplier_rate: tedarikçinin çalışma dönemi için sözleşme kuru (NUCLEUS EUR 44,7; her yıl belirlenir).
 * - mlyt_material.SHORT_CODE (patolojide TK, L, LM, NSİ, SPS, BYK) ve CATALOG_NO (tedarikçinin ürün kodu).
 * - mlyt_test.COST_NOTE: uzmanın hesap notu ("KANLI+EMB HESAPLAMASI", "2* ALP + peg").
 * - "Bedelli malzeme" kategorisi (doktorlara ürün bazlı fiyatlandırılan malzeme: numune kabı vb.).
 */
final class Version20261109065144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: tedarikçi sözleşme kuru, malzeme kısa kodu ve katalog no, test hesap notu, Bedelli malzeme kategorisi';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mlyt_study_supplier_rate (STUDY_SUPPLIER_RATE_ID INT IDENTITY NOT NULL, CURRENCY NCHAR(3) NOT NULL, RATE NUMERIC(12, 4) NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, STUDY_ID INT NOT NULL, SUPPLIER_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (STUDY_SUPPLIER_RATE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_study_supplier_rate ON mlyt_study_supplier_rate (STUDY_ID, SUPPLIER_ID, CURRENCY) WHERE STUDY_ID IS NOT NULL AND SUPPLIER_ID IS NOT NULL AND CURRENCY IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_E8B4F80919CED945 ON mlyt_study_supplier_rate (STUDY_ID)');
        $this->addSql('CREATE INDEX IDX_E8B4F8099F93B984 ON mlyt_study_supplier_rate (SUPPLIER_ID)');
        $this->addSql('CREATE INDEX IDX_E8B4F8091FFD8595 ON mlyt_study_supplier_rate (C_BY)');
        $this->addSql('CREATE INDEX IDX_E8B4F8096A8F8DD6 ON mlyt_study_supplier_rate (U_BY)');
        $this->addSql('CREATE INDEX IDX_E8B4F809822ABD2C ON mlyt_study_supplier_rate (D_BY)');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate ADD CONSTRAINT [DF_mlyt_study_supplier_rate_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate ADD CONSTRAINT [DF_mlyt_study_supplier_rate_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate ADD CONSTRAINT [DF_mlyt_study_supplier_rate_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate ADD CONSTRAINT FK_E8B4F80919CED945 FOREIGN KEY (STUDY_ID) REFERENCES mlyt_study (STUDY_ID)');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate ADD CONSTRAINT FK_E8B4F8099F93B984 FOREIGN KEY (SUPPLIER_ID) REFERENCES mlyt_supplier (SUPPLIER_ID)');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate ADD CONSTRAINT FK_E8B4F8091FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate ADD CONSTRAINT FK_E8B4F8096A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate ADD CONSTRAINT FK_E8B4F809822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material ADD SHORT_CODE NVARCHAR(10) COLLATE Turkish_CI_AS');
        $this->addSql('ALTER TABLE mlyt_material ADD CATALOG_NO NVARCHAR(30)');
        $this->addSql('ALTER TABLE mlyt_test ADD COST_NOTE NVARCHAR(2000) COLLATE Turkish_CI_AS');

        $this->addSql("INSERT INTO mlyt_material_category (CATEGORY_CODE, CATEGORY_NAME, CATEGORY_NAME_EN, SORT_NO) VALUES
            ('BILLABLE', N'Bedelli malzeme', 'Billable supply', 70)");

        // Patoloji kısa kodları (Excel notu "2 TK, 2L, 2 LM, NSİ"); malzeme yoksa güncellenmez
        $this->addSql("UPDATE m SET SHORT_CODE = v.CODE
              FROM mlyt_material m
              JOIN (VALUES (N'TK',  N'DOKU TAKİP KASETİ'),
                           (N'L',   N'LAM 50 Lİ DÜZ LAM /ISOTHERM'),
                           (N'LM',  N'LAMEL 24*60 TEKPATH'),
                           (N'NSİ', N'NSİ(NON STERİL İDRAR KABI)'),
                           (N'SPS', N'SPS(MİNİK PAT. KABI) -15 CC FORMOLLÜ KAP- MİDE BİYOPSİ KABI'),
                           (N'BYK', N'BYK( BÜYÜK PAT.KABI)')) v (CODE, NAME) ON m.MATERIAL_NAME = v.NAME
             WHERE m.RECORD_FL > 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE mlyt_material SET CATEGORY_ID = (SELECT CATEGORY_ID FROM mlyt_material_category WHERE CATEGORY_CODE = 'OTHER')
                        WHERE CATEGORY_ID = (SELECT CATEGORY_ID FROM mlyt_material_category WHERE CATEGORY_CODE = 'BILLABLE')");
        $this->addSql("DELETE FROM mlyt_material_category WHERE CATEGORY_CODE = 'BILLABLE'");
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate DROP CONSTRAINT FK_E8B4F80919CED945');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate DROP CONSTRAINT FK_E8B4F8099F93B984');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate DROP CONSTRAINT FK_E8B4F8091FFD8595');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate DROP CONSTRAINT FK_E8B4F8096A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_study_supplier_rate DROP CONSTRAINT FK_E8B4F809822ABD2C');
        $this->addSql('DROP TABLE mlyt_study_supplier_rate');
        $this->addSql('ALTER TABLE mlyt_material DROP COLUMN SHORT_CODE');
        $this->addSql('ALTER TABLE mlyt_material DROP COLUMN CATALOG_NO');
        $this->addSql('ALTER TABLE mlyt_test DROP COLUMN COST_NOTE');
    }
}
