<?php

declare(strict_types=1);

namespace EKabineti\Service;

/**
 * Parser for the "BUXHETI DHE REALIZIMI <year> - i aprovuar në Kuvend" workbook.
 *
 * Sheets and what they contribute (fund = funding source code used by FreeBalance):
 *   GRANT          fund 10 wages, goods and services, utilities and subsidies per program (second wages table = fund 21)
 *   10 kapitalet   fund 10 capital projects, court-decision budgets (00040) and reserves
 *   21             fund 21 goods and services and subsidies per program
 *   21 kapitalet   fund 21 capital projects and reserves
 *   22             fund 22 (own revenue carried over from the previous year): wages, goods and services, capital projects
 *   06 / 04        loan financing (capital project / goods and services)
 *   00040, REZERVA cross-checks of court decisions and reserves
 *   totali         category x fund control totals and the realisation period
 *
 * Every budget line is identified by a stable key and gets a code that matches the FreeBalance coding block:
 *   operating  "<program>-<economic>"  (economic 100 wages, 130 goods and services, 132 utilities, 200 subsidies)
 *   capital    "<project>"             (FreeBalance PROJ code)
 *   court      "<program>-00040"       (FreeBalance PROJ 00040, court decisions under Art. 40 LMFPP)
 *   reserve    "REZ-<program>-<hash>"  (reserves have no code in the workbook)
 * Amounts are handled in 1/10000 units, so totals reconcile exactly.
 */
final class ApprovedBudgetWorkbook
{
    public const FUND_COLUMNS = ['10'=>'government_grant', '21'=>'own_revenue', '22'=>'carryover_revenue', '06'=>'loan_financing', '04'=>'loan_financing'];
    public const ECONOMIC = ['wages'=>'100', 'goods_services'=>'130', 'utilities'=>'132', 'subsidies'=>'200', 'capital_investments'=>'300'];
    private const TOLERANCE = 100;

    private array $lines = [];
    private array $errors = [];
    private array $warnings = [];
    /** @var array<string,array<string,int>> computed totals [fund][control category] */
    private array $computed = [];
    private array $programNames = [];

    /** @param array<string,string> $programHints project code => program code (e.g. from FreeBalance), used to settle conflicting labels */
    private function __construct(private readonly array $programHints) {}

    /**
     * @param array<string,array<int,array<int,string>>> $sheets output of SpreadsheetReader::sheets()
     * @return array{fiscal_year:?int,as_of:?string,lines:list<array>,errors:list<array>,warnings:list<array>,controls:list<array>,totals:array<string,string>}
     */
    public static function parse(array $sheets, array $programHints = []): array
    {
        $parser = new self($programHints);
        return $parser->run($sheets);
    }

    private function run(array $sheets): array
    {
        $required = ['GRANT', '10 kapitalet', '21', '21 kapitalet', '22', 'totali'];
        foreach ($required as $name) if (!isset($sheets[$name])) $this->error($name, 1, 'sheet', 'missing_sheet');
        if ($this->errors !== []) return $this->result(null, null, []);

        $this->grant($sheets['GRANT']);
        $this->capital('10 kapitalet', $sheets['10 kapitalet'], '10');
        $this->operating21($sheets['21']);
        $this->capital('21 kapitalet', $sheets['21 kapitalet'], '21');
        $this->carryover($sheets['22']);
        if (isset($sheets['06'])) $this->loanProject($sheets['06']);
        if (isset($sheets['04'])) $this->loanGoods($sheets['04']);
        if (isset($sheets['00040'])) $this->crossCheckCourt($sheets['00040']);
        if (isset($sheets['REZERVA'])) $this->crossCheckReserves($sheets['REZERVA']);
        [$year, $asOf, $controls] = $this->totals($sheets['totali']);
        $this->settlePrograms();
        return $this->result($year, $asOf, $controls);
    }

    // ---------------------------------------------------------------- sheets

