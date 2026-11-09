<?php
declare(strict_types=1);

// Legal Office work reports: reading the Word report, preview → confirmation, case links and hearings, re-import.
// All names and case numbers are invented.
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });
use EKabineti\Service\LegalWorkImport;
use EKabineti\Service\LegalWorkReport;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

// A .docx as Word writes it: bold headers and section titles, list items (numPr).
$p = static fn(string $text, bool $list = false, bool $bold = false): string => '<w:p><w:pPr>'.($list ? '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>' : '').'</w:pPr><w:r><w:rPr>'.($bold ? '<w:b/>' : '').'</w:rPr><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>';
$body = $p('Република Северна Македонија').$p('Датум:', false, true).$p('09.10.2026', false, true).$p('Од:', false, true).$p('Марија Пробна, Правен службеник', false, true)
    .$p('Тема:', false, true).$p('Работен извештај за неделата 05 – 09 октомври', false, true)
    .$p('ОДГОВОР НА ТУЖБА (3 вкупно, 2 плата, 1 поништување на одлука)', false, true)
    .$p('Одговор на тужба, Ана Пробна и др., П-9001/26, дневници, инфлација;', true)
    .$p('Одговор на тужба, Петар Пробен, квалификација', true)
    .$p('Одговор на тужба, ПРОБА ДООЕЛ, ГЖ-77/26, поништување на одлука;', true)
    .$p('ЗАСТАПУВАЊА (2)', false, true)
    .$p('Застапување на судско рочиште, Горан Пробен, П-9002-25, потврда за сопственост, 06.10.2026;', true)
    .$p('Застапување на судско рочиште, Ана Пробна и др., П-9001/2026, дневници, 20.10.2026;', true)
    .$p('КАНЦЕЛАРИСКА РАБОТА (1 барање)', false, true)
    .$p('Барање за докази до Општинскиот архив, П-9002/25, според тужителот Горан Пробен;', true);
$docx = sys_get_temp_dir().'/legal-work-'.getmypid().'.docx';
$zip = new ZipArchive(); $zip->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
$zip->close();
$paragraphs = LegalWorkReport::paragraphs($docx);
@unlink($docx);

$reports = LegalWorkReport::parse($paragraphs);
$r = $reports[0] ?? [];
$assert(count($reports) === 1 && $r['report_date'] === '2026-10-09' && $r['officer_name'] === 'Марија Пробна' && $r['officer_title'] === 'Правен службеник', 'header: date, officer and title');
$assert($r['period_start'] === '2026-10-05' && $r['period_end'] === '2026-10-09', 'period "неделата 05 – 09 октомври" takes the year of the report');
$assert(count($r['sections']) === 3 && $r['sections'][0]['declared'] === 3 && $r['warnings'] === [], 'sections and their declared counts (3 вкупно) match the lines read');
$items = $r['items'];
$assert($items[0]['case_reference'] === 'П-9001/26' && $items[0]['parties'] === 'Ана Пробна и др.' && $items[0]['theme_codes'] === ['meals', 'inflation'] && $items[0]['domain'] === 'salary', 'a line gives parties, case number, themes and domain');
$assert($items[1]['case_reference'] === null && $items[1]['parties'] === 'Петар Пробен' && $items[1]['themes'] === ['Квалификација'], 'lines without a case number are kept');
$assert($items[2]['domain'] === 'administrative' && $items[2]['case_reference'] === 'ГЖ-77/26', 'annulment of a decision is its own domain');
$assert($items[3]['case_reference'] === 'П-9002/25' && $items[3]['hearing_date'] === '2026-10-06' && $items[4]['case_reference'] === 'П-9001/26', 'case numbers written with "-" or a 4-digit year are normalised; hearing dates are read');
$assert($items[5]['parties'] === 'Горан Пробен' && $items[5]['section_code'] === 'office_work', '"според тужителот …" gives the party of office work');
$assert(LegalWorkReport::caseKey('K.Dh.Sh.II.nr.927/26') === 'K.Dh.Sh.II.nr.927/26' && LegalWorkReport::caseKey('C nr 6562-2025') === 'C.nr.6562/25', 'Supreme Court and loosely written numbers compare equal');

