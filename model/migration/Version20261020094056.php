<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020094056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'rapor PDF arşivi: sample_report_file; mng_user TITLE (unvan)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sample_report_file (SAMPLE_REPORT_FILE_ID BIGINT IDENTITY NOT NULL, FILE_NAME NVARCHAR(120) NOT NULL, FILE_SIZE INT NOT NULL, SHA256 NCHAR(64) NOT NULL, CONTENT VARBINARY(MAX) NOT NULL, CREATED_AT DATETIME2(6) NOT NULL, SAMPLE_REPORT_ID BIGINT NOT NULL, PRIMARY KEY (SAMPLE_REPORT_FILE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_sample_report_file_REPORT ON sample_report_file (SAMPLE_REPORT_ID) WHERE SAMPLE_REPORT_ID IS NOT NULL');
        $this->addSql('ALTER TABLE sample_report_file ADD CONSTRAINT FK_E9CED76B8ADE5DF5 FOREIGN KEY (SAMPLE_REPORT_ID) REFERENCES sample_report (SAMPLE_REPORT_ID)');
        $this->addSql('ALTER TABLE mng_user ADD TITLE NVARCHAR(50) COLLATE Turkish_CI_AS');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sample_report_file DROP CONSTRAINT FK_E9CED76B8ADE5DF5');
        $this->addSql('DROP TABLE sample_report_file');
        $this->addSql('ALTER TABLE mng_user DROP COLUMN TITLE');
    }
}
