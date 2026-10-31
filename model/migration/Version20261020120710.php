<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020120710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sample_report: EXPERT_NOTE (uzman notu), COMMENT_EDITED_FL';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sample_report ADD COMMENT_EDITED_FL NCHAR(1) NOT NULL CONSTRAINT [DF_sample_report_COMMENT_EDITED_FL] DEFAULT \'N\'');
        $this->addSql('ALTER TABLE sample_report ADD EXPERT_NOTE VARCHAR(MAX) COLLATE Turkish_100_CI_AS_SC_UTF8');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sample_report DROP CONSTRAINT [DF_sample_report_COMMENT_EDITED_FL]');
        $this->addSql('ALTER TABLE sample_report DROP COLUMN COMMENT_EDITED_FL');
        $this->addSql('ALTER TABLE sample_report DROP COLUMN EXPERT_NOTE');
    }
}
