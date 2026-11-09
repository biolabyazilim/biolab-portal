<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Biolab (mikroskopi / mikrobiyoloji) kısa kodları. Uzmanın notlarındaki adlarla: "N.LAM+LAMEL", "R.LAMx2",
 * "4*pastör pipeti", "GAİTA KABI + N.LAM +LAMEL". Kısa kodlu malzeme Test tanımlarında her testte (Biolab ve Patoloji)
 * hasta başı adetle seçilir. Patoloji lameli LM (24×60); buradaki LAMEL22 mikroskopi lameli (22×22).
 * Yalnızca adı birebir tutan ve kısa kodu boş olan malzeme güncellenir; uzman ekrandan değiştirebilir.
 */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLYT: Biolab mikroskopi malzemelerine kısa kod (N.LAM, R.LAM, LAMEL22, PASTÖR, GAİTA, STİ, PETRİ)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE m SET SHORT_CODE = v.CODE
              FROM mlyt_material m
              JOIN (VALUES (N'N.LAM',   N'NORMAL LAM'),
                           (N'R.LAM',   N'RODAJLI LAM TEK'),
                           (N'LAMEL22', N'LAMEL 22X22'),
                           (N'PASTÖR',  N'PASTÖR PİPETİ'),
                           (N'GAİTA',   N'GAİTA KABI KAŞIKLI 15 ML'),
                           (N'STİ',     N'ST,İDRAR KABI'),
                           (N'PETRİ',   N'PETRİ KABI')) v (CODE, NAME) ON m.MATERIAL_NAME = v.NAME
             WHERE m.RECORD_FL > 0 AND m.SHORT_CODE IS NULL
               AND NOT EXISTS (SELECT 1 FROM mlyt_material o WHERE o.COMPANY_ID = m.COMPANY_ID AND o.SHORT_CODE = v.CODE AND o.RECORD_FL > 0)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE mlyt_material SET SHORT_CODE = NULL
             WHERE SHORT_CODE IN (N'N.LAM', N'R.LAM', N'LAMEL22', N'PASTÖR', N'GAİTA', N'STİ', N'PETRİ')");
    }
}
