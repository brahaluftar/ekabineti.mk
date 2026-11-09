<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require dirname(__DIR__).'/app/bootstrap.php';
$result = $app->notifications()->processPending((int) ($argv[1] ?? 25));
$app->logger()->write('info', 'notifications.processed', $result);
echo json_encode($result, JSON_UNESCAPED_UNICODE).PHP_EOL;

