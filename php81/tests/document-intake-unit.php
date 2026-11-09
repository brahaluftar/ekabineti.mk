<?php
declare(strict_types=1);

// Upload of reports with AI: text from Word / PDF / Excel, masking of personal identifiers, AI proposals normalised to
// the record fields, validation, permissions, registration in one transaction with audit and notifications.
// Invented names and numbers only.
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });
date_default_timezone_set('Europe/Skopje');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };
$tmp = sys_get_temp_dir().'/ek-intake-test-'.bin2hex(random_bytes(4));
mkdir($tmp);

// --- Text from the files ------------------------------------------------------------------------------------------
$docx = $tmp.'/raport.docx';
$zip = new ZipArchive(); $zip->open($docx, ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Raporti javor i DI</w:t></w:r></w:p><w:p><w:pPr><w:numPr><w:ilvl w:val="0"/></w:numPr></w:pPr><w:r><w:t>Rruga A: progresi 65%, mungon leja.</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
$text = EKabineti\Service\DocumentIntake::extractText($docx, 'docx');
$assert($text === "Raporti javor i DI\n• Rruga A: progresi 65%, mungon leja.", 'Word text keeps the paragraphs and the list bullets ('.json_encode($text).')');

$xlsx = $tmp.'/raport.xlsx';
$zip = new ZipArchive(); $zip->open($xlsx, ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Task-et" sheetId="1" r:id="rId1"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
$zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Task</t></is></c><c r="B1" t="inlineStr"><is><t>Рок</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Ndriçimi</t></is></c><c r="B2" t="inlineStr"><is><t>2026-12-01</t></is></c></row></sheetData></worksheet>');
$zip->close();
$sheet = EKabineti\Service\DocumentIntake::extractText($xlsx, 'xlsx');
$assert(str_contains($sheet, '## Лист: Task-et') && str_contains($sheet, 'R2: Ndriçimi | 2026-12-01'), 'Excel text has the sheet name and one line per row ('.json_encode($sheet).')');

if (trim((string) @shell_exec('command -v pdftotext 2>/dev/null')) !== '') {
    $content = "BT /F1 12 Tf 72 720 Td (Raporti PDF i DI) Tj ET";
    $objects = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>", "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>", "<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream", "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"];
    $pdf = "%PDF-1.4\n"; $offsets = [];
    foreach ($objects as $i => $o) { $offsets[] = strlen($pdf); $pdf .= ($i + 1)." 0 obj\n{$o}\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    file_put_contents($tmp.'/raport.pdf', $pdf);
    $assert(str_contains(EKabineti\Service\DocumentIntake::extractText($tmp.'/raport.pdf', 'pdf'), 'Raporti PDF i DI'), 'PDF text is read with pdftotext');
    file_put_contents($tmp.'/bad.pdf', 'not a pdf');
    try { EKabineti\Service\DocumentIntake::extractText($tmp.'/bad.pdf', 'pdf'); $assert(false, 'a broken PDF is refused'); } catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'PDF'), 'a broken PDF is refused with a clear message'); }
}

[$masked, $counts] = EKabineti\Service\DocumentIntake::redact('Kontakt: arta.prove@example.test, tel. 044 123 456 ose +383 49 123-456. Nr. personal 1234567890, IBAN XK05 1212 0123 4567 8906. Lënda C.nr.2257/26, shuma 12.500 €, viti 2026.');
$assert(str_contains($masked, '[EMAIL]') && substr_count($masked, '[TEL]') === 2 && str_contains($masked, '[NR_PERSONAL]') && str_contains($masked, '[IBAN]') && !str_contains($masked, '1234567890') && !str_contains($masked, 'example.test'), 'personal identifiers are masked ('.$masked.')');
$assert(str_contains($masked, 'C.nr.2257/26') && str_contains($masked, '12.500 €') && str_contains($masked, '2026') && $counts === ['email'=>1, 'iban'=>1, 'phone'=>2, 'personal_number'=>1], 'case numbers, amounts and years stay ('.json_encode($counts).')');