// Word 97–2003 (.doc) is read in PHP, without LibreOffice (tests/fixtures/legal-work-report.doc, invented names).
$docParagraphs = EKabineti\Service\WordBinaryReader::paragraphs(APP_ROOT.'/tests/fixtures/legal-work-report.doc');
$assert(count($docParagraphs) === 12 && $docParagraphs[4] === 'Марија Пробна, Правен службеник' && $docParagraphs[11] === 'Застапување на судско рочиште, Горан Пробен, П-9002-25, потврда за сопственост, 20.10.2026;', 'the .doc text is read with Cyrillic letters and en dashes ('.count($docParagraphs).' paragraphs)');
$fromDoc = LegalWorkReport::parse(LegalWorkReport::paragraphs(APP_ROOT.'/tests/fixtures/legal-work-report.doc'));
$assert(count($fromDoc) === 1 && $fromDoc[0]['period_start'] === '2026-10-12' && count($fromDoc[0]['items']) === 3 && $fromDoc[0]['warnings'] === [] && $fromDoc[0]['items'][2]['hearing_date'] === '2026-10-20' && $fromDoc[0]['items'][2]['case_reference'] === 'П-9002/25', 'a .doc report gives the same report structure as a .docx (sections, items, hearing)');
try { EKabineti\Service\WordBinaryReader::paragraphs(__FILE__); $assert(false, 'a file that is not a .doc is refused'); } catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'не е Word 97–2003 документ'), 'a file that is not a .doc is refused with a clear message'); }

