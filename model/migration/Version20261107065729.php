<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Şirket tipi ve şube yapısı.
 *   mng_company_type: şirket tipleri (MERKEZ = Merkez, SUBE = Şube)
 *   mng_company.COMPANY_TYPE_ID → mng_company_type, mng_company.PARENT_COMPANY_ID → mng_company (şubenin bağlı olduğu şirket)
 * Var olan şirketler Merkez tipine alınır (şu an hepsi üst şirketi olmayan ana şirketler).
 */
final class Version20261107065729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MNG: mng_company_type (Merkez, Şube); mng_company.COMPANY_TYPE_ID ve PARENT_COMPANY_ID; var olan şirketler → Merkez';
    }

    public function up(Schema $schema): void
    {
        // Şirket tipi tablosu (Doctrine diff)
        $this->addSql('CREATE TABLE mng_company_type (COMPANY_TYPE_ID INT IDENTITY NOT NULL, CODE NVARCHAR(20) NOT NULL, COMPANY_TYPE_NAME NVARCHAR(100) COLLATE Turkish_CI_AS NOT NULL, COMPANY_TYPE_NAME_EN NVARCHAR(100), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (COMPANY_TYPE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7195184180DD2C ON mng_company_type (CODE) WHERE CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_7195181FFD8595 ON mng_company_type (C_BY)');
        $this->addSql('CREATE INDEX IDX_7195186A8F8DD6 ON mng_company_type (U_BY)');
        $this->addSql('CREATE INDEX IDX_719518822ABD2C ON mng_company_type (D_BY)');
        $this->addSql('ALTER TABLE mng_company_type ADD CONSTRAINT [DF_mng_company_type_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_company_type ADD CONSTRAINT [DF_mng_company_type_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_company_type ADD CONSTRAINT [DF_mng_company_type_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mng_company_type ADD CONSTRAINT FK_7195181FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_company_type ADD CONSTRAINT FK_7195186A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_company_type ADD CONSTRAINT FK_719518822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');

        // Şirkete tip ve üst şirket (Doctrine diff)
        $this->addSql('ALTER TABLE mng_company ADD COMPANY_TYPE_ID INT');
        $this->addSql('ALTER TABLE mng_company ADD PARENT_COMPANY_ID BIGINT');
        $this->addSql('ALTER TABLE mng_company ADD CONSTRAINT FK_593E852647C8F651 FOREIGN KEY (COMPANY_TYPE_ID) REFERENCES mng_company_type (COMPANY_TYPE_ID)');
        $this->addSql('ALTER TABLE mng_company ADD CONSTRAINT FK_593E852634785A95 FOREIGN KEY (PARENT_COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_593E852647C8F651 ON mng_company (COMPANY_TYPE_ID)');
        $this->addSql('CREATE INDEX IDX_593E852634785A95 ON mng_company (PARENT_COMPANY_ID)');

        // Başlangıç verisi: tipler (kodlar sabit, CompanyType::HEADQUARTERS / BRANCH)
        $this->addSql("INSERT INTO mng_company_type (CODE, COMPANY_TYPE_NAME, COMPANY_TYPE_NAME_EN) VALUES
            ('MERKEZ', N'Merkez', 'Headquarters'),
            ('SUBE',   N'Şube',   'Branch')");

        // Var olan şirketler üst şirketi olmayan ana şirketler: Merkez
        $this->addSql("UPDATE c SET c.COMPANY_TYPE_ID = t.COMPANY_TYPE_ID
              FROM mng_company c CROSS JOIN mng_company_type t
             WHERE t.CODE = 'MERKEZ' AND c.COMPANY_TYPE_ID IS NULL");
    }

    public function down(Schema $schema): void
    {
        // Önce şirketteki bağlar, sonra tip tablosu (FK sırası)
        $this->addSql('ALTER TABLE mng_company DROP CONSTRAINT FK_593E852647C8F651');
        $this->addSql('ALTER TABLE mng_company DROP CONSTRAINT FK_593E852634785A95');
        $this->addSql('DROP INDEX IDX_593E852647C8F651 ON mng_company');
        $this->addSql('DROP INDEX IDX_593E852634785A95 ON mng_company');
        $this->addSql('ALTER TABLE mng_company DROP COLUMN COMPANY_TYPE_ID');
        $this->addSql('ALTER TABLE mng_company DROP COLUMN PARENT_COMPANY_ID');
        $this->addSql('ALTER TABLE mng_company_type DROP CONSTRAINT FK_7195181FFD8595');
        $this->addSql('ALTER TABLE mng_company_type DROP CONSTRAINT FK_7195186A8F8DD6');
        $this->addSql('ALTER TABLE mng_company_type DROP CONSTRAINT FK_719518822ABD2C');
        $this->addSql('DROP TABLE mng_company_type');
    }
}
