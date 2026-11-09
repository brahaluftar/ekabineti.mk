<?php
declare(strict_types=1);

// Password policy: 14 characters, lowercase, uppercase, number, symbol; a mismatched confirmation is reported as such.
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };
$problems = [EKabineti\Controller\WriteController::class, 'passwordProblems'];

$assert($problems('ArtaProva2026$.') === [], 'a password with 15 characters, lower, upper, number and symbol is accepted');
$assert($problems('Ëndrra-Çelësi7x') === [], 'Albanian letters count as letters and as one character each');
$assert($problems('artaprova2026$.') === ['голема буква'], 'a missing uppercase letter is named');
$assert($problems('ArtaProva2026x') === ['симбол (на пр. $ . ! @ #)'], 'a missing symbol is named');
$assert($problems('Ab1$') === ['најмалку 14 знаци (сега 4)'], 'a short password says how long it is');
$source = (string) file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');
$assert(str_contains($source, "if(\$password!==\$confirmation)throw new HttpException(422,'Лозинката и нејзиното повторување не се совпаѓаат.") && !str_contains($source, '$password!==$confirmation||strlen($password)<14'), 'a mismatched confirmation is no longer reported as a weak password');
$assert(substr_count($source, 'self::assertPasswordPolicy($password);') === 3, 'registration, profile change and admin reset use the same policy');

if ($failures) { fwrite(STDERR, "FAIL\n - ".implode("\n - ", $failures)."\n"); exit(1); }
echo "password-policy-unit: OK\n";
