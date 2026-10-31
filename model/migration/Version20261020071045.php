<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020071045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sample_acceptance: numune reddi icin REJECT_REASON, REJECTED_AT, REJECTED_BY';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sample_acceptance ADD REJECT_REASON NVARCHAR(200) COLLATE Turkish_CI_AS');
        $this->addSql('ALTER TABLE sample_acceptance ADD REJECTED_AT DATETIME2(6)');
        $this->addSql('ALTER TABLE sample_acceptance ADD REJECTED_BY BIGINT');
        $this->addSql('ALTER TABLE sample_acceptance ADD CONSTRAINT FK_9BC32BADC886CCF1 FOREIGN KEY (REJECTED_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('CREATE INDEX IDX_9BC32BADC886CCF1 ON sample_acceptance (REJECTED_BY)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sample_acceptance DROP CONSTRAINT FK_9BC32BADC886CCF1');
        $this->addSql('DROP INDEX IDX_9BC32BADC886CCF1 ON sample_acceptance');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN REJECT_REASON');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN REJECTED_AT');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN REJECTED_BY');
    }
}
