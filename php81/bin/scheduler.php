<?php

declare(strict_types=1);

use EKabineti\Service\Scheduler;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__).'/app/bootstrap.php';
$scheduler = new Scheduler($app->database(), $app->notifications(), $app->logger(), dirname(__DIR__).'/storage', ($app->config()['uploads']['clamav_binary'] ?? null) ?: null, $app->config());
echo json_encode($scheduler->run(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
