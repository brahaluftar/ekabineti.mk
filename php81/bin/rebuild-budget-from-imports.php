<?php

declare(strict_types=1);

/*
 * Backs up the budget table (budget lines of a fiscal year with their directorate access) and rebuilds it from the
 * import files: the approved budget workbook ("BUXHETI DHE REALIZIMI … i aprovuar në Kuvend") and the FreeBalance
 * Summary Report.
 *
 *   php bin/rebuild-budget-from-imports.php --year=2026                       simulation: sources and plan, nothing changes
 *   php bin/rebuild-budget-from-imports.php --year=2026 --execute --confirm="RINDERTO BUXHETIN" [--skip-invalid] [--keep-orphans] [--actor=username]
 *   php bin/rebuild-budget-from-imports.php --year=2026 --fresh-start [--execute --confirm="FSHI DHE RINDERTO BUXHETIN"] [--skip-invalid]
 *   php bin/rebuild-budget-from-imports.php --restore=<backup-id> --confirm="RIKTHE BUXHETIN"
 *   php bin/rebuild-budget-from-imports.php --list-backups
 *
 * Source of the approved budget, in this order (the first available one is used):
 *   1. file          the workbook of the latest posted approved-budget import of the year (storage/private/imports/…)
 *   2. import_rows   the lines saved with that import, when the file was removed (e.g. by the history cleanup)
 *   3. backup table  the most recent earlier backup dbo.bak_<id>_budget_lines, when neither of the above exists
 * The FreeBalance report of the year is imported again from its file when available; otherwise the lines already stored
 * are only re-linked and the balances refreshed.
 *
 * Budget lines are never deleted (FreeBalance, enforcement executions and the audit trail point to them): the import
 * updates, re-codes and creates lines, and lines that are not in the approved budget and have no allocations are set to
 * zero (--keep-orphans leaves them unchanged). Rows with errors block the rebuild unless --skip-invalid is given, which
 * leaves those lines as they are.
 *
 * --fresh-start (start of production): the budget is rebuilt only from the imports. The e-Kabineti allocations of the
 * year (with commitments, reservations, expenditures and change requests) are removed by bin/reset-ekabineti-allocations.php
 * (which backs them up), and the budget lines of the year that are not in the approved budget are deleted, so allocated,
 * committed and spent come only from FreeBalance. Lines still referenced elsewhere (e.g. enforcement executions, legal
 * cases, allocations of another year) are not deleted but set to zero and listed. Needs the approved budget file or its
 * saved rows; the backup table is not accepted as a source, because it would bring the old lines back.
 */

use EKabineti\Core\Database;
use EKabineti\Core\Request;
use EKabineti\Security\Scope;
use EKabineti\Service\ApprovedBudgetSync;
use EKabineti\Service\ApprovedBudgetWorkbook;
use EKabineti\Service\Audit;
use EKabineti\Service\BudgetExecution;
use EKabineti\Service\ImportWorkflow;
use EKabineti\Service\SpreadsheetReader;
use EKabineti\Service\TableBackup;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$_SERVER += ['REQUEST_METHOD'=>'CLI', 'REQUEST_URI'=>'/cli/rebuild-budget-from-imports', 'SCRIPT_NAME'=>'/index.php', 'REMOTE_ADDR'=>null];
$app = require dirname(__DIR__).'/app/bootstrap.php';
$db = $app->database();
$options = getopt('', ['year:', 'execute', 'confirm:', 'skip-invalid', 'keep-orphans', 'actor:', 'restore:', 'list-backups', 'backup-dir:', 'fresh-start']);
$out = static function (string $line): void { echo $line.PHP_EOL; };
$fail = static function (string $line, int $code = 1): never { fwrite(STDERR, '[FAIL] '.$line.PHP_EOL); exit($code); };
$backup = new TableBackup($db);
$backupRoot = rtrim((string) ($options['backup-dir'] ?? APP_ROOT.'/storage/private/backups'), '/');