    private function grant(array $rows): void
    {
        $wagesSeen = 0;
        foreach ($this->headerRows($rows, ['buxheti_2026']) as [$headerRow, $columns]) {
            $label = self::norm($rows[$headerRow][2] ?? '');
            $category = match (true) {
                str_starts_with($label, 'pagat') => 'wages',
                str_starts_with($label, 'mallrat') => 'goods_services',
                str_starts_with($label, 'komunal') => 'utilities',
                str_starts_with($label, 'subvencion') => 'subsidies',
                default => null,
            };
            if ($category === null) continue; // the summary table at the bottom repeats totals from other sheets
            // Fund 10 tables come first; the second wages table (secondary education) is financed from own revenue (fund 21).
            $fund = $category === 'wages' && ++$wagesSeen > 1 ? '21' : '10';
            $this->operatingTable('GRANT', $rows, $headerRow, $columns, $category, $fund, 0, 2, $category === 'wages' ? 3 : null);
        }
        if ($wagesSeen === 0) $this->error('GRANT', 1, 'section', 'missing_section');
    }

    private function operating21(array $rows): void
    {
        foreach ($this->headerRows($rows, ['buxheti', 'alokim']) as [$headerRow, $columns]) {
            $label = self::norm($rows[$headerRow][2] ?? '');
            $category = str_starts_with($label, 'mallrat') ? 'goods_services' : (str_starts_with($label, 'subvencion') ? 'subsidies' : null);
            if ($category === null) continue;
            $this->operatingTable('21', $rows, $headerRow, $columns, $category, '21', 1, 2, null);
        }
    }

    /** Rows below a header until the "TOTALI ..." row, whose budget must equal the sum of the rows. */
    private function operatingTable(string $sheet, array $rows, int $headerRow, array $columns, string $category, string $fund, int $programColumn, int $nameColumn, ?int $staffColumn): void
    {
        $budgetColumn = $columns['buxheti_2026'] ?? $columns['buxheti'];
        $sum = 0; $closed = false;
        foreach ($rows as $number => $row) {
            if ($number <= $headerRow) continue;
            $label = trim((string) ($row[$nameColumn] ?? ''));
            if (str_starts_with(self::norm($label), 'totali')) {
                $this->expectEqual($sheet, $number, 'budget', $sum, $this->amount($sheet, $number, $row, $budgetColumn));
                $closed = true;
                break;
            }
            $program = self::fiveDigits($row[$programColumn] ?? '') ?? self::programFromLabel($label)[0];
            if ($program === null) continue;
            $budget = $this->amount($sheet, $number, $row, $budgetColumn);
            $sum += $budget;
            $this->programNames[$program] ??= self::programFromLabel($label)[1] ?: $label;
            $line = &$this->line('op:'.$program.':'.$category, $program.'-'.self::ECONOMIC[$category], $category, $program, null, $this->programNames[$program]);
            $this->add($line, $fund, $budget, $this->execution($sheet, $number, $row, $columns), $sheet.'!'.$number);
            if ($staffColumn !== null && ($row[$staffColumn] ?? '') !== '') $line['staff_count'] = ($line['staff_count'] ?? 0) + (int) $row[$staffColumn];
            if (isset($columns['alokim'])) $line['released'] = ($line['released'] ?? 0) + $this->amount($sheet, $number, $row, $columns['alokim']);
            unset($line);
            $this->computed[$fund][$category] = ($this->computed[$fund][$category] ?? 0) + $budget;
        }
        if (!$closed) $this->error($sheet, $headerRow, 'totals', 'missing_section_total');
    }

