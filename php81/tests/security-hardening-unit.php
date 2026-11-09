<?php
// Regression checks for release 2026.10.23.01 (no database required): php tests/security-hardening-unit.php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; }
});
use EKabineti\Core\Money;
use EKabineti\Core\Request;
use EKabineti\Service\TabularExport;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; echo ($condition ? '[OK] ' : '[FAIL] ').$message.PHP_EOL; };
$src = static fn(string $path): string => (string) file_get_contents(APP_ROOT.'/'.$path);

// BUG-02/03: no undefined $total checks in edit handlers.
$assert(!str_contains($src('app/Controller/WriteController.php'), 'if($total<=0)throw new HttpException(422,\'Totali duhet të jetë më i madh se zero.\');$token=$this->requireField(\'updated_at\',23);$becameCritical'), 'risk edit no longer checks an undefined $total');
$assert(!preg_match('/\$active=\$this->intField\(\'active\',0,1\);if\(\$total/', $src('app/Controller/WriteController.php')), 'directorate edit no longer checks an undefined $total');

// BUG-04: no SQL literal reuses a named placeholder (PDO_SQLSRV native prepares).
$duplicates = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT.'/app')) as $file) {
    if ($file->getExtension() !== 'php') continue;
    $literals = [];
    foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/\b(SELECT|UPDATE|INSERT|DELETE|MERGE)\b/', $token[1])) $literals[] = $token[1];
    }
    foreach ($literals as $literal) {
        preg_match_all('/(?<![:\w]):([a-zA-Z_]\w*)/', $literal, $names);
        foreach (array_count_values($names[1]) as $name => $count) if ($count > 1) $duplicates[] = basename($file->getPathname()).': :'.$name;
    }
}
$assert($duplicates === [], 'no SQL statement repeats a named parameter'.($duplicates ? ' ('.implode(', ', array_unique($duplicates)).')' : ''));
$assert(str_contains($src('app/Controller/ReadController.php'), 'private function likeAny('), 'list search uses distinct LIKE placeholders');

// BUG-01: the private configuration is not part of the repository.
$assert(!is_file(dirname(APP_ROOT).'/dist/e-kabineti.php'), 'dist/e-kabineti.php (production secrets) removed');

// BUG-06, BUG-07, BUG-12: link revoke, session revocation, persistent throttle.
$assert(!str_contains($src('app/Controller/EvidenceController.php'), '$this->user)'), 'document link revoke no longer uses an undefined property');
$assert(str_contains($src('app/Security/Auth.php'), 'session_epoch') && str_contains($src('app/Controller/WriteController.php'), 'revokeSessions($id)'), 'password reset invalidates existing sessions');
$assert(str_contains($src('app/Controller/WriteController.php'), 'new LoginThrottle') && !str_contains($src('app/Core/Application.php'), '_login_attempts'), 'login throttle is database-backed');

// BUG-15/16/34: request helpers.
$_SERVER = ['HTTP_HOST' => 'kabineti.example', 'HTTP_REFERER' => 'https://evil.example/phish', 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php'];
$request = new Request();
$assert($request->safeReferer('/') === '/', 'cross-site Referer is not used as a redirect target');
$_SERVER['HTTP_REFERER'] = 'https://kabineti.example/projects?q=x';
$assert($request->safeReferer('/') === '/projects?q=x', 'same-origin Referer keeps path and query');
$assert($request->ip() === '10.0.0.5', 'X-Forwarded-For ignored from untrusted clients');
Request::$trustedProxies = ['10.0.0.5'];
$assert($request->ip() === '1.2.3.4', 'X-Forwarded-For honoured from a trusted proxy');
$_GET = ['year' => '0'];
$assert($request->integer('year', 5) === 0, 'integer() keeps a valid 0');

// BUG-37/42: exact money comparison and CSV injection.
$assert(Money::compare('100.0001', '100') === 1 && Money::compare('99.99', '100.00') === -1 && Money::compare('5', '5.0000') === 0, 'decimal comparison is exact');
$assert(TabularExport::csvSafe('=HYPERLINK("x")') === '\'=HYPERLINK("x")' && TabularExport::csvSafe('-12.50') === '-12.50', 'CSV formulas are neutralised, numbers untouched');

// BUG-09/11/19/21/35: notifications, workflow posting, scanner, deployment, multi-role access.
$assert(str_contains($src('app/Service/Notifications.php'), 'bool $dedupe = false'), 'business notifications are no longer de-duplicated by subject');
$assert(str_contains($src('app/Controller/WriteController.php'), 'elseif($isChange){$this->postBudgetChange('), 'final workflow approval posts the budget change');
$assert(!str_contains((string) file_get_contents(dirname(APP_ROOT).'/deploy/apache/e-kabineti.conf'), 'geolocation=()') && str_contains((string) file_get_contents(dirname(APP_ROOT).'/deploy/apache/e-kabineti.conf'), 'HTTP_AUTHORIZATION'), 'Apache vhost forwards Authorization and allows geolocation');
$assert(str_contains($src('app/Controller/WriteController.php'), 'private function applyAccessChanges(') && !str_contains($src('app/Controller/WriteController.php'), "UPDATE user_role_scopes SET valid_to=DATEADD(second,-1,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE user_id=:id AND (valid_to IS NULL"), 'saving a user no longer expires every role');
$assert(str_contains($src('database/migrations/2026_10_23_002900_security_and_integrity_hardening.sql'), 'tr_audit_events_append_only'), 'audit trail is append-only');

// SQL Server error 130: an aggregate cannot wrap a subquery (e.g. SUM(COALESCE((SELECT ...),0))).
$aggregateOverSubquery = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT.'/app')) as $file) {
    if ($file->getExtension() !== 'php') continue;
    if (preg_match_all('/\b(SUM|AVG|MIN|MAX|COUNT)\(\s*(?:COALESCE\(\s*|ISNULL\(\s*)?\(\s*SELECT\b/i', (string) file_get_contents($file->getPathname()), $m)) $aggregateOverSubquery[] = basename($file->getPathname());
}
$assert($aggregateOverSubquery === [], 'no SQL aggregates over a subquery (SQL Server error 130)'.($aggregateOverSubquery ? ': '.implode(', ', $aggregateOverSubquery) : ''));

if ($failures) { fwrite(STDERR, 'FAIL: '.count($failures).' check(s)'.PHP_EOL); exit(1); }
echo 'PASS: security-hardening-unit'.PHP_EOL;
