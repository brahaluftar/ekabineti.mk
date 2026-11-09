<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require dirname(__DIR__).'/app/bootstrap.php';
$config = $app->config();
$scanner = new \EKabineti\Service\DocumentScanner($app->database(), $app->logger(), dirname(__DIR__).'/storage/private', ($config['uploads']['clamav_binary'] ?? null) ?: null);
$result = $scanner->run((int) ($argv[1] ?? 50));
echo json_encode($result).PHP_EOL;
exit(in_array($result['status'], ['ok', 'partial'], true) && $result['errors'] === 0 ? 0 : 1);