$versionWhere = static fn(int $year): string => "budget_version_id IN (SELECT bv.id FROM budget_versions bv JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bv.is_official=1 AND bc.base_year={$year})";
$budgetTables = static fn(int $year): array => [
    'budget_lines' => $versionWhere($year),
    'budget_line_directorate_access' => 'budget_line_id IN (SELECT id FROM budget_lines WHERE '.$versionWhere($year).')',
    'budget_line_code_changes' => 'budget_line_id IN (SELECT id FROM budget_lines WHERE '.$versionWhere($year).')',
];
// References to budget lines that --fresh-start clears itself (re-linked or part of the line's own history).
const FRESH_START_CLEARED = ['budget_execution_lines', 'budget_line_code_changes'];
$recalculate = static function (Database $db, int $year) use ($out): void {
    $execution = new BudgetExecution($db);
    $out('[OK]   FreeBalance: '.$execution->relink($year).' ставки се повторно поврзани со буџетските ставки и проектите.');
    $asOf = $db->scalar('SELECT CONVERT(varchar(10),MAX(as_of_date),23) FROM budget_execution_lines WHERE fiscal_year=:year', ['year'=>$year]);
    if (is_string($asOf)) $out('[OK]   '.$execution->refreshBudgetLineSnapshots($year, $asOf).' буџетски ставки го добија FreeBalance салдото од '.$asOf.'.');
    $t = $db->one('SELECT COUNT(*) n,COALESCE(SUM(bl.total_amount),0) total FROM budget_lines bl JOIN budget_versions bv ON bv.id=bl.budget_version_id JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bv.is_official=1 AND bc.base_year=:year AND bl.budget_category IS NOT NULL AND bl.total_amount<>0', ['year'=>$year]) ?: [];
    $out(sprintf('[OK]   Табела на буџетот %d: %d ставки со износ, вкупно %s МКД.', $year, (int) ($t['n'] ?? 0), number_format((float) ($t['total'] ?? 0), 2)));
};

if (isset($options['list-backups'])) {
    $ids = $backup->ids('budget_lines');
    if (!$ids) $out('Нема резервна копија од табелата на буџетот.');
    foreach ($ids as $id) $out($id.'  ('.(int) $db->scalar('SELECT COUNT(*) FROM dbo.bak_'.$id.'_budget_lines').' ставки)');
    exit(0);
}

if (isset($options['restore'])) {
    $id = (string) $options['restore'];
    if (!$backup->exists($id, 'budget_lines')) $fail('Резервна копија dbo.bak_'.$id.'_budget_lines не постои (видете --list-backups).', 2);
    if (($options['confirm'] ?? '') !== 'RIKTHE BUXHETIN') $fail('За враќање додајте --confirm="RIKTHE BUXHETIN".', 2);
    $years = array_map('intval', array_column($db->all("SELECT DISTINCT bc.base_year FROM dbo.bak_{$id}_budget_lines b JOIN budget_versions bv ON bv.id=b.budget_version_id JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id"), 'base_year'));
    $result = $db->transaction(function () use ($db, $backup, $id, $versionWhere, $years): array {
        $r = ['lines'=>$backup->restore($id, 'budget_lines')];
        if ($backup->exists($id, 'budget_line_directorate_access')) $r['access'] = $backup->restore($id, 'budget_line_directorate_access', ['budget_line_id', 'directorate_id']);
        if ($backup->exists($id, 'budget_line_code_changes')) $r['code_changes'] = $backup->restore($id, 'budget_line_code_changes');
        // Lines created after the backup: their amounts go back to zero (they stay, other records may point to them).
        $r['zeroed'] = 0;
        foreach ($years as $year) $r['zeroed'] += $db->execute("UPDATE budget_lines SET own_revenue=0,government_grant=0,health_grant=0,education_grant=0,loan_financing=0,carryover_revenue=0,updated_at=SYSUTCDATETIME() WHERE {$versionWhere($year)} AND id NOT IN (SELECT id FROM dbo.bak_{$id}_budget_lines) AND total_amount<>0");
        $db->execute("INSERT INTO audit_events(actor_id,action,entity_type,entity_id,before_state,after_state,ip_address,correlation_id,reason,occurred_at) VALUES(NULL,'budget.lines_restored','App\\Models\\BudgetLine',NULL,NULL,:after,NULL,NEWID(),:reason,SYSUTCDATETIME())", ['after'=>json_encode($r), 'reason'=>'backup '.$id]);
        return $r;
    });
    $out(sprintf('[OK]   budget_lines: %d вратени, %d повторно внесени; %d ставки создадени подоцна се поставени на нула.', $result['lines'][0], $result['lines'][1], $result['zeroed']));
    if (isset($result['access'])) $out(sprintf('[OK]   budget_line_directorate_access: %d вратени, %d повторно внесени.', $result['access'][0], $result['access'][1]));
    if (isset($result['code_changes'])) $out(sprintf('[OK]   budget_line_code_changes: %d вратени, %d повторно внесени.', $result['code_changes'][0], $result['code_changes'][1]));
    foreach ($years as $year) $recalculate($db, $year);
    exit(0);
}

