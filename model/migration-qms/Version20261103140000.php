<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Doküman varyantı: aynı kodun birden çok dokümanı olabilir (FR06 klasöründeki 9 form, FR57 ön/arka yüz).
 * Her varyant ayrı dokümandır; tekillik şirket + kod + varyant olur. Tek dosyalı dokümanda varyant boştur.
 * Dış kaynaklı dokümanın kuruluş kodu yoksa DOC_CODE boş (NULL) kalabilir; filtreli UNIQUE index NULL'ları saymaz.
 */
final class Version20261103140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'QMS: qms_document.VARIANT_NAME, DOC_CODE boş olabilir, tekillik kod + varyant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX UQ_qms_document_COMPANY_CODE ON qms_document');
        $this->addSql('ALTER TABLE qms_document ALTER COLUMN DOC_CODE NVARCHAR(30) COLLATE Turkish_CI_AS NULL');
        $this->addSql("ALTER TABLE qms_document ADD VARIANT_NAME NVARCHAR(150) COLLATE Turkish_CI_AS NOT NULL CONSTRAINT [DF_qms_document_VARIANT_NAME] DEFAULT ''");
        $this->addSql('CREATE UNIQUE INDEX UQ_qms_document_COMPANY_CODE ON qms_document (COMPANY_ID, DOC_CODE, VARIANT_NAME) WHERE COMPANY_ID IS NOT NULL AND DOC_CODE IS NOT NULL AND VARIANT_NAME IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UQ_qms_document_COMPANY_CODE ON qms_document');
        $this->addSql('ALTER TABLE qms_document DROP CONSTRAINT [DF_qms_document_VARIANT_NAME]');
        $this->addSql('ALTER TABLE qms_document DROP COLUMN VARIANT_NAME');
        $this->addSql('ALTER TABLE qms_document ALTER COLUMN DOC_CODE NVARCHAR(30) COLLATE Turkish_CI_AS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UQ_qms_document_COMPANY_CODE ON qms_document (COMPANY_ID, DOC_CODE) WHERE COMPANY_ID IS NOT NULL AND DOC_CODE IS NOT NULL');
    }
}
