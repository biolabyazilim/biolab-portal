<?php
declare(strict_types=1);

/**
 * Biolab QMS için doctrine-migrations komut satırı (vendor\bin\doctrine-migrations'ın QMS karşılığı).
 * cli-config.php portal veritabanına bağlandığı için QMS komutları bu dosyadan çalışır:
 *   php qms-migrations.php status | diff | migrate ...   (menü: migration-qms.bat)
 */

use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\ConsoleRunner;

$em = require __DIR__ . '/bootstrap-qms.php';

ConsoleRunner::run([], DependencyFactory::fromEntityManager(
    new PhpFile(__DIR__ . '/migrations-qms.php'),
    new ExistingEntityManager($em)
));
