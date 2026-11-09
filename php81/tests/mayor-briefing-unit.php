<?php
declare(strict_types=1);

// Weekly briefing of the Mayor and meeting preparation: facts from fixed queries, the AI sees only the public text,
// cited refs are checked, decisions are drafted from notes and confirmed by a person. Invented names only.
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });
date_default_timezone_set('Europe/Skopje');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

final class TsqlOnSqlite extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('; SELECT CAST(SCOPE_IDENTITY() AS bigint) AS id', ' RETURNING id', $query);
        $query = preg_replace('/SELECT TOP (\d+) (.*)$/s', 'SELECT $2 LIMIT $1', $query);
        return parent::prepare($query, $options);
    }
}
$pdo = new TsqlOnSqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('SYSUTCDATETIME', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
foreach ([
    'users(id INTEGER PRIMARY KEY,name TEXT)',
    'directorates(id INTEGER PRIMARY KEY,code TEXT,name TEXT,active INT)',
    'projects(id INTEGER PRIMARY KEY,directorate_id INT,code TEXT,name TEXT,objective TEXT,starts_at TEXT,ends_at TEXT,status TEXT,progress INT)',
    'project_tasks(id INTEGER PRIMARY KEY,project_id INT,title TEXT,due_at TEXT,status TEXT,progress INT)',
    'progress_updates(id INTEGER PRIMARY KEY,project_id INT,period TEXT,progress INT,narrative TEXT,blockers TEXT,next_step TEXT,created_at TEXT)',
    'risks(id INTEGER PRIMARY KEY,directorate_id INT,project_id INT,category TEXT,description TEXT,cause TEXT,consequence TEXT,probability INT,impact INT,inherent_score INT,residual_score INT,review_at TEXT,status TEXT,created_at TEXT)',
    'risk_actions(id INTEGER PRIMARY KEY,risk_id INT,action TEXT,status TEXT,due_at TEXT,completed_at TEXT)',
    'budget_execution_lines(id INTEGER PRIMARY KEY,fiscal_year INT,organization_name TEXT,allocated_amount REAL,actual_amount REAL,obligations_amount REAL,as_of_date TEXT,directorate_id INT)',
    'budget_allocations(id INTEGER PRIMARY KEY,project_id INT,current_amount REAL)',
    'commitments(id INTEGER PRIMARY KEY,budget_allocation_id INT,amount REAL)',
    'expenditures(id INTEGER PRIMARY KEY,budget_allocation_id INT,amount REAL)',
    'revenue_transactions(id INTEGER PRIMARY KEY,payment_date TEXT,amount REAL,revenue_group TEXT)',
    'vw_citizen_service_directorate_weeks(directorate_code TEXT,directorate_name TEXT,week_start TEXT,received_count INT,resolved_count INT,open_after_forwarding INT,directorate_id INT)',
    'citizen_services(id INTEGER PRIMARY KEY,directorate_id INT,active INT)',
    'citizen_service_weekly_reports(id INTEGER PRIMARY KEY,directorate_id INT,week_start TEXT)',
    'administrative_cases(id INTEGER PRIMARY KEY,case_number TEXT,case_type TEXT,subject TEXT,description TEXT,originating_directorate_id INT,status TEXT,priority TEXT,opened_at TEXT,is_legal INT,court_reference TEXT,procedural_phase TEXT,claimed_amount REAL,recognized_amount REAL,created_at TEXT)',
    'legal_case_hearings(id INTEGER PRIMARY KEY,case_id INT,scheduled_at TEXT,authority_name TEXT,responsible_user_id INT,status TEXT)',
    'case_forwardings(id INTEGER PRIMARY KEY,case_id INT,from_directorate_id INT,to_directorate_id INT,cover_letter_number TEXT,forwarded_on TEXT,status TEXT)',
    'case_directorates(id INTEGER PRIMARY KEY,case_id INT,directorate_id INT,relationship_type TEXT,status TEXT,due_at TEXT)',
    'case_tasks(id INTEGER PRIMARY KEY,case_id INT,title TEXT,status TEXT,due_at TEXT)',
    'case_actions(id INTEGER PRIMARY KEY,case_id INT,action_type TEXT,action_date TEXT,description TEXT)',
    'mayor_agenda_items(id INTEGER PRIMARY KEY,directorate_id INT,item_type TEXT,project_id INT,risk_id INT,case_id INT,topic_title TEXT,topic_details TEXT,priority TEXT,discussion_date TEXT,status TEXT)',
    'citizen_service_sectors(id INTEGER PRIMARY KEY,directorate_id INT,code TEXT,name TEXT,active INT DEFAULT 1,sort_order INT DEFAULT 100)',
    'citizen_service_catalog(id INTEGER PRIMARY KEY,code TEXT,sector_id INT,window_id INT,name TEXT,category_code TEXT,fee_required INT,online INT,legal_deadline_days INT,tier TEXT,active INT DEFAULT 1,sort_order INT)',
    'citizen_service_item_reports(id INTEGER PRIMARY KEY,row_key TEXT,week_start TEXT,sector_id INT,window_id INT,catalog_id INT,category_code TEXT,received INT,resolved INT,rejected INT,open_count INT,over_deadline INT,online_count INT,amount REAL,submitted_by INT,submitted_at TEXT)',
    'mayor_briefings(id INTEGER PRIMARY KEY,week_start TEXT,directorate_id INT,facts_json TEXT,result_json TEXT,ai_model TEXT,ai_error TEXT,generated_by INT,created_at TEXT)',
    'mayor_meeting_briefs(id INTEGER PRIMARY KEY,agenda_item_id INT,facts_json TEXT,result_json TEXT,ai_model TEXT,ai_error TEXT,notes TEXT,decisions_json TEXT,decisions_text TEXT,confirmed_by INT,confirmed_at TEXT,generated_by INT,created_at TEXT,updated_at TEXT)',
] as $table) $pdo->exec('CREATE TABLE '.$table);
$pdo->exec("INSERT INTO users VALUES(1,'Ана Пробна')");
$pdo->exec("INSERT INTO directorates VALUES(1,'DI','Сектор за инфраструктура',1),(2,'PRAV','Правна служба',1),(3,'DSH','Сектор за здравство',1)");
$pdo->exec("INSERT INTO projects VALUES(7,1,'P-7','Улица А','Асфалтирање на улица А','2026-01-10','2026-09-30','active',60),(8,1,'P-8','Парк Б',NULL,'2026-01-10','2027-06-30','active',20),(9,1,'P-9','Училиште В',NULL,'2026-10-25','2027-10-01','active',0)");
$pdo->exec("INSERT INTO project_tasks VALUES(50,7,'Втор слој','2026-08-01','in_progress',30),(51,7,'Осветлување','2026-12-01','planned',0),(52,7,'Проект','2026-02-01','done',100)");
$pdo->exec("INSERT INTO progress_updates VALUES(90,7,'2026-10-01',60,'Работите продолжуваат.','Недостасува дозвола од водоводот','Средба со ЈП Водовод','2026-10-03')");
$pdo->exec("INSERT INTO risks VALUES(30,1,7,'financial','Пречекорување на трошоците',NULL,NULL,4,5,20,16,'2026-11-20','open','2026-10-28'),(31,1,NULL,'legal','Стар',NULL,NULL,4,4,16,NULL,NULL,'open','2026-01-01')");
$pdo->exec("INSERT INTO risk_actions VALUES(70,30,'Повторно преговарање на договорот','planned','2026-10-15',NULL)");
$pdo->exec("INSERT INTO budget_execution_lines VALUES(1,2026,'Инфраструктура',1000000,250000,100000,'2026-10-31',1),(2,2026,'Здравство',500000,400000,0,'2026-10-31',3)");
$pdo->exec("INSERT INTO budget_allocations VALUES(5,7,200000)");
$pdo->exec("INSERT INTO commitments VALUES(1,5,150000)");
$pdo->exec("INSERT INTO expenditures VALUES(1,5,90000)");
$pdo->exec("INSERT INTO revenue_transactions VALUES(1,'2026-10-27',1000,'10'),(2,'2026-10-30',500,'40'),(3,'2026-10-21',750,'10')");
$pdo->exec("INSERT INTO vw_citizen_service_directorate_weeks VALUES('DI','Сектор за инфраструктура','2026-10-26',40,30,10,1)");
$pdo->exec("INSERT INTO citizen_services VALUES(1,1,1),(2,3,1)");
$pdo->exec("INSERT INTO citizen_service_weekly_reports VALUES(1,1,'2026-10-26')");
$pdo->exec("INSERT INTO administrative_cases VALUES(40,'П-2257/26','kontest_pune','Благој Граѓанин против Општината','Тужителот Благој Граѓанин бара плати.',2,'in_progress','high','2026-03-01',1,'П-2257/26','shkalla_pare',12000,NULL,'2026-10-28')");
$pdo->exec("INSERT INTO legal_case_hearings VALUES(60,40,'2026-11-04 10:00:00','Основен суд Битола',1,'scheduled')");
$pdo->exec("INSERT INTO case_forwardings VALUES(80,40,1,3,'01/2026','2026-10-20','forwarded')");
$pdo->exec("INSERT INTO case_directorates VALUES(1,40,2,'OWNER','active',NULL)");
$pdo->exec("INSERT INTO case_tasks VALUES(1,40,'Повикај го Благој Граѓанин за спогодба','open','2026-01-10')");
$pdo->exec("INSERT INTO case_actions VALUES(1,40,'hearing','2026-10-01','Рочиштето со Благој Граѓанин е одложено')");
$pdo->exec("INSERT INTO citizen_service_sectors(id,directorate_id,code,name) VALUES(1,1,'DI_URB','Одделение за урбанизам'),(2,3,'DSH_DIR','Сектор за здравство')");
$pdo->exec("INSERT INTO citizen_service_catalog VALUES(1,'CS029',1,9,'Одобренија за градење','permits',1,0,30,'B',1,10),(2,'CS001',1,9,'Извод од матична книга на родени','certificates',1,1,45,'A',1,20),(3,'CS067',2,9,'Субвенции за хронични болести','subsidies',0,0,45,'B',1,10)");
$pdo->exec("INSERT INTO citizen_service_item_reports(row_key,week_start,sector_id,window_id,catalog_id,category_code,received,resolved,rejected,open_count,over_deadline,online_count) VALUES('S:CS029','2026-10-26',1,9,1,'permits',12,5,1,6,3,NULL),('S:CS001','2026-10-26',1,9,2,'certificates',NULL,300,0,NULL,NULL,120),('S:CS001','2026-10-19',1,9,2,'certificates',NULL,200,0,NULL,NULL,50)");
$pdo->exec("INSERT INTO mayor_agenda_items VALUES(100,1,'project',7,NULL,NULL,NULL,NULL,'high','2026-11-05','open'),(101,2,'case',NULL,NULL,40,NULL,NULL,'critical',NULL,'open'),(102,1,'topic',NULL,NULL,NULL,'Јавно осветлување','Зимски план','normal',NULL,'open')");

$db = (new ReflectionClass(EKabineti\Core\Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(EKabineti\Core\Database::class, 'pdo'))->setValue($db, $pdo);
$aiConfig = ['enabled'=>true, 'api_key'=>'unit-test-secret', 'model'=>'unit-model', 'timeout_seconds'=>10, 'max_output_tokens'=>900];
$sent = [];
$reply = null;
$transport = static function (array $payload) use (&$sent, &$reply): array { $sent[] = $payload; return ['output'=>[['type'=>'message', 'content'=>[['type'=>'output_text', 'text'=>json_encode($reply)]]]]]; };
$client = new EKabineti\Service\OpenAIResponsesClient($aiConfig, $transport);

// --- Weekly briefing ---------------------------------------------------------------------------------------------
$assert(EKabineti\Service\MayorBriefing::weekStart('2026-11-05') === '2026-11-02' && EKabineti\Service\MayorBriefing::weekStart('2026-11-08') === '2026-11-02' && EKabineti\Service\MayorBriefing::weekStart('2026-11-02') === '2026-11-02', 'the week starts on Monday');
$briefing = new EKabineti\Service\MayorBriefing($db, $client);
$facts = $briefing->facts('2026-11-02');
$refs = [];
foreach ($facts['sections'] as $items) foreach ($items as $i) $refs[$i['ref']] = $i;
$assert($facts['last_week'] === ['2026-10-26', '2026-11-01'] && $facts['next_week'] === ['2026-11-02', '2026-11-08'], 'last week and next week around the briefing date');
$assert(isset($refs['P7']) && !isset($refs['P8']) && str_contains($refs['P7']['text'], 'го помина рокот 30.09.2026 со напредок 60%'), 'projects past their end date are listed, others not');
$assert(isset($refs['PT7']) && str_contains($refs['PT7']['text'], '1 задача(и) со доцнење'), 'late tasks are counted per project (done ones are not)');
$assert(!isset($refs['PS']) && str_contains($refs['PN8']['text'] ?? '', 'Проект P-8 Парк Б (DI) е без напредок во последните три недели: ниеден извештај за напредок') && str_contains($refs['PN7']['text'] ?? '', 'последен извештај на 03.10.2026') && !isset($refs['PN9']) && str_contains($refs['PNC']['text'] ?? '', ': 2.'), 'projects without progress in the last three weeks are listed (not the ones started in that time)');
$assert(!isset($refs['PZ8']), 'projects without tasks are only in the directorate briefing');
$assert(isset($refs['R30']) && !isset($refs['R31']) && str_contains($refs['RC']['text'], 'критични ризици: 2'), 'new critical risks of last week, and open critical risks in total');
$assert(str_contains($refs['B']['text'], 'распределено 1.500.000,00 МКД, потрошено 650.000,00 МКД (43,3%)') && str_contains($refs['BO1']['text'], 'Инфраструктура 25,0% од 1.000.000,00 МКД'), 'budget execution and the organisation with the lowest execution first');
$assert(str_contains($refs['V']['text'], '1.500,00 МКД во 2 уплати (+100,0%') && str_contains($refs['V10']['text'], '1.000,00 МКД (+33,3%)') && str_contains($refs['V40']['text'], '(нов)'), 'revenues of last week compared with the week before');
$assert(str_contains($refs['SDI']['text'], '40 барања, 30 решени, 10 отворени') && str_contains($refs['SM']['text'], 'DSH') && !str_contains($refs['SM']['text'], 'DI,'), 'citizen services per directorate and the directorates that did not report');
$assert(str_contains($refs['SO1']['text'] ?? '', 'DI · Одобренија за градење: 3 предмети над законскиот рок (30 дена) од 6 во постапка') && !isset($refs['SO2']), 'services with cases past the legal deadline');
$assert(str_contains($refs['ST1']['text'] ?? '', 'Извод од матична книга на родени: 300 предмети (+50,0%') && str_contains($refs['ST2']['text'] ?? '', 'Одобренија за градење: 12 предмети (нов'), 'busiest services against the week before');
$assert(str_contains($refs['SD']['text'] ?? '', '40,0% од предметите се завршени онлајн') && str_contains($refs['SX']['text'] ?? '', 'DSH Сектор за здравство') && !str_contains($refs['SX']['text'], 'урбанизам'), 'online share and the sectors without a report');
$assert(isset($refs['H60']) && !str_contains($refs['H60']['text'], 'Благој') && str_contains($refs['H60']['private'], 'Благој Граѓанин'), 'hearings next week: the parties are private');
$assert(isset($refs['F40']) && str_contains($refs['F40']['text'], 'од DI до DSH'), 'forwarded cases not received after 3 days');
$assert(isset($refs['A100'], $refs['A101'], $refs['A102']) && $refs['A100']['link'] === '/mayor-agenda/100/prepare', 'open agenda items link to the meeting preparation');
$aiText = json_encode(EKabineti\Service\MayorBriefing::aiInput($facts), JSON_UNESCAPED_UNICODE);
$assert(!str_contains($aiText, 'Благој') && !str_contains($aiText, 'private') && !str_contains($aiText, '"link"'), 'the AI input has neither private details nor links');

$reply = ['headline'=>'Java me vonesa në infrastrukturë', 'attention'=>[['text'=>'Улица А ka kaluar afatin.', 'refs'=>['P7', 'PT7']], ['text'=>'E shpikur', 'refs'=>['X99']]], 'sections'=>[['key'=>'projects', 'summary'=>'Dy projekte kërkojnë vëmendje.', 'refs'=>['P7', 'BOGUS']], ['key'=>'unknown', 'summary'=>'x', 'refs'=>[]]]];
$id = $briefing->generate('2026-11-02', 1);
$row = $db->one('SELECT * FROM mayor_briefings WHERE id=:id', ['id'=>$id]);
$result = json_decode((string) $row['result_json'], true);
$assert($row['ai_model'] === 'unit-model' && $row['ai_error'] === null && $row['week_start'] === '2026-11-02', 'the briefing is stored with the model');
$assert(count($result['attention']) === 1 && $result['attention'][0]['refs'] === ['P7', 'PT7'], 'attention points with unknown refs only are dropped');
$assert($result['sections']['projects']['refs'] === ['P7'] && !isset($result['sections']['unknown']), 'unknown refs and sections are removed');
$assert(!str_contains((string) $sent[0]['input'], 'Благој') && $sent[0]['max_output_tokens'] === 6000 && $sent[0]['text']['format']['strict'] === true, 'what is sent: no private names, a larger output budget, strict schema');

$failing = new EKabineti\Service\OpenAIResponsesClient($aiConfig, static fn (array $p): array => ['error'=>['message'=>'quota']]);
$row = $db->one('SELECT * FROM mayor_briefings WHERE id=:id', ['id'=>(new EKabineti\Service\MayorBriefing($db, $failing))->generate('2026-11-02', 1)]);
$assert($row['result_json'] === null && str_contains((string) $row['ai_error'], 'quota') && str_contains((string) $row['facts_json'], 'P7'), 'an AI failure keeps the facts and records the error');
$row = $db->one('SELECT * FROM mayor_briefings WHERE id=:id', ['id'=>(new EKabineti\Service\MayorBriefing($db, null))->generate('2026-10-26', null)]);
$assert($row['result_json'] === null && $row['ai_error'] === null && $row['generated_by'] === null, 'without AI the briefing holds the facts only');

// --- Directorate briefing ---------------------------------------------------------------------------------------
$dfacts = $briefing->facts('2026-11-02', 1);
$drefs = [];
foreach ($dfacts['sections'] as $items) foreach ($items as $i) $drefs[$i['ref']] = $i;
$assert($dfacts['directorate'] === 'DI · Сектор за инфраструктура' && array_keys($dfacts['sections']) === EKabineti\Service\MayorBriefing::DIRECTORATE_SECTIONS, 'the directorate briefing has its own sections');
$assert(isset($drefs['P7'], $drefs['PT7'], $drefs['PN8'], $drefs['PN7']) && !str_contains($drefs['PN8']['text'], '(DI)') && str_contains($drefs['PNC']['text'], ': 2.'), 'directorate: late projects, late tasks and projects without progress in three weeks');
$assert(str_contains($drefs['PZ8']['text'] ?? '', 'Проект P-8 Парк Б нема ниедна дефинирана задача (започна на 10.01.2026)') && isset($drefs['PZ9']) && !isset($drefs['PZ7']) && str_contains($drefs['PZC']['text'] ?? '', ': 2.'), 'directorate: projects without defined tasks');
$assert(str_contains($drefs['RC']['text'], ': 2.') && isset($drefs['R30']) && str_contains($drefs['B']['text'] ?? '', 'распределено 1.000.000,00 МКД'), 'directorate risks and budget only');
$assert(str_contains($drefs['S']['text'] ?? '', '40 барања') && isset($drefs['SO1']) && !isset($drefs['SX']) && str_contains($drefs['F40']['text'] ?? '', 'препратен до DSH'), 'directorate citizen services and forwardings');
$assert(isset($drefs['A100'], $drefs['A102']) && !isset($drefs['A101']), 'only the agenda items of the directorate');
$dfacts3 = $briefing->facts('2026-11-02', 3);
$d3 = []; foreach ($dfacts3['sections'] as $items) foreach ($items as $i) $d3[$i['ref']] = $i;
$assert(str_contains($d3['SX']['text'] ?? '', 'Сектор за здравство') && str_contains($d3['F40']['text'] ?? '', 'препратен од DI до секторот') && !isset($d3['P7']), 'another directorate sees its own facts');
$sent = [];
$did = $briefing->generate('2026-11-02', 1, 1);
$assert((int) $db->scalar('SELECT directorate_id FROM mayor_briefings WHERE id=:id', ['id'=>$did]) === 1 && str_contains((string) $sent[0]['instructions'], 'directorate "DI · Сектор за инфраструктура"'), 'a directorate briefing is stored with its directorate and its own instructions');

// --- Meeting preparation ----------------------------------------------------------------------------------------
$sent = [];
$meeting = new EKabineti\Service\MayorMeetingBrief($db, $client);
$project = $meeting->facts(100);
$prefs = array_column($project['items'], null, 'ref');
$assert($project['title'] === 'Проект P-7 · Улица А' && isset($prefs['P'], $prefs['PT'], $prefs['T50'], $prefs['PB'], $prefs['U90'], $prefs['R30']) && !isset($prefs['T51']), 'project facts: status, tasks, late tasks, budget, reports, open risks');
$assert(str_contains($prefs['PB']['text'], 'распределено 200.000,00 МКД, преземено 150.000,00 МКД, потрошено 90.000,00 МКД (45,0%)') && str_contains($prefs['U90']['text'], 'Пречки: Недостасува дозвола'), 'project budget and the last progress report');
$case = $meeting->facts(101);
$cfacts = array_column($case['items'], null, 'ref');
$caseAi = json_encode(EKabineti\Service\MayorMeetingBrief::aiInput($case), JSON_UNESCAPED_UNICODE);
$assert(isset($cfacts['C'], $cfacts['CM'], $cfacts['CD1'], $cfacts['CT1'], $cfacts['CF80'], $cfacts['CH60'], $cfacts['CA1']) && $cfacts['C']['link'] === '/legal-office/cases/40', 'case facts: status, amount, directorates, tasks, forwardings, hearings, actions');
$assert(!str_contains($caseAi, 'Благој') && str_contains($cfacts['C']['private'], 'Благој') && str_contains($cfacts['CT1']['private'], 'Благој'), 'case subject, task titles and action descriptions stay private');
$topic = $meeting->facts(102);
$assert($topic['items'][1]['ref'] === 'TP' && str_contains($topic['items'][1]['text'], 'Зимски план'), 'a topic is prepared from its title and details');

$reply = ['summary'=>'Projekti është me vonesë.', 'summary_refs'=>['P', 'ZZ'], 'key_points'=>[['text'=>'Task-u 50 me vonesë', 'refs'=>['T50']], ['text'=>'Pa burim', 'refs'=>[]]], 'questions'=>[['text'=>'Kur merret leja?', 'refs'=>['U90']], ['text'=>'q2', 'refs'=>[]], ['text'=>'q3', 'refs'=>[]], ['text'=>'q4', 'refs'=>[]]]];
$briefId = $meeting->prepare(100, 1);
$brief = $db->one('SELECT * FROM mayor_meeting_briefs WHERE id=:id', ['id'=>$briefId]);
$prep = json_decode((string) $brief['result_json'], true);
$assert($prep['summary_refs'] === ['P'] && count($prep['key_points']) === 1 && count($prep['questions']) === 3, 'key points need a known ref; at most 3 questions');

$reply = ['decisions'=>[['text'=>'СИ го завршува вториот слој', 'responsible'=>'DI', 'due_date'=>'2026-12-15'], ['text'=>'Средба со ЈП Водовод', 'responsible'=>'', 'due_date'=>'утре'], ['text'=>' ', 'responsible'=>'', 'due_date'=>'']]];
$out = $meeting->draftDecisions($briefId, 'Одлучено е СИ да го заврши слојот до 15 декември. Средба со ЈП Водовод.');
$brief = $db->one('SELECT * FROM mayor_meeting_briefs WHERE id=:id', ['id'=>$briefId]);
$assert(count($out['decisions']) === 2 && $out['decisions'][1]['due_date'] === '', 'empty decisions are dropped and invalid dates cleared');
$assert($brief['decisions_text'] === "1. СИ го завршува вториот слој — Одговорно лице: DI — Рок: 15.12.2026\n2. Средба со ЈП Водовод" && $brief['confirmed_at'] === null, 'the draft is stored as editable text and not confirmed');
$assert(str_contains((string) end($sent)['input'], 'Средба со ЈП Водовод') && end($sent)['text']['format']['name'] === 'mayor_meeting_decisions', 'the notes are sent to draft the decisions');

$db->execute("UPDATE mayor_meeting_briefs SET confirmed_at='2026-11-05 10:00:00',confirmed_by=1 WHERE id=:id", ['id'=>$briefId]);
$assert(!$meeting->draftDecisions($briefId, 'tjetër') || $db->scalar('SELECT notes FROM mayor_meeting_briefs WHERE id=:id', ['id'=>$briefId]) !== 'tjetër', 'confirmed decisions are not overwritten');
$again = array_column($meeting->facts(100)['items'], null, 'ref');
$assert(isset($again['D'.$briefId]) && str_contains($again['D'.$briefId]['text'], 'Одлуки од средбата на 05.11.2026: 1. СИ го завршува'), 'earlier confirmed decisions on the same subject are part of the next preparation');

// --- Wiring -------------------------------------------------------------------------------------------------------
$app = (string) file_get_contents(APP_ROOT.'/app/Core/Application.php');
$assert(str_contains($app, "\$p==='/mayor-briefing')\$this->mayorBriefing()->index()") && str_contains($app, "#^/mayor-agenda/(\\d+)/prepare/(\\d+)/confirm$#") && str_contains($app, "'mayor_briefings', 'mayor_meeting_briefs'"), 'routes and health tables');
$controller = (string) file_get_contents(APP_ROOT.'/app/Controller/MayorBriefingController.php');
$assert(str_contains($controller, 'canManageMayorAgenda($this->userId(), (int) $item[\'directorate_id\'])') && str_contains($controller, "public function confirm(int \$itemId, int \$briefId): never\n    {\n        \$this->requireBriefing();"), 'directors see only their directorate; only the cabinet confirms decisions');
$assert(!preg_match('/style=/', $controller), 'no inline styles (Content Security Policy)');
$read = (string) file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');
$assert(str_contains($read, "/mayor-agenda/'.(int)\$row['id'].'/prepare\">Подготви средба</a>"), 'the agenda links to the meeting preparation');
$scheduler = (string) file_get_contents(APP_ROOT.'/app/Service/Scheduler.php');
$assert(str_contains($scheduler, '$this->weeklyMayorBriefing()') && str_contains($scheduler, "'mayor_briefing', 'Неделен брифинг · '"), 'the scheduler generates the weekly briefing and notifies the cabinet');
$migration = (string) file_get_contents(APP_ROOT.'/database/migrations/2026_11_05_004500_mayor_briefings.sql');
$assert(str_contains($migration, 'CREATE TABLE dbo.mayor_briefings') && str_contains($migration, 'CREATE TABLE dbo.mayor_meeting_briefs'), 'migration creates both tables');

$assert(str_contains($app, "\$p==='/directorate-briefing')\$this->mayorBriefing()->directorateIndex()") && str_contains($app, "#^/directorates/(\\d+)/delete$#") && str_contains($app, "#^/directorates/(\\d+)/status$#"), 'routes of the directorate briefing and of the directorate administration');
$assert(str_contains($scheduler, '$this->weeklyDirectorateBriefings()') && str_contains($scheduler, "WHERE week_start=:week AND directorate_id IS NULL") && str_contains($scheduler, "'directorate_briefing', 'Неделен брифинг на секторот · '"), 'the scheduler makes the directorate briefings and keeps the Mayor briefing apart');
$writeCtl = (string) file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');
$assert(str_contains($writeCtl, 'public function directorateDelete(int $id): never') && str_contains($writeCtl, "'projects'=>'projekte'") && str_contains($writeCtl, "\$reason=\$this->requireField('reason',500);"), 'a directorate is deleted only without linked data and with a reason');
$assert(str_contains($read, '<form method="post" action="/users/access">') && str_contains($read, "action=\"/directorates/'.(int)\$row['id'].'/status\""), 'quick activation / deactivation of users and directorates in their lists');
$assert(str_contains((string) file_get_contents(APP_ROOT.'/database/migrations/2026_11_07_004700_directorate_briefings.sql'), 'ADD directorate_id bigint NULL'), 'migration adds the directorate to the briefings');
if ($failures) { fwrite(STDERR, "FAIL\n - ".implode("\n - ", $failures)."\n"); exit(1); }
echo "mayor-briefing-unit: OK\n";
