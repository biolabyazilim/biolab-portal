<?php
declare(strict_types=1);

/**
 * Biolab QMS EntityManager'ı: Biolab_QMS veritabanı (config.php → db_qms), yalnızca model/Entity/QMS.
 * Portalın EntityManager'ı (bootstrap.php) bu entity'leri görmez; QMS de portal tablolarını görmez.
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;

require __DIR__ . '/vendor/autoload.php';
$settings = require __DIR__ . '/config.php';

$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/model/Entity/QMS'],
    isDevMode: $settings['dev_mode'],
);
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_UPPER, true));
$config->enableNativeLazyObjects(true);

// SQL Server DEFAULT kısıtlamalarına sabit ad (DF_tablo_KOLON)
$sqlServerDefaults = new \Biolab\Model\Common\SqlServerDefaults();
$config->setMiddlewares([$sqlServerDefaults]);

$connection = DriverManager::getConnection($settings['db_qms'], $config);

$em = new EntityManager($connection, $config);

$em->getEventManager()->addEventListener(
    \Doctrine\ORM\Events::onFlush,
    new \Biolab\Model\Common\Qms\QmsAuditListener(
        fn (): ?int => isset($_SESSION['USER_ID']) ? (int) $_SESSION['USER_ID'] : null
    )
);
$em->getEventManager()->addEventListener(\Doctrine\ORM\Tools\ToolEvents::postGenerateSchemaTable, $sqlServerDefaults);

return $em;
