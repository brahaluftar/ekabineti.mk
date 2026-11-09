<?php

declare(strict_types=1);

// Cron fallback for the revenue imports (Të hyrat): requeues reports whose background reading was interrupted and reads
// the ones still waiting. Normally the upload request reads the report itself right after answering the browser.
//   */5 * * * * php /var/www/ekabineti-bitola/current/bin/process-revenue-imports.php

use EKabineti\Core\Request;
use EKabineti\Service\Audit;
use EKabineti\Service\RevenueImport;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__).'/app/bootstrap.php';
$db = $app->database();
echo json_encode(RevenueImport::processQueue($db, new Audit($db, new Request()), 5), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
