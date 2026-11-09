<?php
declare(strict_types=1);

// Project page: who created the project and when, and the collapsible edit history (from audit_events).
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });
date_default_timezone_set('Europe/Skopje');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

final class TsqlOnSqlite extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = preg_replace('/SELECT TOP (\d+) (.*)$/s', 'SELECT $2 LIMIT $1', $query);
        $query = preg_replace('/CONVERT\(varchar\(19\),([^,]+),120\)/', '$1', $query);
        return parent::prepare($query, $options);
    }
}
$pdo = new TsqlOnSqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'Arta Provë'),(2,'Петар Пробен'),(3,'Drita Provë')");
$pdo->exec('CREATE TABLE project_tasks(id INTEGER PRIMARY KEY,project_id INT,title TEXT)');
$pdo->exec("INSERT INTO project_tasks VALUES(50,7,'Asfaltimi'),(51,8,'Projekt tjetër')");
$pdo->exec('CREATE TABLE progress_updates(id INTEGER PRIMARY KEY,project_id INT)');
$pdo->exec('INSERT INTO progress_updates VALUES(90,7)');
$pdo->exec('CREATE TABLE audit_events(id INTEGER PRIMARY KEY,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,before_state TEXT,after_state TEXT,reason TEXT,occurred_at TEXT)');
$event = $pdo->prepare('INSERT INTO audit_events(actor_id,action,entity_type,entity_id,before_state,after_state,reason,occurred_at) VALUES(?,?,?,?,?,?,?,?)');
$event->execute([1, 'project.created', 'App\\Models\\Project', '7', '[]', json_encode(['code'=>'P-7','name'=>'Rruga A','manager_id'=>2,'starts_at'=>'2026-01-10']), null, '2026-10-01 08:00:00']);
$event->execute([2, 'project.updated', 'App\\Models\\Project', '7', json_encode(['code'=>'P-7','name'=>'Rruga A','manager_id'=>2,'starts_at'=>'2026-01-10 00:00:00.000','status'=>'active','has_budget_impact'=>'1']), json_encode(['code'=>'P-7','name'=>'Rruga A <b>нов</b>','manager_id'=>3,'starts_at'=>'2026-01-10','status'=>'on_hold','has_budget_impact'=>true]), null, '2026-10-05 09:30:00']);
$event->execute([3, 'project_task.updated', 'App\\Models\\ProjectTask', '50', json_encode(['title'=>'Asfaltimi','progress'=>10]), json_encode(['title'=>'Asfaltimi','progress'=>40]), null, '2026-10-06 10:00:00']);
$event->execute([3, 'project_task.updated', 'App\\Models\\ProjectTask', '51', '[]', json_encode(['title'=>'Projekt tjetër']), null, '2026-10-06 11:00:00']);
$event->execute([1, 'project.progress_reported', 'App\\Models\\ProgressUpdate', '90', '[]', json_encode(['period'=>'2026-10-01','progress'=>40]), null, '2026-10-07 12:00:00']);
$event->execute([1, 'project.updated', 'App\\Models\\Project', '8', '[]', json_encode(['name'=>'Друго']), null, '2026-10-08 12:00:00']);

$db = (new ReflectionClass(EKabineti\Core\Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(EKabineti\Core\Database::class, 'pdo'))->setValue($db, $pdo);
$controller = (new ReflectionClass(EKabineti\Controller\ReadController::class))->newInstanceWithoutConstructor();
(new ReflectionClass(EKabineti\Core\Controller::class))->getProperty('db')->setValue($controller, $db);
$history = new ReflectionMethod(EKabineti\Controller\ReadController::class, 'projectHistory');
$html = $history->invoke($controller, ['id'=>7, 'created_at'=>'2026-10-01 08:00:00']);

$assert(str_starts_with($html, '<details class="card record-history">') && !str_contains($html, '<details class="card record-history" open'), 'the history is a collapsed <details> block');
$assert(str_contains($html, 'Создадено на <strong>01.10.2026 10:00</strong> од <strong>Arta Provë</strong>'), 'creation date (local time) and creator are shown');
$assert(str_contains($html, 'последна промена на <strong>07.10.2026 14:00</strong> од <strong>Arta Provë</strong>'), 'the last change and who made it are shown');
$assert(str_contains($html, 'Историја на промени (4)'), 'only events of this project, its tasks and its reports are counted ('.substr_count($html, 'history-event').')');
$assert(str_contains($html, '<span class="history-field">Носител / одговорно лице</span> <del>Петар Пробен</del> → <ins>Drita Provë</ins>'), 'changed manager is shown by name, before → after');
$assert(str_contains($html, '<del>Активно</del> → <ins>На чекање</ins>') && str_contains($html, 'Rruga A &lt;b&gt;нов&lt;/b&gt;'), 'statuses use their labels and values are escaped');
$assert(!str_contains($html, '<span class="history-field">Почеток</span> <del>') && !str_contains($html, '<span class="history-field">Буџетско влијание</span> <del>') && !str_contains($html, '<span class="history-field">Шифра</span> <del>'), 'unchanged fields (also date and boolean formats) are not listed');
$assert(str_contains($html, 'Задачата е уредена · Asfaltimi') && !str_contains($html, 'Projekt tjetër') && !str_contains($html, '>Друго<'), 'task edits of this project are included, other projects are not');

$pdo->exec("DELETE FROM audit_events WHERE action='project.created'");
$html = $history->invoke($controller, ['id'=>7, 'created_at'=>'2026-09-01 06:00:00']);
$assert(str_contains($html, 'Создадено на <strong>01.09.2026 08:00</strong> <span class="muted">(создавачот не е во ревизијата)</span>'), 'older projects without an audit entry show the creation date from the project');

$read = (string) file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');
$assert(str_contains($read, '$this->projectHeader($project,$tasks,$canEdit&&$canWrite).$this->projectHistory($project).$this->projectTabs('), 'the history sits under the project header, above the tabs');
$assert(!str_contains($read, 'class="record-history" style='), 'no inline styles (Content Security Policy)');

if ($failures) { fwrite(STDERR, "FAIL\n - ".implode("\n - ", $failures)."\n"); exit(1); }
echo "project-history-unit: OK\n";
