<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Doldurulabilir form ayrımı: yalnızca FILLABLE_FL = 'Y' olan yürürlükteki dokümanlar Form doldur listesinde çıkar.
 * Tipte varsayılan (Form tipi açık), dokümanda kendi değeri (yüklemede satır satır değiştirilebilir).
 * Mevcut dokümanlar tiplerinin varsayılanını alır.
 */
final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'QMS: qms_doc_type.FILLABLE_FL (varsayılan) ve qms_document.FILLABLE_FL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE qms_doc_type ADD FILLABLE_FL NCHAR(1) NOT NULL CONSTRAINT [DF_qms_doc_type_FILLABLE_FL] DEFAULT 'N'");
        $this->addSql("ALTER TABLE qms_document ADD FILLABLE_FL NCHAR(1) NOT NULL CONSTRAINT [DF_qms_document_FILLABLE_FL] DEFAULT 'N'");
        $this->addSql("UPDATE qms_doc_type SET FILLABLE_FL = 'Y' WHERE CODE_PREFIX = 'FR'");
        $this->addSql("UPDATE d SET d.FILLABLE_FL = t.FILLABLE_FL FROM qms_document d JOIN qms_doc_type t ON t.DOC_TYPE_ID = d.DOC_TYPE_ID");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE qms_document DROP CONSTRAINT [DF_qms_document_FILLABLE_FL]');
        $this->addSql('ALTER TABLE qms_document DROP COLUMN FILLABLE_FL');
        $this->addSql('ALTER TABLE qms_doc_type DROP CONSTRAINT [DF_qms_doc_type_FILLABLE_FL]');
        $this->addSql('ALTER TABLE qms_doc_type DROP COLUMN FILLABLE_FL');
    }
}