    private function capital(string $sheet, array $rows, string $fund): void
    {
        $headers = $this->headerRows($rows, ['kodi_i_projektit', 'buxheti']);
        if ($headers === []) { $this->error($sheet, 1, 'header', 'missing_header'); return; }
        [$headerRow, $c] = $headers[0];
        $codeColumn = $c['kodi_i_projektit']; $nameColumn = $c['kapitalet'] ?? $codeColumn + 1; $budgetColumn = $c['buxheti'];
        $courtColumn = $c['buxheti_per_vendime_gjyqesore'] ?? null; $courtSpentColumn = $c['shpenzimi_i_vendimeve_gjyqesore'] ?? null;
        $program = null; $reserve = false; $sums = ['budget'=>0, 'court'=>0, 'reserve'=>0];
        foreach ($rows as $number => $row) {
            if ($number <= $headerRow) continue;
            $label = trim((string) ($row[$nameColumn] ?? '')); $norm = self::norm($label); $codeCell = trim((string) ($row[$codeColumn] ?? ''));
            if ($norm === 'totali_i_rezerves') { $this->expectEqual($sheet, $number, 'reserve', $sums['reserve'], $this->amount($sheet, $number, $row, $budgetColumn)); continue; }
            if ($norm === 'totali') {
                $this->expectEqual($sheet, $number, 'budget', $sums['budget'], $this->amount($sheet, $number, $row, $budgetColumn));
                if ($courtColumn !== null) $this->expectEqual($sheet, $number, 'court_decisions', $sums['court'], $this->amount($sheet, $number, $row, $courtColumn));
                continue;
            }
            if ($norm === 'rezerva' || str_starts_with($norm, 'rezerva_')) { $reserve = true; $program = null; continue; }
            [$headerProgram, $headerName] = self::programFromLabel($label);
            $code = self::fiveDigits($codeCell);
            if ($headerProgram !== null && ($code === null || $code === $headerProgram)) { $program = $headerProgram; $this->programNames[$program] ??= $headerName; continue; }
            if ($label === '' && $code === null) continue;
            if ($reserve || self::norm($codeCell) === 'rezerva') {
                if ($program === null) { $this->error($sheet, $number, 'program', 'line_without_program'); continue; }
                $budget = $this->amount($sheet, $number, $row, $budgetColumn);
                if ($budget === 0) continue;
                $sums['reserve'] += $budget;
                $line = &$this->line('rez:'.$program.':'.self::norm($label), self::reserveCode($program, $label), 'capital_investments', $program, null, $label);
                $line['economic_code'] = 'REZERVA';
                $this->add($line, $fund, $budget, ['actual'=>0, 'obligations'=>0, 'commitments'=>0], $sheet.'!'.$number);
                unset($line);
                $this->computed[$fund]['reserve'] = ($this->computed[$fund]['reserve'] ?? 0) + $budget;
                continue;
            }
            if ($code === null) continue; // group rows such as "DREJTORIA E ARSIMIT"
            if ($program === null) { $this->error($sheet, $number, 'program', 'line_without_program'); continue; }
            $budget = $this->amount($sheet, $number, $row, $budgetColumn);
            $sums['budget'] += $budget;
            $line = &$this->line('cap:'.$code, $code, 'capital_investments', $program, $code, $label);
            $line['program_votes'][$program][] = $sheet.'!'.$number;
            if (isset($line['funds'][$fund])) $this->error($sheet, $number, 'project_code', 'duplicate_in_file');
            $this->add($line, $fund, $budget, $this->execution($sheet, $number, $row, $c), $sheet.'!'.$number);
            if (isset($c['alokimi'])) $line['released'] = ($line['released'] ?? 0) + $this->amount($sheet, $number, $row, $c['alokimi']);
            unset($line);
            $this->computed[$fund]['capital'] = ($this->computed[$fund]['capital'] ?? 0) + $budget;
            if ($courtColumn !== null && ($court = $this->amount($sheet, $number, $row, $courtColumn)) !== 0) {
                $sums['court'] += $court;
                $line = &$this->line('cd:'.$program, $program.'-00040', 'capital_investments', $program, '00040', 'EKZEKUTIMI I VENDIMEVE GJYQËSORE (NENI 40 LMFPP)');
                $line['economic_code'] = 'VENDIME_GJYQESORE';
                $spent = $courtSpentColumn === null ? 0 : $this->amount($sheet, $number, $row, $courtSpentColumn);
                $this->add($line, $fund, $court, ['actual'=>$spent, 'obligations'=>0, 'commitments'=>0], $sheet.'!'.$number);
                $line['court_projects'][$code] = ($line['court_projects'][$code] ?? 0) + $court;
                unset($line);
                $this->computed[$fund]['court'] = ($this->computed[$fund]['court'] ?? 0) + $court;
            }
        }
    }

