<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aylık fire / kayıp / tekrar takibi (Aşama 1): dönem, yüklenen dosyalar ve satırları (Netsis çıkış, LabAsistan test
 * sayıları, kabul edilmeyen / reddedilen numuneler), Netsis depo kodları (satın alma listesi, şirket 1 için),
 * "Aylık takip" ekranı: Maliyet → Aylık takip; uzman ve yönetici görür.
 */
final class Version20261118054925 extends AbstractMigration
{
    /** Satın alma birimi listesi: Netsis Açıklama kolonundaki çıkış-giriş kodları */
    private const DEPOTS = [
        [1, 'MERKEZ', 'STORE'], [11, 'SOĞUKHAVA', 'STORE'],
        [21, 'BİYOKİMYA', 'LAB'], [31, 'MİKROBİYOLOJİ', 'LAB'], [41, 'DIŞLAB', 'LAB'], [141, 'KAN ALMA KAHRAMANLAR', 'LAB'],
        [151, 'KAN ALMA - MOBİL', 'LAB'], [201, 'ELİSA', 'LAB'], [211, 'ICP', 'LAB'],
        [51, 'BAYRAKLI ŞUBE', 'BRANCH'], [61, 'MAVİŞEHİR ŞUBE', 'BRANCH'], [71, 'GÜZELBAHÇE ŞUBE', 'BRANCH'], [81, 'AYDIN ŞUBE', 'BRANCH'],
        [91, 'PATOLOJİ ŞUBE', 'BRANCH'], [131, 'ALSANCAK ŞUBE', 'BRANCH'],
        [101, 'HAKAN OSGB MRK', 'OSGB'], [111, 'HAKAN OSGB SAHA', 'OSGB'], [121, 'MANİSA OSGB', 'OSGB'],
        [77, 'ÖDÜNÇ', 'OTHER'], [161, 'MUTFAK', 'OTHER'], [171, 'İDARİ', 'OTHER'], [181, 'YÖNETİM', 'OTHER'], [191, 'PAZARLAMA', 'OTHER'],
    ];

