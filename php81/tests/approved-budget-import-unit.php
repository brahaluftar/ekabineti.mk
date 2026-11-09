<?php
// Approved-budget workbook parser and budget-code alignment plan (no database required): php tests/approved-budget-import-unit.php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; }
});
use EKabineti\Service\ApprovedBudgetSync;
use EKabineti\Service\ApprovedBudgetWorkbook;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; echo ($condition ? '[OK] ' : '[FAIL] ').$message.PHP_EOL; };

// A miniature workbook with the same layout as "BUXHETI DHE REALIZIMI 2026 - i aprovuar në Kuvend".
$workbook = static function (array $patch = []): array {
    $sheets = [
        'GRANT' => [
            2=>[2=>'Pagat dhe meditjet', 4=>'Buxheti 2026', 26=>'Totali aktual'], 3=>[0=>'16306', 2=>'ADMINISTRATA-PRISHTINE', 3=>'10', 4=>'1000', 26=>'800'], 4=>[2=>'TOTALI →', 4=>'1000'],
            6=>[2=>'Pagat dhe meditjet', 4=>'Buxheti 2026'], 7=>[0=>'94350', 2=>'ARSIMI I MESEM', 4=>'60'], 8=>[2=>'TOTALI →', 4=>'60'],
            10=>[2=>'Mallrat dhe sherbimet', 4=>'Buxheti 2026', 26=>'Totali aktual', 28=>'Obligim', 29=>'Zotim'], 11=>[0=>'16306', 1=>'133', 2=>'ADMINISTRATA-PRISHTINE', 4=>'500', 26=>'100', 28=>'10', 29=>'5'], 12=>[2=>'TOTALI MALLRA DHE SHERBIME', 4=>'500'],
            14=>[4=>'Buxheti 2026'], 15=>[2=>'Капитални', 4=>'300'],
        ],
        '10 kapitalet' => [
            2=>[0=>'Шифра на проектот', 1=>'Kapitalet', 2=>'Буџет', 12=>'Buxheti per vendime gjyqesore', 13=>'Shpenzimi i vendimeve gjyqesore', 27=>'Вкупно', 28=>'Obligim', 29=>'Zotim'],
            4=>[0=>'16306', 1=>'16306- ADMINISTRATA', 2=>'300'], 5=>[0=>'52542', 1=>'Ndertimi i Aneksit', 2=>'200', 12=>'50', 13=>'40', 27=>'150'], 6=>[0=>'56462', 1=>'Sistemi i dokumenteve', 2=>'100'],
            7=>[1=>'DREJTORIA E ARSIMIT', 2=>'0'], 8=>[1=>'ВКУПНО', 2=>'300', 12=>'50'],
            9=>[1=>'REZERVA'], 10=>[1=>'16306- ADMINISTRATA', 2=>'70'], 11=>[0=>'Резерва', 1=>'Blerja e autobuseve', 2=>'70'], 12=>[1=>'TOTALI I REZERVES', 2=>'70'],
        ],
        '21' => [3=>[2=>'Mallrat dhe sherbimet', 3=>'Буџет', 4=>'Alokim', 18=>'Вкупно', 19=>'Obligim', 20=>'Zotim'], 4=>[0=>'1', 1=>'16306', 2=>'ADMINISTRATA-PRISHTINE', 3=>'40', 4=>'40', 18=>'20'], 5=>[2=>'TOTALI MALLRA DHE SHERBIME', 3=>'40']],
        '21 kapitalet' => [2=>[0=>'Шифра на проектот', 1=>'Kapitalet', 2=>'Буџет', 3=>'Распределба', 16=>'Вкупно'], 4=>[0=>'16306', 1=>'16306- ADMINISTRATA', 2=>'150'], 5=>[0=>'52542', 1=>'Ndertimi i Aneksit', 2=>'150', 3=>'100', 16=>'60'], 6=>[1=>'ВКУПНО', 2=>'150']],
        '22' => [
            3=>[2=>'Mallrat dhe sherbimet', 3=>'Буџет', 8=>'Total shpenzimi', 12=>'Остаток'], 4=>[0=>'1', 2=>'16306 - ADMINISTRATA-PRISHTINE', 3=>'30', 8=>'30'], 5=>[2=>'TOTALI MALLRA DHE SHERBIME', 3=>'30'],
            7=>[1=>'Kodi pro.', 2=>'Projektet kapitale', 3=>'Буџет', 8=>'Total shpenzimi', 12=>'Остаток'], 8=>[2=>'16306 - Administrata - Bitola'], 9=>[0=>'1', 1=>'49653', 2=>'Paisje IT', 3=>'25'],
            10=>[2=>'TOTALI KAPITALET', 3=>'25'], 11=>[2=>'TOTALI I KAPITALEVE NGA BARTJA', 3=>'25'],
        ],
        'totali' => [
            1=>[1=>'Buxhet propozimi i Komunes se Bitoles per vitin 2026'], 3=>[2=>'10', 3=>'21', 4=>'22', 5=>'0.6', 6=>'0.4', 7=>'31-99'],
            4=>[1=>'Плати', 2=>'1000', 3=>'60'], 5=>[1=>'M&Sh', 2=>'500', 3=>'40', 4=>'30'], 6=>[1=>'Komunalet'], 7=>[1=>'Subvencionet'], 8=>[1=>'Kapitalet', 2=>'300', 3=>'150', 4=>'25'],
            9=>[1=>'Резерва', 2=>'70'], 10=>[1=>'00040 EKZEKUTIMI I VENDIMEVE GJYQËSORE (NENI 40 LMFPP)', 2=>'50'],
            13=>[1=>' Realizimi I Buxhet propozimit 01.01.2026 deri 30.09.2026'],
        ],
    ];
    foreach ($patch as $sheet => $rows) foreach ($rows as $number => $row) $sheets[$sheet][$number] = $row;
    return $sheets;
};