    private function carryover(array $rows): void
    {
        foreach ($this->headerRows($rows, ['buxheti', 'mbetja']) as [$headerRow, $columns]) {
            $label = self::norm($rows[$headerRow][2] ?? '');
            $category = match (true) {
                str_starts_with($label, 'pagat') => 'wages',
                str_starts_with($label, 'mallrat') => 'goods_services',
                str_starts_with($label, 'projektet_kapitale') => 'capital_investments',
                default => null,
            };
            if ($category === null) continue;
            $budgetColumn = $columns['buxheti']; $program = null; $sum = 0;
            foreach ($rows as $number => $row) {
                if ($number <= $headerRow) continue;
                $text = trim((string) ($row[2] ?? '')); $norm = self::norm($text); $codeCell = trim((string) ($row[1] ?? ''));
                if (str_starts_with($norm, 'totali_i_kapitaleve') || str_starts_with($norm, 'kategorite') || ($category !== 'capital_investments' && str_starts_with($norm, 'totali'))) {
                    if ($category !== 'capital_investments' || str_starts_with($norm, 'totali_i_kapitaleve')) $this->expectEqual('22', $number, 'budget', $sum, $this->amount('22', $number, $row, $budgetColumn));
                    break;
                }
                if (str_starts_with($norm, 'totali')) continue; // subtotal per program in the capital table
                [$labelProgram, $labelName] = self::programFromLabel($text);
                $budget = $codeCell === '' && $labelProgram !== null && $category === 'capital_investments' ? null : $this->amount('22', $number, $row, $budgetColumn);
                if ($category !== 'capital_investments') {
                    if ($labelProgram === null) continue;
                    $this->programNames[$labelProgram] ??= $labelName;
                    $sum += $budget;
                    $line = &$this->line('op:'.$labelProgram.':'.$category, $labelProgram.'-'.self::ECONOMIC[$category], $category, $labelProgram, null, $this->programNames[$labelProgram]);
                    $this->add($line, '22', $budget, $this->execution('22', $number, $row, $columns), '22!'.$number);
                    unset($line);
                    $this->computed['22'][$category] = ($this->computed['22'][$category] ?? 0) + $budget;
                    continue;
                }
                if ($budget === null) { $program = $labelProgram; $this->programNames[$program] ??= $labelName; continue; }
                if ($program === null) { $this->error('22', $number, 'program', 'line_without_program'); continue; }
                $sum += $budget;
                if (self::norm($codeCell) === 'rezerve' || self::norm($codeCell) === 'rezerva') {
                    $line = &$this->line('rez:'.$program.':'.self::norm($text), self::reserveCode($program, $text), 'capital_investments', $program, null, $text);
                    $line['economic_code'] = 'REZERVA';
                    $this->computed['22']['reserve'] = ($this->computed['22']['reserve'] ?? 0) + $budget;
                } elseif (($code = self::fiveDigits($codeCell)) !== null) {
                    $line = &$this->line('cap:'.$code, $code, 'capital_investments', $program, $code, $text);
                    $line['program_votes'][$program][] = '22!'.$number;
                    $this->computed['22']['capital'] = ($this->computed['22']['capital'] ?? 0) + $budget;
                } else { $this->error('22', $number, 'project_code', 'missing_project_code'); continue; }
                $this->add($line, '22', $budget, $this->execution('22', $number, $row, $columns), '22!'.$number);
                unset($line);
            }
        }
    }

    private function loanProject(array $rows): void
    {
        $program = null;
        foreach ($rows as $number => $row) {
            $code = self::fiveDigits($row[0] ?? '');
            if ($code !== null && self::norm($row[2] ?? '') === 'buxheti') { $program = $code; $this->programNames[$program] ??= trim((string) ($row[1] ?? '')); continue; }
            if ($code === null || $program === null || !is_numeric($row[2] ?? '')) continue;
            $budget = $this->amount('06', $number, $row, 2);
            $line = &$this->line('cap:'.$code, $code, 'capital_investments', $program, $code, trim((string) ($row[1] ?? '')));
            $line['program_votes'][$program][] = '06!'.$number;
            $actual = isset($row[16]) ? $this->amount('06', $number, $row, 16) : 0;
            $this->add($line, '06', $budget, ['actual'=>$actual, 'obligations'=>0, 'commitments'=>0], '06!'.$number);
            unset($line);
            $this->computed['06']['capital'] = ($this->computed['06']['capital'] ?? 0) + $budget;
        }
    }

