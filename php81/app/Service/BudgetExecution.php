<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Security\Scope;

/**
 * FreeBalance execution lines linked to directorates, projects and budget lines.
 *
 * Priority rule: when a FreeBalance line is linked to an e-Kabineti project that has its own budget allocations for
 * the fiscal year, the project figures (allocations, commitments, expenditures recorded in e-Kabineti) are the
 * effective values. The FreeBalance figures stay visible beside them as the reconciliation reference.
 */
final class BudgetExecution
{
    /** FreeBalance project code meaning "no project". */
    public const NO_PROJECT = '00000';
    public const COURT_DECISIONS = '00040';

    /**
     * Per budget line (alias bl): e-Kabineti allocations/reservations/commitments/expenditures (ia, ir, ic, ie) and the
     * imported FreeBalance figures of the linked lines (fb). Use with LINE_FIGURES_SELECT.
     */
    public const LINE_FIGURES_APPLY = " OUTER APPLY (SELECT COALESCE(SUM(ba.current_amount),0) v FROM budget_allocations ba WHERE ba.budget_line_id=bl.id) ia"
        ." OUTER APPLY (SELECT COALESCE(SUM(r.amount),0) v FROM reservations r JOIN budget_allocations ba ON ba.id=r.budget_allocation_id WHERE ba.budget_line_id=bl.id AND r.status='active') ir"
        ." OUTER APPLY (SELECT COALESCE(SUM(c.amount),0) v FROM commitments c JOIN budget_allocations ba ON ba.id=c.budget_allocation_id WHERE ba.budget_line_id=bl.id AND c.status='active') ic"
        ." OUTER APPLY (SELECT COALESCE(SUM(e.amount),0) v FROM expenditures e JOIN budget_allocations ba ON ba.id=e.budget_allocation_id WHERE ba.budget_line_id=bl.id) ie"
        ." OUTER APPLY (SELECT COUNT(*) n,COALESCE(SUM(bel.allocated_amount),0) allocated,COALESCE(SUM(bel.obligations_amount+bel.commitments_amount),0) committed,COALESCE(SUM(bel.actual_amount),0) spent,COALESCE(SUM(bel.balance_amount),0) balance,CONVERT(varchar(10),MAX(bel.as_of_date),23) as_of FROM budget_execution_lines bel WHERE bel.budget_line_id=bl.id AND bel.in_latest_report=1) fb";

    /**
     * Effective allocation figures per budget line. By default they come from the imported FreeBalance Summary Report
     * (allocated = A, committed = obligations C + commitments E, spent = actual B, free = balance F). When the line has its
     * own allocations, reservations, commitments or expenditures in e-Kabineti (recorded through projects), those take priority.
     */
    public const LINE_FIGURES_SELECT = "CASE WHEN ia.v>0 OR ir.v>0 OR ic.v>0 OR ie.v>0 OR fb.n=0 THEN ia.v ELSE fb.allocated END allocated,"
        ."CASE WHEN ia.v>0 OR ir.v>0 OR ic.v>0 OR ie.v>0 OR fb.n=0 THEN ir.v ELSE 0 END reserved,"
        ."CASE WHEN ia.v>0 OR ir.v>0 OR ic.v>0 OR ie.v>0 OR fb.n=0 THEN ic.v ELSE fb.committed END committed,"
        ."CASE WHEN ia.v>0 OR ir.v>0 OR ic.v>0 OR ie.v>0 OR fb.n=0 THEN ie.v ELSE fb.spent END spent,"
        ."CASE WHEN ia.v>0 OR ir.v>0 OR ic.v>0 OR ie.v>0 OR fb.n=0 THEN ia.v-ir.v-ic.v ELSE fb.balance END free_amount,"
        ."CASE WHEN ia.v>0 OR ir.v>0 OR ic.v>0 OR ie.v>0 THEN 'e-Kabineti' WHEN fb.n>0 THEN 'FreeBalance' ELSE '' END execution_source,"
        ."CASE WHEN ia.v>0 OR ir.v>0 OR ic.v>0 OR ie.v>0 THEN NULL ELSE fb.as_of END execution_as_of";

    public function __construct(private readonly Database $db) {}

    /** @return array<string,array{program_code:string,program_name:string,directorate_id:?int}> */
    public function programMap(): array
    {
        $map = [];
        foreach ($this->db->all('SELECT program_code,program_name,directorate_id FROM freebalance_program_directorates') as $row) {
            $map[(string) $row['program_code']] = ['program_code'=>(string) $row['program_code'], 'program_name'=>(string) $row['program_name'], 'directorate_id'=>$row['directorate_id'] === null ? null : (int) $row['directorate_id']];
        }
        return $map;
    }