$year = (int) ($options['year'] ?? 0);
if ($year < 2020 || $year > 2100) $fail('Внесете ја годината: --year=2026', 2);

// Actor for the imports and the audit trail: --actor or the first active system administrator.
$actor = isset($options['actor'])
    ? $db->scalar('SELECT id FROM users WHERE username=:u AND status=\'active\'', ['u'=>(string) $options['actor']])
    : $db->scalar("SELECT TOP 1 u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE r.code='system_admin' AND u.status='active' AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME()) ORDER BY u.id");
if (!$actor) $fail('Не е пронајден активен корисник (--actor=username или администратор на системот).', 2);
$actor = (int) $actor;

/** Latest posted batch of a type for the year, with the source that can be used. */
$latestBatch = static function (string $type) use ($db, $year): ?array {
    foreach ($db->all("SELECT TOP 50 id,storage_key,summary,CONVERT(varchar(19),confirmed_at,120) confirmed_at FROM import_batches WHERE type=:type AND status='posted' ORDER BY confirmed_at DESC", ['type'=>$type]) as $batch) {
        $summary = json_decode((string) $batch['summary'], true) ?: [];
        if ((int) ($summary['options']['fiscal_year'] ?? 0) !== $year) continue;
        $path = APP_ROOT.'/storage/private/'.ltrim((string) $batch['storage_key'], '/');
        return $batch + ['options'=>$summary['options'] ?? [], 'path'=>is_file($path) ? $path : null];
    }
    return null;
};

$approved = $latestBatch(ImportWorkflow::APPROVED_BUDGET);
$source = null; $lines = null; $fallbackBackup = null;
if ($approved && $approved['path'] !== null) {
    $source = 'file';
} elseif ($approved) {
    $lines = [];
    foreach ($db->all('SELECT payload FROM import_rows WHERE import_batch_id=:id ORDER BY id', ['id'=>$approved['id']]) as $row) {
        $payload = json_decode((string) $row['payload'], true);
        if (is_array($payload['line'] ?? null)) $lines[] = $payload['line'];
    }
    if ($lines) $source = 'import_rows';
}
if ($source === null) {
    $fallbackBackup = $backup->ids('budget_lines')[0] ?? null;
    if ($fallbackBackup !== null) $source = 'backup_table';
}
$freeBalance = $latestBatch(ImportWorkflow::FREEBALANCE_SUMMARY);
$fresh = isset($options['fresh-start']);
if ($fresh && isset($options['keep-orphans'])) $fail('--fresh-start и --keep-orphans не одат заедно.', 2);

$out('Повторна изградба на табелата на буџетот '.$year.':');
$out('  Одобрен буџет: '.match ($source) {
    'file' => 'датотека на увозот '.$approved['id'].' ('.$approved['confirmed_at'].', '.($approved['options']['source_file_name'] ?? basename((string) $approved['path'])).')',
    'import_rows' => 'зачуваните редови од увозот '.$approved['id'].' ('.count($lines).' ставки; датотеката недостасува)',
    'backup_table' => 'табела на резервната копија dbo.bak_'.$fallbackBackup.'_budget_lines (нема увоз на одобрен буџет со датотека или редови)',
    default => 'НЕМА ИЗВОР (ниеден увоз на одобрен буџет и ниедна резервна копија)',
});
$out('  FreeBalance: '.($freeBalance ? ($freeBalance['path'] !== null ? 'датотека на увозот '.$freeBalance['id'].' (состојба '.($freeBalance['options']['as_of_date'] ?? '?').')' : 'importi '.$freeBalance['id'].' без датотека: само повторно поврзување и пресметка') : 'нема увоз за оваа година'));
if ($source === null) $fail('Нема извор за повторна изградба.', 2);
if ($fresh && $source === 'backup_table') $fail('Новиот почеток бара датотека или зачувани редови од увозот на одобрениот буџет; резервната копија би ги вратила старите ставки.', 2);

