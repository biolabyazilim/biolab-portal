<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kitle birlikte kullanılan malzemeler: kit bir testin reçetesindeyse bu malzemeler (pipet ucu, küvet…) kit miktarı × adet
 * olarak testin maliyetine kendiliğinden eklenir (CostCalculator, satır türü COMPANION).
 */
final class Version20261111121943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: kitle birlikte kullanılan malzemeler (mlyt_material_companion: pipet ucu, küvet… test başına)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mlyt_material_companion (MATERIAL_COMPANION_ID INT IDENTITY NOT NULL, QUANTITY NUMERIC(12, 4) NOT NULL, SORT_NO SMALLINT NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, KIT_MATERIAL_ID INT NOT NULL, MATERIAL_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (MATERIAL_COMPANION_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_material_companion ON mlyt_material_companion (KIT_MATERIAL_ID, MATERIAL_ID) WHERE KIT_MATERIAL_ID IS NOT NULL AND MATERIAL_ID IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_31DC7ED962E87C4 ON mlyt_material_companion (KIT_MATERIAL_ID)');
        $this->addSql('CREATE INDEX IDX_31DC7ED56467867 ON mlyt_material_companion (MATERIAL_ID)');
        $this->addSql('CREATE INDEX IDX_31DC7ED1FFD8595 ON mlyt_material_companion (C_BY)');
        $this->addSql('CREATE INDEX IDX_31DC7ED6A8F8DD6 ON mlyt_material_companion (U_BY)');
        $this->addSql('CREATE INDEX IDX_31DC7ED822ABD2C ON mlyt_material_companion (D_BY)');
        $this->addSql('ALTER TABLE mlyt_material_companion ADD CONSTRAINT [DF_mlyt_material_companion_SORT_NO] DEFAULT 0 FOR SORT_NO');
        $this->addSql('ALTER TABLE mlyt_material_companion ADD CONSTRAINT [DF_mlyt_material_companion_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_material_companion ADD CONSTRAINT [DF_mlyt_material_companion_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_material_companion ADD CONSTRAINT [DF_mlyt_material_companion_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mlyt_material_companion ADD CONSTRAINT FK_31DC7ED962E87C4 FOREIGN KEY (KIT_MATERIAL_ID) REFERENCES mlyt_material (MATERIAL_ID)');
        $this->addSql('ALTER TABLE mlyt_material_companion ADD CONSTRAINT FK_31DC7ED56467867 FOREIGN KEY (MATERIAL_ID) REFERENCES mlyt_material (MATERIAL_ID)');
        $this->addSql('ALTER TABLE mlyt_material_companion ADD CONSTRAINT FK_31DC7ED1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material_companion ADD CONSTRAINT FK_31DC7ED6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_material_companion ADD CONSTRAINT FK_31DC7ED822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mlyt_material_companion DROP CONSTRAINT FK_31DC7ED962E87C4');
        $this->addSql('ALTER TABLE mlyt_material_companion DROP CONSTRAINT FK_31DC7ED56467867');
        $this->addSql('ALTER TABLE mlyt_material_companion DROP CONSTRAINT FK_31DC7ED1FFD8595');
        $this->addSql('ALTER TABLE mlyt_material_companion DROP CONSTRAINT FK_31DC7ED6A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_material_companion DROP CONSTRAINT FK_31DC7ED822ABD2C');
        $this->addSql('DROP TABLE mlyt_material_companion');
    }
}
