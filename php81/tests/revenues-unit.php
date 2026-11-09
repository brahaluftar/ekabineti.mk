<?php
declare(strict_types=1);

// Të hyrat: reading the daily revenue report, preview → confirmation, no duplicates on re-import.
// All names and numbers below are invented.
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });

use EKabineti\Service\RevenueImport;
use EKabineti\Service\RevenueReport;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

// One row = Nr · Shuma · Përfituesi · Paguesi · Grupi · Përshkrimi · Data · UNIREF (no header, as in the bank export).
$report = [
    ['', '', '', '', '', '', '', ''],
    ['0', '45.5', 'KOMUNA-Prishtina', 'TEB SH.A.', '10', 'TEB SH.A. 481 PRB2K3354000111A 1000000017 Arta Testi Prishtine', '01.10.2026', 'PRB2K3354000111A'],
    ['1', '10', 'Kuvendi Komunal-Prishtinë', 'BLERIM PROVA', '40', 'Pagese per KK Prishtine 01 234 AB', '01.10.2026', 'PRB4A0000400001B'],
    ['2', '50', 'Komuna e Prishtines', '01029', '30', 'KOMUNA-Prishtina 01029 NEA PROVA PAGES KOPSHTI TETOR 2026 PRF4J1111111111S', '02.10.2026', 'PRF4J1111111111S'],
    ['3', '120.75', 'Komuna e Prishtines', 'DRITA PROVA', '10', 'TATIMI NË PRONË - NR.TATIMPAGUESIT 2190001', '02.10.2026', 'PRB2K3354000222C'],
    ['4', '2', 'KOMUNA-Prishtina', 'GENTI PROVA', '40', 'Pagese per KK Prishtine 11 942 03 0150001 26 CERT KP', '02.10.2026', 'PRGFP1926010001X'],
    ['5', '2', 'KOMUNA-Prishtina', 'GENTI PROVA', '40', 'Pagese per KK Prishtine 11 942 03 0150001 26 CERT KP', '02.10.2026', 'PRGFP1926010001X'],
    ['6', '15', 'KOMUNA-Prishtina', 'PROVA SHPK', '40', 'Pagese per KK Prishtine KUROR NRF. 811000001', '03.10.2026', 'PRQ4P11111111112'],
    ['7', '10', 'KOMUNA-Prishtina', 'ILIR PROVA', '40', 'TAKSE KOMUNALE', '03.10.2026', 'PRB4A'],
    ['8', '0', 'KOMUNA-Prishtina', 'ILIR PROVA', '40', 'Pagese pa shumë', '03.10.2026', 'PRB4A0000400002C'],
    ['9', '30', 'KOMUNA-Prishtina', 'ILIR PROVA', '40', 'Data e gabuar', '32.13.2026', 'PRN9S1111111111P'],
    ['10', '20', 'KOMUNA-Prishtina', 'ARBEN PROVA', '40', 'pagese ID 1000000025 FAT NR 00123', '03.10.2026', 'PRZZX1111111111A'],
];

