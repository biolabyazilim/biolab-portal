<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016132615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sample_acceptance: PATIENT_NAME_SURNAME, FILE_NO ve GENDER_ID+COMPANY_ID benzersiz kisitlari kaldirildi; sadece BARCODE benzersiz';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_9BC32BAD78093B27 ON sample_acceptance');
        $this->addSql('DROP INDEX UNIQ_9BC32BAD4C508ED9 ON sample_acceptance');
        $this->addSql('DROP INDEX UQ_sample_acceptance_SAMPLE_ACCEPTANCE ON sample_acceptance');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE UNIQUE NONCLUSTERED INDEX UNIQ_9BC32BAD78093B27 ON sample_acceptance (PATIENT_NAME_SURNAME) WHERE PATIENT_NAME_SURNAME IS NOT NULL');
        $this->addSql('CREATE UNIQUE NONCLUSTERED INDEX UNIQ_9BC32BAD4C508ED9 ON sample_acceptance (FILE_NO) WHERE FILE_NO IS NOT NULL');
        $this->addSql('CREATE UNIQUE NONCLUSTERED INDEX UQ_sample_acceptance_SAMPLE_ACCEPTANCE ON sample_acceptance (GENDER_ID, COMPANY_ID) WHERE GENDER_ID IS NOT NULL AND COMPANY_ID IS NOT NULL');
    }
}
