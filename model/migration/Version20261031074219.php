<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * KVKK yönetimi tabloları:
 *   kvk_node          → başlık ağacı (Politikalar, Aydınlatma Metinleri → birimler …)
 *   kvk_document      → başlığa bağlı belge (yayın bağlantısı PUBLIC_KEY, yayın bayrağı PUBLISH_FL)
 *   kvk_document_file → belgenin PDF versiyonları (içerik VARBINARY(MAX), SHA256)
 * Ekranlar, menü ve rol bir sonraki migration'da (Version20261031080000).
 */
final class Version20261031074219 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'KVK: kvk_node, kvk_document, kvk_document_file';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kvk_document (DOCUMENT_ID INT IDENTITY NOT NULL, DOCUMENT_NAME NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, PUBLIC_KEY NCHAR(32) NOT NULL, SORT_NO SMALLINT NOT NULL, PUBLISH_FL NCHAR(1) NOT NULL, PUBLISHED_AT DATETIME2(6), C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, NODE_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (DOCUMENT_ID))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D25B2E6CD546662 ON kvk_document (PUBLIC_KEY) WHERE PUBLIC_KEY IS NOT NULL');
        $this->addSql('CREATE INDEX IX_kvk_document_NODE ON kvk_document (NODE_ID, SORT_NO)');
        $this->addSql('CREATE INDEX IDX_D25B2E6A4494109 ON kvk_document (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_D25B2E64105272D ON kvk_document (NODE_ID)');
        $this->addSql('CREATE INDEX IDX_D25B2E61FFD8595 ON kvk_document (C_BY)');
        $this->addSql('CREATE INDEX IDX_D25B2E66A8F8DD6 ON kvk_document (U_BY)');
        $this->addSql('CREATE INDEX IDX_D25B2E6822ABD2C ON kvk_document (D_BY)');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT [DF_kvk_document_SORT_NO] DEFAULT 0 FOR SORT_NO');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT [DF_kvk_document_PUBLISH_FL] DEFAULT \'N\' FOR PUBLISH_FL');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT [DF_kvk_document_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT [DF_kvk_document_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT [DF_kvk_document_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE kvk_document_file (DOCUMENT_FILE_ID INT IDENTITY NOT NULL, VERSION_NO SMALLINT NOT NULL, FILE_NAME NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, FILE_SIZE INT NOT NULL, SHA256 NCHAR(64) NOT NULL, CONTENT VARBINARY(MAX) NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, DOCUMENT_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (DOCUMENT_FILE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_kvk_document_file_VERSION ON kvk_document_file (DOCUMENT_ID, VERSION_NO) WHERE DOCUMENT_ID IS NOT NULL AND VERSION_NO IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_4A7D08237671AC3F ON kvk_document_file (DOCUMENT_ID)');
        $this->addSql('CREATE INDEX IDX_4A7D08231FFD8595 ON kvk_document_file (C_BY)');
        $this->addSql('CREATE INDEX IDX_4A7D08236A8F8DD6 ON kvk_document_file (U_BY)');
        $this->addSql('CREATE INDEX IDX_4A7D0823822ABD2C ON kvk_document_file (D_BY)');
        $this->addSql('ALTER TABLE kvk_document_file ADD CONSTRAINT [DF_kvk_document_file_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE kvk_document_file ADD CONSTRAINT [DF_kvk_document_file_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE kvk_document_file ADD CONSTRAINT [DF_kvk_document_file_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE kvk_node (NODE_ID INT IDENTITY NOT NULL, NODE_NAME NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, NODE_DESC NVARCHAR(500) COLLATE Turkish_CI_AS, SORT_NO SMALLINT NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, PARENT_NODE_ID INT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (NODE_ID))');
        $this->addSql('CREATE INDEX IX_kvk_node_PARENT ON kvk_node (PARENT_NODE_ID, SORT_NO)');
        $this->addSql('CREATE INDEX IDX_1B50164FA4494109 ON kvk_node (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_1B50164F1E56B51A ON kvk_node (PARENT_NODE_ID)');
        $this->addSql('CREATE INDEX IDX_1B50164F1FFD8595 ON kvk_node (C_BY)');
        $this->addSql('CREATE INDEX IDX_1B50164F6A8F8DD6 ON kvk_node (U_BY)');
        $this->addSql('CREATE INDEX IDX_1B50164F822ABD2C ON kvk_node (D_BY)');
        $this->addSql('ALTER TABLE kvk_node ADD CONSTRAINT [DF_kvk_node_SORT_NO] DEFAULT 0 FOR SORT_NO');
        $this->addSql('ALTER TABLE kvk_node ADD CONSTRAINT [DF_kvk_node_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE kvk_node ADD CONSTRAINT [DF_kvk_node_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE kvk_node ADD CONSTRAINT [DF_kvk_node_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT FK_D25B2E6A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT FK_D25B2E64105272D FOREIGN KEY (NODE_ID) REFERENCES kvk_node (NODE_ID)');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT FK_D25B2E61FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT FK_D25B2E66A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE kvk_document ADD CONSTRAINT FK_D25B2E6822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE kvk_document_file ADD CONSTRAINT FK_4A7D08237671AC3F FOREIGN KEY (DOCUMENT_ID) REFERENCES kvk_document (DOCUMENT_ID)');
        $this->addSql('ALTER TABLE kvk_document_file ADD CONSTRAINT FK_4A7D08231FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE kvk_document_file ADD CONSTRAINT FK_4A7D08236A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE kvk_document_file ADD CONSTRAINT FK_4A7D0823822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE kvk_node ADD CONSTRAINT FK_1B50164FA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE kvk_node ADD CONSTRAINT FK_1B50164F1E56B51A FOREIGN KEY (PARENT_NODE_ID) REFERENCES kvk_node (NODE_ID)');
        $this->addSql('ALTER TABLE kvk_node ADD CONSTRAINT FK_1B50164F1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE kvk_node ADD CONSTRAINT FK_1B50164F6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE kvk_node ADD CONSTRAINT FK_1B50164F822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kvk_document DROP CONSTRAINT FK_D25B2E6A4494109');
        $this->addSql('ALTER TABLE kvk_document DROP CONSTRAINT FK_D25B2E64105272D');
        $this->addSql('ALTER TABLE kvk_document DROP CONSTRAINT FK_D25B2E61FFD8595');
        $this->addSql('ALTER TABLE kvk_document DROP CONSTRAINT FK_D25B2E66A8F8DD6');
        $this->addSql('ALTER TABLE kvk_document DROP CONSTRAINT FK_D25B2E6822ABD2C');
        $this->addSql('ALTER TABLE kvk_document_file DROP CONSTRAINT FK_4A7D08237671AC3F');
        $this->addSql('ALTER TABLE kvk_document_file DROP CONSTRAINT FK_4A7D08231FFD8595');
        $this->addSql('ALTER TABLE kvk_document_file DROP CONSTRAINT FK_4A7D08236A8F8DD6');
        $this->addSql('ALTER TABLE kvk_document_file DROP CONSTRAINT FK_4A7D0823822ABD2C');
        $this->addSql('ALTER TABLE kvk_node DROP CONSTRAINT FK_1B50164FA4494109');
        $this->addSql('ALTER TABLE kvk_node DROP CONSTRAINT FK_1B50164F1E56B51A');
        $this->addSql('ALTER TABLE kvk_node DROP CONSTRAINT FK_1B50164F1FFD8595');
        $this->addSql('ALTER TABLE kvk_node DROP CONSTRAINT FK_1B50164F6A8F8DD6');
        $this->addSql('ALTER TABLE kvk_node DROP CONSTRAINT FK_1B50164F822ABD2C');
        // Önce dosyalar, sonra belgeler, en son başlıklar (FK sırası)
        $this->addSql('DROP TABLE kvk_document_file');
        $this->addSql('DROP TABLE kvk_document');
        $this->addSql('DROP TABLE kvk_node');
    }
}