    public function getDescription(): string
    {
        return 'MLYT: aylık fire / kayıp / tekrar takibi (mlyt_period, mlyt_period_file, mlyt_netsis_outflow, mlyt_lab_test_count, mlyt_lab_sample, mlyt_depot) ve Aylık takip ekranı';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mlyt_depot (DEPOT_ID INT IDENTITY NOT NULL, DEPOT_CODE INT NOT NULL, DEPOT_NAME NVARCHAR(100) COLLATE Turkish_CI_AS NOT NULL, DEPOT_TYPE NVARCHAR(10) NOT NULL, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (DEPOT_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_depot ON mlyt_depot (COMPANY_ID, DEPOT_CODE) WHERE COMPANY_ID IS NOT NULL AND DEPOT_CODE IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_AA922D06A4494109 ON mlyt_depot (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_AA922D061FFD8595 ON mlyt_depot (C_BY)');
        $this->addSql('CREATE INDEX IDX_AA922D066A8F8DD6 ON mlyt_depot (U_BY)');
        $this->addSql('CREATE INDEX IDX_AA922D06822ABD2C ON mlyt_depot (D_BY)');
        $this->addSql('ALTER TABLE mlyt_depot ADD CONSTRAINT [DF_mlyt_depot_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_depot ADD CONSTRAINT [DF_mlyt_depot_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_depot ADD CONSTRAINT [DF_mlyt_depot_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mlyt_lab_sample (LAB_SAMPLE_ID INT IDENTITY NOT NULL, SAMPLE_NO NVARCHAR(30) NOT NULL, REGISTERED_AT DATETIME2(6), ACCEPTED_FL NCHAR(1) NOT NULL, ARRIVAL_TYPE NVARCHAR(40) COLLATE Turkish_CI_AS, INSTITUTION NVARCHAR(150) COLLATE Turkish_CI_AS, TUBE_QTY SMALLINT NOT NULL, TEST_QTY SMALLINT NOT NULL, TEST_SHORT_NAMES NVARCHAR(2000), FILE_ID INT NOT NULL, PRIMARY KEY (LAB_SAMPLE_ID))');
        $this->addSql('CREATE INDEX IX_mlyt_lab_sample_file ON mlyt_lab_sample (FILE_ID)');
        $this->addSql('CREATE INDEX IX_mlyt_lab_sample_no ON mlyt_lab_sample (SAMPLE_NO)');
        $this->addSql('ALTER TABLE mlyt_lab_sample ADD CONSTRAINT [DF_mlyt_lab_sample_ACCEPTED_FL] DEFAULT \'N\' FOR ACCEPTED_FL');
        $this->addSql('CREATE TABLE mlyt_lab_test_count (TEST_COUNT_ID INT IDENTITY NOT NULL, TEST_GROUP NVARCHAR(100) COLLATE Turkish_CI_AS, TEST_CODE NVARCHAR(30) NOT NULL, TEST_NAME NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, TEST_QTY INT NOT NULL, REPEAT_QTY INT NOT NULL, FILE_ID INT NOT NULL, PRIMARY KEY (TEST_COUNT_ID))');
        $this->addSql('CREATE INDEX IX_mlyt_lab_test_count_file ON mlyt_lab_test_count (FILE_ID)');
        $this->addSql('CREATE TABLE mlyt_netsis_outflow (OUTFLOW_ID INT IDENTITY NOT NULL, DOC_DATE DATE NOT NULL, DOC_NO NVARCHAR(30) NOT NULL, STOCK_CODE NVARCHAR(30) NOT NULL, STOCK_NAME NVARCHAR(200) COLLATE Turkish_CI_AS NOT NULL, QUANTITY NUMERIC(14, 3) NOT NULL, UNIT NVARCHAR(10) NOT NULL, QUANTITY2 NUMERIC(14, 3), UNIT2 NVARCHAR(10), FROM_DEPOT INT, TO_CODE INT, NOTE NVARCHAR(30) COLLATE Turkish_CI_AS, FILE_ID INT NOT NULL, PRIMARY KEY (OUTFLOW_ID))');
        $this->addSql('CREATE INDEX IX_mlyt_netsis_outflow_file ON mlyt_netsis_outflow (FILE_ID)');
        $this->addSql('CREATE TABLE mlyt_period (PERIOD_ID INT IDENTITY NOT NULL, PERIOD_YM NCHAR(7) NOT NULL, NOTE NVARCHAR(1000) COLLATE Turkish_CI_AS, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, COMPANY_ID BIGINT NOT NULL, STUDY_ID INT, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (PERIOD_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_period ON mlyt_period (COMPANY_ID, PERIOD_YM) WHERE COMPANY_ID IS NOT NULL AND PERIOD_YM IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_EEE142FAA4494109 ON mlyt_period (COMPANY_ID)');
        $this->addSql('CREATE INDEX IDX_EEE142FA19CED945 ON mlyt_period (STUDY_ID)');
        $this->addSql('CREATE INDEX IDX_EEE142FA1FFD8595 ON mlyt_period (C_BY)');
        $this->addSql('CREATE INDEX IDX_EEE142FA6A8F8DD6 ON mlyt_period (U_BY)');
        $this->addSql('CREATE INDEX IDX_EEE142FA822ABD2C ON mlyt_period (D_BY)');
        $this->addSql('ALTER TABLE mlyt_period ADD CONSTRAINT [DF_mlyt_period_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_period ADD CONSTRAINT [DF_mlyt_period_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_period ADD CONSTRAINT [DF_mlyt_period_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('CREATE TABLE mlyt_period_file (FILE_ID INT IDENTITY NOT NULL, SOURCE_CODE NVARCHAR(20) NOT NULL, UNIT_NAME NVARCHAR(60) COLLATE Turkish_CI_AS NOT NULL, FILE_NAME NVARCHAR(255) COLLATE Turkish_CI_AS NOT NULL, ROW_COUNT INT NOT NULL, DATE_FROM DATE, DATE_TO DATE, WARNINGS NVARCHAR(2000) COLLATE Turkish_CI_AS, C_AT DATETIME2(6), U_AT DATETIME2(6), D_AT DATETIME2(6), ACTIVE_FL NCHAR(1) NOT NULL, RECORD_FL SMALLINT NOT NULL, PERIOD_ID INT NOT NULL, C_BY BIGINT, U_BY BIGINT, D_BY BIGINT, PRIMARY KEY (FILE_ID))');
        $this->addSql('CREATE UNIQUE INDEX UQ_mlyt_period_file ON mlyt_period_file (PERIOD_ID, SOURCE_CODE, UNIT_NAME) WHERE PERIOD_ID IS NOT NULL AND SOURCE_CODE IS NOT NULL AND UNIT_NAME IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_8AFEFAB4900422D1 ON mlyt_period_file (PERIOD_ID)');
        $this->addSql('CREATE INDEX IDX_8AFEFAB41FFD8595 ON mlyt_period_file (C_BY)');
        $this->addSql('CREATE INDEX IDX_8AFEFAB46A8F8DD6 ON mlyt_period_file (U_BY)');
        $this->addSql('CREATE INDEX IDX_8AFEFAB4822ABD2C ON mlyt_period_file (D_BY)');
        $this->addSql('ALTER TABLE mlyt_period_file ADD CONSTRAINT [DF_mlyt_period_file_UNIT_NAME] DEFAULT \'-\' FOR UNIT_NAME');
        $this->addSql('ALTER TABLE mlyt_period_file ADD CONSTRAINT [DF_mlyt_period_file_ROW_COUNT] DEFAULT 0 FOR ROW_COUNT');
        $this->addSql('ALTER TABLE mlyt_period_file ADD CONSTRAINT [DF_mlyt_period_file_C_AT] DEFAULT CURRENT_TIMESTAMP FOR C_AT');
        $this->addSql('ALTER TABLE mlyt_period_file ADD CONSTRAINT [DF_mlyt_period_file_ACTIVE_FL] DEFAULT \'Y\' FOR ACTIVE_FL');
        $this->addSql('ALTER TABLE mlyt_period_file ADD CONSTRAINT [DF_mlyt_period_file_RECORD_FL] DEFAULT 1 FOR RECORD_FL');
        $this->addSql('ALTER TABLE mlyt_depot ADD CONSTRAINT FK_AA922D06A4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE mlyt_depot ADD CONSTRAINT FK_AA922D061FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_depot ADD CONSTRAINT FK_AA922D066A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_depot ADD CONSTRAINT FK_AA922D06822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_lab_sample ADD CONSTRAINT FK_B9F957E894C3C196 FOREIGN KEY (FILE_ID) REFERENCES mlyt_period_file (FILE_ID)');
        $this->addSql('ALTER TABLE mlyt_lab_test_count ADD CONSTRAINT FK_9E36AC1C94C3C196 FOREIGN KEY (FILE_ID) REFERENCES mlyt_period_file (FILE_ID)');
        $this->addSql('ALTER TABLE mlyt_netsis_outflow ADD CONSTRAINT FK_8CD203E994C3C196 FOREIGN KEY (FILE_ID) REFERENCES mlyt_period_file (FILE_ID)');
        $this->addSql('ALTER TABLE mlyt_period ADD CONSTRAINT FK_EEE142FAA4494109 FOREIGN KEY (COMPANY_ID) REFERENCES mng_company (COMPANY_ID)');
        $this->addSql('ALTER TABLE mlyt_period ADD CONSTRAINT FK_EEE142FA19CED945 FOREIGN KEY (STUDY_ID) REFERENCES mlyt_study (STUDY_ID)');
        $this->addSql('ALTER TABLE mlyt_period ADD CONSTRAINT FK_EEE142FA1FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_period ADD CONSTRAINT FK_EEE142FA6A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_period ADD CONSTRAINT FK_EEE142FA822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_period_file ADD CONSTRAINT FK_8AFEFAB4900422D1 FOREIGN KEY (PERIOD_ID) REFERENCES mlyt_period (PERIOD_ID)');
        $this->addSql('ALTER TABLE mlyt_period_file ADD CONSTRAINT FK_8AFEFAB41FFD8595 FOREIGN KEY (C_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_period_file ADD CONSTRAINT FK_8AFEFAB46A8F8DD6 FOREIGN KEY (U_BY) REFERENCES mng_user (USER_ID)');
        $this->addSql('ALTER TABLE mlyt_period_file ADD CONSTRAINT FK_8AFEFAB4822ABD2C FOREIGN KEY (D_BY) REFERENCES mng_user (USER_ID)');

        // Depo kodları (şirket 1: Biolab). Başka şirketler kendi listesini ekler.
        foreach (self::DEPOTS as [$code, $name, $type]) {
            $this->addSql("INSERT INTO mlyt_depot (COMPANY_ID, DEPOT_CODE, DEPOT_NAME, DEPOT_TYPE, ACTIVE_FL, RECORD_FL)
                SELECT 1, $code, N'$name', '$type', 'Y', 1 WHERE EXISTS (SELECT 1 FROM mng_company WHERE COMPANY_ID = 1)");
        }

        // Ekran: Maliyet → Aylık takip (Test maliyetleri'nden sonra)
        $this->addSql("INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES
            ('MLYT_MONTHLY', N'Aylık takip', 'Monthly tracking', 'apps/biolab-maliyet/monthly/monthly-1.0.0.html')");
        $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
            SELECT NULL, r.MENU_ID, f.FORM_ID, N'Aylık takip', 'Monthly tracking', 'fas fa-calendar-check', 30
              FROM mng_menu r JOIN mng_form f ON f.FORM_CODE = 'MLYT_MONTHLY'
             WHERE r.MENU_CODE = 'biolab-maliyet'");
        $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
            SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
             WHERE r.ROLE_CODE IN ('MLYT_EXPERT', 'MLYT_MANAGER') AND f.FORM_CODE = 'MLYT_MONTHLY'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE rf FROM mng_role_form rf JOIN mng_form f ON f.FORM_ID = rf.FORM_ID WHERE f.FORM_CODE = 'MLYT_MONTHLY'");
        $this->addSql("DELETE m FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID WHERE f.FORM_CODE = 'MLYT_MONTHLY'");
        $this->addSql("DELETE FROM mng_form WHERE FORM_CODE = 'MLYT_MONTHLY'");
        $this->addSql('ALTER TABLE mlyt_depot DROP CONSTRAINT FK_AA922D06A4494109');
        $this->addSql('ALTER TABLE mlyt_depot DROP CONSTRAINT FK_AA922D061FFD8595');
        $this->addSql('ALTER TABLE mlyt_depot DROP CONSTRAINT FK_AA922D066A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_depot DROP CONSTRAINT FK_AA922D06822ABD2C');
        $this->addSql('ALTER TABLE mlyt_lab_sample DROP CONSTRAINT FK_B9F957E894C3C196');
        $this->addSql('ALTER TABLE mlyt_lab_test_count DROP CONSTRAINT FK_9E36AC1C94C3C196');
        $this->addSql('ALTER TABLE mlyt_netsis_outflow DROP CONSTRAINT FK_8CD203E994C3C196');
        $this->addSql('ALTER TABLE mlyt_period DROP CONSTRAINT FK_EEE142FAA4494109');
        $this->addSql('ALTER TABLE mlyt_period DROP CONSTRAINT FK_EEE142FA19CED945');
        $this->addSql('ALTER TABLE mlyt_period DROP CONSTRAINT FK_EEE142FA1FFD8595');
        $this->addSql('ALTER TABLE mlyt_period DROP CONSTRAINT FK_EEE142FA6A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_period DROP CONSTRAINT FK_EEE142FA822ABD2C');
        $this->addSql('ALTER TABLE mlyt_period_file DROP CONSTRAINT FK_8AFEFAB4900422D1');
        $this->addSql('ALTER TABLE mlyt_period_file DROP CONSTRAINT FK_8AFEFAB41FFD8595');
        $this->addSql('ALTER TABLE mlyt_period_file DROP CONSTRAINT FK_8AFEFAB46A8F8DD6');
        $this->addSql('ALTER TABLE mlyt_period_file DROP CONSTRAINT FK_8AFEFAB4822ABD2C');
        $this->addSql('DROP TABLE mlyt_depot');
        $this->addSql('DROP TABLE mlyt_lab_sample');
        $this->addSql('DROP TABLE mlyt_lab_test_count');
        $this->addSql('DROP TABLE mlyt_netsis_outflow');
        $this->addSql('DROP TABLE mlyt_period');
        $this->addSql('DROP TABLE mlyt_period_file');
    }
}