$r = ApprovedBudgetWorkbook::parse($workbook());
$byCode = array_column($r['lines'], null, 'code');
$assert($r['errors'] === [] && $r['fiscal_year'] === 2026 && $r['as_of'] === '2026-09-30', 'a consistent workbook parses; year and realisation date are read from "totali"');
$assert(array_filter($r['controls'], static fn(array $c): bool => !$c['ok']) === [], 'every category x fund total reconciles with "totali"');
$assert(isset($byCode['16306-100'], $byCode['94350-100'], $byCode['16306-130'], $byCode['52542'], $byCode['56462'], $byCode['49653'], $byCode['16306-00040']), 'operating, capital, carry-over and court-decision lines get FreeBalance-style codes');
$assert($byCode['16306-130']['government_grant'] === '500.0000' && $byCode['16306-130']['own_revenue'] === '40.0000' && $byCode['16306-130']['carryover_revenue'] === '30.0000' && $byCode['16306-130']['released'] === '40.0000', 'goods and services merge funds 10, 21 and 22 into one line with the 21 allocation');
$assert($byCode['16306-130']['remaining'] === '405.0000', 'remaining is recomputed as budget - actual - obligations - commitments (570 - 150 - 10 - 5)');
$assert($byCode['52542']['total_budgeted'] === '350.0000' && $byCode['52542']['actual'] === '210.0000', 'a project financed by funds 10 and 21 is one line (200 + 150)');
$assert($byCode['16306-00040']['government_grant'] === '50.0000' && $byCode['16306-00040']['actual'] === '40.0000', 'court-decision budgets become the program line <program>-00040');
$assert($byCode['94350-100']['own_revenue'] === '60.0000' && $byCode['16306-100']['staff_count'] === 10, 'the second wages table is fund 21; staff counts are kept');
$reserve = array_values(array_filter($r['lines'], static fn(array $l): bool => str_starts_with($l['code'], 'REZ-16306-')));
$assert(count($reserve) === 1 && $reserve[0]['government_grant'] === '70.0000', 'reserves without a code receive a stable REZ-<program>-<hash> code');
$assert($r['totals']['total'] === '2225.0000', 'the line total equals the approved total (1060 + 570 + 475 + 50 + 70)');

