#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Run on the DreamHost host after rsync.
 * Usage: php83 scripts/remote/bootstrap-env.php /path/to/app_root /path/to/data_dir https://public.url production|local
 */

if ($argc < 5) {
    fwrite(STDERR, "Usage: bootstrap-env.php APP_ROOT DATA_DIR PUBLIC_BASE_URL APP_ENV\n");
    exit(1);
}

[$appRoot, $dataDir, $publicBase, $appEnv] = [$argv[1], $argv[2], $argv[3], $argv[4]];
$configPath = $appRoot . '/config.php';
$apiDir = $appRoot . '/api';

if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
    fwrite(STDERR, "Cannot create data dir\n");
    exit(1);
}
chmod($dataDir, 0750);

$createdConfig = false;
if (!is_file($configPath)) {
    $cfg = [
        'jwt_secret' => bin2hex(random_bytes(32)),
        'data_dir' => $dataDir,
        'seed_admin_email' => 'pawel@kunstman.net',
        'seed_admin_password' => 'alamakota123',
        'public_base_url' => $publicBase,
        'api_base_url' => $publicBase,
        'cookie_domain' => '',
        'cors_origins' => [$publicBase],
        'max_upload_mb' => 50,
        'app_env' => $appEnv,
        'admin_magic_token' => bin2hex(random_bytes(32)),
    ];
    file_put_contents($configPath, "<?php\n\nreturn " . var_export($cfg, true) . ";\n");
    chmod($configPath, 0600);
    $createdConfig = true;
    echo "CREATED_CONFIG=1\n";
    echo 'admin_magic_token=' . $cfg['admin_magic_token'] . "\n";
} else {
    echo "CREATED_CONFIG=0\n";
    $cfg = require $configPath;
    if (!empty($cfg['admin_magic_token'])) {
        echo 'admin_magic_token=' . $cfg['admin_magic_token'] . "\n";
    }
}

$index = <<<'PHP'
<?php

declare(strict_types=1);

use FamilyQuiz\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__, 2) . '/config.php';
$app = App::create($configPath);
$app->run();
PHP;
file_put_contents($apiDir . '/public/index.php', $index);

require $apiDir . '/vendor/autoload.php';
$cfg = require $configPath;
\FamilyQuiz\Db\Connections::configure($cfg);
\FamilyQuiz\Db\Connections::appDb();
$seed = new \FamilyQuiz\Services\SeedService();
$seed->ensureSeedAdmin($cfg);
echo 'DB_READY=' . $cfg['data_dir'] . "\n";