// Preview and confirmation on SQLite (standing in for SQL Server).
final class TsqlOnSqlite extends PDO { public function prepare(string $q, array $o = []): PDOStatement|false { return parent::prepare(str_replace('; SELECT CAST(SCOPE_IDENTITY() AS bigint) AS id', ' RETURNING id', (string) preg_replace(['/ WITH \([A-Z,]+\)/', "/(?<![\w'])N'/"], ['', "'"], $q)), $o); } }
$pdo = new TsqlOnSqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('SYSUTCDATETIME', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$pdo->sqliteCreateFunction('NEWID', static fn (): string => bin2hex(random_bytes(16)), 0);
foreach ([
    'CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,status TEXT)',
    'CREATE TABLE directorates(id INTEGER PRIMARY KEY,code TEXT)',
    'CREATE TABLE import_batches(id TEXT PRIMARY KEY,type TEXT,storage_key TEXT,status TEXT,summary TEXT,errors TEXT,created_by INT,confirmed_at TEXT,created_at TEXT,updated_at TEXT)',
    'CREATE TABLE audit_events(id INTEGER PRIMARY KEY,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,before_state TEXT,after_state TEXT,ip_address TEXT,correlation_id TEXT,reason TEXT,occurred_at TEXT)',
    'CREATE TABLE administrative_cases(id INTEGER PRIMARY KEY,case_uuid TEXT,internal_sequence INT UNIQUE,is_legal INT,case_number TEXT UNIQUE,case_type TEXT,subject TEXT,description TEXT,originating_directorate_id INT,responsible_user_id INT,status TEXT,priority TEXT,opened_at TEXT,created_by INT,created_at TEXT,updated_at TEXT,source_name TEXT,court_reference TEXT,authority_name TEXT,procedural_phase TEXT,assigned_at TEXT,currency TEXT,confidentiality TEXT)',
    'CREATE TABLE case_directorates(id INTEGER PRIMARY KEY,case_id INT,directorate_id INT,relationship_type TEXT,responsible_user_id INT,status TEXT,assigned_by INT,assigned_at TEXT)',
    'CREATE TABLE legal_case_assignments(id INTEGER PRIMARY KEY,case_id INT,responsible_user_id INT,valid_from TEXT,assigned_by INT,reason TEXT)',
    'CREATE TABLE legal_case_parties(id INTEGER PRIMARY KEY,case_id INT,party_name TEXT,party_role TEXT,created_by INT)',
    'CREATE TABLE legal_case_hearings(id INTEGER PRIMARY KEY,case_id INT,procedure_id INT,scheduled_at TEXT,authority_name TEXT,venue_mode TEXT,responsible_user_id INT,status TEXT,notes TEXT,created_by INT)',
    'CREATE TABLE legal_work_reports(id INTEGER PRIMARY KEY,report_key TEXT UNIQUE,import_batch_id TEXT,officer_name TEXT,officer_title TEXT,officer_user_id INT,report_date TEXT,period_start TEXT,period_end TEXT,subject TEXT,source_file_name TEXT,activities INT,sections TEXT,warnings TEXT,created_by INT,created_at TEXT,updated_at TEXT)',
    'CREATE TABLE legal_work_activities(id INTEGER PRIMARY KEY,report_id INT,sort_order INT,section_code TEXT,section_label TEXT,item_text TEXT,activity TEXT,parties TEXT,case_reference TEXT,subject TEXT,themes TEXT,theme_codes TEXT,domain TEXT,hearing_date TEXT,case_id INT,case_link TEXT,hearing_id INT,created_at TEXT)',
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO users VALUES(5,'Марија Пробна','active'),(7,'Importues','active')");
$pdo->exec("INSERT INTO directorates VALUES(3,'PRAV')");
$pdo->exec("INSERT INTO administrative_cases(id,internal_sequence,is_legal,case_number,court_reference,subject) VALUES(40,40,1,'ZL-2026-000040','П 9002-2025','Постоечки предмет')");
$db = (new ReflectionClass(EKabineti\Core\Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(EKabineti\Core\Database::class, 'pdo'))->setValue($db, $pdo);
$_SERVER += ['REQUEST_METHOD'=>'POST', 'REQUEST_URI'=>'/', 'REMOTE_ADDR'=>'127.0.0.1'];
$import = new LegalWorkImport($db, new EKabineti\Service\Audit($db, new EKabineti\Core\Request()), 7);
$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

$pdo->exec("INSERT INTO import_batches(id,type,status) VALUES('w1','legal_work_report','preview')");
$plan = $import->preview('w1', LegalWorkReport::parse($paragraphs), 'raporti.docx');
$assert($plan['status'] === 'ready' && $plan['counts']['activities'] === 6 && $plan['counts']['linked'] === 2 && $plan['counts']['new_cases'] === 2 && $plan['counts']['hearings'] === 2 && $plan['counts']['without_case'] === 1, 'preview: 6 activities, 2 lines link to the existing case (C.nr.9002/25), 2 new cases, 2 hearings ('.json_encode($plan['counts']).')');
$assert($count('SELECT COUNT(*) FROM legal_work_activities') === 0 && $count('SELECT COUNT(*) FROM administrative_cases') === 1, 'nothing is written before confirmation');
$posted = $import->confirm('w1');
$assert($posted['activities'] === 6 && $posted['cases_created'] === 2 && $posted['hearings_created'] === 2, 'confirmation stores the activities, creates the cases and the hearings ('.json_encode($posted).')');
$new = $pdo->query("SELECT * FROM administrative_cases WHERE court_reference='П-9001/26'")->fetch();
$assert($new && (int) $new['is_legal'] === 1 && $new['case_type'] === 'labor' && (int) $new['responsible_user_id'] === 5 && $new['authority_name'] === 'Основен суд Битола' && str_starts_with((string) $new['case_number'], 'ZL-2026-'), 'a created case is a Legal Office case, typed from the subject, assigned to the officer');
$assert($count("SELECT COUNT(*) FROM legal_case_parties WHERE case_id=".(int) $new['id']." AND party_name='Ана Пробна' AND party_role='claimant'") === 1 && $count("SELECT COUNT(*) FROM legal_case_parties WHERE case_id=".(int) $new['id']." AND party_role='respondent'") === 1, 'claimants ("etj" removed) and the municipality as respondent');
$assert($pdo->query("SELECT authority_name FROM administrative_cases WHERE court_reference='ГЖ-77/26'")->fetchColumn() === 'Апелационен суд Битола', 'AP. cases go to the Court of Appeal');
$assert($count("SELECT COUNT(*) FROM legal_case_hearings WHERE case_id=40 AND status='held' AND scheduled_at LIKE '2026-10-06%'") === 1 && $count("SELECT COUNT(*) FROM legal_case_hearings WHERE case_id=".(int) $new['id']." AND status='scheduled' AND scheduled_at LIKE '2026-10-20%'") === 1, 'hearings: held before the report date, scheduled after it');
$assert($count("SELECT COUNT(*) FROM legal_work_activities WHERE case_id=40 AND case_link='linked'") === 2 && $count("SELECT COUNT(*) FROM legal_work_activities WHERE theme_codes LIKE '%,meals,%'") === 2, 'activities keep their case link and themes for grouping');

$pdo->exec("INSERT INTO import_batches(id,type,status) VALUES('w2','legal_work_report','preview')");
$again = $import->preview('w2', LegalWorkReport::parse($paragraphs), 'raporti.docx');
$assert($again['counts']['replaced'] === 1 && $again['counts']['new_cases'] === 0 && $again['counts']['linked'] === 5, 'the same report again: replaces the earlier copy, every case now exists');
$second = $import->confirm('w2');
$assert($count('SELECT COUNT(*) FROM legal_work_reports') === 1 && $count('SELECT COUNT(*) FROM legal_work_activities') === 6 && $count('SELECT COUNT(*) FROM administrative_cases') === 3 && $count('SELECT COUNT(*) FROM legal_case_hearings') === 2 && $second['hearings_reused'] === 2, 'nothing is duplicated: one report, the same cases and hearings');
try { $import->confirm('w2'); $assert(false, 'a confirmed import cannot be confirmed twice'); } catch (EKabineti\Core\HttpException) { $assert(true, ''); }

// Excel template: rows grouped into reports by officer and period; explicit columns win.
$sheet = [
    1=>array_values(LegalWorkReport::TEMPLATE_COLUMNS),
    2=>['09.10.2026', 'Лука Пробен', 'Правен службеник', '05.10.2026', '09.10.2026', 'Одговор на тужба', 'Одговор на тужба', 'Даниела Пробна; Ерол Пробен и др.', 'П 9100-2026', 'дневници, квалификација', ''],
    3=>['', 'Лука Пробен', '', '46300', '46304', 'Застапувања на рочишта', 'Застапување на судско рочиште', 'Даниела Пробна', 'П-9100/26', 'потврда за сопственост', '46309'],
    4=>['', 'Мира Пробна', '', '01.11.2026', '07.11.2026', 'Нов вид работа', 'Средба со секторот', '', '', '', ''],
    5=>['', '', '', '', '', '', '', '', '', '', ''],
    6=>['', 'Без активност', '', '01.11.2026', '07.11.2026', 'Жалби', '', '', '', '', ''],
];
$fromSheet = LegalWorkReport::fromSheet($sheet);
$assert(count($fromSheet) === 2 && $fromSheet[0]['officer_name'] === 'Лука Пробен' && $fromSheet[0]['period_start'] === '2026-10-05' && $fromSheet[0]['period_end'] === '2026-10-09' && count($fromSheet[0]['items']) === 2, 'template rows with the same officer and period (text or Excel dates) form one report');
$first = $fromSheet[0]['items'][0];
$assert($first['case_reference'] === 'П-9100/26' && $first['parties'] === 'Даниела Пробна; Ерол Пробен и др.' && $first['theme_codes'] === ['meals', 'qualification'] && $first['section_code'] === 'claim_response', 'template columns: normalised case number, parties split on ";", themes from the subject');
$assert($fromSheet[0]['items'][1]['hearing_date'] === '2026-10-14' && $fromSheet[0]['items'][1]['section_code'] === 'representation', 'Excel date numbers are read (hearing 14.10.2026)');
$assert(str_starts_with($fromSheet[1]['items'][0]['section_code'], 'other_') && $fromSheet[1]['items'][0]['section_label'] === 'Нов вид работа', 'an unknown type of work is kept under its own name');
$assert(in_array('Редот 6 е прескокнат: недостасува активност.', $fromSheet[0]['warnings'], true), 'incomplete rows are skipped with a note');
$assert(LegalWorkReport::fromSheet([1=>['A', 'B'], 2=>['x', 'y']])[0]['warnings'][0] === 'Листот ги нема насловите од образецот (Службеник, Вид на работа, Активност …). Преземете го образецот од страницата.', 'a sheet without the template headers is rejected');
$pdo->exec("INSERT INTO import_batches(id,type,status) VALUES('x1','legal_work_report','preview')");
$excelPlan = $import->preview('x1', $fromSheet, 'shablloni.xlsx');
$assert($excelPlan['status'] === 'ready' && $excelPlan['counts']['reports'] === 2 && $excelPlan['counts']['activities'] === 3 && $excelPlan['counts']['new_cases'] === 1 && $excelPlan['counts']['hearings'] === 1, 'the template is imported through the same preview ('.json_encode($excelPlan['counts']).')');
$worksheet = new ReflectionMethod(EKabineti\Service\TabularExport::class, 'worksheet');
$xml = $worksheet->invoke(new EKabineti\Service\TabularExport(), LegalWorkReport::TEMPLATE_COLUMNS, [], ['lists'=>['section'=>'Listat!$A$2:$A$8'], 'widths'=>['activity'=>34], 'filter'=>false]);
$assert(str_contains($xml, '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorStyle="warning" sqref="F2:F1000"><formula1>Listat!$A$2:$A$8</formula1></dataValidation>') && str_contains($xml, '<col min="7" max="7" width="34"') && !str_contains($xml, '<autoFilter'), 'the template has a drop-down for the type of work and sized columns');
$xmlDoc = new DOMDocument(); $assert($xmlDoc->loadXML($xml), 'the template sheet is well-formed XML');
$controllerSource = (string) file_get_contents(APP_ROOT.'/app/Controller/LegalWorkController.php');
$assert(str_contains($controllerSource, 'href="/legal-office/work-reports/template.xlsx">Преземи Excel образец</a>') && str_contains($controllerSource, "\$extension === 'xlsx' ? LegalWorkReport::fromSheet("), 'the template is offered on the page and accepted by the import');

// Responsible for the cases an import creates: the user chosen in the dialog (default: the head of the Legal Office).
$pdo->exec("INSERT INTO users(id,name,status) VALUES(9,'Марија Правна','active')");
$pdo->exec("INSERT INTO import_batches(id,type,status) VALUES('r1','legal_work_report','preview')");
$other = LegalWorkReport::parse(array_map(static fn(array $p): array => [str_replace(['П-9001/26', 'ГЖ-77/26', 'П-9002-25', 'П-9002/25', 'Марија Пробна'], ['П-9301/26', 'ГЖ-93/26', 'П-9302-25', 'П-9302/25', 'Весна Пробна'], $p[0]), $p[1], $p[2]], $paragraphs));
$withResponsible = $import->preview('r1', $other, 'raporti.docx', 9);
$import->confirm('r1');
$assert($withResponsible['responsible_user_id'] === 9 && $count("SELECT COUNT(*) FROM administrative_cases WHERE court_reference IN ('П-9301/26','ГЖ-93/26','П-9302/25') AND responsible_user_id=9") === 3 && $count("SELECT COUNT(*) FROM legal_case_assignments a JOIN administrative_cases c ON c.id=a.case_id WHERE c.court_reference='П-9301/26' AND a.responsible_user_id=9") === 1, 'new cases, their assignment and hearings go to the responsible chosen in the dialog');
$assert($count("SELECT COUNT(*) FROM legal_case_hearings h JOIN administrative_cases c ON c.id=h.case_id WHERE c.court_reference='П-9302/25' AND h.responsible_user_id=9") === 1, 'hearings of those cases have the same responsible');
$migration = (string) file_get_contents(APP_ROOT.'/database/migrations/2026_11_05_004400_legal_imported_cases_reassignment.sql');
$assert(!str_contains($migration, 'UPDATE') && str_contains($migration, 'SELECT 1 AS no_op'), 'the reassignment migration of the previous installation is a no-op: no data is carried over');
$controllerText = (string) file_get_contents(APP_ROOT.'/app/Controller/LegalWorkController.php');
$assert(str_contains($controllerText, '<label class="wide">Одговорно лице за новите предмети<select name="responsible_user_id">') && str_contains((string) file_get_contents(APP_ROOT.'/app/Service/LegalWorkImport.php'), "COALESCE(r.base_role_code,r.code)='legal_director'"), 'the import dialog offers the responsible, the head of the Legal Office by default');

$app = (string) file_get_contents(APP_ROOT.'/app/Core/Application.php');
$assert(str_contains($app, "if(str_starts_with(\$p,'/legal-office/work-reports')){") && str_contains($app, "'legal_work_reports', 'legal_work_activities'"), 'routes are behind the Legal Office access check; tables are in the health check');
$controller = (string) file_get_contents(APP_ROOT.'/app/Controller/LegalWorkController.php');
$assert(!str_contains($controller, 'style="') && str_contains($controller, 'data-dialog-open="legal-work-import"'), 'import in a dialog, no inline styles');

if ($failures) { fwrite(STDERR, "FAIL\n - ".implode("\n - ", $failures)."\n"); exit(1); }
echo "legal-work-report-unit: OK\n";
