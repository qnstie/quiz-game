<?php

declare(strict_types=1);

use FamilyQuiz\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::create();
$app->run();
