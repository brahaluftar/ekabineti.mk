<?php

declare(strict_types=1);

/*
 * Backs up, removes and restores the allocations and commitments recorded in e-Kabineti, and recalculates the figures
 * imported from the FreeBalance Summary Report.
 *
 *   php bin/reset-ekabineti-allocations.php                          simulation: counts and totals, nothing changes
 *   php bin/reset-ekabineti-allocations.php --execute --confirm="FSHI ALOKIMET" [--include-change-requests] [--year=2026]
 *   php bin/reset-ekabineti-allocations.php --recalculate             only re-link and recalculate the FreeBalance figures
 *   php bin/reset-ekabineti-allocations.php --restore=<backup-id> --confirm="RIKTHE ALOKIMET"
 *   php bin/reset-ekabineti-allocations.php --list-backups
 *
 * --execute
 *   1. backup: every affected row is written to JSON files (storage/private/backups/allocations-<id>/, with a manifest and
 *      SHA-256 sums) and copied into database tables dbo.bak_<id>_<table>; the budget table (budget_lines and their
 *      directorate access) is backed up the same way, without being changed;
 *   2. removes, in one transaction: expenditures, budget transactions, commitments and reservations of the allocations,
 *      then the allocations themselves (and, with --include-change-requests, the budget change requests and their
 *      workflow that point to them; without the flag the script stops when such requests exist);
 *   3. re-links the FreeBalance lines to the budget lines and refreshes the imported balances. With no allocations or
 *      commitments left in e-Kabineti, the budget pages show the FreeBalance figures (allocated A, committed C+E, spent B, free F).
 * Expenditures are removed together with the commitments they belong to, because the database does not allow an
 * expenditure without its commitment; they are part of the backup and of a restore.
 * --year limits the reset to allocations of that fiscal year.
 */

use EKabineti\Core\Database;
use EKabineti\Service\BudgetExecution;
use EKabineti\Service\TableBackup;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$app = require dirname(__DIR__).'/app/bootstrap.php';
$db = $app->database();
$options = getopt('', ['execute', 'confirm:', 'include-change-requests', 'year:', 'recalculate', 'restore:', 'list-backups', 'backup-dir:']);
$out = static function (string $line): void { echo $line.PHP_EOL; };
$fail = static function (string $line, int $code = 1): never { fwrite(STDERR, '[FAIL] '.$line.PHP_EOL); exit($code); };

// Tables in delete order (children first). Restore runs in the reverse order.
const RESET_TABLES = ['workflow_steps', 'workflow_instances', 'budget_change_requests', 'expenditures', 'budget_transactions', 'commitments', 'reservations', 'budget_allocations'];

$year = isset($options['year']) ? (int) $options['year'] : null;
if ($year !== null && ($year < 2020 || $year > 2100)) $fail('--year не е валиден.', 2);

/** WHERE fragments selecting the affected rows of each table (they all go through the allocations in scope). */
$scope = static function (?int $year): array {
    $allocations = 'SELECT id FROM budget_allocations'.($year !== null ? ' WHERE fiscal_year='.$year : '');
    $changeRequests = "SELECT id FROM budget_change_requests WHERE source_allocation_id IN ({$allocations}) OR target_allocation_id IN ({$allocations})";
    $instances = "SELECT id FROM workflow_instances WHERE entity_type LIKE N'%BudgetChangeRequest' AND entity_id IN ({$changeRequests})";
    return [
        'budget_allocations' => "id IN ({$allocations})",
        'reservations' => "budget_allocation_id IN ({$allocations})",
        'commitments' => "budget_allocation_id IN ({$allocations})",
        'budget_transactions' => "budget_allocation_id IN ({$allocations})",
        'expenditures' => "budget_allocation_id IN ({$allocations}) OR commitment_id IN (SELECT id FROM commitments WHERE budget_allocation_id IN ({$allocations}))",
        'budget_change_requests' => "id IN ({$changeRequests})",
        'workflow_instances' => "id IN ({$instances})",
        'workflow_steps' => "workflow_instance_id IN ({$instances})",
    ];
};

/** Copyable columns of a table (no rowversion / computed columns). */
$columns = static function (Database $db, string $table): array {
    return array_column($db->all("SELECT c.name FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id WHERE c.object_id=OBJECT_ID(:table) AND c.is_computed=0 AND t.name NOT IN ('timestamp','rowversion') ORDER BY c.column_id", ['table'=>'dbo.'.$table]), 'name');
};

