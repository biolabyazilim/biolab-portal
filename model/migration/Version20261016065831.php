<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016065831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mng_user_company: kullanici-sirket baglantisi (DEFAULT_FL varsayilan sirket); ssadmin -> BIOLAB';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mng_user_company (USER_COMPANY_ID BIGINT IDENTITY NOT NULL, DEFAULT_FL NCHAR(1) NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, USER_ID BIGINT NOT NULL, COMPANY_ID BIGINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (USER_COMPANY_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mng_user_company_USER_COMPANY ON mng_user_company (USER_ID, COMPANY_ID) WHERE USER_ID IS NOT NULL AND COMPANY_ID IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_D870CF9AA0666B6F ON mng_user_company (USER_ID)');
        $this->addSql('CREATE INDEX IDX_D870CF9AA4494109 ON mng_user_company (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_D870CF9A1FFD8595 ON mng_user_company (C_BY)');
        $this->addSql('CREATE INDEX IDX_D870CF9A6A8F8DD6 ON mng_user_company (U_BY)');
        $this->addSql('CREATE INDEX IDX_D870CF9A822ABD2C ON mng_user_company (D_BY)');
        $this->addSql('ALTER TABLE mng_user_company ADD CONSTRAINT [DF_mng_user_company_DEFAULT_FL] DEFAULT \'N\' FOR DEFAULT_FL');
        $this->addSql('ALTER TABLE mng_user_company ADD CONSTRAINT [DF_mng_user_company_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_user_company ADD CONSTRAINT [DF_mng_user_company_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mng_user_company ADD CONSTRAINT [DF_mng_user_company_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mng_user_company ADD CONSTRAINT FK_D870CF9AA0666B6F FOREIGN KEY (USER_ID) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user_company ADD CONSTRAINT FK_D870CF9AA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE mng_user_company ADD CONSTRAINT FK_D870CF9A1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user_company ADD CONSTRAINT FK_D870CF9A6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mng_user_company ADD CONSTRAINT FK_D870CF9A822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');

        // Mevcut kullanıcılar (ssadmin) BIOLAB şirketine varsayılan olarak bağlanır; yoksa girişte şirket bulunamaz
        $this->addSql("INSERT INTO mng_user_company (USER_ID, COMPANY_ID, DEFAULT_FL)
                       SELECT u.USER_ID, c.COMPANY_ID, 'Y'
                         FROM mng_user u CROSS JOIN mng_company c
                        WHERE c.CODE = 'BIOLAB'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mng_user_company DROP CONSTRAINT FK_D870CF9AA0666B6F');
        $this->addSql('ALTER TABLE mng_user_company DROP CONSTRAINT FK_D870CF9AA4494109');
        $this->addSql('ALTER TABLE mng_user_company DROP CONSTRAINT FK_D870CF9A1FFD8595');
        $this->addSql('ALTER TABLE mng_user_company DROP CONSTRAINT FK_D870CF9A6A8F8DD6');
        $this->addSql('ALTER TABLE mng_user_company DROP CONSTRAINT FK_D870CF9A822ABD2C');
        $this->addSql('DROP TABLE mng_user_company');
    }
}