    /** Everything needed to link the lines of one fiscal year, loaded once. */
    public function linkContext(int $year): array
    {
        $projects = [];
        foreach ($this->db->all('SELECT id,code,directorate_id FROM projects') as $row) $projects[self::key((string) $row['code'])][] = ['id'=>(int) $row['id'], 'directorate_id'=>(int) $row['directorate_id']];
        $lines = [];
        $lineProjects = [];
        foreach ($this->db->all('SELECT bl.id,bl.code,bl.directorate_id,bl.linked_project_id FROM budget_lines bl JOIN budget_versions bv ON bv.id=bl.budget_version_id JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bv.is_official=1 AND bc.base_year=:year', ['year'=>$year]) as $row) {
            $lines[self::key((string) $row['code'])][] = ['id'=>(int) $row['id'], 'directorate_id'=>(int) $row['directorate_id']];
            if ($row['linked_project_id'] !== null) $lineProjects[(int) $row['id']] = (int) $row['linked_project_id'];
        }
        return ['map'=>$this->programMap(), 'projects'=>$projects, 'budget_lines'=>$lines, 'line_projects'=>$lineProjects];
    }

    /** @return array{directorate_id:?int,project_id:?int,budget_line_id:?int,warnings:list<string>} */
    public static function links(array $line, array $context): array
    {
        $warnings = [];
        $directorateId = $context['map'][(string) $line['program_code']]['directorate_id'] ?? null;
        if ($directorateId === null) $warnings[] = 'program_unmapped';
        $projectId = null; $budgetLineId = null;
        $code = (string) $line['project_code'];
        if ($code !== self::NO_PROJECT && $code !== self::COURT_DECISIONS && self::key($code) !== '' && trim($code, '0') !== '') {
            $projectId = self::pick($context['projects'][self::key($code)] ?? [], $directorateId, $warnings, 'project_code_ambiguous');
            $budgetLineId = self::pick($context['budget_lines'][self::key($code)] ?? [], $directorateId, $warnings, 'budget_line_ambiguous');
        } elseif ($code === self::COURT_DECISIONS) {
            // Approved-budget codes: court decisions per program are "<program>-00040".
            $budgetLineId = self::pick($context['budget_lines'][self::key($line['program_code'].'-'.self::COURT_DECISIONS)] ?? [], $directorateId, $warnings, 'budget_line_ambiguous');
        } elseif (($line['economic_code'] ?? '') !== '') {
            // Operating lines without a project use "<program>-<economic>" (e.g. 16306-130).
            $budgetLineId = self::pick($context['budget_lines'][self::key($line['program_code'].'-'.$line['economic_code'])] ?? [], $directorateId, $warnings, 'budget_line_ambiguous');
        }
        // A project chosen for the budget line on the Budget execution page wins over the project found by code.
        if ($budgetLineId !== null && isset($context['line_projects'][$budgetLineId])) $projectId = $context['line_projects'][$budgetLineId];
        return ['directorate_id'=>$directorateId, 'project_id'=>$projectId, 'budget_line_id'=>$budgetLineId, 'warnings'=>$warnings];
    }