// Reading the rows.
$parsed = RevenueReport::parse($report);
$rows = $parsed['rows'];
$assert($parsed['fatal'] === [] && count($rows) === 11, 'the empty first line is skipped and every payment row is read');
$teb = $rows[0];
$assert($teb['raw_description'] === 'TEB SH.A. 481 PRB2K3354000111A 1000000017 Arta Testi Prishtine' && $teb['raw_amount'] === '45.5' && $teb['raw_payment_date'] === '01.10.2026', 'source values are kept exactly as in the file');
$assert($teb['payment_date'] === '2026-10-01' && $teb['amount'] === '45.50' && $teb['revenue_group'] === '10', 'date, amount and group are read');
$assert($teb['uniref_municipality'] === 'PR' && $teb['uniref_type'] === 'B2' && $teb['uniref_subtype'] === 'K' && $teb['uniref_reference'] === '3354000111' && $teb['uniref_generic'] === 0, 'UNIREF is split into municipality, type, subtype and reference');
$assert($teb['payer_name'] === 'Arta Testi' && $teb['payer_type'] === 'individual' && $teb['identification_number'] === '1000000017' && $teb['identification_type'] === 'personal_number', 'payer name and personal number are read from a bank row (TEB)');
$assert($teb['invoice_number'] === '3354000111' && $teb['category_code'] === 'property_tax' && $teb['classification_method'] === 'uniref', 'the property-tax bill number comes from the UNIREF; the type comes from the UNIREF rule');
$assert($rows[1]['plate_number'] === '01-234-AB' && $rows[1]['category_code'] === 'vehicle_registration' && $rows[1]['payer_name'] === 'BLERIM PROVA', 'licence plates are normalised (01-234-AB)');
$assert($rows[2]['invoice_number'] === '01029' && $rows[2]['category_code'] === 'edu_kindergarten' && $rows[2]['uniref_generic'] === 1 && $rows[2]['payer_name'] === 'NEA PROVA', 'kindergarten: invoice number, generic UNIREF of the service, child name from the description');
$assert($rows[3]['taxpayer_number'] === '2190001' && $rows[3]['property_number'] === null, 'the property-tax register number is the taxpayer number, not the property number');
$assert($rows[4]['case_reference'] === '11-942/03-0150001/26' && $rows[4]['category_code'] === 'cadastre_data', 'case / protocol numbers are read (11-942/03-0150001/26)');
$assert($rows[4]['occurrence'] === 1 && $rows[5]['occurrence'] === 2 && $rows[4]['source_key'].'#2' === $rows[5]['source_key'], 'identical rows of one file get the same key plus #n');
$assert($rows[6]['category_code'] === 'civil_registry' && $rows[6]['payer_type'] === 'business' && $rows[6]['identification_number'] === '811000001' && $rows[6]['identification_type'] === 'business_number', 'businesses and their NUI are recognised');
$assert($rows[7]['category_code'] === 'vehicle_registration' && in_array('incomplete_uniref', $rows[7]['warnings'], true) && $rows[7]['errors'] === [], 'a cut-off UNIREF (PRB4A) still gives the type, with a warning');
$assert($rows[8]['errors'] === ['invalid_amount'] && $rows[9]['errors'] === ['invalid_date'] && $rows[8]['source_key'] === null, 'rows without a positive amount or a valid date are errors');
$assert($rows[10]['category_code'] === 'unclassified' && in_array('unclassified', $rows[10]['warnings'], true) && $rows[10]['identification_number'] === '1000000025' && $rows[10]['invoice_number'] === '00123', 'unknown types stay unclassified; labelled ID and invoice numbers are read');
$assert(RevenueReport::classify('F9', 'DUPLIKAT', '30', RevenueReport::RULES)[0] === 'edu_duplicates' && RevenueReport::classify('FJ', 'SHFRYTEZIMI I SALLES', '30', RevenueReport::RULES)[0] === 'edu_halls' && RevenueReport::classify('FX', 'QIRA', '30', RevenueReport::RULES)[0] === 'edu_other', 'the longest / highest-priority rule wins (F9, F + SALL, F)');
$assert(RevenueReport::classify(null, '', '20', RevenueReport::RULES) === ['health', 'group'], 'without a UNIREF the group decides');
$assert(RevenueReport::parse([['x', 'y', 'z']])['fatal'] !== [], 'a file of another layout is rejected');
$twice = RevenueReport::parse([
    ['3', '1000', 'Komuna - Prishtina', 'Provë Shpk', '40', 'Pages ne baze te Fatures Nr. 1926000001 PRA8H1926000001U', '20.10.2026', 'PRA8H1926000001U'],
    ['14', '1000', 'Komuna - Prishtina', 'Provë shpk', '40', 'Pages ne baze te Fatures Nr. 1926000001 PRA8H1926000001U', '20.10.2026', 'PRA8H1926000001U'],
    ['15', '5', 'Komuna - Prishtina', 'Друго', '40', 'Pagese', '20.10.2026', 'PRA8H1926000002U'],
])['rows'];
$assert($twice[0]['source_key'] !== $twice[1]['source_key'] && $twice[1]['occurrence'] === 1, 'rows typed differently ("Shpk" / "shpk") are separate payments, not merged');
$assert(in_array('possible_duplicate', $twice[0]['warnings'], true) && in_array('possible_duplicate', $twice[1]['warnings'], true) && !in_array('possible_duplicate', $twice[2]['warnings'], true), '…but both are marked as a possible double payment of the same invoice');