// Budget lines of the version the approved-budget import writes to (it is created by the first import).
$existingLines = static function () use ($db, $year): array {
    $version = (int) ($db->scalar("SELECT TOP 1 bv.id FROM budget_versions bv JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bc.base_year=:year AND bc.name=N'Буџет одобрен од Советот на општината' AND bv.scenario='approved' AND bv.is_official=1 ORDER BY bv.version_no DESC", ['year'=>$year]) ?: 0);
    return $version > 0 ? $db->all("SELECT bl.id,bl.directorate_id,bl.code,bl.name,bl.budget_category,bl.source_section_code,bl.program_code,bl.approved_line_key,bl.own_revenue,bl.government_grant,bl.health_grant,bl.education_grant,bl.loan_financing,bl.carryover_revenue,COALESCE((SELECT SUM(ba.current_amount) FROM budget_allocations ba WHERE ba.budget_line_id=bl.id),0) allocated FROM budget_lines bl WHERE bl.budget_version_id=:version", ['version'=>$version]) : [];
};
/** Lines of the year's official versions that the approved budget does not claim (old lines, other versions). */
$unclaimed = static function (array $planLines) use ($db, $year, $versionWhere, $existingLines): array {
    $claimed = [];
    foreach (ApprovedBudgetSync::plan($planLines, $existingLines())['lines'] as $line) if ($line['budget_line_id'] !== null) $claimed[(int) $line['budget_line_id']] = true;
    return array_values(array_filter($db->all('SELECT bl.id,bl.code,bl.name,bl.total_amount FROM budget_lines bl WHERE '.$versionWhere($year).' ORDER BY bl.code'), static fn(array $row): bool => !isset($claimed[(int) $row['id']])));
};
/** Lines that cannot be deleted, with the tables that still point to them (foreign keys read from the database). */
$blockers = static function (array $ids, bool $beforeReset) use ($db, $year): array {
    $reasons = [];
    if ($ids === []) return $reasons;
    foreach ($db->all("SELECT OBJECT_NAME(fk.parent_object_id) tbl,COL_NAME(fkc.parent_object_id,fkc.parent_column_id) col FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id=fk.object_id WHERE fk.referenced_object_id=OBJECT_ID('dbo.budget_lines') AND fk.delete_referential_action=0") as $ref) {
        $table = (string) $ref['tbl']; $column = (string) $ref['col'];
        if (in_array($table, FRESH_START_CLEARED, true)) continue;
        // Before the reset, the allocations of the year still exist but will be removed with it.
        $extra = $beforeReset && $table === 'budget_allocations' ? ' AND fiscal_year<>'.$year : '';
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->all("SELECT [{$column}] id,COUNT(*) n FROM dbo.[{$table}] WHERE [{$column}] IN (".implode(',', $chunk)."){$extra} GROUP BY [{$column}]") as $row) $reasons[(int) $row['id']][] = $table.' ('.(int) $row['n'].')';
        }
    }
    return $reasons;
};
$allocationCount = static fn(): int => (int) $db->scalar('SELECT COUNT(*) FROM budget_allocations WHERE fiscal_year=:year', ['year'=>$year]);