$recalculate = static function (Database $db) use ($out): void {
    $execution = new BudgetExecution($db);
    $relinked = $execution->relink();
    $out('[OK]   FreeBalance: '.$relinked.' ставки се повторно поврзани со буџетските ставки и проектите.');
    foreach ($db->all('SELECT fiscal_year,CONVERT(varchar(10),MAX(as_of_date),23) as_of FROM budget_execution_lines GROUP BY fiscal_year ORDER BY fiscal_year') as $row) {
        $refreshed = $execution->refreshBudgetLineSnapshots((int) $row['fiscal_year'], (string) $row['as_of']);
        $t = $db->one('SELECT COUNT(*) lines,SUM(CASE WHEN budget_line_id IS NOT NULL THEN 1 ELSE 0 END) linked,COALESCE(SUM(allocated_amount),0) allocated,COALESCE(SUM(obligations_amount+commitments_amount),0) committed,COALESCE(SUM(actual_amount),0) spent,COALESCE(SUM(balance_amount),0) balance FROM budget_execution_lines WHERE fiscal_year=:year AND in_latest_report=1', ['year'=>$row['fiscal_year']]) ?: [];
        $out(sprintf('[OK]   %d (состојба %s): %d FreeBalance ставки, %d поврзани со буџетски ставки; распределено %s, преземено %s, потрошено %s, салдо %s; %d буџетски ставки го добија новото салдо.',
            (int) $row['fiscal_year'], $row['as_of'], (int) ($t['lines'] ?? 0), (int) ($t['linked'] ?? 0), number_format((float) ($t['allocated'] ?? 0), 2), number_format((float) ($t['committed'] ?? 0), 2), number_format((float) ($t['spent'] ?? 0), 2), number_format((float) ($t['balance'] ?? 0), 2), $refreshed));
    }
};

if (isset($options['list-backups'])) {
    $tables = $db->all("SELECT name FROM sys.tables WHERE name LIKE 'bak[_]%[_]budget[_]allocations' ORDER BY name DESC");
    if (!$tables) $out('Нема резервна копија во базата.');
    foreach ($tables as $t) { $id = substr((string) $t['name'], 4, 15); $out($id.'  ('.(int) $db->scalar('SELECT COUNT(*) FROM dbo.'.$t['name']).' распределби)'); }
    exit(0);
}

if (isset($options['restore'])) {
    $id = (string) $options['restore'];
    if (!preg_match('/^\d{8}_\d{6}$/', $id)) $fail('ID на резервната копија мора да биде како 20261026_153000 (видете --list-backups).', 2);
    if (($options['confirm'] ?? '') !== 'RIKTHE ALOKIMET') $fail('За враќање додајте --confirm="RIKTHE ALOKIMET".', 2);
    $restored = $db->transaction(function () use ($db, $id, $columns, $out): array {
        $counts = [];
        foreach (array_reverse(RESET_TABLES) as $table) {
            $backup = 'bak_'.$id.'_'.$table;
            if (!$db->scalar('SELECT OBJECT_ID(:name)', ['name'=>'dbo.'.$backup])) continue;
            $cols = array_values(array_intersect($columns($db, $table), $columns($db, $backup)));
            $list = implode(',', array_map(static fn(string $c): string => '['.$c.']', $cols));
            $identity = (bool) $db->scalar('SELECT OBJECTPROPERTY(OBJECT_ID(:table),\'TableHasIdentity\')', ['table'=>'dbo.'.$table]);
            if ($identity) $db->execute('SET IDENTITY_INSERT dbo.'.$table.' ON');
            $counts[$table] = $db->execute("INSERT INTO dbo.{$table} ({$list}) SELECT {$list} FROM dbo.{$backup} b WHERE NOT EXISTS (SELECT 1 FROM dbo.{$table} t WHERE t.id=b.id)");
            if ($identity) $db->execute('SET IDENTITY_INSERT dbo.'.$table.' OFF');
        }
        $db->execute("INSERT INTO audit_events(actor_id,action,entity_type,entity_id,before_state,after_state,ip_address,correlation_id,reason,occurred_at) VALUES(NULL,'budget.allocations_restored','App\\Models\\BudgetAllocation',NULL,NULL,:after,NULL,NEWID(),:reason,SYSUTCDATETIME())", ['after'=>json_encode($counts), 'reason'=>'backup '.$id]);
        return $counts;
    });
    foreach ($restored as $table => $n) $out('[OK]   '.$table.': '.$n.' редови се вратени.');
    $recalculate($db);
    exit(0);
}

if (isset($options['recalculate']) && !isset($options['execute'])) { $recalculate($db); exit(0); }

