<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Form doldurma (pilot): doldurulmuş formlar qms_form_record'a kaydedilir. Kayıt yalnızca eklenir
 * (UPDATE/DELETE engelleyen trigger); düzeltme yeni kayıtla yapılır.
 * Ekran QMS_RECORD "Form doldur" adıyla menüde Dokümanlarım'ın altında (sıra 45); her kullanıcı (QMS_USER) açar.
 */
final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'QMS: qms_form_record (doldurulmuş formlar) ve Form doldur ekranı';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE qms_form_record (FORM_RECORD_ID BIGINT IDENTITY NOT NULL, FILLED_AT DATETIME2(6) NOT NULL, RECORD_TITLE NVARCHAR(200) COLLATE Turkish_CI_AS, PAGE_COUNT SMALLINT NOT NULL, FILE_PATH NVARCHAR(300) NOT NULL, FILE_HASH NCHAR(64) NOT NULL, MARKS VARCHAR(MAX) COLLATE Turkish_100_CI_AS_SC_UTF8 NOT NULL, IP_ADDRESS NVARCHAR(45), COMPANY_ID BIGINT NOT NULL, DOCUMENT_ID BIGINT NOT NULL, REVISION_ID BIGINT NOT NULL, USER_ID BIGINT NOT NULL, PRIMARY KEY (FORM_RECORD_ID))');
        $this->addSql('CREATE INDEX IX_qms_form_record_REVISION ON qms_form_record (REVISION_ID)');
        $this->addSql('CREATE INDEX IX_qms_form_record_DOCUMENT ON qms_form_record (DOCUMENT_ID, FILLED_AT)');
        $this->addSql('CREATE INDEX IDX_E8DFD235A4494109 ON qms_form_record (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_E8DFD2357671AC3F ON qms_form_record (DOCUMENT_ID)');
        $this->addSql('CREATE INDEX IDX_E8DFD235A0666B6F ON qms_form_record (USER_ID)');
        $this->addSql('ALTER TABLE qms_form_record ADD CONSTRAINT FK_E8DFD235A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE qms_form_record ADD CONSTRAINT FK_E8DFD2357671AC3F FOREIGN KEY (DOCUMENT_ID) REFERENCES qms_document (DOCUMENT_ID)');
        $this->addSql('ALTER TABLE qms_form_record ADD CONSTRAINT FK_E8DFD235A8B4A887 FOREIGN KEY (REVISION_ID) REFERENCES qms_revision (REVISION_ID)');
        $this->addSql('ALTER TABLE qms_form_record ADD CONSTRAINT FK_E8DFD235A0666B6F FOREIGN KEY (USER_ID) REFERENCES mng_user (USER_ID)');
        $this->addSql("CREATE TRIGGER TR_qms_form_record_NO_CHANGE ON qms_form_record INSTEAD OF UPDATE, DELETE AS
            BEGIN
                SET NOCOUNT ON;
                THROW 51000, N'qms_form_record kayıtları değiştirilemez ve silinemez.', 1;
            END");

        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('QMS_RECORD', N'Form doldur', 'Fill in a form', 'apps/biolab-qms/qms_record/qmsRecord-1.0.0.html')");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Form doldur', 'Fill in a form', 'fas fa-pen-nib', 45
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'QMS_RECORD'
             WHERE r.MENU_CODE = 'biolab-qms'");
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE = 'QMS_USER' AND f.FORM_CODE = 'QMS_RECORD'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'QMS_RECORD'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'QMS_RECORD'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'QMS_RECORD'");
        $this->addSql('DROP TRIGGER TR_qms_form_record_NO_CHANGE');
        $this->addSql('ALTER TABLE qms_form_record DROP CONSTRAINT FK_E8DFD235A4494109');
        $this->addSql('ALTER TABLE qms_form_record DROP CONSTRAINT FK_E8DFD2357671AC3F');
        $this->addSql('ALTER TABLE qms_form_record DROP CONSTRAINT FK_E8DFD235A8B4A887');
        $this->addSql('ALTER TABLE qms_form_record DROP CONSTRAINT FK_E8DFD235A0666B6F');
        $this->addSql('DROP TABLE qms_form_record');
    }
}
