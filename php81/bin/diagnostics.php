<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$configPath = getenv('EKABINETI_CONFIG') ?: dirname($root, 2).'/shared/e-kabineti.php';
$config = is_file($configPath) ? require $configPath : [];
$database = is_array($config) ? ($config['database'] ?? []) : [];

echo 'Release: '.trim((string) @file_get_contents($root.'/RELEASE')).PHP_EOL;
echo 'PHP: '.PHP_VERSION.' · '.PHP_SAPI.PHP_EOL;
echo 'pdo_sqlsrv: '.(phpversion('pdo_sqlsrv') ?: 'unavailable').PHP_EOL;
echo 'sqlsrv: '.(phpversion('sqlsrv') ?: 'unavailable').PHP_EOL;
echo 'TLS requested: '.(($database['encrypt'] ?? true) ? 'TRUE' : 'FALSE').PHP_EOL;
echo 'Trust server certificate: '.(($database['trust_server_certificate'] ?? false) ? 'TRUE' : 'FALSE').PHP_EOL;
echo 'SQL Server endpoint: '.($database['host'] ?? 'unconfigured').':'.($database['port'] ?? 'unconfigured').PHP_EOL;
echo 'Database name: '.($database['name'] ?? 'unconfigured').PHP_EOL;
echo 'Database credentials: '.(trim((string)($database['username'] ?? '')) !== '' ? 'CONFIGURED' : 'MISSING').PHP_EOL;
echo 'Database connection: validated through /health/details during activation'.PHP_EOL;
