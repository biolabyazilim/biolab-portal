<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saha takip mesaileri: sht_shift. Mobil uygulamanın Mesaiye Başla / Bitir olayları (api/saha.php shiftStart / shiftEnd).
 * CLIENT_UUID tekil: telefon kuyruktan aynı olayı tekrar gönderirse çift kayıt oluşmaz.
 */
final class Version20261112072240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: sht_shift (saha personeli mesaileri)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sht_shift (SHIFT_ID INT IDENTITY NOT NULL, CLIENT_UUID NCHAR(36) NOT NULL, STARTED_AT DATETIME2(6) NOT NULL, START_LAT NUMERIC(9, 6), START_LON NUMERIC(9, 6), ENDED_AT DATETIME2(6), END_LAT NUMERIC(9, 6), END_LON NUMERIC(9, 6), END_REASON NVARCHAR(10), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, PERSONEL_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (SHIFT_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AAFFD2F24C212E28 ON sht_shift (CLIENT_UUID) WHERE CLIENT_UUID IS NOT NULL');
        $this->addSql('CREATE INDEX IX_sht_shift_PERSONEL_START ON sht_shift (PERSONEL_ID, STARTED_AT)');
        $this->addSql('CREATE INDEX IX_sht_shift_COMPANY_START ON sht_shift (COMPANY_ID, STARTED_AT)');
        $this->addSql('CREATE INDEX IDX_AAFFD2F2A4494109 ON sht_shift (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_AAFFD2F21D8D7B81 ON sht_shift (PERSONEL_ID)');
        $this->addSql('CREATE INDEX IDX_AAFFD2F21FFD8595 ON sht_shift (C_BY)');
        $this->addSql('CREATE INDEX IDX_AAFFD2F26A8F8DD6 ON sht_shift (U_BY)');
        $this->addSql('CREATE INDEX IDX_AAFFD2F2822ABD2C ON sht_shift (D_BY)');
        $this->addSql('ALTER TABLE sht_shift ADD CONSTRAINT [DF_sht_shift_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE sht_shift ADD CONSTRAINT [DF_sht_shift_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE sht_shift ADD CONSTRAINT [DF_sht_shift_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE sht_shift ADD CONSTRAINT FK_AAFFD2F2A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE sht_shift ADD CONSTRAINT FK_AAFFD2F21D8D7B81 FOREIGN KEY (PERSONEL_ID) REFERENCES sht_personel (PERSONEL_ID)');
        $this->addSql('ALTER TABLE sht_shift ADD CONSTRAINT FK_AAFFD2F21FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_shift ADD CONSTRAINT FK_AAFFD2F26A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_shift ADD CONSTRAINT FK_AAFFD2F2822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_shift DROP CONSTRAINT FK_AAFFD2F2A4494109');
        $this->addSql('ALTER TABLE sht_shift DROP CONSTRAINT FK_AAFFD2F21D8D7B81');
        $this->addSql('ALTER TABLE sht_shift DROP CONSTRAINT FK_AAFFD2F21FFD8595');
        $this->addSql('ALTER TABLE sht_shift DROP CONSTRAINT FK_AAFFD2F26A8F8DD6');
        $this->addSql('ALTER TABLE sht_shift DROP CONSTRAINT FK_AAFFD2F2822ABD2C');
        $this->addSql('DROP TABLE sht_shift');
    }
}
