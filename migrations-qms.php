<?php
// Biolab QMS migration ayarları (Biolab_QMS veritabanı). Portalınki: migrations.php
return [
    'table_storage'    => ['table_name' => 'doctrine_migration_versions'],
    'migrations_paths' => ['Biolab\MigrationsQms' => __DIR__ . '/model/migration-qms'],
    'all_or_nothing'   => true,
    'transactional'    => true,
];
