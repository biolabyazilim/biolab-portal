<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saha takip (SHT) ilk tabloları:
 *   sht_personel     → saha personeli; portal kullanıcısına (mng_user) bağlı, PERSONEL_CODE = Traccar Identifier
 *   sht_device_token → mobil uygulama oturumları (token özeti SHA-256, süre, iptal)
 * Rol bir sonraki migration'da (Version20261111113000).
 */
final class Version20261111112744 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SHT: sht_personel, sht_device_token (saha takip mobil girişi)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sht_device_token (TOKEN_ID INT IDENTITY NOT NULL, TOKEN_HASH NCHAR(64) NOT NULL, DEVICE_INFO NVARCHAR(200) COLLATE Turkish_CI_AS, APP_VERSION NVARCHAR(20), LAST_SEEN_AT DATETIME2(6), EXPIRES_AT DATETIME2(6) NOT NULL, REVOKED_AT DATETIME2(6), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, PERSONEL_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (TOKEN_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A8FC9CF379E265E1 ON sht_device_token (TOKEN_HASH) WHERE TOKEN_HASH IS NOT NULL');
        $this->addSql('CREATE INDEX IX_sht_device_token_PERSONEL ON sht_device_token (PERSONEL_ID, REVOKED_AT)');
        $this->addSql('CREATE INDEX IDX_A8FC9CF3A4494109 ON sht_device_token (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_A8FC9CF31D8D7B81 ON sht_device_token (PERSONEL_ID)');
        $this->addSql('CREATE INDEX IDX_A8FC9CF31FFD8595 ON sht_device_token (C_BY)');
        $this->addSql('CREATE INDEX IDX_A8FC9CF36A8F8DD6 ON sht_device_token (U_BY)');
        $this->addSql('CREATE INDEX IDX_A8FC9CF3822ABD2C ON sht_device_token (D_BY)');
        $this->addSql('ALTER TABLE sht_device_token ADD CONSTRAINT [DF_sht_device_token_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE sht_device_token ADD CONSTRAINT [DF_sht_device_token_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE sht_device_token ADD CONSTRAINT [DF_sht_device_token_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE sht_personel (PERSONEL_ID INT IDENTITY NOT NULL, PERSONEL_CODE NVARCHAR(20) NOT NULL, TRACCAR_DEVICE_ID INT, PHONE NVARCHAR(20), JOB_TYPE NVARCHAR(50) COLLATE Turkish_CI_AS, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, USER_ID BIGINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (PERSONEL_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_609B3EBA55DD3E57 ON sht_personel (PERSONEL_CODE) WHERE PERSONEL_CODE IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_609B3EBAA0666B6F ON sht_personel (USER_ID) WHERE USER_ID IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_609B3EBAA4494109 ON sht_personel (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_609B3EBA1FFD8595 ON sht_personel (C_BY)');
        $this->addSql('CREATE INDEX IDX_609B3EBA6A8F8DD6 ON sht_personel (U_BY)');
        $this->addSql('CREATE INDEX IDX_609B3EBA822ABD2C ON sht_personel (D_BY)');
        $this->addSql('ALTER TABLE sht_personel ADD CONSTRAINT [DF_sht_personel_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE sht_personel ADD CONSTRAINT [DF_sht_personel_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE sht_personel ADD CONSTRAINT [DF_sht_personel_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE sht_device_token ADD CONSTRAINT FK_A8FC9CF3A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE sht_device_token ADD CONSTRAINT FK_A8FC9CF31D8D7B81 FOREIGN KEY (PERSONEL_ID) REFERENCES sht_personel (PERSONEL_ID)');
        $this->addSql('ALTER TABLE sht_device_token ADD CONSTRAINT FK_A8FC9CF31FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_device_token ADD CONSTRAINT FK_A8FC9CF36A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_device_token ADD CONSTRAINT FK_A8FC9CF3822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_personel ADD CONSTRAINT FK_609B3EBAA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE sht_personel ADD CONSTRAINT FK_609B3EBAA0666B6F FOREIGN KEY (USER_ID) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_personel ADD CONSTRAINT FK_609B3EBA1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_personel ADD CONSTRAINT FK_609B3EBA6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE sht_personel ADD CONSTRAINT FK_609B3EBA822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sht_device_token DROP CONSTRAINT FK_A8FC9CF3A4494109');
        $this->addSql('ALTER TABLE sht_device_token DROP CONSTRAINT FK_A8FC9CF31D8D7B81');
        $this->addSql('ALTER TABLE sht_device_token DROP CONSTRAINT FK_A8FC9CF31FFD8595');
        $this->addSql('ALTER TABLE sht_device_token DROP CONSTRAINT FK_A8FC9CF36A8F8DD6');
        $this->addSql('ALTER TABLE sht_device_token DROP CONSTRAINT FK_A8FC9CF3822ABD2C');
        $this->addSql('ALTER TABLE sht_personel DROP CONSTRAINT FK_609B3EBAA4494109');
        $this->addSql('ALTER TABLE sht_personel DROP CONSTRAINT FK_609B3EBAA0666B6F');
        $this->addSql('ALTER TABLE sht_personel DROP CONSTRAINT FK_609B3EBA1FFD8595');
        $this->addSql('ALTER TABLE sht_personel DROP CONSTRAINT FK_609B3EBA6A8F8DD6');
        $this->addSql('ALTER TABLE sht_personel DROP CONSTRAINT FK_609B3EBA822ABD2C');
        $this->addSql('DROP TABLE sht_device_token');
        $this->addSql('DROP TABLE sht_personel');
    }
}
