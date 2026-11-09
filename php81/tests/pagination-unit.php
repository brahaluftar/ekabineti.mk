<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });

use EKabineti\Core\Database;
use EKabineti\Core\Pagination;

$failures=[];
$assert=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};

// SQLite stands in for SQL Server: OFFSET n ROWS FETCH NEXT m ROWS ONLY becomes LIMIT m OFFSET n; every statement is kept.
final class TsqlOnSqlite extends PDO
{
    public array $log = [];
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->log[] = $query;
        return parent::prepare((string) preg_replace('/OFFSET (\d+) ROWS FETCH NEXT (\d+) ROWS ONLY/', 'LIMIT $2 OFFSET $1', $query), $options);
    }
}
$pdo = new TsqlOnSqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE items(id INTEGER PRIMARY KEY, name TEXT, grp INTEGER)');
for ($i = 1; $i <= 45; $i++) $pdo->exec("INSERT INTO items(id,name,grp) VALUES($i,'item $i',".($i % 4).')');
$db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);

$_GET = []; $_SESSION = []; $_SERVER['REQUEST_URI'] = '/projects?q=x';
$p = Pagination::fromRequest();
$assert($p->size === 20 && $p->page() === 1, 'the default page size is 20');
$rows = $p->query($db, 'SELECT TOP 500 id,name FROM items WHERE grp<>:g ORDER BY id DESC', ['g'=>9]);
$assert(count($rows) === 20 && $p->total === 45 && (int) $rows[0]['id'] === 45 && !isset($rows[0]['pagination_total']), 'one SQL query returns the page and the total (COUNT(*) OVER()), without the helper column');
$assert(!str_contains(end($pdo->log), 'TOP 500') && str_contains(end($pdo->log), 'OFFSET 0 ROWS FETCH NEXT 20 ROWS ONLY'), 'the TOP cap is removed and the page is fetched with OFFSET/FETCH');

$_GET = ['per'=>'10', 'page'=>'3'];
$p = Pagination::fromRequest();
$rows = $p->query($db, 'SELECT id FROM items ORDER BY id', []);
$assert($p->size === 10 && count($rows) === 10 && (int) $rows[0]['id'] === 21 && $p->pages() === 5, 'page 3 of 10 rows starts at row 21');
$_GET = [];
$assert(Pagination::fromRequest()->size === 10, 'the chosen page size is remembered for the table');
$_GET = ['per'=>'7'];
$assert(Pagination::fromRequest()->size === 10, 'only 10, 20, 50, 100 and 200 are accepted');
$_GET = ['t1_per'=>'50'];
$assert(Pagination::fromRequest('t1')->size === 50 && Pagination::fromRequest()->size === 10, 'each table on a page has its own parameters and size');

$_GET = ['per'=>'20', 'page'=>'9'];
$p = Pagination::fromRequest();
$rows = $p->query($db, 'SELECT id FROM items ORDER BY id', []);
$assert($p->page() === 3 && $p->total === 45 && count($rows) === 5 && (int) $rows[0]['id'] === 41, 'a page past the end shows the last page');

$_GET = ['per'=>'10'];
$p = Pagination::fromRequest();
$rows = $p->query($db, 'SELECT DISTINCT TOP 100 grp FROM items ORDER BY grp', []);
$assert($p->total === 4 && count($rows) === 4, 'SELECT DISTINCT is counted over a derived table');
$rows = Pagination::fromRequest()->query($db, "SELECT grp,COUNT(*) n FROM items WHERE name<>'a ORDER BY b' GROUP BY grp ORDER BY grp", []);
$assert(count($rows) === 4, 'GROUP BY lists and ORDER BY inside string literals are handled');
$assert(Pagination::orderByPosition('SELECT a FROM (SELECT TOP 1 a FROM t ORDER BY a) x ORDER BY a') === 51 && Pagination::orderByPosition("SELECT a FROM t WHERE b='ORDER BY'") === null && Pagination::orderByPosition('SELECT reorder_by FROM t') === null, 'only the top-level ORDER BY is found');

$_GET = ['q'=>'x', 'per'=>'10', 'page'=>'2'];
$p = Pagination::fromRequest();
$rows = $p->slice(range(1, 45));
$nav = $p->nav();
$assert($rows === range(11, 20) && str_contains($nav, '11–20 од 45') && str_contains($nav, 'data-page-size') && str_contains($nav, '<input type="hidden" name="q" value="x">') && str_contains($nav, 'q=x&amp;page=3&amp;per=10') && str_contains($nav, 'aria-current="page">2<'), 'the navigation keeps the filters, shows the position and offers 10/20/50/100/200');
foreach (Pagination::SIZES as $size) $assert(str_contains($nav, '<option value="'.$size.'"'), 'page size '.$size.' is offered');
$small = Pagination::fromRequest('small'); $small->slice(range(1, 8));
$assert($small->nav() === '', 'tables of up to 10 rows have no navigation');

// Wiring: every list goes through the paginator.
$read = (string) file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');
$assert(substr_count($read, "exportData('") >= 13 && str_contains($read, "\$list=fn(string \$sql,array \$params)=>\$paged?\$this->pagedQuery(\$sql,\$params)"), 'the module lists are paged in SQL on screen and capped in exports');
foreach (["exportData('projects',true)", "exportData('budget',true)", "exportData('execution',true)", "exportData('changes',true)", "exportData('risks',true)", "exportData('reports',true)", "exportData('directorates',true)", "exportData('imports',true)", "exportData('audit',true)"] as $call) $assert(str_contains($read, $call), $call.' is paged');
foreach (['SELECT TOP 2000 pp.*', 'SELECT TOP 1000 ee.id', 'SELECT TOP 500 c.id,c.internal_sequence', 'SELECT TOP 200 id,name,username', 'SELECT TOP 200 ba.id', 'SELECT DISTINCT TOP 200 c.id'] as $cap) $assert(!str_contains($read, $cap), 'the display cap "'.$cap.'" is replaced by pagination');
$assert(str_contains($read, 'private function simpleTable(array $rows,string|bool $paging=true)'), 'generic tables are paged by default');
$assert(str_contains((string) file_get_contents(APP_ROOT.'/public/assets/app.js'), "select[data-page-size]") && str_contains((string) file_get_contents(APP_ROOT.'/app/Controller/EvidenceController.php'), '$pagination->nav()'), 'the page size reloads the list and the monitoring table is paged');

if($failures){fwrite(STDERR,"FAIL\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "pagination-unit: OK\n";