// Simulation: the same plan the import would apply, read-only.
if ($source !== 'backup_table') {
    if ($source === 'file') {
        $hints = [];
        foreach ($db->all("SELECT project_code,MAX(program_code) program_code FROM budget_execution_lines WHERE fiscal_year=:year AND project_code NOT IN ('00000','00040') GROUP BY project_code HAVING COUNT(DISTINCT program_code)=1", ['year'=>$year]) as $row) $hints[(string) $row['project_code']] = (string) $row['program_code'];
        $parsed = ApprovedBudgetWorkbook::parse((new SpreadsheetReader())->sheets((string) $approved['path']), $hints);
        if ($parsed['errors'] !== []) $fail('Работната книга има грешки: '.json_encode(array_slice($parsed['errors'], 0, 5), JSON_UNESCAPED_UNICODE), 3);
        $planLines = $parsed['lines'];
    } else {
        $planLines = $lines;
    }
    $map = (new BudgetExecution($db))->programMap();
    foreach ($planLines as &$line) $line['directorate_id'] = $map[(string) $line['program_code']]['directorate_id'] ?? null;
    unset($line);
    $plan = ApprovedBudgetSync::plan($planLines, $existingLines())['summary'];
    $out(sprintf('  План: %d нови, %d ажурирања (%d промени на шифри), %d без промена, %d со грешки, %d ставки надвор од буџетот (%s).',
        $plan['create'], $plan['update'], $plan['code_changes'], $plan['unchanged'], $plan['invalid'], $plan['orphans'], isset($options['keep-orphans']) ? 'mbeten' : 'се поставуваат на нула ако немаат распределби'));
    if ($plan['invalid'] > 0 && !isset($options['skip-invalid'])) $out('  [ВНИМАНИЕ] Редовите со грешки ја блокираат повторната изградба; додајте --skip-invalid за нивните ставки да останат какви што се.');
}
if ($fresh) {
    $e = $db->one('SELECT COUNT(*) n,COALESCE(SUM(current_amount),0) amount,(SELECT COUNT(*) FROM commitments WHERE budget_allocation_id IN (SELECT id FROM budget_allocations WHERE fiscal_year=:y1)) commitments,(SELECT COUNT(*) FROM expenditures WHERE budget_allocation_id IN (SELECT id FROM budget_allocations WHERE fiscal_year=:y2)) expenditures FROM budget_allocations WHERE fiscal_year=:y3', ['y1'=>$year, 'y2'=>$year, 'y3'=>$year]) ?: [];
    $out(sprintf('  Нов почеток: се бришат %d распределби од е-Кабинет (%s МКД), %d обврски и %d расходи, со резервна копија преку reset-ekabineti-allocations.php.', (int) ($e['n'] ?? 0), number_format((float) ($e['amount'] ?? 0), 2), (int) ($e['commitments'] ?? 0), (int) ($e['expenditures'] ?? 0)));
    $old = $unclaimed($planLines);
    $blocked = $blockers(array_map('intval', array_column($old, 'id')), true);
    $out(sprintf('  Буџетски ставки надвор од увозот: %d се бришат (%s МКД), %d остануваат на нула бидејќи имаат референци.', count($old) - count($blocked), number_format(array_sum(array_map(static fn(array $r): float => isset($blocked[(int) $r['id']]) ? 0.0 : (float) $r['total_amount'], $old)), 2), count($blocked)));
    foreach (array_slice($old, 0, 60) as $row) $out('    '.(isset($blocked[(int) $row['id']]) ? '[останува: '.implode(', ', $blocked[(int) $row['id']]).'] ' : '[се брише] ').$row['code'].' · '.$row['name'].' · '.number_format((float) $row['total_amount'], 2));
    if (count($old) > 60) $out('    … и '.(count($old) - 60).' други.');
}
$confirmation = $fresh ? 'FSHI DHE RINDERTO BUXHETIN' : 'RINDERTO BUXHETIN';
if (!isset($options['execute'])) { $out('Симулација: ништо не е променето. За извршување: --execute --confirm="'.$confirmation.'"'); exit(0); }
if (($options['confirm'] ?? '') !== $confirmation) $fail('За извршување додајте --confirm="'.$confirmation.'".', 2);

// 0. Fresh start: the e-Kabineti allocations of the year go first (the reset script backs them up).
if ($fresh && $allocationCount() > 0) {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/reset-ekabineti-allocations.php').' --execute --confirm='.escapeshellarg('FSHI ALOKIMET').' --include-change-requests --year='.$year.(isset($options['backup-dir']) ? ' --backup-dir='.escapeshellarg((string) $options['backup-dir']) : '');
    $out('[..]   Чистење на распределбите од е-Кабинет за '.$year.':');
    passthru($command, $exitCode);
    if ($exitCode !== 0 || $allocationCount() > 0) $fail('Чистењето на распределбите не успеа; буџетските ставки не се променети.', 3);
}