    private function loanGoods(array $rows): void
    {
        foreach ($this->headerRows($rows, ['buxheti']) as [$headerRow, $columns]) {
            if (!str_starts_with(self::norm($rows[$headerRow][2] ?? ''), 'mallrat')) continue;
            $this->operatingTable('04', $rows, $headerRow, $columns, 'goods_services', '04', 1, 2, null);
        }
    }

    private function crossCheckCourt(array $rows): void
    {
        foreach ($rows as $number => $row) {
            $code = self::fiveDigits($row[0] ?? '');
            if ($code === null || self::programFromLabel((string) ($row[1] ?? ''))[0] === $code) continue;
            $budget = $this->amount('00040', $number, $row, 2);
            $found = false;
            foreach ($this->lines as $line) if (str_starts_with($line['key'], 'cd:') && isset($line['court_projects'][$code])) { $found = true; if (abs($line['court_projects'][$code] - $budget) > self::TOLERANCE) $this->warn('00040', $number, 'court_amount_differs', $code); }
            if (!$found) $this->warn('00040', $number, 'court_code_unknown', $code);
        }
    }

    private function crossCheckReserves(array $rows): void
    {
        $fund = null; $sum = []; $controlRows = [];
        foreach ($rows as $number => $row) {
            $text = self::norm($row[2] ?? '');
            if (str_starts_with($text, 'rezerva_grant')) $fund = '10';
            elseif (str_starts_with($text, 'rezerva_te_hyra')) $fund = '21';
            elseif ($text === 'totali_i_rezerves' && $fund !== null) $controlRows[$fund] = [$number, $this->amount('REZERVA', $number, $row, 3)];
        }
        foreach ($controlRows as $fund => [$number, $amount]) {
            if (abs(($this->computed[$fund]['reserve'] ?? 0) - $amount) > self::TOLERANCE) $this->warn('REZERVA', $number, 'reserve_total_differs', 'fund '.$fund);
        }
    }

    /** Category x fund control totals; also yields the fiscal year and the realisation date. */
    private function totals(array $rows): array
    {
        $year = null; $asOf = null; $controls = [];
        foreach ($rows as $row) foreach ($row as $value) {
            if ($year === null && preg_match('/per\s+vitin\s+(\d{4})/iu', (string) $value, $m)) $year = (int) $m[1];
            if ($asOf === null && preg_match('/deri\s+(\d{2})\.(\d{2})\.(\d{4})/u', (string) $value, $m)) $asOf = $m[3].'-'.$m[2].'-'.$m[1];
        }
        $fundColumns = [];
        foreach ($rows as $number => $row) {
            foreach ($row as $column => $value) {
                $value = trim((string) $value);
                $fund = match (true) { $value === '10' => '10', $value === '21' => '21', $value === '22' => '22', $value === '0.6' => '06', $value === '0.4' => '04', default => null };
                if ($fund !== null) $fundColumns[$fund] = $column;
            }
            if (count($fundColumns) >= 3) break;
        }
        $categories = ['pagat'=>'wages', 'm_sh'=>'goods_services', 'komunalet'=>'utilities', 'subvencionet'=>'subsidies', 'kapitalet'=>'capital', 'rezerva'=>'reserve', '00040'=>'court'];
        $seen = [];
        foreach ($rows as $number => $row) {
            $label = self::norm($row[1] ?? '');
            $category = null;
            foreach ($categories as $prefix => $name) if (str_starts_with($label, $prefix)) { $category = $name; break; }
            if ($category === null || isset($seen[$category])) continue; // only the first table (approved budget) is a budget control
            $seen[$category] = true;
            foreach ($fundColumns as $fund => $column) {
                $expected = $this->amount('totali', $number, $row, $column);
                $actual = $this->computed[$fund][$category] ?? 0;
                if ($fund === '06' || $fund === '04') $actual = ($this->computed[$fund][$category] ?? 0);
                $ok = abs($expected - $actual) <= self::TOLERANCE;
                $controls[] = ['fund'=>$fund, 'category'=>$category, 'expected'=>self::format($expected), 'computed'=>self::format($actual), 'ok'=>$ok, 'row'=>$number];
                if (!$ok) $this->error('totali', $number, $category.'/'.$fund, 'control_totals_mismatch');
            }
            $donations = $this->amount('totali', $number, $row, 7);
            if ($donations !== 0) $this->warn('totali', $number, 'donations_not_itemised', self::format($donations));
        }
        if (count($seen) < 5) $this->error('totali', 1, 'totals', 'missing_control_totals');
        return [$year, $asOf, $controls];
    }

