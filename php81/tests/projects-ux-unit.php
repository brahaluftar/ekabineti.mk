<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });
$failures=[];
$assert=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$read=(string)file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');
$write=(string)file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');
$js=(string)file_get_contents(APP_ROOT.'/public/assets/app.js');
$css=(string)file_get_contents(APP_ROOT.'/public/assets/app.css');

// Project page: header, indicators, tabs, full-width task table, dialog.
$assert(str_contains($read,"in_array(\$tab,['tasks','reports','budget','documents','risks','more'],true)")&&str_contains($read,'private function projectTabs('),'the project page is split into tabs (tasks first)');
$assert(str_contains($read,'data-modal-open="task-modal"')&&str_contains($read,'href="/projects/\'.$id.\'/tasks/new" data-modal-open="task-modal"'),'„Нова задача“ opens the dialog and still links to the task page without JavaScript');
$assert(str_contains($read,'<dialog class="modal" id="task-modal"')&&str_contains($read,'data-task-edit="')&&str_contains($read,"EvidenceUi::dropzone('documents[]',true,false"),'one dialog creates and edits tasks; documents are dropped while creating');
$assert(str_contains($read,'<table class="data-table">')&&str_contains($read,'table-wrap table-wrap--wide')&&str_contains($read,'<tfoot><tr><td>Вкупно'),'tasks are a full-width table with totals; the first column stays fixed when scrolling');
$assert(str_contains($read,"'late'=>['Со доцнење'")&&str_contains($read,"\$t['late']=\$days<0"),'late tasks are flagged and filterable');
$assert(!str_contains($read,'style="width:'),'progress bars use <progress>, allowed by the Content Security Policy (no inline styles)');
// Server: weight rebalancing and returning to the table.
$assert(str_contains($write,"if(\$this->request->input('rebalance')==='1')\$this->rebalanceTaskWeights(\$projectId,(float)\$data['weight'],null);")&&str_contains($write,"if(\$this->request->input('rebalance')==='1')\$this->rebalanceTaskWeights(\$projectId,(float)\$data['weight'],\$taskId);"),'creating or editing a task can rebalance the other weights');
$assert(str_contains($write,"'?tab=tasks&new_task=1':'?tab=tasks#task-'.\$id")&&str_contains($write,"'?tab=tasks#task-'.\$taskId"),'after saving, the page returns to the task table (or a new dialog with „Зачувај и додај друга“)');
// Projects list.
$assert(str_contains($read,'private function projectsListTable(')&&str_contains($read,'private function projectCards(')&&str_contains($read,"'Со задоцнета задача'"),'the projects list has count filters, a table with a fixed project column and a card view');
$assert(str_contains($read,'SUM(x.late) late,COUNT(*) total FROM (SELECT p.status,CASE WHEN EXISTS('),'count cards aggregate over a derived table (SQL Server error 130)');
// Browser behaviour.
$assert(str_contains($js,"document.querySelectorAll('[data-task-modal]')")&&str_contains($js,"weightInput.setCustomValidity(")&&str_contains($js,"event.key === 'Enter' && (event.ctrlKey || event.metaKey)"),'the dialog checks the weight total and saves with Ctrl+Enter');
$assert(str_contains($css,'.modal::backdrop')&&str_contains($css,'border-radius: 18px 18px 0 0')&&str_contains($css,'.fab {'),'on phones the dialog is a bottom sheet and „Нова задача“ floats');

// Back from a project to the filtered list and page it was opened from.
$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/projects?directorate_id=4&q=rruga&status=active&page=3&per=50';
EKabineti\Core\View::rememberList('/projects');
$assert(EKabineti\Core\View::listUrl('/projects', '#project-12') === '/projects?directorate_id=4&q=rruga&status=active&page=3&per=50#project-12', 'the project page links back to the filtered list, on the same page, at the same row');
$_SESSION['_list_urls']['/projects'] = 'https://evil.example/projects?x=1';
$assert(EKabineti\Core\View::listUrl('/projects') === '/projects' && EKabineti\Core\View::listUrl('/risks', '#x" onclick') === '/risks', 'only an address of the same list is used; anchors are checked');
$view = (string) file_get_contents(APP_ROOT.'/app/Core/View.php');
$assert(str_contains($view, "if (\$activeModule !== null && \$path === \$activeModule['path'] && (\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') self::rememberList(\$path);") && str_contains($view, "\$href = \$path !== \$module['path'] ? self::listUrl(\$module['path']) : \$module['path'];"), 'every module remembers its list; the breadcrumb of a record returns to it');
$assert(str_contains($read, "View::listUrl('/projects','#project-'.\$id)") && str_contains($read, '<tr id="project-\'.(int)$r[\'id\'].\'">') && str_contains($read, '<article class="project-card" id="project-'), 'project rows and cards carry an anchor for the way back');
unset($_SESSION);