// --- Database ----------------------------------------------------------------------------------------------------
final class TsqlOnSqlite extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('; SELECT CAST(SCOPE_IDENTITY() AS bigint) AS id', ' RETURNING id', $query);
        $query = preg_replace('/ WITH \([A-Z,]+\)/', '', $query);
        $query = str_replace('CONVERT(date,SYSUTCDATETIME())', "date('now')", $query);
        $query = preg_replace('/SELECT TOP (\d+) (.*)$/s', 'SELECT $2 LIMIT $1', $query);
        return parent::prepare($query, $options);
    }
}
$pdo = new TsqlOnSqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('SYSUTCDATETIME', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
foreach ([
    'users(id INTEGER PRIMARY KEY,name TEXT,status TEXT,locale TEXT,notification_email_mode TEXT)',
    'roles(id INTEGER PRIMARY KEY,code TEXT,name TEXT,base_role_code TEXT,active INT DEFAULT 1)',
    'permissions(id INTEGER PRIMARY KEY,action TEXT,resource_type TEXT)',
    'permission_role(permission_id INT,role_id INT)',
    'user_role_scopes(id INTEGER PRIMARY KEY,user_id INT,role_id INT,directorate_id INT,program_id INT,project_id INT,valid_from TEXT,valid_to TEXT)',
    'directorates(id INTEGER PRIMARY KEY,code TEXT,name TEXT,active INT)',
    'projects(id INTEGER PRIMARY KEY,directorate_id INT,code TEXT,name TEXT,objective TEXT,starts_at TEXT,ends_at TEXT,status TEXT,has_budget_impact INT,budget_category TEXT,progress INT,manager_id INT,sponsor_id INT,created_at TEXT,updated_at TEXT)',
    'project_user_access(project_id INT,user_id INT,access_level TEXT)',
    'project_tasks(id INTEGER PRIMARY KEY,project_id INT,title TEXT,owner_id INT,starts_at TEXT,due_at TEXT,status TEXT,progress INT,weight_percent REAL,next_step TEXT,has_budget_impact INT,dependency_notes TEXT,created_at TEXT,updated_at TEXT)',
    'progress_updates(id INTEGER PRIMARY KEY,project_id INT,period TEXT,progress INT,narrative TEXT,blockers TEXT,next_step TEXT,created_by INT,created_at TEXT,updated_at TEXT)',
    'risks(id INTEGER PRIMARY KEY,directorate_id INT,project_id INT,category TEXT,description TEXT,cause TEXT,consequence TEXT,owner_id INT,probability INT,impact INT,inherent_score INT,response TEXT,residual_score INT,residual_probability INT,residual_impact INT,review_at TEXT,status TEXT,created_at TEXT,updated_at TEXT)',
    'administrative_cases(id INTEGER PRIMARY KEY,case_number TEXT,case_type TEXT,subject TEXT,description TEXT,originating_directorate_id INT,responsible_user_id INT,status TEXT,priority TEXT,opened_at TEXT,created_by INT,created_at TEXT,updated_at TEXT)',
    'case_directorates(id INTEGER PRIMARY KEY,case_id INT,directorate_id INT,relationship_type TEXT,assigned_by INT,assigned_at TEXT)',
    'document_intakes(id INTEGER PRIMARY KEY,directorate_id INT,sector_id INT,file_name TEXT,file_type TEXT,file_size INT,file_hash TEXT,storage_key TEXT,text_chars INT,text_truncated INT,redactions TEXT,status TEXT,summary TEXT,proposal_json TEXT,ai_model TEXT,error TEXT,result_json TEXT,uploaded_by INT,created_at TEXT,confirmed_by INT,confirmed_at TEXT)',
    'audit_events(id INTEGER PRIMARY KEY,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,before_state TEXT,after_state TEXT,ip_address TEXT,correlation_id TEXT,reason TEXT,occurred_at TEXT)',
    'notifications(id TEXT,recipient_id INT,channel TEXT,template TEXT,subject TEXT,payload TEXT,status TEXT,retry_count INT,sent_at TEXT,created_at TEXT,updated_at TEXT)',
] as $t) $pdo->exec('CREATE TABLE '.$t);
$pdo->exec("INSERT INTO users VALUES(1,'Drita Provë','active','mk','off'),(2,'Kryetari Provë','active','mk','off'),(3,'Menaxher Provë','active','mk','off'),(4,'Vetëm lexues','active','mk','off')");
$pdo->exec("INSERT INTO roles(id,code,name) VALUES(1,'director','Drejtor'),(2,'mayor','Kryetar'),(3,'project_manager','Menaxher'),(4,'reporter','Известувач')");
$pdo->exec("INSERT INTO permissions VALUES(1,'view','project'),(2,'create','project'),(3,'create','risk'),(4,'create','case'),(5,'view','risk')");
$pdo->exec("INSERT INTO permission_role VALUES(1,1),(2,1),(3,1),(4,1),(1,4),(5,4)");
$pdo->exec("INSERT INTO user_role_scopes(user_id,role_id,directorate_id) VALUES(1,1,1),(2,2,NULL),(3,3,1),(4,4,1)");
$pdo->exec("INSERT INTO directorates VALUES(1,'DI','Drejtoria e Infrastrukturës',1),(2,'DSH','Drejtoria e Shëndetësisë',1)");
$pdo->exec("INSERT INTO projects(id,directorate_id,code,name,status,progress,manager_id,sponsor_id) VALUES(7,1,'P-7','Rruga A','active',40,3,NULL),(8,1,'P-8','Parku B','active',0,3,NULL),(9,2,'P-9','Spitali','active',0,NULL,NULL)");
$pdo->exec("INSERT INTO project_tasks(id,project_id,title,status,progress,weight_percent) VALUES(50,7,'Shtresa','in_progress',50,0)");
$pdo->exec("INSERT INTO administrative_cases(id,case_number,case_type,subject) VALUES(1,'01/2026','ankesë','E vjetër')");
$pdo->exec("INSERT INTO document_intakes(id,directorate_id,file_name,file_type,file_size,file_hash,storage_key,status,uploaded_by) VALUES(1,1,'raport.docx','docx',100,'".str_repeat('a', 64)."','k','ready',1)");

$db = (new ReflectionClass(EKabineti\Core\Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(EKabineti\Core\Database::class, 'pdo'))->setValue($db, $pdo);
$scope = new EKabineti\Security\Scope($db);
$audit = new EKabineti\Service\Audit($db, new EKabineti\Core\Request());
$notifications = new EKabineti\Service\Notifications($db, new EKabineti\Service\GraphMail([]), (new ReflectionClass(EKabineti\Core\Logger::class))->newInstanceWithoutConstructor());
$sent = []; $reply = [];
$transport = static function (array $payload) use (&$sent, &$reply): array { $sent[] = $payload; return ['output'=>[['type'=>'message', 'content'=>[['type'=>'output_text', 'text'=>json_encode($reply)]]]]]; };
$client = new EKabineti\Service\OpenAIResponsesClient(['enabled'=>true, 'api_key'=>'unit-test-secret', 'model'=>'unit-model', 'max_output_tokens'=>900], $transport);
$intake = new EKabineti\Service\DocumentIntake($db, $scope, 1, $client);

// --- AI proposals -----------------------------------------------------------------------------------------------
$blank = array_fill_keys(['project_id', 'code', 'name', 'objective', 'starts_at', 'ends_at', 'period', 'progress', 'narrative', 'blockers', 'next_step', 'title', 'due_at', 'task_status', 'case_number', 'case_type', 'subject', 'priority', 'category', 'description', 'cause', 'consequence', 'probability', 'impact', 'response', 'review_at'], null);
$reply = ['summary'=>'Raport javor i DI.', 'items'=>[
    ['type'=>'progress_report', 'project_id'=>7, 'period'=>'2026-11-06', 'progress'=>65, 'narrative'=>'Shtresa e dytë përfundoi.', 'blockers'=>'Mungon leja e KRU.', 'source_excerpt'=>'Rruga A: progresi 65%', 'confidence'=>'high'] + $blank,
    ['type'=>'task', 'project_id'=>9, 'title'=>'Ndriçimi', 'due_at'=>'01.12.2026', 'task_status'=>'weird', 'source_excerpt'=>'Ndriçimi', 'confidence'=>'medium'] + $blank,
    ['type'=>'risk', 'category'=>'schedule_delivery', 'description'=>'Vonesë nga leja.', 'probability'=>9, 'impact'=>4, 'response'=>null, 'source_excerpt'=>'mungon leja', 'confidence'=>'low'] + $blank,
    ['type'=>'case', 'case_number'=>'C.nr.2257/26', 'case_type'=>'kontest', 'subject'=>'Барање за плаќање', 'priority'=>'urgent', 'source_excerpt'=>'C.nr.2257/26', 'confidence'=>'high'] + $blank,
    ['type'=>'project', 'code'=>null, 'name'=>'Trotuari C', 'starts_at'=>'2026-12-01', 'source_excerpt'=>'нов проект', 'confidence'=>'medium'] + $blank,
    ['type'=>'meeting', 'source_excerpt'=>'x', 'confidence'=>'low'] + $blank,
]];
[$summary, $items] = $intake->propose("Raporti javor i DI\n• Rruga A: progresi 65%, mungon leja.", 1);
$input = json_decode((string) $sent[0]['input'], true);
$assert($summary === 'Raport javor i DI.' && count($items) === 5, 'unknown types are dropped');
$assert(array_column($input['existing_projects'], 'code') === ['P-7', 'P-8'] && $input['directorate'] === 'DI · Drejtoria e Infrastrukturës' && $sent[0]['max_output_tokens'] === 8000 && $sent[0]['text']['format']['strict'] === true, 'the AI gets the directorate projects only, a strict schema and a large output budget');
$assert($items[0]['fields'] === ['project_id'=>7, 'period'=>'2026-11-06', 'progress'=>65, 'narrative'=>'Shtresa e dytë përfundoi.', 'blockers'=>'Mungon leja e KRU.', 'next_step'=>null] && $items[0]['excerpt'] === 'Rruga A: progresi 65%', 'a progress report keeps only its fields');
$assert($items[1]['fields']['project_id'] === null && $items[1]['fields']['due_at'] === null && $items[1]['fields']['task_status'] === 'not_started', 'a project of another directorate, a bad date and an unknown status are cleared');
$assert($items[2]['fields']['probability'] === 5 && $items[2]['fields']['response'] === 'reduce' && $items[3]['fields']['priority'] === 'normal' && $items[4]['fields']['budget_category'] === 'capital_investments', 'scores are bounded and enums fall back to defaults');

// --- Validation -------------------------------------------------------------------------------------------------
$str = static fn(array $f): array => array_map(static fn($v): string => $v === null ? '' : (string) $v, $f);
[, $e] = $intake->validate('task', $str($items[1]['fields']), 1);
$assert(in_array('„Проект“ недостасува.', $e, true), 'a task needs a project of the directorate');
[, $e] = $intake->validate('task', ['project_id'=>'9', 'title'=>'X', 'task_status'=>'not_started'], 1);
$assert(in_array('Проектот не припаѓа на секторот.', $e, true), 'a project of another directorate is refused');
[, $e] = $intake->validate('task', ['project_id'=>'7', 'title'=>'X', 'task_status'=>'done', 'progress'=>'50'], 1);
$assert(in_array('Завршената задача мора да има напредок 100%.', $e, true), 'task status and progress agree as in the task form');
[, $e] = $intake->validate('project', $str($items[4]['fields']), 1);
$assert(in_array('„Шифра“ недостасува.', $e, true) && in_array('„Носител / одговорно лице“ недостасува.', $e, true), 'a new project needs a code and a manager');
[, $e] = $intake->validate('project', ['code'=>'P-7', 'name'=>'Друго', 'manager_id'=>'4', 'budget_category'=>'capital_investments'], 1);
$assert(in_array('Постои друг проект со оваа шифра во секторот.', $e, true) && in_array('Носителот нема активна улога за овој сектор.', $e, true), 'project code is unique and the manager must have a managing role');
[, $e] = $intake->validate('case', ['case_number'=>'01/2026', 'case_type'=>'x', 'subject'=>'y', 'priority'=>'normal'], 1);
$assert(in_array('Веќе постои предмет со овој број.', $e, true), 'case numbers are unique');
$assert(array_column($intake->managers(1), 'name') === ['Drita Provë', 'Kryetari Provë', 'Menaxher Provë'], 'managers: directors, project managers and the global roles');

// --- Registration -----------------------------------------------------------------------------------------------
$fail = static function (callable $f): string { try { $f(); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
$good = [
    ['type'=>'progress_report', 'fields'=>$str($items[0]['fields'])],
    ['type'=>'task', 'fields'=>['project_id'=>'8', 'title'=>'Ndriçimi', 'due_at'=>'2026-12-01', 'task_status'=>'not_started', 'progress'=>'']],
    ['type'=>'risk', 'fields'=>$str($items[2]['fields']) + ['project_id'=>'7']],
    ['type'=>'case', 'fields'=>$str($items[3]['fields'])],
    ['type'=>'project', 'fields'=>['code'=>'P-10', 'name'=>'Trotuari C', 'starts_at'=>'2026-12-01', 'manager_id'=>'3', 'budget_category'=>'capital_investments']],
];
$bad = $good; $bad[4]['fields']['code'] = '';
$assert(str_contains($fail(fn () => $intake->register(1, $bad, $audit, $notifications)), 'Предлог 5 (Проект): „Шифра“ недостасува.'), 'one invalid proposal stops the registration, with its number');
$assert((int) $db->scalar('SELECT COUNT(*) FROM progress_updates') === 0 && (int) $db->scalar('SELECT COUNT(*) FROM risks') === 0, 'nothing is saved when a proposal fails');
$dup = [$good[3], $good[3]];
$assert(str_contains($fail(fn () => $intake->register(1, $dup, $audit, $notifications)), 'се повторува'), 'the same case number twice is refused');
$reader = new EKabineti\Service\DocumentIntake($db, $scope, 4, $client);
$assert(str_contains($fail(fn () => $reader->register(1, [$good[2]], $audit, $notifications)), 'Немате дозвола да создадете ризик'), 'permissions are checked per record type');
$assert(str_contains($fail(fn () => $reader->register(1, [$good[1]], $audit, $notifications)), 'Немате пристап за запишување во овој проект'), 'writing to a project needs write access');
$assert(str_contains($fail(fn () => $intake->register(1, [], $audit, $notifications)), 'барем еден'), 'at least one proposal');

$created = $intake->register(1, $good, $audit, $notifications);
$assert(array_column($created, 'type') === ['progress_report', 'task', 'risk', 'case', 'project'], 'all chosen proposals are registered');
$assert((int) $db->scalar('SELECT progress FROM projects WHERE id=7') === 65 && (int) $db->scalar('SELECT COUNT(*) FROM progress_updates WHERE project_id=7 AND created_by=1') === 1, 'the progress report updates the project progress (tasks without weights)');
$task = $db->one("SELECT * FROM project_tasks WHERE title='Ndriçimi'");
$assert((int) $task['project_id'] === 8 && (float) $task['weight_percent'] === 0.0 && $task['status'] === 'not_started' && str_contains((string) $task['dependency_notes'], 'прикачениот документ #1'), 'the task gets weight 0 and its source');
$risk = $db->one('SELECT * FROM risks');
$assert((int) $risk['inherent_score'] === 20 && $risk['status'] === 'open' && (int) $risk['owner_id'] === 1 && (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE recipient_id=2 AND template='critical_risk'") === 1, 'a critical risk notifies the Mayor');
$assert((int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE recipient_id=3 AND template='project_progress_reported'") === 1, 'the project manager is told about the progress report');
$assert($db->scalar("SELECT relationship_type FROM case_directorates WHERE case_id=(SELECT id FROM administrative_cases WHERE case_number='C.nr.2257/26')") === 'OWNER', 'the case belongs to the directorate');
$assert($db->one("SELECT status,manager_id FROM projects WHERE code='P-10'") === ['status'=>'draft', 'manager_id'=>3], 'a new project is a draft with its manager');
$row = $db->one('SELECT * FROM document_intakes WHERE id=1');
$assert($row['status'] === 'confirmed' && (int) $row['confirmed_by'] === 1 && count(json_decode((string) $row['result_json'], true)) === 5, 'the intake keeps what was registered');
$assert((int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action='document_intake.confirmed'") === 1 && (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE reason='Од прикачениот документ #1'") === 5, 'every record is audited with its source');
$assert(str_contains($fail(fn () => $intake->register(1, $good, $audit, $notifications)), 'не е подготвен'), 'an intake is registered once');

// --- Wiring -------------------------------------------------------------------------------------------------------
$app = (string) file_get_contents(APP_ROOT.'/app/Core/Application.php');
$assert(str_contains($app, "\$p==='/document-intake')\$this->documentIntake()->upload()") && str_contains($app, "#^/document-intake/(\\d+)/confirm$#") && str_contains($app, "'document_intakes',"), 'routes and health tables');
$controller = (string) file_get_contents(APP_ROOT.'/app/Controller/DocumentIntakeController.php');
$assert(str_contains($controller, "[\$text, \$counts] = DocumentIntake::redact(\$text);") && strpos($controller, 'DocumentIntake::redact(') < strpos($controller, '->propose('), 'the text is masked before it reaches the AI');
$assert(str_contains($controller, "privacy_confirmed") && str_contains($controller, "'/storage/private/'") && !preg_match('/style=/', $controller), 'privacy confirmation, private storage, no inline styles');
$sql = (string) file_get_contents(APP_ROOT.'/database/migrations/2026_11_09_004900_document_intake.sql');
$assert(str_contains($sql, 'CREATE TABLE dbo.document_intakes') && str_contains($sql, "CHECK (file_type IN (N'docx',N'doc',N'pdf',N'xlsx'))"), 'migration');

foreach (glob($tmp.'/*') ?: [] as $f) @unlink($f); @rmdir($tmp);
if ($failures) { fwrite(STDERR, "FAIL\n - ".implode("\n - ", $failures)."\n"); exit(1); }
echo "document-intake-unit: OK\n";
