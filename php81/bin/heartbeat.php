<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require dirname(__DIR__).'/app/bootstrap.php';
$db = $app->database();
$db->execute("MERGE cache AS target USING (SELECT :key AS [key],:value AS [value],:expiration AS [expiration]) AS source ON target.[key]=source.[key] WHEN MATCHED THEN UPDATE SET value=source.[value],expiration=source.[expiration] WHEN NOT MATCHED THEN INSERT ([key],value,expiration) VALUES (source.[key],source.[value],source.[expiration]);", ['key'=>'scheduler:last_heartbeat','value'=>gmdate('c'),'expiration'=>time()+600]);
echo gmdate('c').PHP_EOL;
