<?php

declare(strict_types=1);

/**
 * Same-origin front controller for DreamHost when the Web Directory (or
 * subdirectory rewrite) points traffic at …/public/gateway.php.
 */
use FamilyQuiz\App;

// DreamHost / CGI often strips Authorization; restore from rewrite env.
if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
    foreach (['REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $key) {
        if (!empty($_SERVER[$key])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER[$key];
            break;
        }
    }
}

require dirname(__DIR__) . '/api/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/config.php';
$app = App::create($configPath);
$app->run();
