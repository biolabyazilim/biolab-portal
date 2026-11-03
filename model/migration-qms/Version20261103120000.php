<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * REHBERLER klasöründeki dört tip (RH, KEK, KP, NEK) tek "Rehber" tipinde birleşir: her klasörde bir tip.
 * KEK01 / KP01 / NEK01 tek başına duran dokümanlardır; ayrı tip gerektirmez. Tipin öneki "RH,KEK,KP,NEK",
 * kod kalıbı ^(RH|KEK|KP|NEK)\d{1,4}$ olur. Silinen üç tipin henüz dokümanı, akışı yoktur (yalnızca varsayılan dağıtım).
 */
final class Version20261103120000 extends AbstractMigration
{
    private const MERGED = ['KEK', 'KP', 'NEK'];

    public function getDescription(): string
    {
        return 'QMS: REHBERLER tipleri (RH, KEK, KP, NEK) tek Rehber tipinde birleşir';
    }

    public function up(Schema $schema): void
    {
        $in = "N'" . implode("', N'", self::MERGED) . "'";
        $this->abortIf((int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM qms_document d JOIN qms_doc_type t ON t.DOC_TYPE_ID = d.DOC_TYPE_ID WHERE t.CODE_PREFIX IN ({$in})") > 0,
            'KEK / KP / NEK tipinde doküman var; tipler birleştirilmeden önce dokümanlar Rehber tipine taşınmalı.');

        $types = "SELECT DOC_TYPE_ID FROM qms_doc_type WHERE CODE_PREFIX IN ({$in})";
        $this->addSql("DELETE sp FROM qms_doc_type_step_position sp JOIN qms_doc_type_step s ON s.DOC_TYPE_STEP_ID = sp.DOC_TYPE_STEP_ID WHERE s.DOC_TYPE_ID IN ({$types})");
        $this->addSql("DELETE FROM qms_doc_type_step WHERE DOC_TYPE_ID IN ({$types})");
        $this->addSql("DELETE FROM qms_doc_type_distribution WHERE DOC_TYPE_ID IN ({$types})");
        $this->addSql("DELETE FROM qms_doc_type WHERE CODE_PREFIX IN ({$in})");
        $this->addSql("UPDATE qms_doc_type SET CODE_PREFIX = N'RH,KEK,KP,NEK', CODE_PATTERN = N'^(RH|KEK|KP|NEK)\\d{1,4}$'
                        WHERE CODE_PREFIX = N'RH'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE qms_doc_type SET CODE_PREFIX = N'RH', CODE_PATTERN = N'^RH\\d{1,4}$' WHERE CODE_PREFIX = N'RH,KEK,KP,NEK'");
        foreach ([['KEK', 'Kalite El Kitabı'], ['KP', 'Kalite Politikası'], ['NEK', 'Numune Alma El Kitabı']] as [$prefix, $name]) {
            $this->addSql("INSERT INTO qms_doc_type (COMPANY_ID, CATEGORY_ID, CODE_PREFIX, DOC_TYPE_NAME, CODE_PATTERN,
                                                      REVIEW_MONTHS, RETENTION_YEARS, READ_ACK_FL, EXTERNAL_FL)
                SELECT COMPANY_ID, CATEGORY_ID, N'{$prefix}', N'{$name}', N'^{$prefix}\\d{1,4}$', 24, 10, 'Y', 'N'
                  FROM qms_doc_type WHERE CODE_PREFIX = N'RH'");
            $this->addSql("INSERT INTO qms_doc_type_distribution (DOC_TYPE_ID, SUBJECT_TYPE, SUBJECT_ID)
                SELECT DOC_TYPE_ID, 'OWNER_DEPT', NULL FROM qms_doc_type WHERE CODE_PREFIX = N'{$prefix}'");
        }
    }
}
