<?php

declare(strict_types=1);

// Router for PHP built-in server: php -S 127.0.0.1:8080 -t server/public server/public/router.php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
