#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Usage: php update-public-url.php APP_ROOT PUBLIC_BASE_URL URL_PATH_PREFIX DATA_DIR
 */
if ($argc < 5) {
    fwrite(STDERR, "Usage: update-public-url.php APP_ROOT PUBLIC_BASE_URL URL_PATH_PREFIX DATA_DIR\n");
    exit(1);
}

[$appRoot, $publicBase, $pathPrefix, $dataDir] = [$argv[1], $argv[2], $argv[3], $argv[4]];
$path = $appRoot . '/config.php';
if (!is_file($path)) {
    fwrite(STDERR, "Missing config: {$path}\n");
    exit(1);
}

$c = require $path;
$c['public_base_url'] = $publicBase;
$c['api_base_url'] = $publicBase;
$c['cors_origins'] = [$publicBase, 'https://www.kunstman.net'];
$c['url_path_prefix'] = $pathPrefix;
$c['data_dir'] = $dataDir;

file_put_contents($path, "<?php\n\nreturn " . var_export($c, true) . ";\n");
chmod($path, 0600);
echo "CONFIG_UPDATED=1\n";
echo "public_base_url={$publicBase}\n";
