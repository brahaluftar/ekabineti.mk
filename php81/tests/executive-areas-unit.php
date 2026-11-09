<?php
declare(strict_types=1);

// Report of the policy areas the executive works in at the same time: areas from directorates or projects,
// directorates per area, projects / tasks / risks / cases / agenda / budget in a period, shared locations, settings.
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });
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
    'policy_areas(id INTEGER PRIMARY KEY,code TEXT,name TEXT,active INT DEFAULT 1,sort_order INT,created_at TEXT)',
    'directorates(id INTEGER PRIMARY KEY,code TEXT,name TEXT,active INT,policy_area_id INT)',
    'projects(id INTEGER PRIMARY KEY,directorate_id INT,code TEXT,name TEXT,status TEXT,progress INT,starts_at TEXT,ends_at TEXT,location_name TEXT,policy_area_id INT)',
    'project_tasks(id INTEGER PRIMARY KEY,project_id INT,status TEXT,starts_at TEXT,due_at TEXT)',
    'risks(id INTEGER PRIMARY KEY,directorate_id INT,project_id INT,inherent_score INT,residual_score INT,status TEXT)',
    'administrative_cases(id INTEGER PRIMARY KEY,case_number TEXT,case_type TEXT,status TEXT,priority TEXT,opened_at TEXT,closed_at TEXT,is_legal INT,originating_directorate_id INT)',
    'mayor_agenda_items(id INTEGER PRIMARY KEY,directorate_id INT,project_id INT,status TEXT)',
    'budget_allocations(id INTEGER PRIMARY KEY,project_id INT,fiscal_year INT,current_amount REAL)',
] as $t) $pdo->exec('CREATE TABLE '.$t);
$pdo->exec("INSERT INTO policy_areas(id,code,name,sort_order) VALUES(1,'infrastructure','Инфраструктура',10),(2,'education','Образование',50)");
$pdo->exec("INSERT INTO directorates VALUES(1,'INF','Капитални инвестиции',1,1),(2,'DARS','Образование',1,2),(3,'DMOB','Мобилност',1,NULL)");
$pdo->exec("INSERT INTO projects VALUES
 (1,1,'P-1','Rruga A','active',40,'2026-01-01','2026-12-31','Rruga A',NULL),
 (2,1,'P-2','Shkolla e re','active',20,'2026-03-01','2027-06-30','Shkolla X',2),
 (3,2,'P-3','Kabinetet','active',60,'2026-09-01',NULL,'shkolla  x',NULL),
 (4,3,'P-4','Sinjalizimi','active',10,NULL,'2026-11-15','rruga a ',NULL),
 (5,1,'P-5','Затворен','active',100,'2025-01-01','2025-12-31','Rruga A',NULL),
 (6,2,'P-6','Draft','draft',0,'2026-01-01','2026-12-31',NULL,NULL)");
$pdo->exec("INSERT INTO project_tasks VALUES(1,1,'in_progress','2026-10-01','2026-10-15'),(2,1,'done','2026-01-01','2026-02-01'),(3,2,'not_started','2026-12-01','2027-01-01')");
$pdo->exec("INSERT INTO risks VALUES(1,1,2,20,16,'open'),(2,3,NULL,15,NULL,'monitoring'),(3,1,1,20,10,'open'),(4,2,NULL,25,NULL,'closed')");
$pdo->exec("INSERT INTO administrative_cases VALUES(1,'01/26','kërkesë','open','normal','2026-10-01',NULL,0,1),(2,'02/26','ankesë','closed','normal','2026-01-01','2026-02-01',0,2),(3,'03/26','kërkesë','open','high','2026-12-15',NULL,0,2)");
$pdo->exec("INSERT INTO mayor_agenda_items VALUES(1,2,3,'open'),(2,1,NULL,'discussed')");
$pdo->exec("INSERT INTO budget_allocations VALUES(1,1,2026,100000),(2,1,2025,999),(3,5,2026,5000)");

$db = (new ReflectionClass(EKabineti\Core\Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(EKabineti\Core\Database::class, 'pdo'))->setValue($db, $pdo);
$areas = new EKabineti\Service\ExecutiveAreas($db);

$rows = $areas->overview('2026-11-01', '2026-11-30');
$by = array_column($rows, null, 'area_id');
$assert(array_column($rows, 'area_id') === [2, 1, 0], 'areas with more directorates at the same time come first ('.json_encode(array_column($rows, 'area_id')).')');
$assert($by[2]['name'] === 'Образование' && $by[2]['directorates'] === ['DARS', 'INF'] && $by[2]['projects'] === 2 && $by[2]['progress'] === 40.0, 'a project with its own area counts there: the school built by INF is Education');
$assert($by[2]['risks_critical'] === 1 && $by[2]['agenda_open'] === 1 && $by[2]['cases_open'] === 0, 'risks and agenda follow the project area; closed and future cases are left out');
$assert($by[1]['directorates'] === ['INF'] && $by[1]['projects'] === 1 && $by[1]['tasks_open'] === 1 && $by[1]['tasks_late'] === 1 && $by[1]['cases_open'] === 1 && $by[1]['risks_critical'] === 0 && $by[1]['budget'] === 100000.0, 'infrastructure: ended projects, drafts, mitigated risk scores and other years are left out');
$assert($by[0]['name'] === EKabineti\Service\ExecutiveAreas::NONE && $by[0]['directorates'] === ['DMOB'] && $by[0]['projects'] === 1 && $by[0]['risks_critical'] === 1, 'work of a directorate without an area is shown apart');

$after = array_column($areas->overview('2026-11-20', '2026-11-30'), null, 'area_id');
$assert(($after[0]['projects'] ?? 0) === 0 && ($after[0]['risks_critical'] ?? 0) === 1, 'a project that ended before the period is not counted');

$projects = $areas->areaProjects(2, '2026-11-01', '2026-11-30');
$assert(array_column($projects, 'code') === ['P-3', 'P-2'] && (int) $projects[1]['risks_critical'] === 1, 'projects of an area with their risks');
$assert(array_column($areas->areaCases(1, '2026-11-30'), 'case_number') === ['01/26'], 'open cases of an area');

$shared = $areas->sharedLocations('2026-11-01', '2026-11-30');
$names = array_map(static fn(array $g): string => implode(',', array_column($g, 'code')), $shared);
sort($names);
$assert($names === ['P-1,P-4', 'P-2,P-3'], 'projects of different directorates at the same location are found, case and spaces ignored ('.json_encode($names).')');

// Settings.
$fail = static function (callable $f): string { try { $f(); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
$id = $areas->createArea('  Мобилност   и сообраќај ');
$assert($db->one('SELECT code,name,sort_order FROM policy_areas WHERE id=:id', ['id'=>$id]) === ['code'=>'area_1', 'name'=>'Мобилност и сообраќај', 'sort_order'=>60], 'a new area gets a code and goes last');
$assert(str_contains($fail(fn () => $areas->createArea('Образование')), 'Веќе постои'), 'area names are unique');
$areas->assign('directorates', 3, $id);
$assert(array_column($areas->overview('2026-11-01', '2026-11-30'), 'area_id') === [2, 1, $id], 'the directorate default area moves its work');
$areas->assign('projects', 1, 2);
$moved = array_column($areas->overview('2026-11-01', '2026-11-30'), null, 'area_id');
$assert($moved[2]['projects'] === 3 && ($moved[1]['projects'] ?? 0) === 0 && $moved[1]['cases_open'] === 1, 'a project can be moved to another area (the directorate keeps its cases there)');
$areas->assign('projects', 1, null);
$areas->updateArea($id, 'Mobilitet', '5', false);
$assert(str_contains($fail(fn () => $areas->assign('projects', 1, $id)), 'не е активна'), 'an inactive area cannot be chosen');
$assert(array_key_first($areas->areas()) === 1 && (int) $areas->areas()[$id]['active'] === 0 && !isset($areas->areas(true)[$id]), 'inactive areas are listed last and hidden from the choices');

// Wiring.
$sql = (string) file_get_contents(APP_ROOT.'/database/migrations/2026_11_09_005000_policy_areas.sql');
$assert(str_contains($sql, 'CREATE TABLE dbo.policy_areas') && str_contains($sql, "(N'INF',N'infrastructure')") && str_contains($sql, "ALTER TABLE dbo.projects ADD policy_area_id"), 'migration: areas, default area of the directorates, project area');
$app = (string) file_get_contents(APP_ROOT.'/app/Core/Application.php');
$assert(str_contains($app, "\$p==='/executive-areas')\$this->executiveAreas()->index()") && strpos($app, "/executive-areas/settings") < strpos($app, "#^/executive-areas/(\\d+)$#") && str_contains($app, "'policy_areas',"), 'routes and health tables');
$controller = (string) file_get_contents(APP_ROOT.'/app/Controller/ExecutiveAreasController.php');
$assert(substr_count($controller, '$this->requireAccess();') === 6 && !preg_match('/style=/', $controller) && str_contains($controller, "str_starts_with(\$return, '/executive-areas') && !str_contains(\$return, '//')"), 'access on every action, no inline styles, safe return links');

if ($failures) { fwrite(STDERR, "FAIL\n - ".implode("\n - ", $failures)."\n"); exit(1); }
echo "executive-areas-unit: OK\n";