$broken = ApprovedBudgetWorkbook::parse($workbook(['10 kapitalet'=>[8=>[1=>'ВКУПНО', 2=>'301', 12=>'50']]]));
$assert(in_array('subtotal_mismatch', array_column($broken['errors'], 'code'), true), 'a section total that differs from its rows blocks the import');
$control = ApprovedBudgetWorkbook::parse($workbook(['totali'=>[5=>[1=>'M&Sh', 2=>'501', 3=>'40', 4=>'30']]]));
$assert(in_array('control_totals_mismatch', array_column($control['errors'], 'code'), true), 'a difference with the "totali" sheet blocks the import');
$missing = ApprovedBudgetWorkbook::parse(array_diff_key($workbook(), ['22'=>true]));
$assert(in_array('missing_sheet', array_column($missing['errors'], 'code'), true), 'a missing sheet is reported');
$hinted = ApprovedBudgetWorkbook::parse($workbook(), ['56462'=>'16730']);
$assert(array_column($hinted['lines'], null, 'code')['56462']['program_code'] === '16730' && in_array('program_conflict', array_column($hinted['warnings'], 'code'), true), 'FreeBalance settles a mislabelled program and the change is reported');

// Plan against existing database lines.
$lines = array_map(static fn(array $l): array => $l + ['directorate_id'=>2], $r['lines']);
$row = static fn(int $id, string $code, string $name, ?string $category, string $section, array $extra = []): array => $extra + ['id'=>$id, 'directorate_id'=>2, 'code'=>$code, 'name'=>$name, 'budget_category'=>$category, 'source_section_code'=>$section, 'program_code'=>null, 'approved_line_key'=>null,
    'own_revenue'=>'0', 'government_grant'=>'0', 'health_grant'=>'0', 'education_grant'=>'0', 'loan_financing'=>'0', 'carryover_revenue'=>'0', 'allocated'=>'0'];
$existing = [
    $row(1, '13', 'Mallra Administrata', 'goods_services', '16306', ['government_grant'=>'400']),
    $row(2, '52542', 'Aneksi', 'capital_investments', '16306', ['government_grant'=>'200', 'allocated'=>'500']),
    $row(3, '59818', 'Paisje IT', 'capital_investments', '16306'),
    $row(4, 'OLD', 'Linje e vjeter', 'subsidies', '16306', ['government_grant'=>'5']),
    $row(5, '16306-100', 'Плата', 'wages', '16306', ['government_grant'=>'600', 'education_grant'=>'400']),
];
$plan = ApprovedBudgetSync::plan($lines, $existing);
$planned = array_column($plan['lines'], null, 'code');
$assert($planned['16306-130']['budget_line_id'] === 1 && $planned['16306-130']['matched_by'] === 'program' && isset($planned['16306-130']['changes']['code']), 'an operating line keeps its database id and changes code via its program');
$assert($planned['49653']['budget_line_id'] === 3 && $planned['49653']['matched_by'] === 'name' && $planned['49653']['changes']['code']['from'] === '59818', 'a wrong project code is corrected through the project name');
$assert(in_array('below_allocations', array_column($planned['52542']['errors'], 'code'), true), 'a budget below existing allocations is blocked');
$assert($planned['16306-100']['target_amounts']['education_grant'] === '400.0000' && $planned['16306-100']['target_amounts']['government_grant'] === '600.0000', 'an existing education/health split of the government grant is preserved');
$assert(array_column($plan['orphans'], 'code') === ['OLD'], 'existing lines outside the approved budget are reported, not deleted');
$assert($planned['16306-00040']['action'] === 'create' && $planned['16306-00040']['budget_line_id'] === null, 'court-decision lines never take over project lines');
$conflict = ApprovedBudgetSync::plan($lines, [$row(9, '56462', 'Projekt tjeter', 'subsidies', '99999')]);
$assert(in_array('code_conflict', array_column(array_column($conflict['lines'], null, 'code')['56462']['errors'], 'code'), true), 'a new code that collides with another line of the directorate is blocked');
$unmapped = ApprovedBudgetSync::plan(array_map(static fn(array $l): array => ['directorate_id'=>null] + $l, $r['lines']), []);
$assert($unmapped['summary']['invalid'] === count($r['lines']), 'lines of unmapped programs cannot be posted');
$assert(ApprovedBudgetSync::fingerprint($plan) !== ApprovedBudgetSync::fingerprint(ApprovedBudgetSync::plan($lines, array_slice($existing, 1))), 'the plan fingerprint changes when the database changes');

