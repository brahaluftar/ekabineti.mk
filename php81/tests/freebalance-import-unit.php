<?php
// FreeBalance Summary Report parser and project-priority links (no database required): php tests/freebalance-import-unit.php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; }
});
use EKabineti\Service\BudgetExecution;
use EKabineti\Service\FreeBalanceReport;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; echo ($condition ? '[OK] ' : '[FAIL] ').$message.PHP_EOL; };
$codes = static fn(array $result): array => array_column($result['errors'], 'code');

// Same layout as the FreeBalanceSummaryReport export: indentation encodes CAT/RESP/PCLASS/MOBJ/PROJ/CLAS.
$report = static function (array $overrides = [], bool $withTotal = true): array {
    $rows = [
        ['', '', 'Viti Fiskal  2026'],
        ['', 'Опис', 'Shuma e Alokuar', 'Реализирано', 'Obligimet', 'Fondet jo e obliguar', '', 'Zotimet', 'Салдо'],
        ['', '', '', '', '', 'Износ', '%', '', 'Износ', '%'],
        ['', 'CAT / RESP / PCLASS / MOBJ / PROJ / CLAS', 'A', 'B', 'C', 'D=A-(B+C)', 'D/A', 'E', 'F=D-E', 'F/A'],
        ['', '    10 BUXHETI', '300', '150', '20', '130', '43.3', '30', '100', '33.3'],
        ['', '      616 PRISHTINË', '300', '150', '20', '130', '43.3', '30', '100', '33.3'],
        ['', '        16306 ADMINISTRATA - PRISHTINË', '300', '150', '20', '130', '43.3', '30', '100', '33.3'],
        ['', '          300 PASURITË JOFINANCIARE', '300', '150', '20', '130', '43.3', '30', '100', '33.3'],
        ['', '            52542 NDERTIMI I ANEKSI', '100', '89.5', '10.5', '0', '0', '0', '0', '0'],
        ['', '              0133 SHËRBIMET TJERA TË PËRGJITHSHME', '100', '89.5', '10.5', '0', '0', '0', '0', '0'],
        ['', '            00000 NUK PERDORET', '200', '60.5', '9.5', '130', '65', '30', '100', '50'],
        ['', '              0133 SHËRBIMET TJERA TË PËRGJITHSHME', '200', '60.5', '9.5', '130', '65', '30', '100', '50'],
    ];
    if ($withTotal) $rows[] = ['', 'Totali i përgjithshëm', '300', '150', '20', '130', '43.3', '30', '100', '33.3'];
    foreach ($overrides as $index => $row) $rows[$index] = $row;
    return FreeBalanceReport::parse($rows);
};

$ok = $report();
$assert($ok['errors'] === [] && $ok['fiscal_year'] === 2026, 'a consistent report parses without errors and reads the fiscal year');
$assert(count($ok['lines']) === 2, 'only CLAS rows become detail lines');
$line = $ok['lines'][0];
$assert($line['fund_code'] === '10' && $line['program_code'] === '16306' && $line['economic_code'] === '300' && $line['project_code'] === '52542' && $line['function_code'] === '0133', 'each line carries the full CAT/RESP/PCLASS/MOBJ/PROJ/CLAS path');
$assert($line['source_key'] === '2026:10:616:16306:300:52542:0133', 'the source key is year plus the six codes');
$assert($line['allocated'] === '100.0000' && $line['actual'] === '89.5000' && $line['obligations'] === '10.5000' && $line['balance'] === '0.0000', 'amounts are stored exactly with four decimals');
$assert($ok['totals']['allocated'] === $ok['control']['allocated'] && $ok['totals']['balance'] === '100.0000', 'line totals equal the control total');

