<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Elektronik imza yalnızca Form ve Liste tiplerinde: diğer tiplerde ıslak imza basılı nüshaya atılır, sisteme
 * yüklenen revizyon imzacısız yürürlüğe girer. Tipte ESIGN_FL (Doküman tipleri ekranından değiştirilebilir).
 */
final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'QMS: qms_doc_type.ESIGN_FL (FR ve LS açık)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE qms_doc_type ADD ESIGN_FL NCHAR(1) NOT NULL CONSTRAINT [DF_qms_doc_type_ESIGN_FL] DEFAULT 'N'");
        $this->addSql("UPDATE qms_doc_type SET ESIGN_FL = 'Y' WHERE CODE_PREFIX IN ('FR', 'LS')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE qms_doc_type DROP CONSTRAINT [DF_qms_doc_type_ESIGN_FL]');
        $this->addSql('ALTER TABLE qms_doc_type DROP COLUMN ESIGN_FL');
    }
}
