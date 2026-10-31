<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015083423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MNG: mng_user, mng_language, mng_company (ortak audit kolonlari ile)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mng_company (COMPANY_ID BIGINT IDENTITY NOT NULL, CODE NVARCHAR(20) NOT NULL, COMPANY_NAME NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, TAX_NUMBER NVARCHAR(20), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, LANGUAGE_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (COMPANY_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_593E85264180DD2C ON mng_company (CODE) WHERE CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_593E852637BF6EFC ON mng_company (LANGUAGE_ID)');
        $this->addSql('CREATE INDEX IDX_593E85261FFD8595 ON mng_company (C_BY)');
        $this->addSql('CREATE INDEX IDX_593E85266A8F8DD6 ON mng_company (U_BY)');
        $this->addSql('CREATE INDEX IDX_593E8526822ABD2C ON mng_company (D_BY)');
        $this->addSql('ALTER TABLE mng_company ADD CONSTRAINT [DF_mng_company_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_company ADD CONSTRAINT [DF_mng_company_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mng_language (LANGUAGE_ID INT IDENTITY NOT NULL, CODE NVARCHAR(5) NOT NULL, NAME NVARCHAR(50) COLLATE Turkish_CI_AS NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (LANGUAGE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E0A329C54180DD2C ON mng_language (CODE) WHERE CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_E0A329C51FFD8595 ON mng_language (C_BY)');
        $this->addSql('CREATE INDEX IDX_E0A329C56A8F8DD6 ON mng_language (U_BY)');
        $this->addSql('CREATE INDEX IDX_E0A329C5822ABD2C ON mng_language (D_BY)');
        $this->addSql('ALTER TABLE mng_language ADD CONSTRAINT [DF_mng_language_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_language ADD CONSTRAINT [DF_mng_language_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mng_user (USER_ID BIGINT IDENTITY NOT NULL, USERNAME NVARCHAR(50) NOT NULL, FULL_NAME NVARCHAR(100) COLLATE Turkish_CI_AS NOT NULL, PASSWORD_HASH NVARCHAR(255) NOT NULL, EMAIL NVARCHAR(150), LAST_LOGIN_AT DATETIME2(6), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (USER_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F62033163E6D9A3B ON mng_user (USERNAME) WHERE USERNAME IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_F62033161FFD8595 ON mng_user (C_BY)');
        $this->addSql('CREATE INDEX IDX_F62033166A8F8DD6 ON mng_user (U_BY)');
        $this->addSql('CREATE INDEX IDX_F6203316822ABD2C ON mng_user (D_BY)');
        $this->addSql('ALTER TABLE mng_user ADD CONSTRAINT [DF_mng_user_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_user ADD CONSTRAINT [DF_mng_user_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mng_company ADD CONSTRAINT FK_593E852637BF6EFC FOREIGN KEY (LANGUAGE_ID) REFERENCES mng_language (LANGUAGE_ID)');
        $this->addSql('ALTER TABLE mng_company ADD CONSTRAINT FK_593E85261FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_company ADD CONSTRAINT FK_593E85266A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_company ADD CONSTRAINT FK_593E8526822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_language ADD CONSTRAINT FK_E0A329C51FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_language ADD CONSTRAINT FK_E0A329C56A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_language ADD CONSTRAINT FK_E0A329C5822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user ADD CONSTRAINT FK_F62033161FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user ADD CONSTRAINT FK_F62033166A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user ADD CONSTRAINT FK_F6203316822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mng_company DROP CONSTRAINT FK_593E852637BF6EFC');
        $this->addSql('ALTER TABLE mng_company DROP CONSTRAINT FK_593E85261FFD8595');
        $this->addSql('ALTER TABLE mng_company DROP CONSTRAINT FK_593E85266A8F8DD6');
        $this->addSql('ALTER TABLE mng_company DROP CONSTRAINT FK_593E8526822ABD2C');
        $this->addSql('ALTER TABLE mng_language DROP CONSTRAINT FK_E0A329C51FFD8595');
        $this->addSql('ALTER TABLE mng_language DROP CONSTRAINT FK_E0A329C56A8F8DD6');
        $this->addSql('ALTER TABLE mng_language DROP CONSTRAINT FK_E0A329C5822ABD2C');
        $this->addSql('ALTER TABLE mng_user DROP CONSTRAINT FK_F62033161FFD8595');
        $this->addSql('ALTER TABLE mng_user DROP CONSTRAINT FK_F62033166A8F8DD6');
        $this->addSql('ALTER TABLE mng_user DROP CONSTRAINT FK_F6203316822ABD2C');
        $this->addSql('DROP TABLE mng_company');
        $this->addSql('DROP TABLE mng_language');
        $this->addSql('DROP TABLE mng_user');
    }
}