$tampered = $report([9=>['', '              0133 SHËRBIMET TJERA TË PËRGJITHSHME', '100', '89.5', '10.5', '0', '0', '0', '0', '0'], 8=>['', '            52542 NDERTIMI I ANEKSI', '101', '89.5', '11.5', '0', '0', '0', '0', '0']]);
$assert(in_array('subtotal_mismatch', $codes($tampered), true), 'a subtotal that differs from its lines is rejected');
$formula = $report([9=>['', '              0133 SHËRBIMET', '100', '80', '10.5', '0', '0', '0', '0', '0'], 8=>['', '            52542 NDERTIMI I ANEKSI', '100', '80', '10.5', '0', '0', '0', '0', '0']]);
$assert(in_array('formula_mismatch', array_column($formula['lines'][0]['errors'], 'code'), true), 'D=A-(B+C) and F=D-E are enforced per line');
$assert(in_array('missing_control_totals', $codes($report([], false)), true), 'a report without "Totali i përgjithshëm" is rejected');
$assert(in_array('missing_header', $codes(FreeBalanceReport::parse([['', 'Опис', 'Shuma e Alokuar']])), true), 'missing amount columns are reported');
$assert(in_array('unexpected_indent', $codes($report([11=>['', '                0133 NIVEL I SHTATË', '200', '60.5', '9.5', '130', '65', '30', '100', '50']])), true), 'a description with 16 leading spaces (not 4/6/8/10/12/14) is rejected');
$assert(in_array('unexpected_indent', $codes($report([7=>['', '     616 PRISHTINË', '300', '150', '20', '130', '43.3', '30', '100', '33.3']])), true), 'a description with 5 leading spaces is rejected');
$assert(FreeBalanceReport::INDENTS === [4=>0, 6=>1, 8=>2, 10=>3, 12=>4, 14=>5] && FreeBalanceReport::SEGMENTS === ['CAT', 'RESP', 'PCLASS', 'MOBJ', 'PROJ', 'CLAS'], 'levels follow the fixed leading spaces 4 CAT, 6 RESP, 8 PCLASS, 10 MOBJ, 12 PROJ, 14 CLAS');
$assert($line['fund_name'] === 'BUXHETI' && $line['program_name'] === 'ADMINISTRATA - PRISHTINË' && $line['project_name'] === 'NDERTIMI I ANEKSI' && $line['function_name'] === 'SHËRBIMET TJERA TË PËRGJITHSHME', 'every description is split into Code and Description');
// A second funding source: its lines carry its own parents, never the previous CAT's program or project.
$twoFunds = $report([
    13=>['', '    06 FINANCIMI I HUAMARRJES', '50', '0', '0', '50', '100', '0', '50', '100'],
    14=>['', '      616 PRISHTINË', '50', '0', '0', '50', '100', '0', '50', '100'],
    15=>['', '        18166 INFRASTRUKTURA PUBLIKE - PRISHTINË', '50', '0', '0', '50', '100', '0', '50', '100'],
    16=>['', '          300 PASURITË JOFINANCIARE', '50', '0', '0', '50', '100', '0', '50', '100'],
    17=>['', '            53544 EFIQIENCA E ENERGJISE', '50', '0', '0', '50', '100', '0', '50', '100'],
    18=>['', '              0451 TRANSPORTI RRUGOR', '50', '0', '0', '50', '100', '0', '50', '100'],
    19=>['', 'Totali i përgjithshëm', '350', '150', '20', '180', '51.4', '30', '150', '42.9'],
], false);
$third = $twoFunds['lines'][2] ?? [];
$assert($twoFunds['errors'] === [] && ($third['fund_code'] ?? '') === '06' && $third['program_code'] === '18166' && $third['project_code'] === '53544' && $third['function_code'] === '0451', 'levels are filled down and a new CAT resets RESP..CLAS');
$gap = $report([13=>['', '    06 FINANCIMI I HUAMARRJES', '50', '0', '0', '50', '100', '0', '50', '100'], 14=>['', '        18166 INFRASTRUKTURA PUBLIKE', '50', '0', '0', '50', '100', '0', '50', '100']]);
$assert(in_array('hierarchy_gap', $codes($gap), true), 'a PCLASS directly under a CAT (missing RESP) is rejected instead of inheriting the previous RESP');

// Links: program map decides the directorate; project code links to e-Kabineti projects and budget lines.
$context = ['map'=>['16306'=>['program_code'=>'16306', 'program_name'=>'ADMINISTRATA', 'directorate_id'=>2]], 'projects'=>['52542'=>[['id'=>40, 'directorate_id'=>2]]], 'budget_lines'=>['52542'=>[['id'=>900, 'directorate_id'=>2], ['id'=>901, 'directorate_id'=>5]]]];
$links = BudgetExecution::links($line, $context);
$assert($links['directorate_id'] === 2 && $links['project_id'] === 40 && $links['budget_line_id'] === 900, 'project and budget line are linked; duplicates are resolved by the mapped directorate');
$none = BudgetExecution::links($ok['lines'][1], $context);
$assert($none['project_id'] === null && $none['budget_line_id'] === null, 'project code 00000 is never linked');
$unmapped = BudgetExecution::links(['program_code'=>'92030', 'project_code'=>'52542'], $context);
$assert($unmapped['directorate_id'] === null && in_array('program_unmapped', $unmapped['warnings'], true) && in_array('budget_line_ambiguous', $unmapped['warnings'], true), 'unmapped programs are flagged and ambiguous codes are not guessed');