// 1. Backup of the budget table
$id = TableBackup::newId();
while ($backup->exists($id, 'budget_lines')) { sleep(1); $id = TableBackup::newId(); }
$directory = $backupRoot.'/budget-'.$id;
$saved = $backup->backup($id, $directory, $budgetTables($year));
$backup->writeManifest($directory, ['backup_id'=>$id, 'created_at_utc'=>gmdate('c'), 'year'=>$year, 'source'=>$source, 'fresh_start'=>$fresh, 'approved_batch'=>$approved['id'] ?? null, 'freebalance_batch'=>$freeBalance['id'] ?? null] + $saved);
$out('[OK]   Backup '.$id.': '.$directory.' и табелите dbo.bak_'.$id.'_* ('.$saved['rows']['budget_lines'].' ставки).');

$workflow = new ImportWorkflow($db, new Scope($db), new Audit($db, new Request()), $actor);
/** A new batch that re-uses the source file of an earlier import. */
$newBatch = static function (string $type, ?string $sourcePath) use ($db, $actor): array {
    $batchId = Request::uuid();
    $extension = $sourcePath !== null ? strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) : 'json';
    $directory = APP_ROOT.'/storage/private/imports/'.$batchId;
    if (!mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('Папката за увоз не е создадена.');
    $path = $directory.'/source.'.$extension;
    if ($sourcePath !== null && !copy($sourcePath, $path)) throw new RuntimeException('Копирањето на датотеката за увоз не успеа.');
    $db->execute("INSERT INTO import_batches(id,type,storage_key,status,created_by,created_at,updated_at) VALUES(:id,:type,:key,'preview',:user,SYSUTCDATETIME(),SYSUTCDATETIME())", ['id'=>$batchId, 'type'=>$type, 'key'=>'imports/'.$batchId.'/source.'.$extension, 'user'=>$actor]);
    return [$batchId, $path];
};

// 2. Approved budget
if ($source === 'backup_table') {
    $restored = $db->transaction(fn(): array => ['lines'=>$backup->restore($fallbackBackup, 'budget_lines')] + ($backup->exists($fallbackBackup, 'budget_line_directorate_access') ? ['access'=>$backup->restore($fallbackBackup, 'budget_line_directorate_access', ['budget_line_id', 'directorate_id'])] : []));
    $out(sprintf('[OK]   Буџетот е повторно изграден од резервната копија %s: %d ставки вратени, %d повторно внесени.', $fallbackBackup, $restored['lines'][0], $restored['lines'][1]));
} else {
    [$batchId, $path] = $newBatch(ImportWorkflow::APPROVED_BUDGET, $source === 'file' ? (string) $approved['path'] : null);
    $previewOptions = ['fiscal_year'=>$year, 'as_of_date'=>(string) ($approved['options']['as_of_date'] ?? ''), 'source_file_name'=>(string) ($approved['options']['source_file_name'] ?? ''), 'source_sha256'=>$source === 'file' ? (hash_file('sha256', $path) ?: '') : ''];
    $preview = $source === 'file'
        ? $workflow->preview($batchId, ImportWorkflow::APPROVED_BUDGET, $path, $previewOptions)
        : $workflow->previewApprovedBudgetFromLines($batchId, $lines, $previewOptions);
    if ($preview['status'] !== 'ready') $fail('Прегледот на одобрениот буџет не е подготвен (пакет '.$batchId.'). Ништо не е променето во буџетските ставки; видете Увози → Одобрен буџет.', 3);
    $result = $workflow->confirm($db->one('SELECT * FROM import_batches WHERE id=:id', ['id'=>$batchId]), ['retire_orphans'=>!isset($options['keep-orphans']), 'skip_invalid'=>isset($options['skip-invalid'])]);
    $out(sprintf('[OK]   Одобрен буџет (пакет %s): %d нови, %d ажурирања, %d без промена, %d промени на шифри, %d ставки на нула, %d прескокнати.',
        $batchId, $result['created'], $result['updated'], $result['unchanged'], $result['code_changes'], $result['retired'], count($result['skipped'] ?? [])));
}