    /** A project listed under different programs is assigned by FreeBalance when possible, otherwise by the first sheet. */
    private function settlePrograms(): void
    {
        foreach ($this->lines as &$line) {
            if (!isset($line['program_votes'])) continue;
            $programs = array_map('strval', array_keys($line['program_votes']));
            $hint = $this->programHints[$line['project_code']] ?? null;
            $chosen = $hint !== null && $hint !== '' ? $hint : $programs[0];
            if (count($programs) > 1 || ($hint !== null && $hint !== $programs[0])) {
                $this->warnings[] = ['sheet'=>implode(', ', array_merge(...array_values($line['program_votes']))), 'row'=>0, 'code'=>'program_conflict', 'detail'=>$line['code'].': '.implode('/', $programs).' → '.$chosen.($hint !== null ? ' (FreeBalance)' : '')];
            }
            $line['program_code'] = $chosen;
            unset($line['program_votes']);
        }
        unset($line);
    }

    // ---------------------------------------------------------------- helpers

    private function &line(string $key, string $code, string $category, string $program, ?string $project, string $name): array
    {
        if (!isset($this->lines[$key])) {
            $this->lines[$key] = ['key'=>$key, 'code'=>$code, 'category'=>$category, 'economic_code'=>self::ECONOMIC_LABELS[$category], 'program_code'=>$program, 'project_code'=>$project,
                'name'=>trim($name), 'funds'=>[], 'actual'=>0, 'obligations'=>0, 'commitments'=>0, 'sources'=>[]];
        }
        return $this->lines[$key];
    }

    private const ECONOMIC_LABELS = ['wages'=>'PAGA', 'goods_services'=>'MALLRA_SHERBIME', 'utilities'=>'SHPENZIME_KOMUNALE', 'subsidies'=>'SUBVENCIONE', 'capital_investments'=>'INVESTIME_KAPITALE'];

    private function add(array &$line, string $fund, int $budget, array $execution, string $source): void
    {
        $line['funds'][$fund] = ($line['funds'][$fund] ?? 0) + $budget;
        foreach (['actual', 'obligations', 'commitments'] as $key) $line[$key] += $execution[$key];
        $line['sources'][] = $source;
    }

    private function execution(string $sheet, int $number, array $row, array $columns): array
    {
        $column = static function (array $names) use ($columns): ?int { foreach ($names as $name) if (isset($columns[$name])) return $columns[$name]; return null; };
        $read = fn(?int $col): int => $col === null ? 0 : $this->amount($sheet, $number, $row, $col);
        return [
            'actual'=>$read($column(['totali_aktual', 'total_shpenzimi', 'totali_i_shpenzimit', 'totali'])),
            'obligations'=>$read($column(['obligim', 'obligimi'])),
            'commitments'=>$read($column(['zotim', 'zotimi'])),
        ];
    }

    /** @return list<array{0:int,1:array<string,int>}> header rows containing all the given labels, with label => column */
    private function headerRows(array $rows, array $labels): array
    {
        $found = [];
        foreach ($rows as $number => $row) {
            $columns = [];
            foreach ($row as $column => $value) { $key = self::norm($value); if ($key !== '' && !isset($columns[$key])) $columns[$key] = $column; }
            if (array_diff($labels, array_keys($columns)) === []) $found[] = [$number, $columns];
        }
        return $found;
    }