// Directorate budget codes.
$assert(EKabineti\Service\BudgetExecution::parseBudgetCodes('94350, 92030;93150  92310,92030') === ['92030', '92310', '93150', '94350'], 'directorate budget codes accept commas, semicolons or spaces, are de-duplicated and sorted');
$rejected = false; try { EKabineti\Service\BudgetExecution::parseBudgetCodes('16306, ADM'); } catch (InvalidArgumentException) { $rejected = true; }
$assert($rejected, 'a budget code that is not a program number is rejected');
// Skipping rows with errors and administrator-only cleanup.
$workflow = (string) file_get_contents(APP_ROOT.'/app/Service/ImportWorkflow.php');
$write = (string) file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');
$assert(str_contains($workflow, "empty(\$confirmOptions['skip_invalid'])") && str_contains($workflow, "THEN 'skipped' ELSE 'posted'"), 'rows with errors are posted only with skip_invalid and are marked skipped');
$cleanup = substr($write, (int) strpos($write, 'public function importsCleanup'), 600);
$delete = substr($write, (int) strpos($write, 'public function importDelete'), 300);
$assert(str_contains($cleanup, 'isSystemAdmin') && str_contains($delete, 'isSystemAdmin'), 'cleaning or deleting import history is reserved to the system administrator');

// Project budget category and the allocation reset script.
$read = (string) file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');
$assert(substr_count($write, '$this->assertProjectCategory($project,$line);') === 2 && str_contains($write, "AND bl.budget_category<>:category"), 'linking a project to a budget line of another category is refused (project budget link and allocation form); the category cannot change while such allocations exist');
$assert(str_contains($read, "'bl.directorate_id=:owner AND bl.budget_category=:category'") && str_contains($read, 'name="budget_category" required'), 'the project budget picker lists only lines of the project directorate and category (Finance/administrator: all) and the project forms ask for the category');
$reset = (string) file_get_contents(APP_ROOT.'/bin/reset-ekabineti-allocations.php');
$assert(str_contains($reset, "!== 'FSHI ALOKIMET'") && str_contains($reset, "!== 'RIKTHE ALOKIMET'") && str_contains($reset, "if (!isset(\$options['execute'])) { \$out('Симулација"), 'the reset script simulates by default and needs a typed confirmation to delete or restore');
$assert(strpos($reset, 'SELECT {$list} INTO dbo.bak_') < strpos($reset, 'DELETE FROM dbo.') && str_contains($reset, "manifest.json") && str_contains($reset, "--include-change-requests"), 'the reset script backs up to files and database tables before deleting, and never removes change requests silently');