// 2b. Fresh start: delete the lines the approved budget does not claim; lines still referenced elsewhere stay at zero.
if ($fresh) {
    $old = $unclaimed($planLines);
    $ids = array_map('intval', array_column($old, 'id'));
    $blocked = $blockers($ids, false);
    $deletable = array_values(array_diff($ids, array_keys($blocked)));
    $deleted = $db->transaction(function () use ($db, $deletable, $blocked, $old, $actor, $id): int {
        $n = 0;
        foreach (array_chunk($deletable, 500) as $chunk) {
            $list = implode(',', $chunk);
            $db->execute("UPDATE budget_execution_lines SET budget_line_id=NULL WHERE budget_line_id IN ({$list})");
            $db->execute("DELETE FROM budget_line_code_changes WHERE budget_line_id IN ({$list})");
            $n += $db->execute("DELETE FROM budget_lines WHERE id IN ({$list})");
        }
        if ($n !== count($deletable)) throw new RuntimeException("Избришани се {$n} ставки наместо ".count($deletable).'; ништо не е променето.');
        foreach (array_chunk(array_keys($blocked), 500) as $chunk) $db->execute('UPDATE budget_lines SET own_revenue=0,government_grant=0,health_grant=0,education_grant=0,loan_financing=0,carryover_revenue=0,updated_at=SYSUTCDATETIME() WHERE id IN ('.implode(',', $chunk).') AND total_amount<>0');
        $codes = array_column(array_filter($old, static fn(array $r): bool => !isset($blocked[(int) $r['id']])), 'code');
        $db->execute("INSERT INTO audit_events(actor_id,action,entity_type,entity_id,before_state,after_state,ip_address,correlation_id,reason,occurred_at) VALUES(:actor,'budget.lines_deleted_fresh_start','App\\Models\\BudgetLine',NULL,NULL,:after,NULL,NEWID(),:reason,SYSUTCDATETIME())", ['actor'=>$actor, 'after'=>json_encode(['deleted'=>$n, 'codes'=>$codes, 'kept_zero'=>array_keys($blocked)], JSON_UNESCAPED_UNICODE), 'reason'=>'backup '.$id]);
        return $n;
    });
    $out(sprintf('[OK]   %d ставки надвор од одобрениот буџет се избришани; %d останаа на нула бидејќи имаат референци.', $deleted, count($blocked)));
    foreach ($old as $row) if (isset($blocked[(int) $row['id']])) $out('       mbetet: '.$row['code'].' · '.$row['name'].' ('.implode(', ', $blocked[(int) $row['id']]).')');
}

// 3. FreeBalance
if ($freeBalance && $freeBalance['path'] !== null) {
    [$batchId, $path] = $newBatch(ImportWorkflow::FREEBALANCE_SUMMARY, (string) $freeBalance['path']);
    $preview = $workflow->preview($batchId, ImportWorkflow::FREEBALANCE_SUMMARY, $path, ['as_of_date'=>(string) ($freeBalance['options']['as_of_date'] ?? ''), 'source_file_name'=>(string) ($freeBalance['options']['source_file_name'] ?? ''), 'source_sha256'=>hash_file('sha256', $path) ?: '']);
    if ($preview['status'] === 'ready') {
        $result = $workflow->confirm($db->one('SELECT * FROM import_batches WHERE id=:id', ['id'=>$batchId]));
        $out(sprintf('[OK]   FreeBalance (пакет %s): %d ставки, %d изменети, %d без промена.', $batchId, $result['posted'], $result['created'] + $result['updated'], $result['unchanged']));
    } else {
        $out('[ВНИМАНИЕ] FreeBalance извештајот не е повторно увезен (пакет '.$batchId.' не е подготвен); постојните ставки повторно се поврзуваат.');
    }
}
$recalculate($db, $year);
$out('Враќање, ако е потребно: php bin/rebuild-budget-from-imports.php --restore='.$id.' --confirm="RIKTHE BUXHETIN"');
if ($fresh) $out('Распределби од е-Кабинет: php bin/reset-ekabineti-allocations.php --list-backups, потоа --restore=<id> --confirm="RIKTHE ALOKIMET"');