// Priority rule is implemented in BudgetExecution::effective(): project rows use e-Kabineti allocations.
$service = (string) file_get_contents(APP_ROOT.'/app/Service/BudgetExecution.php');
$assert(str_contains($service, "'source'=>'project'") && str_contains($service, 'isset($projectData[$projectId])'), 'linked projects with allocations take e-Kabineti values');
$workflow = (string) file_get_contents(APP_ROOT.'/app/Service/ImportWorkflow.php');
$start = (int) strpos($workflow, 'private function previewFreeBalance');
$freeBalanceCode = substr($workflow, $start, (int) strpos($workflow, 'private function budgetLinesForYear') - $start);
$assert($start > 0 && !preg_match('/INSERT INTO (budget_allocations|commitments|expenditures|budget_transactions)\b/', $freeBalanceCode), 'the FreeBalance import never creates allocations, commitments or expenditures');

// Allocations and commitments default to FreeBalance; e-Kabineti project data on the line takes priority.
$select = BudgetExecution::LINE_FIGURES_SELECT; $apply = BudgetExecution::LINE_FIGURES_APPLY;
$assert(str_contains($select, 'THEN ia.v ELSE fb.allocated END allocated') && str_contains($select, 'THEN ic.v ELSE fb.committed END committed') && str_contains($select, 'THEN ie.v ELSE fb.spent END spent') && str_contains($select, 'ELSE fb.balance END free_amount'), 'allocated, committed, spent and free come from FreeBalance unless the line has e-Kabineti data');
$assert(str_contains($apply, 'COALESCE(SUM(bel.obligations_amount+bel.commitments_amount),0) committed') && str_contains($apply, 'bel.in_latest_report=1'), 'FreeBalance commitments are obligations + commitments of the latest report');
$read = (string) file_get_contents(APP_ROOT.'/app/Controller/ReadController.php'); $mayor = (string) file_get_contents(APP_ROOT.'/app/Service/MayorDashboardReport.php');
$assert(substr_count($read, 'BudgetExecution::LINE_FIGURES_SELECT') === 3 && str_contains($mayor, 'BudgetExecution::LINE_FIGURES_SELECT'), 'budget page, overview export, dashboard and Mayor report use the FreeBalance defaults');

// Menus, sources and import sub-pages.
$view = (string) file_get_contents(APP_ROOT.'/app/Core/View.php'); $app = (string) file_get_contents(APP_ROOT.'/app/Core/Application.php'); $js = (string) file_get_contents(APP_ROOT.'/public/assets/app.js');
$assert(str_contains($view, "'Табела на буџетот'") && str_contains($view, "'Табела на распределби'") && str_contains($view, "'Табела на преземени обврски'") && str_contains($view, "'Нова буџетска ставка'") && !str_contains($view, "'Нова ставка']"), 'budget menu has the budget, allocations and commitments tables and "Нова буџетска ставка"');
$assert(count(EKabineti\Service\ImportWorkflow::SECTIONS) === 8 && array_diff(array_values(EKabineti\Service\ImportWorkflow::TYPE_SECTIONS), array_keys(EKabineti\Service\ImportWorkflow::SECTIONS)) === [] && str_contains($app, "'#^/imports/([a-z-]+)$#'"), 'every import type has its own sub-page /imports/<section>');
$assert(substr_count($read, "'source'=>'Import FreeBalance'") === 4 && str_contains($read, "THEN 'Import (shpenzime)' ELSE 'e-Kabineti' END source"), 'execution, allocations, commitments and expenditures show whether a row comes from an import or e-Kabineti');
$assert(str_contains($js, "['ArrowLeft', 'ArrowRight', 'Home', 'End']") && str_contains($js, 'scrollBy({ left:'), 'wide tables scroll horizontally with the Left/Right keys');

if ($failures) { fwrite(STDERR, count($failures)." failure(s)\n"); exit(1); }
echo "All FreeBalance checks passed.\n";
