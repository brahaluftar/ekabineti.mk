<?php
declare(strict_types=1);

// Service catalog and weekly reporting per service: rows by tier, fields by category, validation, window totals computed
// from the sector reports, drill-down summary, sectors without a report, the migration and the wiring.
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
        $query = preg_replace('/ WITH \([A-Z,]+\)/', '', $query);
        $query = preg_replace('/SELECT TOP (\d+) (.*)$/s', 'SELECT $2 LIMIT $1', $query);
        return parent::prepare($query, $options);
    }
}
$pdo = new TsqlOnSqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('SYSUTCDATETIME', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
foreach ([
    'users(id INTEGER PRIMARY KEY,name TEXT,email TEXT)',
    'directorates(id INTEGER PRIMARY KEY,code TEXT,name TEXT,active INT)',
    'citizen_services(id INTEGER PRIMARY KEY,directorate_id INT,code TEXT,name TEXT,window_type TEXT,active INT,description TEXT,sla_target_hours REAL,sort_order INT,created_at TEXT,updated_at TEXT)',
    'citizen_service_metrics(id INTEGER PRIMARY KEY,service_id INT,metric_key TEXT,label TEXT,metric_role TEXT,active INT,sort_order INT,created_at TEXT,updated_at TEXT)',
    'citizen_service_weekly_reports(id INTEGER PRIMARY KEY,service_id INT,directorate_id INT,week_start TEXT,average_resolution_hours REAL,notes TEXT,submitted_by INT,submitted_at TEXT,updated_at TEXT)',
    'citizen_service_weekly_values(report_id INT,metric_id INT,metric_value INT,amount_value REAL,created_at TEXT,updated_at TEXT)',
    'citizen_service_sectors(id INTEGER PRIMARY KEY,directorate_id INT,code TEXT,name TEXT,active INT DEFAULT 1,sort_order INT DEFAULT 100,created_at TEXT)',
    'citizen_service_catalog(id INTEGER PRIMARY KEY,code TEXT,sector_id INT,window_id INT,name TEXT,category_code TEXT,beneficiaries TEXT,fee_required INT,online INT,legal_deadline_days INT,tier TEXT,active INT DEFAULT 1,sort_order INT,created_at TEXT,updated_at TEXT)',
    'citizen_service_sector_reporters(id INTEGER PRIMARY KEY,sector_id INT,user_id INT,active INT,assigned_by INT,assigned_at TEXT)',
    'citizen_service_item_reports(id INTEGER PRIMARY KEY,row_key TEXT,week_start TEXT,sector_id INT,window_id INT,catalog_id INT,category_code TEXT,received INT,resolved INT,rejected INT,open_count INT,over_deadline INT,online_count INT,amount REAL,submitted_by INT,submitted_at TEXT,UNIQUE(row_key,week_start))',
] as $table) $pdo->exec('CREATE TABLE '.$table);
$pdo->exec("INSERT INTO users VALUES(1,'Arta Provë','arta@example.test'),(2,'Петар Пробен','besnik@example.test')");
$pdo->exec("INSERT INTO directorates VALUES(1,'ADM','Сектор за администрација',1),(2,'DMQS','Сектор за социјална заштита',1)");
$pdo->exec("INSERT INTO citizen_services(id,directorate_id,code,name,window_type,active,sort_order) VALUES(10,1,'ADM_CIVIL_STATUS','Матична евиденција','request',1,10),(11,2,'DMQS_SUBSIDIES','Социјални субвенции','subsidy',1,10),(12,1,'ADM_QSHQ','QSHQ','request',1,20)");
$pdo->exec("INSERT INTO citizen_service_metrics(service_id,metric_key,label,metric_role,active,sort_order) VALUES(10,'received','Барање','received',1,10),(10,'reviewed','Разгледувања','informational',1,20),(10,'resolved','Завршени','resolved',1,30),(10,'open','Во постапка','open',1,40),(11,'received','Aplikime','received',1,10),(11,'open','Во разгледување','open',1,20),(11,'resolved','Одобрени','resolved',1,30),(11,'rejected','Одбиени','resolved',1,40),(11,'approved_amount','Износ','amount',1,50)");
$pdo->exec("INSERT INTO citizen_service_sectors(id,directorate_id,code,name) VALUES(1,1,'ADM_GJC','Одделение за матична евиденција'),(2,2,'DMQS_DIR','Сектор за социјална заштита'),(3,1,'ADM_SSQ','Одделение за услуги на граѓаните')");
$pdo->exec("INSERT INTO citizen_service_catalog(id,code,sector_id,window_id,name,category_code,beneficiaries,fee_required,online,legal_deadline_days,tier,sort_order) VALUES
 (1,'CS001',1,10,'Извод од матична книга на родени','certificates','Граѓани',1,1,45,'A',10),
 (2,'CS017',1,10,'Упис во матична книга на родени','registrations','Граѓани',1,0,45,'A',20),
 (3,'CS015',1,10,'Решение за исправка на податоци','decisions','Граѓани',1,0,45,'C',30),
 (4,'CS022',1,10,'Решение за поништување на упис','decisions','Граѓани',1,0,30,'C',40),
 (5,'CS062',2,11,'Поддршка за семејствата','subsidies','Граѓани',0,0,45,'B',10),
 (6,'CS023',3,12,'Барање за државјанство','decisions','Граѓани',0,0,90,'C',10)");

$db = (new ReflectionClass(EKabineti\Core\Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(EKabineti\Core\Database::class, 'pdo'))->setValue($db, $pdo);
$catalog = new EKabineti\Service\CitizenServiceCatalog($db);

// --- Rows and fields --------------------------------------------------------------------------------------------
$rows = array_column($catalog->rows(1), null, 'key');
$assert(array_keys($rows) === ['S:CS001', 'S:CS017', 'C:ADM_GJC:decisions:ADM_CIVIL_STATUS'], 'tier A/B one row per service, tier C one row per category and window, after the services ('.implode(',', array_keys($rows)).')');
$group = $rows['C:ADM_GJC:decisions:ADM_CIVIL_STATUS'];
$assert($group['name'] === 'Други – Решенија и управни барања' && count($group['services']) === 2 && $group['deadline'] === 30, 'the tier C group lists its services and uses the shortest legal deadline');
$assert(array_keys($rows['S:CS001']['fields']) === ['resolved', 'rejected', 'online_count'] && $rows['S:CS001']['fields']['online_count'] === 'Од нив онлајн', 'certificates: issued, rejected and, for an online service, how many online');
$assert(array_keys($rows['S:CS017']['fields']) === ['received', 'resolved', 'rejected', 'open_count', 'over_deadline'] && str_contains($rows['S:CS017']['fields']['over_deadline'], '45 дена'), 'registrations: with the cases past the legal deadline');
$sub = $catalog->rows(2)[0];
$assert(array_keys($sub['fields']) === ['received', 'resolved', 'rejected', 'open_count', 'over_deadline', 'amount'], 'subsidies have the approved amount');
$single = $catalog->rows(3)[0];
$assert($single['name'] === 'Барање за државјанство', 'a tier C group with one service shows the service name');

// --- Saving and validation ----------------------------------------------------------------------------------------
$week = '2026-10-26';
$error = static function (callable $f): string { try { $f(); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
$assert(str_contains($error(fn () => $catalog->save(1, '2026-10-27', [], 1)), 'во понеделник'), 'the week must start on Monday');
$assert(str_contains($error(fn () => $catalog->save(1, $week, ['S:CS017'=>['received'=>'5', 'open_count'=>'2', 'over_deadline'=>'3']], 1)), 'над законскиот рок не можат'), 'past the deadline cannot exceed the open cases');
$assert(str_contains($error(fn () => $catalog->save(1, $week, ['S:CS001'=>['resolved'=>'5', 'online_count'=>'9']], 1)), 'онлајн предметите не можат'), 'online cannot exceed the total');
$assert(str_contains($error(fn () => $catalog->save(1, $week, ['S:CS001'=>['resolved'=>'-1']], 1)), 'цел број'), 'negative numbers are refused');
$assert((int) $db->scalar('SELECT COUNT(*) FROM citizen_service_item_reports') === 0, 'a failed validation stores nothing');

$result = $catalog->save(1, $week, ['S:CS001'=>['resolved'=>'1.200', 'rejected'=>'3', 'online_count'=>'400'], 'S:CS017'=>['received'=>'250', 'resolved'=>'230', 'rejected'=>'', 'open_count'=>'20', 'over_deadline'=>'4'], 'C:ADM_GJC:decisions:ADM_CIVIL_STATUS'=>['received'=>'7', 'resolved'=>'5', 'open_count'=>'2']], 1);
$assert($result['rows'] === 3 && $result['windows'] === [10], 'the sector rows are stored and their window recomputed');
$saved = $catalog->values(1, $week);
$assert((int) $saved['S:CS001']['resolved'] === 1200 && $saved['S:CS001']['received'] === null && (int) $saved['S:CS017']['rejected'] === 0 && $saved['S:CS017']['amount'] === null, 'thousand separators are read, empty fields are 0 and fields of other categories stay empty');
$window = [];
foreach ($db->all('SELECT m.metric_key,v.metric_value FROM citizen_service_weekly_values v JOIN citizen_service_metrics m ON m.id=v.metric_id JOIN citizen_service_weekly_reports r ON r.id=v.report_id WHERE r.service_id=10 AND r.week_start=:w', ['w'=>$week]) as $v) $window[$v['metric_key']] = (int) $v['metric_value'];
$assert($window === ['received'=>1460, 'reviewed'=>1438, 'resolved'=>1438, 'open'=>22], 'window totals: received (certificates count as issued + rejected), finished, open ('.json_encode($window).')');
$assert(str_contains((string) $db->scalar('SELECT notes FROM citizen_service_weekly_reports WHERE service_id=10'), 'Пресметано'), 'the window report says it was computed');

$catalog->save(2, $week, [$sub['key']=>['received'=>'30', 'resolved'=>'10', 'rejected'=>'5', 'open_count'=>'15', 'amount'=>'12.500,50']], 1);
$subsidy = [];
foreach ($db->all('SELECT m.metric_key,v.metric_value,v.amount_value FROM citizen_service_weekly_values v JOIN citizen_service_metrics m ON m.id=v.metric_id JOIN citizen_service_weekly_reports r ON r.id=v.report_id WHERE r.service_id=11', []) as $v) $subsidy[$v['metric_key']] = $v['metric_key'] === 'approved_amount' ? (float) $v['amount_value'] : (int) $v['metric_value'];
$assert(($subsidy['resolved'] ?? null) === 10 && ($subsidy['rejected'] ?? null) === 5 && ($subsidy['received'] ?? null) === 30 && abs(($subsidy['approved_amount'] ?? 0) - 12500.5) < 0.001, 'subsidy windows: approved and rejected apart, and the approved amount ('.json_encode($subsidy).')');

$catalog->save(1, $week, ['S:CS001'=>['resolved'=>'1000'], 'S:CS017'=>['received'=>'250', 'resolved'=>'230', 'open_count'=>'20', 'over_deadline'=>'4'], 'C:ADM_GJC:decisions:ADM_CIVIL_STATUS'=>['received'=>'7', 'resolved'=>'5', 'open_count'=>'2']], 2);
$assert((int) $db->scalar('SELECT COUNT(*) FROM citizen_service_item_reports WHERE week_start=:w', ['w'=>$week]) === 4 && (int) $db->scalar("SELECT resolved FROM citizen_service_item_reports WHERE row_key='S:CS001'") === 1000 && (int) $db->scalar("SELECT submitted_by FROM citizen_service_item_reports WHERE row_key='S:CS001'") === 2, 'saving again updates the rows instead of adding them');
$assert((int) $db->scalar("SELECT COUNT(*) FROM citizen_service_weekly_reports WHERE service_id=10") === 1, 'one window report per week');

// --- Summary and missing sectors ----------------------------------------------------------------------------------
$catalog->save(1, '2026-10-19', ['S:CS001'=>['resolved'=>'800'], 'S:CS017'=>[], 'C:ADM_GJC:decisions:ADM_CIVIL_STATUS'=>[]], 1);
$dirs = array_column($catalog->summary($week, 'directorate'), null, 'code');
$assert((int) $dirs['ADM']['received'] === 1257 && (int) $dirs['ADM']['previous_received'] === 800 && (int) $dirs['ADM']['over_deadline'] === 4, 'directorate level with the week before ('.json_encode($dirs['ADM']).')');
$assert((int) $dirs['DMQS']['amount'] === 12500, 'amounts are summed');
$services = $catalog->summary($week, 'service', ['sector_id'=>1]);
$assert($services[0]['name'] === 'Извод од матична книга на родени' && (int) $services[0]['online_base'] === 1000 && (int) $services[0]['online_count'] === 0 && count($services) === 3, 'service level, busiest first, inside a sector');
$names = array_column($services, 'name');
$assert(in_array('Други – Решенија и управни барања (ADM_GJC)', $names, true), 'tier C groups are named in the service level');
$cats = array_column($catalog->summary($week, 'category', ['directorate_id'=>1]), null, 'code');
$assert(isset($cats['certificates'], $cats['registrations'], $cats['decisions']) && $cats['registrations']['name'] === 'Регистрации', 'category level with labels');
$scoped = $catalog->summary($week, 'directorate', [], [2]);
$assert(count($scoped) === 1 && $scoped[0]['code'] === 'DMQS', 'directors see only their directorates');
$missing = $catalog->missingSectors($week);
$assert(array_column($missing, 'name') === ['Одделение за услуги на граѓаните'], 'sectors with services and without a report');
$assert(count($catalog->missingSectors($week, [2])) === 0, 'missing sectors follow the directorate scope');
$computed = $catalog->computedWindowIds(); sort($computed);
$assert($computed === [10, 11, 12], 'windows with catalog services are computed');

// --- Reporters ------------------------------------------------------------------------------------------------------
$catalog->setReporter(1, 2, true, 1);
$catalog->setReporter(1, 2, true, 1);
$assert(array_column($catalog->reporters(1), 'name') === ['Петар Пробен'], 'a reporter is assigned once');
$catalog->setReporter(1, 2, false, 1);
$assert($catalog->reporters(1) === [], 'a reporter can be removed');

// --- Administration of the catalog ---------------------------------------------------------------------------------
$fail = static function (callable $f): string { try { $f(); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
$base = ['name'=>'Дозвола за јавни настани', 'sector_id'=>'1', 'window_id'=>'10', 'category_code'=>'permits', 'beneficiaries'=>'Граѓани, НВО', 'fee_required'=>'1', 'online'=>'', 'legal_deadline_days'=>'15', 'tier'=>'B', 'sort_order'=>''];
$assert(str_contains($fail(fn () => $catalog->saveService(null, ['window_id'=>'11'] + $base)), 'на истиот сектор'), 'the window must be of the directorate of the sector');
$assert(str_contains($fail(fn () => $catalog->saveService(null, ['name'=>'Извод од матична книга на родени'] + $base)), 'со ова име'), 'no two services with the same name in a sector');
$assert(str_contains($fail(fn () => $catalog->saveService(null, ['legal_deadline_days'=>'0'] + $base)), 'Законскиот рок'), 'the legal deadline is 1–3650 days or empty');
$assert(str_contains($fail(fn () => $catalog->saveService(null, ['category_code'=>'x'] + $base)), 'Категорија'), 'the category must be known');
$newId = $catalog->saveService(null, $base);
$new = $catalog->service($newId);
$assert($new['code'] === 'CS063' && (int) $new['window_id'] === 10 && (int) $new['legal_deadline_days'] === 15 && (int) $new['fee_required'] === 1 && (int) $new['online'] === 0 && (int) $new['sort_order'] === 50, 'a new service gets the next code and goes last in its window ('.json_encode([$new['code'], $new['sort_order']]).')');
$assert(in_array('S:CS063', array_column($catalog->rows(1), 'key'), true), 'the new service is asked in the next report of the sector');

$windowServiceId = $catalog->saveService(null, ['name'=>'Дозвола за табли', 'window_id'=>'new', 'new_window_name'=>'Други дозволи', 'new_window_type'=>'request', 'category_code'=>'permits'] + $base);
$w = $db->one('SELECT w.* FROM citizen_services w JOIN citizen_service_catalog c ON c.window_id=w.id WHERE c.id=:id', ['id'=>$windowServiceId]);
$assert($w['code'] === 'ADM_SRV1' && (int) $w['directorate_id'] === 1 && $w['window_type'] === 'request' && array_column($db->all('SELECT metric_key FROM citizen_service_metrics WHERE service_id=:id ORDER BY sort_order', ['id'=>$w['id']]), 'metric_key') === ['received', 'reviewed', 'resolved', 'open'], 'a new window is created in the directorate with the standard fields');
$assert(str_contains($fail(fn () => $catalog->saveService(null, ['name'=>'X y z', 'window_id'=>'new', 'new_window_name'=>''] + $base)), 'новиот шалтер'), 'a new window needs a name');

// Tier change in the middle of a week: the old row is dropped when the sector reports again.
$catalog->saveService($newId, ['tier'=>'C', 'category_code'=>'decisions', 'legal_deadline_days'=>'30'] + $base);
$keys = array_column($catalog->rows(1), 'key');
$assert(!in_array('S:CS063', $keys, true) && in_array('C:ADM_GJC:decisions:ADM_CIVIL_STATUS', $keys, true), 'after the tier change the service is reported in its group');
$catalog->save(1, '2026-11-02', ['S:CS001'=>['resolved'=>'10'], 'S:CS017'=>[], 'C:ADM_GJC:decisions:ADM_CIVIL_STATUS'=>['received'=>'1'], 'S:CS064'=>[]], 1);
$db->execute("INSERT INTO citizen_service_item_reports(row_key,week_start,sector_id,window_id,catalog_id,category_code,received,submitted_by,submitted_at) VALUES('S:CS999','2026-11-02',1,10,NULL,'permits',500,1,'2026-11-02')");
$catalog->save(1, '2026-11-02', ['S:CS001'=>['resolved'=>'10'], 'S:CS017'=>[], 'C:ADM_GJC:decisions:ADM_CIVIL_STATUS'=>['received'=>'1'], 'S:CS064'=>[]], 1);
$assert((int) $db->scalar("SELECT COUNT(*) FROM citizen_service_item_reports WHERE row_key='S:CS999'") === 0, 'rows that no longer exist are dropped on the next save');
$assert((int) $db->scalar("SELECT v.metric_value FROM citizen_service_weekly_values v JOIN citizen_service_weekly_reports r ON r.id=v.report_id JOIN citizen_service_metrics m ON m.id=v.metric_id WHERE r.service_id=10 AND r.week_start='2026-11-02' AND m.metric_key='received'") === 11, 'the window total no longer counts the dropped row');

// Deactivate, delete.
$catalog->setServiceActive(1, false);
$assert(!in_array('S:CS001', array_column($catalog->rows(1), 'key'), true), 'a deactivated service is no longer asked');
$catalog->setServiceActive(1, true);
$assert(str_contains($fail(fn () => $catalog->deleteService(1)), 'Деактивирајте'), 'a reported service cannot be deleted');
$assert(str_contains($fail(fn () => $catalog->deleteService($newId)), 'Други'), 'a service reported in its tier C group cannot be deleted');
$assert(str_contains($fail(fn () => $catalog->deleteService($windowServiceId)), 'Деактивирајте'), 'a service reported with zeros also counts as reported');
$fresh = $catalog->saveService(null, ['name'=>'Нова услуга без извештај'] + $base);
$catalog->deleteService($fresh);
$assert($catalog->service($fresh) === null, 'a service never reported is deleted');

// Sectors.
$sectorId = $catalog->saveSector(null, 1, '  Одделение   за архива ');
$sec = $catalog->sector($sectorId);
$assert($sec['code'] === 'ADM_SEK1' && $sec['name'] === 'Одделение за архива' && (int) $sec['sort_order'] === 110, 'a new sector gets a code and goes last');
$assert(str_contains($fail(fn () => $catalog->saveSector(null, 1, 'Одделение за архива')), 'со ова име'), 'no two sectors with the same name in a directorate');
$catalog->saveSector($sectorId, 1, 'Одделение за општински архив', '5');
$assert($catalog->sector($sectorId)['name'] === 'Одделение за општински архив' && (int) $catalog->sector($sectorId)['sort_order'] === 5, 'a sector is renamed and reordered');
$archiveService = $catalog->saveService(null, ['name'=>'Потврда од општинскиот архив', 'sector_id'=>(string) $sectorId, 'category_code'=>'certificates'] + $base);
$assert(str_contains($fail(fn () => $catalog->deleteSector($sectorId)), 'има услуги'), 'a sector with services cannot be deleted');
$catalog->setSectorActive($sectorId, false);
$assert(!in_array($sectorId, array_map('intval', array_column($catalog->sectors(), 'id')), true) && in_array($sectorId, array_map('intval', array_column($catalog->sectors(null, true), 'id')), true), 'an inactive sector is hidden from reporting and shown to the administrators');
$assert(!in_array($sectorId, array_map('intval', array_column($catalog->missingSectors('2026-11-02'), 'id')), true), 'an inactive sector is not counted as missing');
$catalog->deleteService($archiveService);
$catalog->setReporter($sectorId, 2, true, 1);
$catalog->deleteSector($sectorId);
$assert($catalog->sector($sectorId) === null && (int) $db->scalar('SELECT COUNT(*) FROM citizen_service_sector_reporters WHERE sector_id=:id', ['id'=>$sectorId]) === 0, 'an empty sector is deleted with its reporter assignments');
$assert(str_contains($fail(fn () => $catalog->deleteSector(1)), 'има услуги'), 'sectors with reports or services stay');

// --- Migration ------------------------------------------------------------------------------------------------------
$sql = (string) file_get_contents(APP_ROOT.'/database/migrations/2026_11_06_004600_citizen_service_catalog.sql');
preg_match_all("/^    \\(N'(CS\\d{3}[RS]?)',N'([A-Z]+)',N'([A-Z_]+)',N'([A-Z_]+)',N'((?:[^']|'')+)',N'([a-z_]+)',N'[^']*',([01]),([01]),(\\d+|NULL),N'([ABC])',\\d+\\)/m", $sql, $m, PREG_SET_ORDER);
$assert(count($m) === 0 && !str_contains($sql, 'INSERT INTO dbo.directorates'), 'no service catalog or directorate of another municipality is seeded: the catalog is entered in the application ('.count($m).')');
$assert(!preg_match('/[\w.]+@[\w-]+\.[a-z]+|https?:|53434|76397|Krasniqi|Rruga "UÇK"/u', $sql), 'no contacts, links, addresses or application counts are imported');

// --- Wiring ---------------------------------------------------------------------------------------------------------
$app = (string) file_get_contents(APP_ROOT.'/app/Core/Application.php');
$assert(str_contains($app, "\$p==='/citizen-services/by-service')\$this->citizenCatalog()->reporting()") && str_contains($app, "#^/citizen-services/sectors/(\\d+)/weekly$#") && str_contains($app, "'citizen_service_item_reports',"), 'routes and health tables');
$write = (string) file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');
$assert(str_contains($write, 'Овој шалтер се пресметува од известувањето по услуги'), 'computed windows cannot be typed by hand');
$read = (string) file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');
$assert(str_contains($read, '$canReport = $service[\'active\'] && !$isComputed'), 'the window page shows computed windows without a form');
$scope = (string) file_get_contents(APP_ROOT.'/app/Security/Scope.php');
$assert(str_contains($scope, 'public function canReportCitizenSector(') && str_contains($scope, '|| $this->citizenSectorIds($userId) !== []'), 'assigned officers report their sector and reach the module');
$controller = (string) file_get_contents(APP_ROOT.'/app/Controller/CitizenServiceCatalogController.php');
$assert(!preg_match('/style=/', $controller), 'no inline styles (Content Security Policy)');
$briefing = (string) file_get_contents(APP_ROOT.'/app/Service/MayorBriefing.php');
$assert(str_contains($briefing, "\$item('SO'.(\$i + 1)") && str_contains($briefing, "\$item('SX'"), 'the weekly briefing has the services past the deadline and the sectors without a report');

$assert(str_contains($app, "#^/citizen-services/catalog/(\\d+)/delete$#") && str_contains($app, "\$p==='/citizen-services/catalog/sectors')\$this->citizenCatalog()->sectorSave()"), 'administration routes');
$assert(substr_count($controller, '$this->requireManage(') >= 7 && str_contains($controller, "\$reason = \$this->requireField('reason', 500);"), 'every administration action checks the directorate, deletions need a reason');
$view = (string) file_get_contents(APP_ROOT.'/app/Core/View.php');
$assert(str_contains($view, "['path'=>'/citizen-services/catalog','group'=>'admin'") && str_contains($view, "\$module['path'] !== '/citizen-services/catalog' || \$this->scope->citizenManageDirectorateIds(\$userId) !== []"), 'the catalog is in the main menu under Administrimi, for those who administer it');
if ($failures) { fwrite(STDERR, "FAIL\n - ".implode("\n - ", array_filter($failures))."\n"); exit(1); }
echo "citizen-service-catalog-unit: OK\n";
