<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016132506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mng_gender ve sample_acceptance tablolari (IDENTITY); cinsiyet kayitlari: 1 Kadin (F), 2 Erkek (M)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mng_gender (GENDER_ID SMALLINT IDENTITY NOT NULL, GENDER NVARCHAR(100) COLLATE Turkish_CI_AS, GENDER_ABR NVARCHAR(1), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (GENDER_ID))');
        $this->addSql('CREATE INDEX IDX_2A04269D1FFD8595 ON mng_gender (C_BY)');
        $this->addSql('CREATE INDEX IDX_2A04269D6A8F8DD6 ON mng_gender (U_BY)');
        $this->addSql('CREATE INDEX IDX_2A04269D822ABD2C ON mng_gender (D_BY)');
        $this->addSql('ALTER TABLE mng_gender ADD CONSTRAINT [DF_mng_gender_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_gender ADD CONSTRAINT [DF_mng_gender_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_gender ADD CONSTRAINT [DF_mng_gender_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE sample_acceptance (SAMPLE_ACCEPTANCE_ID BIGINT IDENTITY NOT NULL, BARCODE NVARCHAR(50) NOT NULL, PATIENT_NAME_SURNAME NVARCHAR(50) NOT NULL, FILE_NO NVARCHAR(50) NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, GENDER_ID SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (SAMPLE_ACCEPTANCE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9BC32BADA8EBFC7C ON sample_acceptance (BARCODE) WHERE BARCODE IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9BC32BAD78093B27 ON sample_acceptance (PATIENT_NAME_SURNAME) WHERE PATIENT_NAME_SURNAME IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9BC32BAD4C508ED9 ON sample_acceptance (FILE_NO) WHERE FILE_NO IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UQ_sample_acceptance_SAMPLE_ACCEPTANCE ON sample_acceptance (GENDER_ID, COMPANY_ID) WHERE GENDER_ID IS NOT NULL AND COMPANY_ID IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_9BC32BAD7B87F8EF ON sample_acceptance (GENDER_ID)');
        $this->addSql('CREATE INDEX IDX_9BC32BADA4494109 ON sample_acceptance (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_9BC32BAD1FFD8595 ON sample_acceptance (C_BY)');
        $this->addSql('CREATE INDEX IDX_9BC32BAD6A8F8DD6 ON sample_acceptance (U_BY)');
        $this->addSql('CREATE INDEX IDX_9BC32BAD822ABD2C ON sample_acceptance (D_BY)');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT [DF_sample_acceptance_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT [DF_sample_acceptance_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT [DF_sample_acceptance_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mng_gender ADD CONSTRAINT FK_2A04269D1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_gender ADD CONSTRAINT FK_2A04269D6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_gender ADD CONSTRAINT FK_2A04269D822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT FK_9BC32BAD7B87F8EF FOREIGN KEY (GENDER_ID) REFERENCES mng_gender (GENDER_ID)');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT FK_9BC32BADA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT FK_9BC32BAD1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT FK_9BC32BAD6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT FK_9BC32BAD822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');

        // Sabit cinsiyet kayıtları. GENDER_ID IDENTITY olduğu için numaralar ekleme sırasından gelir:
        // boş tabloda 1 = Kadın, 2 = Erkek
        $this->addSql("INSERT INTO mng_gender (GENDER, GENDER_ABR) VALUES (N'Kadın', 'F')");
        $this->addSql("INSERT INTO mng_gender (GENDER, GENDER_ABR) VALUES (N'Erkek', 'M')");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mng_gender DROP CONSTRAINT FK_2A04269D1FFD8595');
        $this->addSql('ALTER TABLE mng_gender DROP CONSTRAINT FK_2A04269D6A8F8DD6');
        $this->addSql('ALTER TABLE mng_gender DROP CONSTRAINT FK_2A04269D822ABD2C');
        $this->addSql('ALTER TABLE sample_acceptance DROP CONSTRAINT FK_9BC32BAD7B87F8EF');
        $this->addSql('ALTER TABLE sample_acceptance DROP CONSTRAINT FK_9BC32BADA4494109');
        $this->addSql('ALTER TABLE sample_acceptance DROP CONSTRAINT FK_9BC32BAD1FFD8595');
        $this->addSql('ALTER TABLE sample_acceptance DROP CONSTRAINT FK_9BC32BAD6A8F8DD6');
        $this->addSql('ALTER TABLE sample_acceptance DROP CONSTRAINT FK_9BC32BAD822ABD2C');
        $this->addSql('DROP TABLE mng_gender');
        $this->addSql('DROP TABLE sample_acceptance');
    }
}
