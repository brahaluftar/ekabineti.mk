<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$checks = [
    'php_8_1_or_newer'=>version_compare(PHP_VERSION, '8.1.0', '>='),
    'pdo_sqlsrv'=>extension_loaded('pdo_sqlsrv'),
    'sqlsrv'=>extension_loaded('sqlsrv'),
    'curl'=>extension_loaded('curl'),
    'mbstring'=>extension_loaded('mbstring'),
    'fileinfo'=>extension_loaded('fileinfo'),
    'simplexml'=>extension_loaded('simplexml'),
    'zip'=>extension_loaded('zip'),
    'sodium'=>extension_loaded('sodium'),
    'openssl'=>extension_loaded('openssl'),
    'legacy_xls_converter'=>PHP_OS_FAMILY === 'Windows' ? class_exists('COM') : trim((string)shell_exec('command -v libreoffice 2>/dev/null || command -v soffice 2>/dev/null')) !== '',
    'storage_writable'=>is_dir($root.'/storage') && is_writable($root.'/storage'),
];

$configPath = getenv('EKABINETI_CONFIG') ?: dirname($root, 2).'/shared/e-kabineti.php';
$config = is_file($configPath) ? require $configPath : null;
$checks['private_config_readable'] = is_array($config);
if (is_array($config)) {
    $database = $config['database'] ?? [];
    $checks['database_config_complete'] = trim((string)($database['host'] ?? '')) !== ''
        && (int)($database['port'] ?? 0) > 0
        && trim((string)($database['name'] ?? '')) !== ''
        && trim((string)($database['username'] ?? '')) !== ''
        && trim((string)($database['password'] ?? '')) !== '';
    $checks['database_tls_requested'] = ($database['encrypt'] ?? true) === true;
    $appUrl = (string)($config['app']['url'] ?? '');
    $checks['https_app_url'] = ($config['app']['env'] ?? 'production') !== 'production'
        ? str_starts_with($appUrl, 'http://')
        : str_starts_with($appUrl, 'https://');
    $checks['health_token_present'] = strlen(trim((string)($config['app']['health_token'] ?? ''))) >= 32;
    $google = is_array($config['google_sheets'] ?? null) ? $config['google_sheets'] : [];
    $enabledEnvironment = getenv('GOOGLE_SHEETS_SYNC_ENABLED');
    $googleEnabled = $enabledEnvironment !== false
        ? in_array(strtolower(trim($enabledEnvironment)), ['1','true','yes','on'], true)
        : (bool)($google['enabled'] ?? false);
    if ($googleEnabled) {
        $spreadsheetId = (string)(getenv('GOOGLE_SHEETS_SPREADSHEET_ID') ?: ($google['spreadsheet_id'] ?? ''));
        $dashboardGid = (string)(getenv('GOOGLE_SHEETS_DASHBOARD_GID') ?: ($google['dashboard_gid'] ?? '0'));
        $credentials = (string)(getenv('GOOGLE_SERVICE_ACCOUNT_CREDENTIALS') ?: ($google['credentials'] ?? ''));
        $credentialsFile = (string)(getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ($google['credentials_file'] ?? ''));
        $syncTimezone = (string)(getenv('GOOGLE_SHEETS_SYNC_TIMEZONE') ?: ($google['timezone'] ?? 'Europe/Skopje'));
        $checks['google_spreadsheet_id'] = (bool)preg_match('/^[A-Za-z0-9_-]{20,}$/', $spreadsheetId);
        $checks['google_dashboard_gid'] = (bool)preg_match('/^\d+$/', $dashboardGid);
        $checks['google_credentials_configured'] = trim($credentials) !== '' || trim($credentialsFile) !== '';
        $checks['google_sync_timezone'] = in_array($syncTimezone, timezone_identifiers_list(), true);
    }
}

$failed = [];
foreach ($checks as $name=>$ok) {
    echo ($ok ? '[OK]   ' : '[FAIL] ').$name.PHP_EOL;
    if (!$ok) {
        $failed[] = $name;
    }
}

echo '[INFO] Database and schema are validated by the protected web health check during atomic activation.'.PHP_EOL;
exit($failed === [] ? 0 : 1);
