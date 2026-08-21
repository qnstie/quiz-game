#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/server/vendor/autoload.php';

use FamilyQuiz\Db\Connections;

$configPath = dirname(__DIR__) . '/server/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing server/config.php\n");
    exit(1);
}
$config = require $configPath;
Connections::configure($config);
Connections::appDb();
echo "App DB migrated at {$config['data_dir']}/app.db\n";

$projects = Connections::appDb()->query('SELECT id FROM projects')->fetchAll();
foreach ($projects as $p) {
    Connections::projectDb($p['id']);
    echo "Project DB ok: {$p['id']}\n";
}
echo "Done.\n";
