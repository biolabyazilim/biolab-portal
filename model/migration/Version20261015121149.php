<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015121149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mng_user: hatali giris sayaci (FAILED_LOGIN_COUNT) ve kilit suresi (LOCKED_UNTIL)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mng_user ADD FAILED_LOGIN_COUNT SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE mng_user ADD LOCKED_UNTIL DATETIME2(6)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mng_user DROP CONSTRAINT [DF_mng_user_FAILED_LOGIN_COUNT]');
        $this->addSql('ALTER TABLE mng_user DROP COLUMN FAILED_LOGIN_COUNT');
        $this->addSql('ALTER TABLE mng_user DROP COLUMN LOCKED_UNTIL');
    }
}