    private function amount(string $sheet, int $number, array $row, int $column): int
    {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value === '') return 0;
        if (!is_numeric($value)) { $this->error($sheet, $number, self::columnName($column), 'invalid_amount'); return 0; }
        return (int) round((float) $value * 10000);
    }

    private function expectEqual(string $sheet, int $number, string $column, int $computed, int $expected): void
    {
        if (abs($computed - $expected) > self::TOLERANCE) $this->error($sheet, $number, $column, 'subtotal_mismatch', self::format($computed).' ≠ '.self::format($expected));
    }

    private function error(string $sheet, int $row, string $column, string $code, string $detail = ''): void
    {
        $this->errors[] = ['sheet'=>$sheet, 'row'=>$row, 'column'=>$column, 'code'=>$code] + ($detail !== '' ? ['detail'=>$detail] : []);
    }

    private function warn(string $sheet, int $row, string $code, string $detail): void
    {
        $this->warnings[] = ['sheet'=>$sheet, 'row'=>$row, 'code'=>$code, 'detail'=>$detail];
    }

    private function result(?int $year, ?string $asOf, array $controls): array
    {
        $lines = []; $totals = array_fill_keys(array_unique(array_values(self::FUND_COLUMNS)), 0) + ['total'=>0];
        foreach ($this->lines as $line) {
            $amounts = array_fill_keys(array_unique(array_values(self::FUND_COLUMNS)), 0);
            foreach ($line['funds'] as $fund => $value) $amounts[self::FUND_COLUMNS[$fund]] += $value;
            $budget = array_sum($amounts);
            if ($budget === 0 && $line['actual'] === 0) continue;
            $remaining = $budget - $line['actual'] - $line['obligations'] - $line['commitments'];
            $out = ['key'=>$line['key'], 'code'=>$line['code'], 'category'=>$line['category'], 'economic_code'=>$line['economic_code'], 'program_code'=>$line['program_code'],
                'program_name'=>$this->programNames[$line['program_code']] ?? '', 'project_code'=>$line['project_code'], 'name'=>mb_substr($line['name'], 0, 250),
                'funds'=>array_map([self::class, 'format'], $line['funds'])];
            foreach ($amounts as $column => $value) { $out[$column] = self::format($value); $totals[$column] += $value; }
            $out['total_budgeted'] = self::format($budget); $totals['total'] += $budget; $totals['expenditures'] = ($totals['expenditures'] ?? 0) + $line['actual'];
            foreach (['actual', 'obligations', 'commitments'] as $key) $out[$key] = self::format($line[$key]);
            $out['remaining'] = self::format($remaining);
            if (isset($line['released'])) $out['released'] = self::format($line['released']);
            if (isset($line['staff_count'])) $out['staff_count'] = $line['staff_count'];
            if (isset($line['court_projects'])) $out['court_projects'] = array_map([self::class, 'format'], $line['court_projects']);
            $out['sources'] = implode(', ', $line['sources']);
            $lines[] = $out;
        }
        return ['fiscal_year'=>$year, 'as_of'=>$asOf, 'lines'=>$lines, 'errors'=>$this->errors, 'warnings'=>$this->warnings, 'controls'=>$controls, 'totals'=>array_map([self::class, 'format'], $totals)];
    }

    public static function reserveCode(string $program, string $name): string
    {
        return 'REZ-'.$program.'-'.strtoupper(substr(hash('sha256', self::norm($name)), 0, 6));
    }

    private static function fiveDigits(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{5}(?:\.0+)?$/', $value)) return substr($value, 0, 5);
        return null;
    }

    /** "16306- ADMINISTRATA", "16306 - Administrata - Prishtinë", "  94350 ARSIMI ..." → [program, name] */
    private static function programFromLabel(string $label): array
    {
        if (preg_match('/^\s*(\d{5})(?:\s*[-–]\s*|\s+)(.+)$/u', $label, $m)) return [$m[1], trim($m[2])];
        return [null, ''];
    }

    public static function format(int $units): string
    {
        $sign = $units < 0 ? '-' : ''; $units = abs($units);
        return $sign.intdiv($units, 10000).'.'.str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }

    private static function columnName(int $index): string
    {
        $name = ''; $index++;
        while ($index > 0) { $mod = ($index - 1) % 26; $name = chr(65 + $mod).$name; $index = intdiv($index - $mod, 26); }
        return $name;
    }

    public static function norm(mixed $value): string
    {
        $value = Cyrillic::headerFold((string) $value);
        $value = strtr($value, ['ë'=>'e', 'ç'=>'c', '&'=>'_']);
        return trim((string) preg_replace('/[^a-z0-9]+/u', '_', $value), '_');
    }
}
