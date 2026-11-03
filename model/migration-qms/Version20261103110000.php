<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * QMS doküman tipleri: ortak klasördeki (KALİTE DOKÜMANLARI) dosya adlarından çıkarılan önekler.
 * Yalnızca kurallar eklenir; doküman eklenmez (dokümanları kalite uzmanı toplu yükleme ekranından yükler).
 * İmza akışı ve dağıtım burada boştur: kalite, Doküman tipleri ekranında her tip için pozisyonları seçer
 * (dağıtım için "dokümanın sahibi departman" varsayılanı eklenir).
 *
 *   Klasör                    Tip                       Önek
 *   DIŞ KAYNAKLI DÖKÜMANLAR   Dış kaynaklı doküman      DK   (dış kaynaklı: kod kurumun kodudur, kalıp serbest)
 *   FORMLAR                   Form                      FR
 *   GÖREV TANIMLARI           Görev tanımı              GT
 *   LİSTELER                  Liste                     LS
 *   PLANLAR                   Plan                      PL
 *   PROSEDÜRLER               Prosedür                  PR
 *   PROSESLER                 Proses                    PS
 *   REHBERLER                 Rehber / Kalite El Kitabı / Kalite Politikası / Numune Alma El Kitabı   RH / KEK / KP / NEK
 *   SOP                       SOP                       SOP
 *   TALİMATLAR                Talimat                   TL
 *
 * Kod kalıbı: önek + 1–4 hane (TL01, FR135, SOP01). Dosya adındaki ayırıcı (FR-135, FR.01) yüklemede atılır.
 */
final class Version20261103110000 extends AbstractMigration
{
    // [klasör kodu, ad, önek, dış kaynaklı]
    private const TYPES = [
        ['DIS_KAYNAKLI', 'Dış kaynaklı doküman',   'DK',  true],
        ['FORM',         'Form',                   'FR',  false],
        ['GOREV_TANIMI', 'Görev tanımı',           'GT',  false],
        ['LISTE',        'Liste',                  'LS',  false],
        ['PLAN',         'Plan',                   'PL',  false],
        ['PROSEDUR',     'Prosedür',               'PR',  false],
        ['PROSES',       'Proses',                 'PS',  false],
        ['REHBER',       'Rehber',                 'RH',  false],
        ['REHBER',       'Kalite El Kitabı',       'KEK', false],
        ['REHBER',       'Kalite Politikası',      'KP',  false],
        ['REHBER',       'Numune Alma El Kitabı',  'NEK', false],
        ['SOP',          'SOP',                    'SOP', false],
        ['TALIMAT',      'Talimat',                'TL',  false],
    ];

    public function getDescription(): string
    {
        return 'QMS: 13 doküman tipi (klasör, ad, önek, kod kalıbı); imza akışı kalite tarafından ekrandan girilir';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TYPES as [$category, $name, $prefix, $external]) {
            // Dış kaynaklıda kod kurumun kodudur (R10.01, EA-2-13 …) ya da boştur: kalıp serbest
            $pattern  = $external ? '^.{1,30}$' : '^' . $prefix . '\d{1,4}$';
            $readAck  = $external ? 'N' : 'Y';
            $this->addSql("INSERT INTO qms_doc_type (COMPANY_ID, CATEGORY_ID, CODE_PREFIX, DOC_TYPE_NAME, CODE_PATTERN,
                                                      REVIEW_MONTHS, RETENTION_YEARS, READ_ACK_FL, EXTERNAL_FL)
                SELECT c.COMPANY_ID, c.CATEGORY_ID, N'{$prefix}', N'{$name}', N'{$pattern}', 24, 10, '{$readAck}', '" . ($external ? 'Y' : 'N') . "'
                  FROM qms_doc_category c JOIN mng_company co ON co.COMPANY_ID = c.COMPANY_ID
                 WHERE c.CODE = '{$category}' AND co.CODE = 'BIOLAB'");
        }

        // Varsayılan dağıtım: dokümanın sahibi departman (dış kaynaklıda herkes)
        $this->addSql("INSERT INTO qms_doc_type_distribution (DOC_TYPE_ID, SUBJECT_TYPE, SUBJECT_ID)
            SELECT DOC_TYPE_ID, CASE WHEN EXTERNAL_FL = 'Y' THEN 'ALL' ELSE 'OWNER_DEPT' END, NULL
              FROM qms_doc_type WHERE CODE_PREFIX IN (" . $this->prefixList() . ")");
    }

    public function down(Schema $schema): void
    {
        // Yalnızca bu migration'ın eklediği tipler ve onlara bağlı akış / dağıtım satırları
        $in = "SELECT DOC_TYPE_ID FROM qms_doc_type WHERE CODE_PREFIX IN (" . $this->prefixList() . ")";
        $this->addSql("DELETE sp FROM qms_doc_type_step_position sp JOIN qms_doc_type_step s ON s.DOC_TYPE_STEP_ID = sp.DOC_TYPE_STEP_ID WHERE s.DOC_TYPE_ID IN ({$in})");
        $this->addSql("DELETE FROM qms_doc_type_step WHERE DOC_TYPE_ID IN ({$in})");
        $this->addSql("DELETE FROM qms_doc_type_distribution WHERE DOC_TYPE_ID IN ({$in})");
        $this->addSql("DELETE FROM qms_doc_type WHERE CODE_PREFIX IN (" . $this->prefixList() . ")");
    }

    private function prefixList(): string
    {
        return "N'" . implode("', N'", array_column(self::TYPES, 2)) . "'";
    }
}
