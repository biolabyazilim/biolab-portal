<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020072030 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'device_result: eslestirme izi (MATCH_TYPE, MATCH_NOTE, MATCHED_AT, MATCHED_BY); mevcut bagli sonuclar AUTO';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE device_result ADD MATCH_TYPE NVARCHAR(10)');
        $this->addSql('ALTER TABLE device_result ADD MATCH_NOTE NVARCHAR(200) COLLATE Turkish_CI_AS');
        $this->addSql('ALTER TABLE device_result ADD MATCHED_AT DATETIME2(6)');
        $this->addSql('ALTER TABLE device_result ADD MATCHED_BY BIGINT');
        $this->addSql('ALTER TABLE device_result ADD CONSTRAINT FK_C07AB00835DE5CF2 FOREIGN KEY (MATCHED_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('CREATE INDEX IDX_C07AB00835DE5CF2 ON device_result (MATCHED_BY)');
        // Daha önce barkodla bağlanmış sonuçlar otomatik eşleşme sayılır
        $this->addSql("UPDATE device_result SET MATCH_TYPE = 'AUTO', MATCHED_AT = COALESCE(U_AT, C_AT) WHERE SAMPLE_ACCEPTANCE_ID IS NOT NULL AND MATCH_TYPE IS NULL");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE device_result DROP CONSTRAINT FK_C07AB00835DE5CF2');
        $this->addSql('DROP INDEX IDX_C07AB00835DE5CF2 ON device_result');
        $this->addSql('ALTER TABLE device_result DROP COLUMN MATCH_TYPE');
        $this->addSql('ALTER TABLE device_result DROP COLUMN MATCH_NOTE');
        $this->addSql('ALTER TABLE device_result DROP COLUMN MATCHED_AT');
        $this->addSql('ALTER TABLE device_result DROP COLUMN MATCHED_BY');
    }
}
