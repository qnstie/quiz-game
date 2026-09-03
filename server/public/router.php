<?php

declare(strict_types=1);

// Router for PHP built-in server: php -S 127.0.0.1:8080 -t server/public server/public/router.php
// Serves the built SPA from web/dist so local quiz needs only PHP (no Vite).

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

$isApi = $path === '/health'
    || str_starts_with($path, '/api')
    || str_starts_with($path, '/media');

if (!$isApi) {
    $dist = dirname(__DIR__, 2) . '/web/dist';
    $distReal = realpath($dist);
    if ($distReal !== false) {
        $candidate = $path === '/' ? $distReal . '/index.html' : $distReal . $path;
        $resolved = is_file($candidate) ? realpath($candidate) : false;
        if ($resolved !== false && str_starts_with($resolved, $distReal . DIRECTORY_SEPARATOR)) {
            $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'html' => 'text/html; charset=utf-8',
                'js', 'mjs' => 'text/javascript; charset=utf-8',
                'css' => 'text/css; charset=utf-8',
                'json', 'webmanifest', 'map' => 'application/json',
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'ico' => 'image/x-icon',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                default => mime_content_type($resolved) ?: 'application/octet-stream',
            };
            header('Content-Type: ' . $mime);
            readfile($resolved);
            return true;
        }

        $index = $distReal . '/index.html';
        $hasExt = str_contains(basename($path), '.');
        if (!$hasExt && is_file($index)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($index);
            return true;
        }
    }
}

require __DIR__ . '/index.php';