    /** Re-applies the program map and project/budget-line links, e.g. after the program map was edited. */
    public function relink(?int $year = null): int
    {
        $years = $year === null ? array_map('intval', array_column($this->db->all('SELECT DISTINCT fiscal_year FROM budget_execution_lines'), 'fiscal_year')) : [$year];
        $changed = 0;
        foreach ($years as $fiscalYear) {
            $context = $this->linkContext($fiscalYear);
            foreach ($this->db->all('SELECT id,program_code,economic_code,project_code,directorate_id,project_id,budget_line_id FROM budget_execution_lines WHERE fiscal_year=:year', ['year'=>$fiscalYear]) as $row) {
                $links = self::links($row, $context);
                if ([$links['directorate_id'], $links['project_id'], $links['budget_line_id']] === [self::nullableInt($row['directorate_id']), self::nullableInt($row['project_id']), self::nullableInt($row['budget_line_id'])]) continue;
                $this->db->execute('UPDATE budget_execution_lines SET directorate_id=:directorate,project_id=:project,budget_line_id=:line,updated_at=SYSUTCDATETIME() WHERE id=:id', ['directorate'=>$links['directorate_id'], 'project'=>$links['project_id'], 'line'=>$links['budget_line_id'], 'id'=>$row['id']]);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Copies the FreeBalance balance into budget_lines.imported_available_amount ("Mbetja sipas importit") for linked
     * lines. A snapshot older than the one already stored never overwrites it.
     */
    public function refreshBudgetLineSnapshots(int $year, string $asOf): int
    {
        return $this->db->execute(
            'UPDATE bl SET imported_available_amount=CASE WHEN x.balance<0 THEN 0 ELSE x.balance END,imported_available_as_of=:as_of,updated_at=SYSUTCDATETIME()
             FROM budget_lines bl JOIN (SELECT budget_line_id,SUM(balance_amount) balance FROM budget_execution_lines WHERE fiscal_year=:year AND in_latest_report=1 AND budget_line_id IS NOT NULL GROUP BY budget_line_id) x ON x.budget_line_id=bl.id
             WHERE bl.imported_available_as_of IS NULL OR bl.imported_available_as_of<=:as_of_check',
            ['as_of'=>$asOf, 'year'=>$year, 'as_of_check'=>$asOf]
        );
    }

    /** "92030, 92310 93150" → ['92030','92310','93150']; throws on anything that is not a 4–6 digit program code. */
    public static function parseBudgetCodes(string $value): array
    {
        $codes = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', trim($value)) ?: [], static fn(string $code): bool => $code !== '')));
        foreach ($codes as $code) if (!preg_match('/^\d{4,6}$/', $code)) throw new \InvalidArgumentException('Буџетската шифра „'.$code.'“ не е валидна (се очекува шифра на програма, на пр. 16306).');
        sort($codes);
        return $codes;
    }

    /**
     * Makes $codes the budget (program) codes of a directorate: links them in the program map, unlinks codes that were
     * removed, stores the list on the directorate and re-links the FreeBalance lines. A code already linked to another
     * directorate is refused. Must run inside the caller's transaction.
     * @return array{added:list<string>,removed:list<string>}
     */
    public function assignDirectorateCodes(int $directorateId, array $codes, ?int $userId, string $fallbackName): array
    {
        $current = array_map('strval', array_column($this->db->all('SELECT program_code FROM freebalance_program_directorates WITH (UPDLOCK,HOLDLOCK) WHERE directorate_id=:directorate', ['directorate'=>$directorateId]), 'program_code'));
        foreach ($codes as $code) {
            $owner = $this->db->one('SELECT m.directorate_id,d.name FROM freebalance_program_directorates m WITH (UPDLOCK,HOLDLOCK) LEFT JOIN directorates d ON d.id=m.directorate_id WHERE m.program_code=:code', ['code'=>$code]);
            if ($owner && $owner['directorate_id'] !== null && (int) $owner['directorate_id'] !== $directorateId) throw new \InvalidArgumentException('Буџетска шифра '.$code.' е поврзана со секторот '.$owner['name'].'. Најпрво отстранете ја од него.');
        }
        $added = array_values(array_diff($codes, $current)); $removed = array_values(array_diff($current, $codes));
        foreach ($added as $code) {
            $this->db->execute('UPDATE freebalance_program_directorates SET directorate_id=:directorate,updated_by=:user,updated_at=SYSUTCDATETIME() WHERE program_code=:code', ['directorate'=>$directorateId, 'user'=>$userId, 'code'=>$code]);
            $this->db->execute('INSERT INTO freebalance_program_directorates(program_code,program_name,directorate_id,updated_by) SELECT :code,:name,:directorate,:user WHERE NOT EXISTS (SELECT 1 FROM freebalance_program_directorates WHERE program_code=:code_check)', ['code'=>$code, 'name'=>mb_substr($fallbackName, 0, 250), 'directorate'=>$directorateId, 'user'=>$userId, 'code_check'=>$code]);
        }
        foreach ($removed as $code) $this->db->execute('UPDATE freebalance_program_directorates SET directorate_id=NULL,updated_by=:user,updated_at=SYSUTCDATETIME() WHERE program_code=:code', ['user'=>$userId, 'code'=>$code]);
        $this->db->execute('UPDATE directorates SET budget_codes=:codes WHERE id=:id', ['codes'=>$codes === [] ? null : implode(', ', $codes), 'id'=>$directorateId]);
        if ($added !== [] || $removed !== []) $this->relink();
        return ['added'=>$added, 'removed'=>$removed];
    }

    /** Recomputes directorates.budget_codes from the program map (after the map was edited on the FreeBalance page). */
    public function syncDirectorateCodes(): void
    {
        $codes = [];
        foreach ($this->db->all('SELECT program_code,directorate_id FROM freebalance_program_directorates WHERE directorate_id IS NOT NULL ORDER BY program_code') as $row) $codes[(int) $row['directorate_id']][] = (string) $row['program_code'];
        foreach ($this->db->all('SELECT id,budget_codes FROM directorates') as $row) {
            $value = isset($codes[(int) $row['id']]) ? implode(', ', $codes[(int) $row['id']]) : null;
            if ($value !== $row['budget_codes']) $this->db->execute('UPDATE directorates SET budget_codes=:codes WHERE id=:id', ['codes'=>$value, 'id'=>$row['id']]);
        }
    }

    public function years(): array
    {
        return array_map('intval', array_column($this->db->all('SELECT DISTINCT fiscal_year FROM budget_execution_lines ORDER BY fiscal_year DESC'), 'fiscal_year'));
    }

    /**
     * Effective execution rows for one fiscal year, honouring the user's directorate scope.
     * A project with its own allocations is collapsed into one row whose values come from e-Kabineti.
     *
     * @return array{rows:list<array>,as_of:?string,totals:array<string,float>}
     */
    public function effective(Scope $scope, int $userId, int $year, array $filters = []): array
    {
        $where = 'bel.fiscal_year=:year AND bel.in_latest_report=1'; $params = ['year'=>$year];
        $ids = $scope->directorateIds($userId);
        if ($ids !== null) {
            if ($ids === []) return ['rows'=>[], 'as_of'=>null, 'totals'=>self::emptyTotals()];
            $marks = [];
            foreach ($ids as $i => $id) { $marks[] = ':scope_'.$i; $params['scope_'.$i] = $id; }
            $where .= ' AND bel.directorate_id IN ('.implode(',', $marks).')';
        }
        if (($filters['directorate_id'] ?? 0) > 0) { $where .= ' AND bel.directorate_id=:directorate'; $params['directorate'] = (int) $filters['directorate_id']; }
        foreach (['fund_code'=>'fund', 'program_code'=>'program', 'economic_code'=>'economic'] as $column => $key) {
            if (($filters[$key] ?? '') !== '') { $where .= " AND bel.{$column}=:{$key}"; $params[$key] = (string) $filters[$key]; }
        }
        if (($filters['q'] ?? '') !== '') {
            $where .= ' AND (bel.project_code LIKE :q1 OR bel.project_name LIKE :q2 OR bel.program_name LIKE :q3 OR p.name LIKE :q4)';
            $like = '%'.str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], (string) $filters['q']).'%';
            foreach ([1, 2, 3, 4] as $i) $params['q'.$i] = $like;
        }
        $lines = $this->db->all("SELECT bel.*,CONVERT(varchar(10),bel.as_of_date,23) as_of,d.name directorate,p.code linked_project_code,p.name linked_project_name FROM budget_execution_lines bel LEFT JOIN directorates d ON d.id=bel.directorate_id LEFT JOIN projects p ON p.id=bel.project_id WHERE {$where} ORDER BY bel.fund_code,bel.program_code,bel.economic_code,bel.project_code,bel.function_code", $params);

        $projectIds = array_values(array_unique(array_filter(array_map(static fn(array $line): int => (int) ($line['project_id'] ?? 0), $lines))));
        $projectData = [];
        if ($projectIds !== []) {
            $marks = []; $projectParams = ['project_year'=>$year];
            foreach ($projectIds as $i => $id) { $marks[] = ':project_'.$i; $projectParams['project_'.$i] = $id; }
            // SQL Server cannot aggregate over a subquery (error 130): compute per allocation first, then sum per project.
            foreach ($this->db->all("SELECT x.project_id,SUM(x.current_amount) allocated,SUM(x.reserved) reserved,SUM(x.committed) committed,SUM(x.spent) spent FROM (SELECT ba.project_id,ba.current_amount,COALESCE((SELECT SUM(r.amount) FROM reservations r WHERE r.budget_allocation_id=ba.id AND r.status='active'),0) reserved,COALESCE((SELECT SUM(c.amount) FROM commitments c WHERE c.budget_allocation_id=ba.id AND c.status='active'),0) committed,COALESCE((SELECT SUM(e.amount) FROM expenditures e WHERE e.budget_allocation_id=ba.id),0) spent FROM budget_allocations ba WHERE ba.fiscal_year=:project_year AND ba.project_id IN (".implode(',', $marks).')) x GROUP BY x.project_id', $projectParams) as $row) {
                $projectData[(int) $row['project_id']] = $row;
            }
        }

        $rows = []; $byProject = []; $asOf = null;
        foreach ($lines as $line) {
            $asOf = max($asOf ?? '', (string) $line['as_of']);
            $freeBalance = [
                'allocated'=>(float) $line['allocated_amount'], 'actual'=>(float) $line['actual_amount'],
                'open'=>(float) $line['obligations_amount'] + (float) $line['commitments_amount'], 'balance'=>(float) $line['balance_amount'],
            ];
            $projectId = (int) ($line['project_id'] ?? 0);
            if ($projectId > 0 && isset($projectData[$projectId])) {
                if (!isset($byProject[$projectId])) {
                    $app = $projectData[$projectId];
                    $committed = (float) $app['committed']; $spent = (float) $app['spent']; $reserved = (float) $app['reserved'];
                    $effective = ['allocated'=>(float) $app['allocated'], 'actual'=>$spent, 'open'=>max($committed - $spent, 0.0) + $reserved, 'balance'=>(float) $app['allocated'] - $reserved - max($committed, $spent)];
                    $byProject[$projectId] = count($rows);
                    $rows[] = ['source'=>'project', 'project_id'=>$projectId, 'directorate'=>$line['directorate'], 'fund'=>[], 'program'=>$line['program_code'].' '.$line['program_name'], 'economic'=>[], 'project_code'=>$line['linked_project_code'], 'project_name'=>$line['linked_project_name'], 'function'=>'', 'lines'=>0, 'freebalance'=>array_fill_keys(array_keys($freeBalance), 0.0)] + $effective;
                }
                $row = &$rows[$byProject[$projectId]];
                $row['lines']++;
                $row['fund'][$line['fund_code']] = $line['fund_code'].' '.$line['fund_name'];
                $row['economic'][$line['economic_code']] = $line['economic_code'].' '.$line['economic_name'];
                foreach ($freeBalance as $key => $value) $row['freebalance'][$key] += $value;
                unset($row);
                continue;
            }
            $rows[] = ['source'=>'freebalance', 'project_id'=>$projectId ?: null, 'directorate'=>$line['directorate'], 'fund'=>[$line['fund_code'].' '.$line['fund_name']], 'program'=>$line['program_code'].' '.$line['program_name'], 'economic'=>[$line['economic_code'].' '.$line['economic_name']], 'project_code'=>$line['project_code'], 'project_name'=>$line['project_name'], 'function'=>$line['function_code'].' '.$line['function_name'], 'lines'=>1, 'freebalance'=>$freeBalance] + $freeBalance;
        }

        if (($filters['source'] ?? '') !== '') $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['source'] === $filters['source']));
        $totals = self::emptyTotals();
        foreach ($rows as &$row) {
            $row['fund'] = implode(', ', array_values($row['fund']));
            $row['economic'] = implode(', ', array_values($row['economic']));
            $row['difference'] = $row['source'] === 'project' && (abs($row['allocated'] - $row['freebalance']['allocated']) > 0.005 || abs($row['actual'] - $row['freebalance']['actual']) > 0.005);
            foreach (['allocated', 'actual', 'open', 'balance'] as $key) $totals[$key] += $row[$key];
            $totals['differences'] += $row['difference'] ? 1 : 0;
            $totals['project_rows'] += $row['source'] === 'project' ? 1 : 0;
        }
        unset($row);
        return ['rows'=>$rows, 'as_of'=>$asOf, 'totals'=>$totals];
    }

    /** FreeBalance figures of one project (all years), for the project page. */
    public function projectSummary(int $projectId): array
    {
        return $this->db->all("SELECT fiscal_year,CONVERT(varchar(10),MAX(as_of_date),23) as_of,COUNT(*) lines,SUM(allocated_amount) allocated,SUM(actual_amount) actual,SUM(obligations_amount+commitments_amount) open_commitments,SUM(balance_amount) balance FROM budget_execution_lines WHERE project_id=:project AND in_latest_report=1 GROUP BY fiscal_year ORDER BY fiscal_year DESC", ['project'=>$projectId]);
    }

    private static function pick(array $candidates, ?int $directorateId, array &$warnings, string $ambiguous): ?int
    {
        if (count($candidates) > 1 && $directorateId !== null) $candidates = array_values(array_filter($candidates, static fn(array $c): bool => $c['directorate_id'] === $directorateId));
        if (count($candidates) === 1) return $candidates[0]['id'];
        if (count($candidates) > 1) $warnings[] = $ambiguous;
        return null;
    }

    private static function emptyTotals(): array
    {
        return ['allocated'=>0.0, 'actual'=>0.0, 'open'=>0.0, 'balance'=>0.0, 'differences'=>0, 'project_rows'=>0];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public static function key(string $value): string
    {
        $value = mb_strtoupper(Cyrillic::fold(trim($value)));
        $value = strtr($value, ['Ë'=>'E', 'Ç'=>'C']);
        return (string) preg_replace('/[^A-Z0-9]+/u', '', $value);
    }
}
