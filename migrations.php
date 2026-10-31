<?php
return [
    'table_storage'    => ['table_name' => 'doctrine_migration_versions'],
    'migrations_paths' => ['Biolab\Migrations' => __DIR__ . '/model/migration'],
    'all_or_nothing'   => true,
    'transactional'    => true,
];