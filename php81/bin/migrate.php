<?php

declare(strict_types=1);

/*
 * Ги извршува миграциите од database/migrations што сѐ уште не се евидентирани во dbo.migrations
 * (forward-only, идемпотентно; секоја миграција се евидентира еднаш).
 *
 * Миграциите менуваат шема (CREATE/ALTER), затоа се извршуваат со SQL сметка што има права db_owner/db_ddladmin,
 * а не со сметката на апликацијата (која има само читање и запишување):
 *
 *   EKABINETI_SQL_ADMIN_USER=... EKABINETI_SQL_ADMIN_PASSWORD=... php bin/migrate.php
 *
 * Без променливите, корисничкото име и лозинката се бараат интерактивно.
 */

use EKabineti\Core\Database;
use EKabineti\Service\MigrationRunner;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }

$app = require dirname(__DIR__).'/app/bootstrap.php';
$database = $app->config()['database'] ?? [];

$user = getenv('EKABINETI_SQL_ADMIN_USER');
if ($user === false) { echo 'Корисничко име на SQL администраторот (db_owner): '; $user = trim((string) fgets(STDIN)); }
$password = getenv('EKABINETI_SQL_ADMIN_PASSWORD');
if ($password === false) {
    echo 'Лозинка на SQL администраторот: ';
    $tty = DIRECTORY_SEPARATOR === '/' && function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $password = trim((string) fgets(STDIN));
    if ($tty) { shell_exec('stty echo'); echo PHP_EOL; }
}
if (trim((string) $user) === '' || (string) $password === '') { fwrite(STDERR, "Недостасуваат податоци за SQL администраторот.\n"); exit(2); }

try {
    $db = new Database(['username'=>(string) $user, 'password'=>(string) $password] + $database);
    $result = (new MigrationRunner($db, dirname(__DIR__).'/database/migrations'))->run();
    echo '[OK] Извршени миграции: '.count($result['applied']).', веќе извршени: '.count($result['skipped']).PHP_EOL;
    foreach ($result['applied'] as $name) echo '       + '.$name.PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] '.$e->getMessage().PHP_EOL.'Ниедна миграција од неуспешниот пакет не е евидентирана; поправете ја причината и извршете ја командата повторно.'.PHP_EOL);
    exit(1);
}