// Simulation / execution
$where = $scope($year);
$counts = [];
foreach (RESET_TABLES as $table) $counts[$table] = (int) $db->scalar("SELECT COUNT(*) FROM dbo.{$table} WHERE {$where[$table]}");
$sums = $db->one("SELECT COALESCE((SELECT SUM(current_amount) FROM budget_allocations WHERE {$where['budget_allocations']}),0) allocated,COALESCE((SELECT SUM(amount) FROM commitments WHERE status='active' AND ({$where['commitments']})),0) committed,COALESCE((SELECT SUM(amount) FROM expenditures WHERE {$where['expenditures']}),0) spent") ?: [];
$out('Распределби и обврски евидентирани во е-Кабинет'.($year !== null ? ' за годината '.$year : '').':');
foreach (array_reverse(RESET_TABLES) as $table) $out(sprintf('  %-24s %d', $table, $counts[$table]));
$out(sprintf('  Износи: распределено %s, преземено (активни) %s, потрошено %s', number_format((float) ($sums['allocated'] ?? 0), 2), number_format((float) ($sums['committed'] ?? 0), 2), number_format((float) ($sums['spent'] ?? 0), 2)));
if ($counts['budget_change_requests'] > 0 && !isset($options['include-change-requests'])) {
    $out('[ВНИМАНИЕ] '.$counts['budget_change_requests'].' барања за буџетска промена се поврзани со овие распределби. Додајте --include-change-requests за да ги вклучите (со нивниот работен тек) во резервната копија и бришењето.');
    if (isset($options['execute'])) $fail('Запрено без да се промени ништо.', 3);
}
if (!isset($options['execute'])) { $out('Симулација: ништо не е променето. За извршување: --execute --confirm="FSHI ALOKIMET"'); exit(0); }
if (($options['confirm'] ?? '') !== 'FSHI ALOKIMET') $fail('За извршување додајте --confirm="FSHI ALOKIMET".', 2);
if (!isset($options['include-change-requests'])) { foreach (['budget_change_requests', 'workflow_instances', 'workflow_steps'] as $t) $counts[$t] = 0; }

// 1. Backup (files first, then database copies)
$id = gmdate('Ymd_His');
$dir = rtrim((string) ($options['backup-dir'] ?? APP_ROOT.'/storage/private/backups'), '/').'/allocations-'.$id;
if (!is_dir($dir) && !mkdir($dir, 0700, true)) $fail('Папката за резервна копија не е создадена: '.$dir);
$manifest = ['backup_id'=>$id, 'created_at_utc'=>gmdate('c'), 'year'=>$year, 'counts'=>[], 'sums'=>$sums, 'files'=>[], 'database_tables'=>[]];
foreach (RESET_TABLES as $table) {
    if ($counts[$table] === 0) continue;
    $cols = $columns($db, $table);
    $list = implode(',', array_map(static fn(string $c): string => '['.$c.']', $cols));
    $rows = $db->all("SELECT {$list} FROM dbo.{$table} WHERE {$where[$table]} ORDER BY id");
    $file = $dir.'/'.$table.'.json';
    if (file_put_contents($file, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) $fail('Резервната копија во датотека не успеа: '.$file);
    $manifest['files'][$table] = ['rows'=>count($rows), 'sha256'=>hash_file('sha256', $file)];
    $db->execute("SELECT {$list} INTO dbo.bak_{$id}_{$table} FROM dbo.{$table} WHERE {$where[$table]}");
    $copied = (int) $db->scalar("SELECT COUNT(*) FROM dbo.bak_{$id}_{$table}");
    if ($copied !== count($rows)) $fail("Копијата во базата на {$table} ka {$copied} редови наместо ".count($rows).'. Ништо не е избришано.');
    $manifest['database_tables'][$table] = 'dbo.bak_'.$id.'_'.$table;
    $manifest['counts'][$table] = count($rows);
}
// The budget table is backed up too (not changed by this script); bin/rebuild-budget-from-imports.php can restore it.
$lineScope = 'budget_version_id IN (SELECT bv.id FROM budget_versions bv JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bv.is_official=1'.($year !== null ? ' AND bc.base_year='.$year : '').')';
$budgetBackup = (new TableBackup($db))->backup($id, $dir, ['budget_lines'=>$lineScope, 'budget_line_directorate_access'=>'budget_line_id IN (SELECT id FROM budget_lines WHERE '.$lineScope.')']);
$manifest['budget_table'] = $budgetBackup;
file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
$out('[OK]   Backup '.$id.': '.$dir.' и табелите dbo.bak_'.$id.'_*');

// 2. Remove, children first, in one transaction
$deleted = $db->transaction(function () use ($db, $where, $counts, $id): array {
    $done = [];
    foreach (RESET_TABLES as $table) {
        if ($counts[$table] === 0) continue;
        $done[$table] = $db->execute("DELETE FROM dbo.{$table} WHERE id IN (SELECT id FROM dbo.bak_{$id}_{$table})");
        if ($done[$table] !== $counts[$table]) throw new RuntimeException("{$table}: избришани {$done[$table]} редови наместо {$counts[$table]}; трансакцијата е откажана.");
    }
    $db->execute("INSERT INTO audit_events(actor_id,action,entity_type,entity_id,before_state,after_state,ip_address,correlation_id,reason,occurred_at) VALUES(NULL,'budget.allocations_reset','App\\Models\\BudgetAllocation',NULL,NULL,:after,NULL,NEWID(),:reason,SYSUTCDATETIME())", ['after'=>json_encode($done), 'reason'=>'backup '.$id]);
    return $done;
});
foreach ($deleted as $table => $n) $out('[OK]   '.$table.': '.$n.' редови се избришани.');

// 3. Recalculate the FreeBalance figures
$recalculate($db);
$out('Враќање, ако е потребно: php bin/reset-ekabineti-allocations.php --restore='.$id.' --confirm="RIKTHE ALOKIMET"');