// File fields open the picker through a <label for> (a transparent input over the box does not open it on iOS).
$zone = EKabineti\Service\EvidenceUi::dropzone('file', false, true, 'XLSX', 'Извештај', '.xlsx');
$assert(preg_match('/<input type="file" class="dropzone__input" id="(dropzone-\d+)"[^>]* accept="\.xlsx"/', $zone, $zm) === 1 && str_contains($zone, '<label class="dropzone__prompt" for="'.$zm[1].'"') && str_contains($zone, 'Изберете датотека'), 'the drop zone is a label for the file input, with a visible button and the accepted types');
$assert(!str_contains($css, '.dropzone__input { position: absolute; inset: 0;') && str_contains($js, "if (!input.multiple) { files = chosen.slice(0, 1); sync(false); return; }"), 'the input is not laid over the box; a single file keeps the browser\'s own selection');

// Rebalancing on a real database (SQLite standing in for SQL Server).
final class TsqlOnSqlite extends PDO { public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare(str_replace(' WITH (UPDLOCK,HOLDLOCK)', '', $query), $options); } }
$pdo=new TsqlOnSqlite('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('SYSUTCDATETIME',static fn():string=>gmdate('Y-m-d H:i:s'),0);
$pdo->exec('CREATE TABLE project_tasks(id INTEGER PRIMARY KEY,project_id INT,weight_percent NUMERIC,updated_at TEXT)');
$pdo->exec('CREATE TABLE audit_events(id INTEGER PRIMARY KEY,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,before_state TEXT,after_state TEXT,ip_address TEXT,correlation_id TEXT,reason TEXT,occurred_at TEXT)');
foreach([10,10,30,25,15,10] as$i=>$w)$pdo->exec('INSERT INTO project_tasks(id,project_id,weight_percent) VALUES('.($i+1).',12,'.$w.')');
$db=(new ReflectionClass(EKabineti\Core\Database::class))->newInstanceWithoutConstructor();(new ReflectionProperty(EKabineti\Core\Database::class,'pdo'))->setValue($db,$pdo);
$_SERVER+=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/projects/12/tasks','REMOTE_ADDR'=>'127.0.0.1'];
$controller=(new ReflectionClass(EKabineti\Controller\WriteController::class))->newInstanceWithoutConstructor();
$auth=(new ReflectionClass(EKabineti\Security\Auth::class))->newInstanceWithoutConstructor();
foreach((new ReflectionClass(EKabineti\Security\Auth::class))->getProperties() as$prop)if($prop->getName()==='user')$prop->setValue($auth,['id'=>7]);
$base=new ReflectionClass(EKabineti\Core\Controller::class);
$base->getProperty('db')->setValue($controller,$db);$base->getProperty('auth')->setValue($controller,$auth);$base->getProperty('audit')->setValue($controller,new EKabineti\Service\Audit($db,new EKabineti\Core\Request()));
$rebalance=new ReflectionMethod(EKabineti\Controller\WriteController::class,'rebalanceTaskWeights');
$rebalance->invoke($controller,12,10.0,null);
$weights=array_map('floatval',array_column($pdo->query('SELECT weight_percent FROM project_tasks ORDER BY id')->fetchAll(),'weight_percent'));
$assert(abs(array_sum($weights)-90.0)<0.001&&abs($weights[2]-27.0)<0.02&&abs($weights[0]-9.0)<0.02,'a new 10% task scales the others from 100% to 90% in proportion ('.implode(', ',$weights).')');
$rebalance->invoke($controller,12,50.0,3);
$weights=array_map('floatval',array_column($pdo->query('SELECT weight_percent FROM project_tasks ORDER BY id')->fetchAll(),'weight_percent'));
$others=array_sum($weights)-$weights[2];
$assert(abs($others-50.0)<0.001&&abs($weights[2]-27.0)<0.02,'editing a task to 50% scales only the other tasks to 50% (the edited one is saved by the update itself)');
$before=$pdo->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
$rebalance->invoke($controller,12,1.0,null);
$assert((int)$pdo->query('SELECT COUNT(*) FROM audit_events')->fetchColumn()===(int)$before,'nothing changes when the total stays within 100%');
$assert((int)$before===2&&str_contains((string)$pdo->query("SELECT action FROM audit_events LIMIT 1")->fetchColumn(),'weights_rebalanced'),'each rebalancing is audited');

if($failures){fwrite(STDERR,"FAIL\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "projects-ux-unit: OK\n";