// Cross-directorate/category links and project choice on Budget execution: Finance and administrator only.
$scopeSrc = (string) file_get_contents(APP_ROOT.'/app/Security/Scope.php');
$assert(str_contains($scopeSrc, 'public function canLinkAcrossBudget') && str_contains($scopeSrc, "=== 'FIN'") && str_contains($write, "if(\$this->scope->canLinkAcrossBudget(\$this->userId()))return;") && str_contains($write, 'Буџетската ставка припаѓа на друг сектор'), 'only Finance (role or directorate FIN) and the administrator may link a project to lines of other directorates or categories');
$assert(substr_count($write, "if(!\$this->scope->canLinkAcrossBudget(\$this->userId()))throw new HttpException(403,'Проектот во буџетското извршување") === 2 && str_contains($read, 'executionProjectTable'), 'choosing the project on Budget execution is reserved to Finance and the administrator');
$context = ['map'=>['16306'=>['directorate_id'=>2]], 'projects'=>[], 'budget_lines'=>['16306130'=>[['id'=>77, 'directorate_id'=>2]]], 'line_projects'=>[77=>40]];
$linked = EKabineti\Service\BudgetExecution::links(['program_code'=>'16306', 'economic_code'=>'130', 'project_code'=>'00000'], $context);
$assert($linked['budget_line_id'] === 77 && $linked['project_id'] === 40, 'a project chosen for a budget line on Budget execution is applied to its FreeBalance lines');

// Budget table backup and rebuild from the import files.
$rebuild = (string) file_get_contents(APP_ROOT.'/bin/rebuild-budget-from-imports.php');
$assert(str_contains($rebuild, "\$fresh ? 'FSHI DHE RINDERTO BUXHETIN' : 'RINDERTO BUXHETIN'") && str_contains($rebuild, "(\$options['confirm'] ?? '') !== \$confirmation") && str_contains($rebuild, "!== 'RIKTHE BUXHETIN'") && str_contains($rebuild, "if (!isset(\$options['execute'])) { \$out('Симулација"), 'the rebuild script simulates by default and needs a typed confirmation to rebuild, start fresh or restore');
$assert(str_contains($rebuild, "if (\$fresh && \$source === 'backup_table') \$fail("), 'a fresh start never takes the old lines back from a backup table');
$assert(strpos($rebuild, 'passthru($command, $exitCode)') < strpos($rebuild, '$backup->backup($id, $directory, $budgetTables($year))') && str_contains($rebuild, "--include-change-requests --year='.\$year") && str_contains($rebuild, 'if ($exitCode !== 0 || $allocationCount() > 0) $fail('), 'a fresh start first removes the e-Kabineti allocations of the year with the reset script and stops if that fails');
$assert(strpos($rebuild, '$workflow->confirm(') < strpos($rebuild, 'DELETE FROM budget_lines WHERE id IN') && str_contains($rebuild, "fk.referenced_object_id=OBJECT_ID('dbo.budget_lines') AND fk.delete_referential_action=0") && str_contains($rebuild, 'array_diff($ids, array_keys($blocked))'), 'unclaimed lines are deleted after the import, except lines that other tables still point to');
$assert(str_contains($rebuild, "if (\$n !== count(\$deletable)) throw new RuntimeException(") && str_contains($rebuild, "'budget_line_code_changes' => 'budget_line_id IN"), 'the delete is all-or-nothing and the code history is backed up with the lines');
$assert(strpos($rebuild, "\$source = 'file'") < strpos($rebuild, "\$source = 'import_rows'") && strpos($rebuild, "\$source = 'import_rows'") < strpos($rebuild, "\$source = 'backup_table'"), 'the approved budget source is the import file, then the saved import rows, then the latest backup table');
$assert(strpos($rebuild, '$backup->backup($id, $directory, $budgetTables($year))') < strpos($rebuild, '$workflow->confirm('), 'the budget table is backed up before it is rebuilt');
$assert(str_contains((string) file_get_contents(APP_ROOT.'/bin/reset-ekabineti-allocations.php'), "'budget_lines'=>\$lineScope"), 'the allocation reset also backs up the budget table');
$assert(str_contains((string) file_get_contents(APP_ROOT.'/app/Service/TableBackup.php'), 'array $keys = [\'id\']') && method_exists(EKabineti\Service\ImportWorkflow::class, 'previewApprovedBudgetFromLines'), 'backups restore tables with composite keys and the approved budget can be staged from saved lines');

if ($failures) { fwrite(STDERR, count($failures)." failure(s)\n"); exit(1); }
echo "All approved-budget checks passed.\n";