// Preview and confirmation on a real database (SQLite standing in for SQL Server).
final class TsqlOnSqlite extends PDO { public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare(str_replace(' WITH (UPDLOCK,HOLDLOCK)', '', $query), $options); } }
$pdo = new TsqlOnSqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('SYSUTCDATETIME', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$pdo->exec('CREATE TABLE import_batches(id TEXT PRIMARY KEY,type TEXT,storage_key TEXT,status TEXT,summary TEXT,errors TEXT,created_by INT,confirmed_at TEXT,created_at TEXT,updated_at TEXT)');
$pdo->exec('CREATE TABLE audit_events(id INTEGER PRIMARY KEY,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,before_state TEXT,after_state TEXT,ip_address TEXT,correlation_id TEXT,reason TEXT,occurred_at TEXT)');
$pdo->exec('CREATE TABLE revenue_categories(id INTEGER PRIMARY KEY,code TEXT UNIQUE,name TEXT,revenue_group TEXT,sort_order INT,active INT DEFAULT 1)');
$pdo->exec('CREATE TABLE revenue_category_rules(id INTEGER PRIMARY KEY,uniref_prefix TEXT,keyword TEXT,category_id INT,priority INT,active INT DEFAULT 1,created_by INT,created_at TEXT)');
$fields = implode(',', array_map(static fn (string $f): string => $f.' TEXT', RevenueImport::FIELDS));
$pdo->exec('CREATE TABLE revenue_import_rows(id INTEGER PRIMARY KEY,import_batch_id TEXT,source_row_number INT,row_status TEXT,errors TEXT,source_key TEXT,content_key TEXT,loose_key TEXT,occurrence INT,'.$fields.')');
$pdo->exec('CREATE TABLE revenue_transactions(id INTEGER PRIMARY KEY,source_key TEXT UNIQUE,import_batch_id TEXT,source_row_number INT,'.$fields.',classified_by INT,classified_at TEXT,created_at TEXT,updated_at TEXT)');
$i = 0;
foreach (RevenueReport::CATEGORIES as $code => [$name, $group]) $pdo->prepare('INSERT INTO revenue_categories(code,name,revenue_group,sort_order) VALUES(?,?,?,?)')->execute([$code, $name, $group, $i += 10]);
foreach (RevenueReport::RULES as [$prefix, $keyword, $code, $priority]) $pdo->prepare('INSERT INTO revenue_category_rules(uniref_prefix,keyword,category_id,priority) SELECT ?,?,id,? FROM revenue_categories WHERE code=?')->execute([$prefix, $keyword, $priority, $code]);
$db = (new ReflectionClass(EKabineti\Core\Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(EKabineti\Core\Database::class, 'pdo'))->setValue($db, $pdo);
$_SERVER += ['REQUEST_METHOD'=>'POST', 'REQUEST_URI'=>'/revenues/import', 'REMOTE_ADDR'=>'127.0.0.1'];
$import = new RevenueImport($db, new EKabineti\Service\Audit($db, new EKabineti\Core\Request()), 7);
$batch = static function (string $id) use ($pdo): void { $pdo->prepare("INSERT INTO import_batches(id,type,status,created_by) VALUES(?,'revenue_daily','preview',7)")->execute([$id]); };
$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

$batch('b1');
$first = $import->preview('b1', $report, ['source_file_name'=>'raporti.xlsx']);
$assert($first['status'] === 'ready' && $first['counts'] === ['new'=>8, 'existing'=>0, 'duplicate_in_file'=>1, 'invalid'=>2], 'preview: 8 new, 1 repeated in the file, 2 with errors ('.json_encode($first['counts']).')');
$assert($count('SELECT COUNT(*) FROM revenue_transactions') === 0, 'nothing is imported before confirmation');
$posted = $import->confirm('b1');
$assert($posted['imported'] === 8 && $count('SELECT COUNT(*) FROM revenue_transactions') === 8 && $posted['amount'] === '273.25', 'confirmation imports only the new rows ('.json_encode($posted).')');
$assert($count("SELECT COUNT(*) FROM revenue_import_rows WHERE import_batch_id='b1'") === 3, 'the preview keeps only the rows that were not imported');
$assert($count("SELECT COUNT(*) FROM revenue_transactions WHERE raw_description LIKE 'TEB SH.A. 481%' AND payer_name='Arta Testi' AND identification_number='1000000017'") === 1, 'source values and the values read from them are stored together');
try { $import->confirm('b1'); $assert(false, 'a posted batch cannot be confirmed twice'); } catch (EKabineti\Core\HttpException $e) { $assert($e->getCode() === 409 || str_contains($e->getMessage(), 'потврден'), 'a posted batch cannot be confirmed twice'); }

$batch('b2');
$again = $import->preview('b2', $report);
$assert($again['status'] === 'unchanged' && $again['counts']['new'] === 0 && $again['counts']['existing'] === 8, 'the same file again: everything already exists, nothing to import');

$batch('b3');
$overlap = array_merge(array_slice($report, 3, 2), [['0', '33.30', 'KOMUNA-Prishtina', 'VJOSA PROVA', '40', 'Pagese per KK Prishtine 01 777 ZZ', '04.10.2026', 'PRB4A0000400009Z']]);
$third = $import->preview('b3', $overlap);
$assert($third['counts']['new'] === 1 && $third['counts']['existing'] === 2, 'an overlapping report adds only the new payment');
$import->confirm('b3');

$batch('b4');
$separate = $import->preview('b4', $report, ['duplicates'=>'separate']);
$assert($separate['counts']['new'] === 1 && $separate['counts']['duplicate_in_file'] === 0, '"separate payments" adds the second identical row once, and only once');
$import->confirm('b4');
$batch('b5');
$assert($import->preview('b5', $report, ['duplicates'=>'separate'])['counts']['new'] === 0, 'repeating it with "separate payments" adds nothing more');

$batch('b6');
$import->preview('b6', [['0', '5', 'X', 'Y', '40', 'z', '05.10.2026', 'PRQQX1111111111A']]);
$import->cancel('b6');
$assert($count("SELECT COUNT(*) FROM revenue_import_rows WHERE import_batch_id='b6'") === 0 && $pdo->query("SELECT status FROM import_batches WHERE id='b6'")->fetchColumn() === 'cancelled', 'cancelling removes the preview');

$pdo->exec("INSERT INTO revenue_categories(code,name,revenue_group,sort_order) VALUES('test_zz','Provë','40',999)");
$categoryId = (int) $pdo->query("SELECT id FROM revenue_categories WHERE code='test_zz'")->fetchColumn();
$assert($import->applyRule('ZZ', 'FAT', $categoryId) === 1 && $count("SELECT COUNT(*) FROM revenue_transactions WHERE category_id={$categoryId} AND classification_method='keyword'") === 1, 'a new rule classifies the payments still unclassified');
$assert($import->applyRule('B2', null, $categoryId) === 0, 'payments already classified by a rule keep their type');

$js = (string) file_get_contents(APP_ROOT.'/public/assets/app.js');
// Background reading: the upload only queues the report; process() reads it, once.
$csvKey = 'imports/revenues-unit-'.getmypid().'/source.csv';
@mkdir(APP_ROOT.'/storage/private/'.dirname($csvKey), 0770, true);
file_put_contents(APP_ROOT.'/storage/private/'.$csvKey, "0;7.5;KOMUNA-Prishtina;DRITON PROVA;40;Pagese per KK Prishtine 01 555 QQ;06.10.2026;PRB4A0000400555Q\n1;8;KOMUNA-Prishtina;DRITON PROVA;40;Pagese per KK Prishtine 01 556 QQ;06.10.2026;PRB4A0000400556Q\n");
$import->queue('q1', $csvKey, ['source_file_name'=>'raporti.csv', 'source_sha256'=>str_repeat('a', 64)]);
$assert($pdo->query("SELECT status FROM import_batches WHERE id='q1'")->fetchColumn() === 'queued' && $count("SELECT COUNT(*) FROM revenue_import_rows WHERE import_batch_id='q1'") === 0, 'the upload only queues the report');
$assert($import->process('q1', 0) === 'ready' && $count("SELECT COUNT(*) FROM revenue_import_rows WHERE import_batch_id='q1' AND row_status='new'") === 2, 'the background step reads the file and writes the preview');
$assert($import->process('q1', 0) === 'not_queued', 'a report is read only once (queued → processing claim)');
$import->confirm('q1');
$assert($import->sameFileImported(str_repeat('a', 64), 'other') !== null && $import->sameFileImported(str_repeat('b', 64), 'other') === null, 'uploading the same file again is recognised');
@unlink(APP_ROOT.'/storage/private/'.$csvKey); @rmdir(APP_ROOT.'/storage/private/'.dirname($csvKey));
$import->queue('q2', 'imports/does-not-exist/source.xlsx', []);
$assert($import->process('q2', 0) === 'failed' && $pdo->query("SELECT status FROM import_batches WHERE id='q2'")->fetchColumn() === 'failed' && str_contains((string) $pdo->query("SELECT errors FROM import_batches WHERE id='q2'")->fetchColumn(), 'не се наоѓа'), 'a report that cannot be read ends as "failed" with the reason');
$held = fopen(APP_ROOT.'/storage/revenue-import.lock', 'c+'); flock($held, LOCK_EX);
$import->queue('q3', $csvKey, []);
$assert($import->process('q3', 0) === 'busy' && $pdo->query("SELECT status FROM import_batches WHERE id='q3'")->fetchColumn() === 'queued', 'only one report is read at a time; the next one waits in the queue');
flock($held, LOCK_UN); fclose($held);
$serviceSource = (string) file_get_contents(APP_ROOT.'/app/Service/RevenueImport.php');
$controllerSource = (string) file_get_contents(APP_ROOT.'/app/Controller/RevenueController.php');
$assert(str_contains($controllerSource, "\$this->respondAndContinue('/revenues/imports/'.\$id") && str_contains($controllerSource, 'session_write_close();') && str_contains($controllerSource, "fastcgi_finish_request()"), 'the upload answers at once, releases the session and reads the report afterwards');
$assert(str_contains($serviceSource, "FROM OPENJSON(:rows) WITH (") && str_contains($serviceSource, "FROM OPENJSON(:keys) WITH (source_key nvarchar(80)"), 'on SQL Server the preview is written with OPENJSON (one parameter, one cached plan)');
$assert(str_contains($serviceSource, "sp_getapplock @Resource=N'ekabineti.revenue_import'") && !str_contains($serviceSource, 'FROM revenue_transactions t WITH (UPDLOCK,HOLDLOCK)'), 'confirmations are serialised with an application lock, without range locks on the payments');
$assert(str_contains((string) file_get_contents(APP_ROOT.'/app/Service/Scheduler.php'), 'RevenueImport::processQueue(') && is_file(APP_ROOT.'/bin/process-revenue-imports.php'), 'the scheduler and a cron script pick up interrupted reports');
$assert(str_contains((string) file_get_contents(APP_ROOT.'/app/Service/SpreadsheetReader.php'), "' --kill-after=10 180 '"), 'XLS conversion with LibreOffice is stopped after 3 minutes');
$assert(str_contains($js, "document.querySelectorAll('[data-import-status]')"), 'the import page follows the progress and reloads when done');

// Large reports: read in chunks of 1 000 rows and confirmed in chunks of 4 000 rows.
$large = (static function (): Generator {
    for ($i = 0; $i < 9500; $i++) yield $i => [(string) $i, number_format(1 + $i / 100, 2, '.', ''), 'KOMUNA-Prishtina', 'PAGUES PROVA '.$i, '40', 'Pagese per KK Prishtine 01 '.str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT).' AB', '07.11.2026', sprintf('PRB4A%010dZ', 500000 + $i)];
    yield 9500 => ['9500', '5', 'KOMUNA-Prishtina', 'Rasti Provë', '40', 'Плаќање', '07.11.2026', 'PRB4A0000999999Z'];
    yield 9501 => ['9501', '5', 'KOMUNA-Prishtina', 'rasti provë', '40', 'Плаќање', '07.11.2026', 'PRB4A0000999999Z'];
})();
$batch('big');
$memoryBefore = memory_get_usage();
$bigPreview = $import->preview('big', $large);
$assert($bigPreview['counts']['new'] === 9502 && $count("SELECT COUNT(*) FROM revenue_import_rows WHERE import_batch_id='big'") === 9502, 'a large report is staged chunk by chunk ('.json_encode($bigPreview['counts']).')');
$assert(($bigPreview['warnings']['possible_duplicate'] ?? 0) === 2, 'possible double payments are found across chunks, after the whole report is read');
$assert(RevenueImport::MAX_ROWS === 300000, 'reports of up to 300 000 rows are accepted');
$import->startPosting('big');
$assert($pdo->query("SELECT status FROM import_batches WHERE id='big'")->fetchColumn() === 'posting', 'confirmation claims the batch (ready → posting) before writing');
$bigPosted = $import->post('big');
$assert($bigPosted['imported'] === 9502 && $count("SELECT COUNT(*) FROM revenue_import_rows WHERE import_batch_id='big'") === 0 && $pdo->query("SELECT status FROM import_batches WHERE id='big'")->fetchColumn() === 'posted', 'all rows are confirmed in chunks of 4 000 and leave the preview');
$serviceText = (string) file_get_contents(APP_ROOT.'/app/Service/RevenueImport.php');
$assert(str_contains($serviceText, 'private const POST_CHUNK = 4000;') && str_contains($serviceText, "status='posting' AND updated_at<DATEADD(minute,-15"), 'an interrupted confirmation is resumed by the scheduler');
$assert(str_contains((string) file_get_contents(APP_ROOT.'/public/.user.ini'), 'upload_max_filesize=64M'), 'uploads of up to 64 MB reach PHP');

// Clear everything and restore from one import only.
$restoreId = 'aaaaaaaa-0000-4000-8000-000000000001';
$restoreKey = 'imports/'.$restoreId.'/source.csv';
@mkdir(APP_ROOT.'/storage/private/imports/'.$restoreId, 0770, true);
file_put_contents(APP_ROOT.'/storage/private/'.$restoreKey, "0;12.5;KOMUNA-Prishtina;LIRA PROVA;40;Pagese per KK Prishtine 01 901 RR;09.11.2026;PRB4A0000409011R\n1;20;KOMUNA-Prishtina;BESA PROVA;10;TATIMI NË PRONË;09.11.2026;PRB2K3354900001A\n");
$import->queue($restoreId, $restoreKey, ['source_file_name'=>'rikthim.csv']);
$import->process($restoreId, 0);
$import->confirm($restoreId);
$beforeClear = $count('SELECT COUNT(*) FROM revenue_transactions');
$assert($beforeClear > 2 && $import->sourcePath($restoreId) !== null && $import->sourcePath('q1') === null, 'only imports whose stored file is on the server can be restored');
$batch('open-preview');
$import->preview('open-preview', [['0', '9', 'X', 'Y', '40', 'z', '10.11.2026', 'PRB4A0000409999Z']]);
try { $import->startClear('clear-0', null, 'x'); $assert(false, 'clearing waits while a report is queued or being read'); } catch (EKabineti\Core\HttpException $e) { $assert(str_contains($e->getMessage(), 'Во тек е'), 'clearing waits while a report is queued or being read'); }
$pdo->exec("UPDATE import_batches SET status='cancelled' WHERE status IN ('queued','processing','posting')");
$import->startClear('clear-1', $restoreId, 'Fillimi i prodhimit');
try { $import->startClear('clear-2', null, 'x'); $assert(false, 'a second clear cannot start while one is running'); } catch (EKabineti\Core\HttpException $e) { $assert(str_contains($e->getMessage(), 'Во тек е'), 'a second clear cannot start while one is running'); }
$cleared = $import->clearAll('clear-1');
$assert($cleared['deleted']['payments'] === $beforeClear && $cleared['restore_status'] === 'posted', 'clearing deletes every payment and restores the chosen import ('.json_encode($cleared).')');
$assert($count('SELECT COUNT(*) FROM revenue_transactions') === 2 && $count("SELECT COUNT(*) FROM revenue_transactions WHERE import_batch_id='".$cleared['restored_batch']."'") === 2, 'afterwards only the payments of that import are in the system');
$assert($pdo->query("SELECT status FROM import_batches WHERE id='big'")->fetchColumn() === 'cleared' && $pdo->query("SELECT status FROM import_batches WHERE id='open-preview'")->fetchColumn() === 'cancelled' && $count("SELECT COUNT(*) FROM revenue_import_rows WHERE import_batch_id='open-preview'") === 0, 'confirmed imports are marked "cleared"; open previews are cancelled');
$assert($pdo->query("SELECT status FROM import_batches WHERE id='clear-1'")->fetchColumn() === 'posted' && $count("SELECT COUNT(*) FROM audit_events WHERE action='revenue.cleared_all' AND reason='Fillimi i prodhimit'") === 1, 'the clear operation is recorded and audited with its reason');
$batch('noop');
$again = $import->reimport($restoreId, 'bbbbbbbb-0000-4000-8000-000000000002');
$assert($again === 'unchanged', 'reimporting a report that is already in the system adds nothing');
foreach (['aaaaaaaa-0000-4000-8000-000000000001', 'bbbbbbbb-0000-4000-8000-000000000002', $cleared['restored_batch']] as $dir) { foreach (glob(APP_ROOT.'/storage/private/imports/'.$dir.'/*') ?: [] as $f) @unlink($f); @rmdir(APP_ROOT.'/storage/private/imports/'.$dir); }
$controllerText = (string) file_get_contents(APP_ROOT.'/app/Controller/RevenueController.php');
$assert(str_contains($controllerText, "!== 'ИЗБРИШИ СÈ'") && str_contains($controllerText, '$service->clearAll($operationId)') && str_contains($controllerText, "respondAndContinue('/revenues/maintenance'"), 'clearing needs the typed confirmation and runs in the background');

// Drill-down and filters.
$assert(str_contains($controllerText, 'private function drillPath(array $filters): string') && str_contains($controllerText, "\$this->breakdowns(\$from, \$params, \$filters, \$amount)") && str_contains($controllerText, "#payments\">Уплати →</a>"), 'groups drill down to types, breakdowns and the list of payments');
$filtersMethod = new ReflectionMethod(EKabineti\Controller\RevenueController::class, 'filters');
$controller = (new ReflectionClass(EKabineti\Controller\RevenueController::class))->newInstanceWithoutConstructor();
$_GET = ['group'=>'40', 'amount_min'=>'10,5', 'amount_max'=>'x', 'plate'=>'01 234 ab', 'uniref_type'=>'b4', 'payer_type'=>'business', 'warning'=>'possible_duplicate', 'method'=>'evil', 'batch'=>"1' OR 1=1", 'sort'=>'amount_desc'];
[$where, $params, $filters] = $filtersMethod->invoke($controller);
ksort($filters);
$assert($filters === ['amount_min'=>'10.50', 'group'=>'40', 'payer_type'=>'business', 'plate'=>'01 234 ab', 'sort'=>'amount_desc', 'uniref_type'=>'B4', 'warning'=>'possible_duplicate'], 'valid filters are applied, invalid values (amount "x", method, batch) are ignored ('.json_encode($filters).')');
$assert($params['f_plate'] === '%01234AB%' && $params['f_uniref_type'] === 'B4%' && str_contains($where, 't.amount>=:f_amount_min') && !str_contains($where, 'f_batch'), 'plates match without spaces or dashes; amounts and codes are normalised');
$sql = 'SELECT COUNT(*) FROM revenue_transactions t JOIN revenue_categories c ON c.id=t.category_id WHERE '.$where;
$statement = $pdo->prepare($sql); $statement->execute($params);
$assert($statement->fetchColumn() !== false, 'the filter SQL runs');
$_GET = [];

// Bulk insert without bound parameters: values are SQL literals.
$assert(RevenueImport::literal("O'Brien", true) === "N'O''Brien'" && RevenueImport::literal(null, true) === 'NULL' && RevenueImport::literal(12, true) === '12', 'quotes are doubled, NULL and integers are written as such');
$assert(RevenueImport::literal("a\\\nb\r", true) === "N'a\\'+NCHAR(10)+N'b'+NCHAR(13)+N''" && RevenueImport::literal("x\0y", false) === "'xy'", 'line breaks become NCHAR() on SQL Server (no backslash-newline continuation); NUL is removed');
$pdo->exec('CREATE TABLE literal_check(v TEXT)');
$tricky = "a'); DROP TABLE revenue_transactions; --";
$pdo->exec('INSERT INTO literal_check(v) VALUES ('.RevenueImport::literal($tricky, false).')');
$assert($pdo->query('SELECT v FROM literal_check')->fetchColumn() === $tricky && $count('SELECT COUNT(*) FROM revenue_transactions') > 0, 'a value that looks like SQL is stored as text');
$controllerSource = (string) file_get_contents(APP_ROOT.'/app/Controller/RevenueController.php');
$assert(str_contains((string) file_get_contents(APP_ROOT.'/app/Service/RevenueImport.php'), '$reader->firstSheetRows($path)'), 'XLSX reports are streamed with XMLReader');
$sheet = sys_get_temp_dir().'/revenues-unit-'.getmypid().'.xlsx';
$zip = new ZipArchive(); $zip->open($sheet, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
$zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Плаќање</t></si><si><r><t>PRB2K</t></r><r><t>3354000111A</t></r></si></sst>');
$zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="2"><c r="A2"><v>0</v></c><c r="B2"><v>45.5</v></c><c r="F2" t="s"><v>0</v></c><c r="H2" t="s"><v>1</v></c></row><row r="3"/><row r="4"><c r="B4" t="inlineStr"><is><t>10</t></is></c></row></sheetData></worksheet>');
$zip->close();
$streamed = iterator_to_array((new EKabineti\Service\SpreadsheetReader())->firstSheetRows($sheet), true);
@unlink($sheet);
$assert(array_keys($streamed) === [1, 3] && $streamed[1] === ['0', '45.5', '', '', '', 'Плаќање', '', 'PRB2K3354000111A'] && $streamed[3] === ['', '10'], 'the streaming reader keeps Excel row numbers, gaps, shared, rich and inline strings');

// Wiring.
$app = (string) file_get_contents(APP_ROOT.'/app/Core/Application.php');
$view = (string) file_get_contents(APP_ROOT.'/app/Core/View.php');
$controller = (string) file_get_contents(APP_ROOT.'/app/Controller/RevenueController.php');
$js = (string) file_get_contents(APP_ROOT.'/public/assets/app.js');
$migration = (string) file_get_contents(APP_ROOT.'/database/migrations/2026_10_30_003900_revenues.sql');
$assert(str_contains($app, "if(str_starts_with(\$p,'/revenues')){") && str_contains($app, '$this->revenuesAccess();') && str_contains($app, "'revenue_transactions'"), 'routes are behind the revenue access check and the tables are in the health check');
$assert(str_contains($view, "['path'=>'/revenues','group'=>'finance'") && str_contains($view, "canAccessRevenues(\$userId)"), 'Të hyrat is a Finance module, visible only to who may see it');
$assert(str_contains($controller, 'data-dialog-open="revenue-import"') && str_contains($controller, '<dialog class="modal" id="revenue-import"') && str_contains($controller, 'href="/revenues/import"'), 'the import opens in a dialog and still works without JavaScript');
$assert(str_contains($js, "document.querySelectorAll('dialog[data-dialog]')") && str_contains($js, "data-open-on-load"), 'the generic dialog script opens and closes the dialog');
$assert(str_contains($migration, 'CONSTRAINT UQ_revenue_transactions_source_key UNIQUE (source_key)') && str_contains($migration, 'IF NOT EXISTS (SELECT 1 FROM dbo.revenue_category_rules)'), 'the database refuses duplicates and production rule changes are not overwritten');
$assert(!str_contains($controller, 'style="'), 'no inline styles (Content Security Policy)');

if ($failures) { fwrite(STDERR, "FAIL\n - ".implode("\n - ", $failures)."\n"); exit(1); }
echo "revenues-unit: OK\n";
