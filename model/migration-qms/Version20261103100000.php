<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * QMS doküman klasörleri (qms_doc_category) ve doküman tipinin klasöre bağlanması.
 * Klasörler ortak klasördeki düzenle birebir aynıdır: KALİTE DOKÜMANLARI › DIŞ KAYNAKLI DÖKÜMANLAR … TALİMATLAR.
 * qms_doc_type'a CATEGORY_ID (zorunlu) ve EXTERNAL_FL (dış kaynaklı doküman) eklenir.
 * Not: down()'daki CREATE SCHEMA db_* satırları silindi (sa ile yapılan diff sistem şemalarını fark sanıyor).
 */
final class Version20261103100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'QMS: doküman klasörleri (kategori), doküman tipine klasör ve dış kaynaklı işareti; KALİTE DOKÜMANLARI klasörleri';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE qms_doc_category (CATEGORY_ID INT IDENTITY NOT NULL, CODE NVARCHAR(30) NOT NULL, CATEGORY_NAME NVARCHAR(150) COLLATE Turkish_CI_AS NOT NULL, SORT_NO SMALLINT NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, PARENT_ID INT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (CATEGORY_ID))');
        $this->addSql('CREATE INDEX IX_qms_doc_category_PARENT ON qms_doc_category (PARENT_ID, SORT_NO)');
        $this->addSql('CREATE UNIQUE INDEX UQ_qms_doc_category_COMPANY_CODE ON qms_doc_category (COMPANY_ID, CODE) WHERE COMPANY_ID IS NOT NULL AND CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_875CE81DA4494109 ON qms_doc_category (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_875CE81DEF5927F ON qms_doc_category (PARENT_ID)');
        $this->addSql('CREATE INDEX IDX_875CE81D1FFD8595 ON qms_doc_category (C_BY)');
        $this->addSql('CREATE INDEX IDX_875CE81D6A8F8DD6 ON qms_doc_category (U_BY)');
        $this->addSql('CREATE INDEX IDX_875CE81D822ABD2C ON qms_doc_category (D_BY)');
        $this->addSql('ALTER TABLE qms_doc_category ADD CONSTRAINT [DF_qms_doc_category_SORT_NO] DEFAULT 0 FOR SORT_NO');
        $this->addSql('ALTER TABLE qms_doc_category ADD CONSTRAINT [DF_qms_doc_category_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE qms_doc_category ADD CONSTRAINT [DF_qms_doc_category_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE qms_doc_category ADD CONSTRAINT [DF_qms_doc_category_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE qms_doc_category ADD CONSTRAINT FK_875CE81DA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE qms_doc_category ADD CONSTRAINT FK_875CE81DEF5927F FOREIGN KEY (PARENT_ID) REFERENCES qms_doc_category (CATEGORY_ID)');
        $this->addSql('ALTER TABLE qms_doc_category ADD CONSTRAINT FK_875CE81D1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE qms_doc_category ADD CONSTRAINT FK_875CE81D6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE qms_doc_category ADD CONSTRAINT FK_875CE81D822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE qms_doc_type ADD EXTERNAL_FL NCHAR(1) NOT NULL CONSTRAINT [DF_qms_doc_type_EXTERNAL_FL] DEFAULT \'N\'');
        $this->addSql('ALTER TABLE qms_doc_type ADD CATEGORY_ID INT NOT NULL');
        $this->addSql('ALTER TABLE qms_doc_type ADD CONSTRAINT FK_5F095F61A70849EA FOREIGN KEY (CATEGORY_ID) REFERENCES qms_doc_category (CATEGORY_ID)');
        $this->addSql('CREATE INDEX IDX_5F095F61A70849EA ON qms_doc_type (CATEGORY_ID)');
        // Klasörler: ortak klasördeki "YÜRÜRLÜKTEKİ DOSYALAR › KALİTE DOKÜMANLARI" düzeni (ad ve sıra aynı)
        $this->addSql("INSERT INTO qms_doc_category (COMPANY_ID, PARENT_ID, CODE, CATEGORY_NAME, SORT_NO)
            SELECT COMPANY_ID, NULL, 'KALITE', N'KALİTE DOKÜMANLARI', 10 FROM mng_company WHERE CODE = 'BIOLAB'");
        foreach ([
            ['DIS_KAYNAKLI', 'DIŞ KAYNAKLI DÖKÜMANLAR', 10],
            ['FORM',         'FORMLAR',                 20],
            ['GOREV_TANIMI', 'GÖREV TANIMLARI',         30],
            ['LISTE',        'LİSTELER',                40],
            ['PLAN',         'PLANLAR',                 50],
            ['PROSEDUR',     'PROSEDÜRLER',             60],
            ['PROSES',       'PROSESLER',               70],
            ['REHBER',       'REHBERLER',               80],
            ['SOP',          'SOP',                     90],
            ['TALIMAT',      'TALİMATLAR',             100],
        ] as [$code, $name, $sort]) {
            $this->addSql("INSERT INTO qms_doc_category (COMPANY_ID, PARENT_ID, CODE, CATEGORY_NAME, SORT_NO)
                SELECT p.COMPANY_ID, p.CATEGORY_ID, '{$code}', N'{$name}', {$sort}
                  FROM qms_doc_category p JOIN mng_company c ON c.COMPANY_ID = p.COMPANY_ID
                 WHERE p.CODE = 'KALITE' AND c.CODE = 'BIOLAB'");
        }
    }

    public function down(Schema $schema): void
    {
        // Önce qms_doc_type'ın klasöre bağlantısı kaldırılır (diff sırası tersti: tablo, ona bağlı FK dururken silinemez).
        // Tablo silinince klasör başlangıç verisi de gider.
        $this->addSql('ALTER TABLE qms_doc_type DROP CONSTRAINT FK_5F095F61A70849EA');
        $this->addSql('DROP INDEX IDX_5F095F61A70849EA ON qms_doc_type');
        $this->addSql('ALTER TABLE qms_doc_type DROP CONSTRAINT [DF_qms_doc_type_EXTERNAL_FL]');
        $this->addSql('ALTER TABLE qms_doc_type DROP COLUMN EXTERNAL_FL');
        $this->addSql('ALTER TABLE qms_doc_type DROP COLUMN CATEGORY_ID');
        $this->addSql('ALTER TABLE qms_doc_category DROP CONSTRAINT FK_875CE81DA4494109');
        $this->addSql('ALTER TABLE qms_doc_category DROP CONSTRAINT FK_875CE81DEF5927F');
        $this->addSql('ALTER TABLE qms_doc_category DROP CONSTRAINT FK_875CE81D1FFD8595');
        $this->addSql('ALTER TABLE qms_doc_category DROP CONSTRAINT FK_875CE81D6A8F8DD6');
        $this->addSql('ALTER TABLE qms_doc_category DROP CONSTRAINT FK_875CE81D822ABD2C');
        $this->addSql('DROP TABLE qms_doc_category');
    }
}
