<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019134811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sample_acceptance: ACCEPTED_AT (kabul zamani) eklendi, mevcut kayitlar C_AT ile dolduruldu; COMPANY_ID+ACCEPTED_AT indeksi';
    }

    public function up(Schema $schema): void
    {
        // Tabloda kayıt olduğu için NOT NULL kolon doğrudan eklenemez: önce boş geçilebilir ekle, doldur, sonra zorunlu yap
        $this->addSql('ALTER TABLE sample_acceptance ADD ACCEPTED_AT DATETIME2(6) NULL');
        $this->addSql('UPDATE sample_acceptance SET ACCEPTED_AT = COALESCE(C_AT, SYSDATETIME()) WHERE ACCEPTED_AT IS NULL');
        $this->addSql('ALTER TABLE sample_acceptance ALTER COLUMN ACCEPTED_AT DATETIME2(6) NOT NULL');
        $this->addSql('CREATE INDEX IX_sample_acceptance_COMPANY_ACCEPTED_AT ON sample_acceptance (COMPANY_ID, ACCEPTED_AT)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IX_sample_acceptance_COMPANY_ACCEPTED_AT ON sample_acceptance');
        $this->addSql('ALTER TABLE sample_acceptance DROP COLUMN ACCEPTED_AT');
    }
}
