<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016135235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sample_acceptance: BARCODE_MANUAL_FL, BIRTH_DATE, INSTITUTION, STATUS eklendi; PATIENT_NAME_SURNAME 100 karakter (Turkish_CI_AS)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sample_acceptance ADD BARCODE_MANUAL_FL NCHAR(1) NOT NULL DEFAULT \'N\'');
        $this->addSql('ALTER TABLE sample_acceptance ADD BIRTH_DATE DATE NOT NULL');
        $this->addSql('ALTER TABLE sample_acceptance ADD INSTITUTION NVARCHAR(150) COLLATE Turkish_CI_AS');
        $this->addSql('ALTER TABLE sample_acceptance ADD STATUS NVARCHAR(20) NOT NULL DEFAULT \'WAITING\'');
        $this->addSql('ALTER TABLE sample_acceptance ALTER COLUMN PATIENT_NAME_SURNAME NVARCHAR(100) COLLATE Turkish_CI_AS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sample_acceptance DROP CONSTRAINT [DF_sample_acceptance_BARCODE_MANUAL_FL]');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN BARCODE_MANUAL_FL');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN BIRTH_DATE');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN INSTITUTION');
        $this->addSql('ALTER TABLE sample_acceptance DROP CONSTRAINT [DF_sample_acceptance_STATUS]');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN STATUS');
        $this->addSql('ALTER TABLE sample_acceptance ALTER COLUMN PATIENT_NAME_SURNAME NVARCHAR(50) NOT NULL');
    }
}
