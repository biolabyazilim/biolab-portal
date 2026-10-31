<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020074524 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cihaz raporu: device_result (METHOD, TOTAL_AREA, REPORT_*), device_result_peak, device_result_file';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE device_result_file (DEVICE_RESULT_FILE_ID BIGINT IDENTITY NOT NULL, FILE_TYPE NVARCHAR(30) NOT NULL, FILE_NAME NVARCHAR(100), MIME_TYPE NVARCHAR(50) NOT NULL, FILE_SIZE INT NOT NULL, SHA256 NCHAR(64) NOT NULL, CONTENT VARBINARY(MAX) NOT NULL, CREATED_AT DATETIME2(6) NOT NULL, DEVICE_RESULT_ID BIGINT NOT NULL, PRIMARY KEY (DEVICE_RESULT_FILE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_device_result_file_TYPE ON device_result_file (DEVICE_RESULT_ID, FILE_TYPE) WHERE DEVICE_RESULT_ID IS NOT NULL AND FILE_TYPE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_6E2479983F85D83C ON device_result_file (DEVICE_RESULT_ID)');
        $this->addSql('CREATE TABLE device_result_peak (DEVICE_RESULT_PEAK_ID BIGINT IDENTITY NOT NULL, SORT_NO SMALLINT NOT NULL, PEAK_NAME NVARCHAR(50) NOT NULL, RETENTION_TIME NVARCHAR(20), HEIGHT BIGINT, AREA BIGINT, AREA_PCT NVARCHAR(20), DEVICE_RESULT_ID BIGINT NOT NULL, PRIMARY KEY (DEVICE_RESULT_PEAK_ID))');
        $this->addSql('CREATE INDEX IX_device_result_peak_RESULT ON device_result_peak (DEVICE_RESULT_ID)');
        $this->addSql('ALTER TABLE device_result_file ADD CONSTRAINT FK_6E2479983F85D83C FOREIGN KEY (DEVICE_RESULT_ID) REFERENCES device_result (DEVICE_RESULT_ID) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE device_result_peak ADD CONSTRAINT FK_405ADBF53F85D83C FOREIGN KEY (DEVICE_RESULT_ID) REFERENCES device_result (DEVICE_RESULT_ID) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE device_result ADD METHOD NVARCHAR(20)');
        $this->addSql('ALTER TABLE device_result ADD TOTAL_AREA BIGINT');
        $this->addSql('ALTER TABLE device_result ADD REPORT_FETCHED_AT DATETIME2(6)');
        $this->addSql('ALTER TABLE device_result ADD REPORT_ATTEMPTS SMALLINT NOT NULL CONSTRAINT [DF_device_result_REPORT_ATTEMPTS] DEFAULT 0');
        $this->addSql('ALTER TABLE device_result ADD REPORT_ERROR NVARCHAR(300)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE device_result_file DROP CONSTRAINT FK_6E2479983F85D83C');
        $this->addSql('ALTER TABLE device_result_peak DROP CONSTRAINT FK_405ADBF53F85D83C');
        $this->addSql('DROP TABLE device_result_file');
        $this->addSql('DROP TABLE device_result_peak');
        $this->addSql('ALTER TABLE device_result DROP COLUMN METHOD');
        $this->addSql('ALTER TABLE device_result DROP COLUMN TOTAL_AREA');
        $this->addSql('ALTER TABLE device_result DROP COLUMN REPORT_FETCHED_AT');
        $this->addSql('ALTER TABLE device_result DROP CONSTRAINT [DF_device_result_REPORT_ATTEMPTS]');
        $this->addSql('ALTER TABLE device_result DROP COLUMN REPORT_ATTEMPTS');
        $this->addSql('ALTER TABLE device_result DROP COLUMN REPORT_ERROR');
    }
}
