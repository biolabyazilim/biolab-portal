<?php
declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;

require __DIR__ . '/vendor/autoload.php';
$settings = require __DIR__ . '/config.php';

$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/model/Entity/BIO'],   // model/Entity/QMS ayrı veritabanında (bootstrap-qms.php)
    isDevMode: $settings['dev_mode'],
);
//$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_UPPER, true));
$config->enableNativeLazyObjects(true);

// SQL Server DEFAULT kısıtlamalarına sabit ad (DF_tablo_KOLON)
$sqlServerDefaults = new \Biolab\Model\Common\SqlServerDefaults();
$config->setMiddlewares([$sqlServerDefaults]);

$connection = DriverManager::getConnection($settings['db'], $config);

$em = new EntityManager($connection, $config);

$em->getEventManager()->addEventListener(
    \Doctrine\ORM\Events::onFlush,
    new \Biolab\Model\Common\AuditListener(
        fn (): ?int => isset($_SESSION['USER_ID']) ? (int) $_SESSION['USER_ID'] : null
    )
);
$em->getEventManager()->addEventListener(\Doctrine\ORM\Tools\ToolEvents::postGenerateSchemaTable, $sqlServerDefaults);

return $em;