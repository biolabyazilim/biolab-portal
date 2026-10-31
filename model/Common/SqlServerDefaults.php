<?php
declare(strict_types=1);

namespace Biolab\Model\Common;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;

/**
 * SQL Server'da DEFAULT kısıtlamalarına sabit ad verir: DF_<tablo>_<KOLON>
 * (örn. DF_mng_user_ACTIVE_FL). Böylece diff, default'lu kolonların down()
 * kısmını da üretebilir. Entity'lerde hiçbir şey yazmaya gerek yok.
 */
final class SqlServerDefaults implements Middleware
{
    // 1) diff sırasında: entity kolonlarına kısıtlama adını ekler
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $tableName = $args->getClassMetadata()->getTableName();

        foreach ($args->getClassTable()->getColumns() as $column) {
            if ($column->getDefault() !== null) {
                $column->setPlatformOption(
                    SQLServerPlatform::OPTION_DEFAULT_CONSTRAINT_NAME,
                    'DF_' . $tableName . '_' . $column->getName(),
                );
            }
        }
    }

    // 2) SQL üretilirken: "ADD DEFAULT ..." yerine "ADD CONSTRAINT DF_... DEFAULT ..." yazar
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return new class extends SQLServerPlatform {
                    protected function getDefaultConstraintDeclarationSQL(array $column): string
                    {
                        $sql  = parent::getDefaultConstraintDeclarationSQL($column);
                        $name = $column[self::OPTION_DEFAULT_CONSTRAINT_NAME] ?? null;

                        return $name ? ' CONSTRAINT ' . $this->quoteSingleIdentifier($name) . $sql : $sql;
                    }
                };
            }
        };
    }
}
