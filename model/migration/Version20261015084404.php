<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015084404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'C_AT icin DEFAULT CURRENT_TIMESTAMP; bos C_AT degerlerini doldur';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mng_company ADD CONSTRAINT [DF_mng_company_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_language ADD CONSTRAINT [DF_mng_language_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mng_user ADD CONSTRAINT [DF_mng_user_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');

        // Daha önce eklenmiş, C_AT'i boş kayıtlar (ör. tr/en dilleri)
        $this->addSql('UPDATE mng_company SET C_AT = GETDATE() WHERE C_AT IS NULL');
        $this->addSql('UPDATE mng_language SET C_AT = GETDATE() WHERE C_AT IS NULL');
        $this->addSql('UPDATE mng_user SET C_AT = GETDATE() WHERE C_AT IS NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mng_company DROP CONSTRAINT [DF_mng_company_C_AT]');
        $this->addSql('ALTER TABLE mng_language DROP CONSTRAINT [DF_mng_language_C_AT]');
        $this->addSql('ALTER TABLE mng_user DROP CONSTRAINT [DF_mng_user_C_AT]');
    }
}
