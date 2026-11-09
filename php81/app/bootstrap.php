<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'EKabineti\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require $file;
    }
});

$configuredPath = getenv('EKABINETI_CONFIG') ?: '';
$candidates = array_filter([
    $configuredPath,
    dirname(APP_ROOT, 2).'/shared/e-kabineti.php',
    dirname(APP_ROOT).'/shared/e-kabineti.php',
    APP_ROOT.'/config/e-kabineti.php',
]);
$configFile = '';
foreach ($candidates as $candidate) {
    if (is_file($candidate)) { $configFile = $candidate; break; }
}
if ($configFile === '') {
    throw new RuntimeException('Missing private configuration. Set EKABINETI_CONFIG or create ../shared/e-kabineti.php.');
}

$config = require $configFile;
if (!is_array($config)) {
    throw new RuntimeException('The application configuration must return an array.');
}

$timezone = (string) ($config['app']['timezone'] ?? 'Europe/Skopje');
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    throw new RuntimeException('Invalid IANA timezone: '.$timezone);
}
date_default_timezone_set($timezone);

return new EKabineti\Core\Application($config);
