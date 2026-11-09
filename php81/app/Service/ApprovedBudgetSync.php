<?php

declare(strict_types=1);

namespace EKabineti\Service;

/**
 * Plans how the approved-budget workbook lines map onto the budget lines already in the database.
 *
 * Matching runs in passes, so a strong match is never taken away by a weaker one:
 *   1. key      the line was imported from this workbook before (approved_line_key)
 *   2. code     same budget code (normalised) and compatible category
 *   3. program  operating line only: same program (program_code / source_section_code) and category, unique
 *   4. name     same normalised name and category, unique
 * Anything left becomes a new line. Existing lines that match nothing are reported, never deleted.
 * A match keeps the database id, so allocations, projects, commitments and expenditures stay attached while
 * the code, name, program and amounts are aligned with the approved budget.
 */
final class ApprovedBudgetSync
{
    public const AMOUNTS = ['government_grant', 'own_revenue', 'carryover_revenue', 'loan_financing'];

    /**
     * @param list<array> $lines    parsed workbook lines, each with directorate_id resolved (null = unmapped program)
     * @param list<array> $existing budget lines of the official version: id, directorate_id, code, name, budget_category,
     *                              source_section_code, program_code, approved_line_key, the five source amounts, carryover_revenue, allocated
     * @return array{lines:list<array>,orphans:list<array>,summary:array<string,int>}
     */
    public static function plan(array $lines, array $existing): array
    {
        $claimed = []; $match = [];
        $byId = [];
        foreach ($existing as $row) $byId[(int) $row['id']] = $row;
        $passes = [
            'key' => static fn(array $line, array $row): bool => (string) ($row['approved_line_key'] ?? '') === $line['key'],
            'code' => static fn(array $line, array $row): bool => self::key((string) $row['code']) === self::key($line['code']) && self::compatible($line, $row),
            'program' => static fn(array $line, array $row): bool => str_starts_with($line['key'], 'op:') && $row['budget_category'] === $line['category'] && in_array($line['program_code'], [(string) ($row['program_code'] ?? ''), self::key((string) ($row['source_section_code'] ?? ''))], true),
            'name' => static fn(array $line, array $row): bool => self::name((string) $row['name']) === self::name($line['name']) && self::compatible($line, $row),
        ];
        foreach ($passes as $by => $test) {
            foreach ($lines as $i => $line) {
                if (isset($match[$i])) continue;
                $candidates = array_values(array_filter($existing, static fn(array $row): bool => !isset($claimed[(int) $row['id']]) && $test($line, $row)));
                if (count($candidates) > 1 && $line['directorate_id'] !== null) {
                    $same = array_values(array_filter($candidates, static fn(array $row): bool => (int) $row['directorate_id'] === (int) $line['directorate_id']));
                    if ($same !== []) $candidates = $same;
                }
                if (count($candidates) !== 1) continue;
                $match[$i] = ['id'=>(int) $candidates[0]['id'], 'by'=>$by];
                $claimed[(int) $candidates[0]['id']] = $i;
            }
        }

        $planned = []; $summary = ['create'=>0, 'update'=>0, 'unchanged'=>0, 'code_changes'=>0, 'invalid'=>0];
        foreach ($lines as $i => $line) {
            $errors = [];
            if ($line['directorate_id'] === null) $errors[] = ['column'=>'program_code', 'code'=>'program_unmapped'];
            $row = isset($match[$i]) ? $byId[$match[$i]['id']] : null;
            $target = ['code'=>$line['code'], 'name'=>$line['name'], 'directorate_id'=>$line['directorate_id'], 'budget_category'=>$line['category'], 'program_code'=>$line['program_code'], 'project_code'=>$line['project_code']];
            $amounts = array_intersect_key($line, array_flip(self::AMOUNTS)) + ['health_grant'=>'0.0000', 'education_grant'=>'0.0000'];
            if ($row !== null) {
                // Keep an existing education/health split of the government grant when the workbook only gives fund 10 in total.
                $split = (float) $row['health_grant'] + (float) $row['education_grant'];
                if ($split > 0 && (float) $line['government_grant'] + 0.00001 >= $split) {
                    $amounts['health_grant'] = self::money($row['health_grant']); $amounts['education_grant'] = self::money($row['education_grant']);
                    $amounts['government_grant'] = self::money((float) $line['government_grant'] - $split);
                } elseif ($split > 0) {
                    $errors[] = ['column'=>'government_grant', 'code'=>'grant_split_needs_review'];
                }
            }
            $changes = [];
            if ($row !== null) {
                foreach ($target as $field => $value) {
                    $old = $field === 'directorate_id' ? (int) $row[$field] : (string) ($row[$field] ?? '');
                    $new = $field === 'directorate_id' ? (int) $value : (string) ($value ?? '');
                    if ($old !== $new) $changes[$field] = ['from'=>$row[$field] ?? null, 'to'=>$value];
                }
                foreach ($amounts as $field => $value) if (abs((float) ($row[$field] ?? 0) - (float) $value) > 0.00005) $changes[$field] = ['from'=>self::money($row[$field] ?? 0), 'to'=>$value];
                if ((float) $line['total_budgeted'] + 0.00001 < (float) $row['allocated']) $errors[] = ['column'=>'total_budgeted', 'code'=>'below_allocations'];
                if (isset($changes['directorate_id']) && (float) $row['allocated'] > 0) $errors[] = ['column'=>'directorate_id', 'code'=>'directorate_change_with_allocations'];
            }
            $action = $row === null ? 'create' : ($changes === [] && (string) ($row['approved_line_key'] ?? '') === $line['key'] ? 'unchanged' : 'update');
            $planned[$i] = $line + ['action'=>$action, 'budget_line_id'=>$row['id'] ?? null, 'matched_by'=>$match[$i]['by'] ?? null, 'previous_code'=>$row['code'] ?? null,
                'allocated'=>self::money($row['allocated'] ?? 0), 'changes'=>$changes, 'target_amounts'=>$amounts, 'errors'=>$errors];
        }

        // Final (directorate, code) pairs must stay unique, as the database index requires.
        $final = [];
        foreach ($existing as $row) if (!isset($claimed[(int) $row['id']])) $final[(int) $row['directorate_id'].'|'.self::key((string) $row['code'])][] = 'existing:'.$row['id'];
        foreach ($planned as $i => $line) if ($line['directorate_id'] !== null) $final[(int) $line['directorate_id'].'|'.self::key($line['code'])][] = $i;
        foreach ($final as $owners) {
            if (count($owners) < 2) continue;
            foreach ($owners as $owner) if (is_int($owner)) $planned[$owner]['errors'][] = ['column'=>'code', 'code'=>'code_conflict'];
        }

        foreach ($planned as $line) {
            if ($line['errors'] !== []) { $summary['invalid']++; continue; }
            $summary[$line['action']]++;
            if (isset($line['changes']['code'])) $summary['code_changes']++;
        }
        $orphans = [];
        foreach ($existing as $row) {
            if (isset($claimed[(int) $row['id']]) || $row['budget_category'] === null) continue;
            $total = array_sum(array_map(static fn(string $field): float => (float) ($row[$field] ?? 0), [...self::AMOUNTS, 'health_grant', 'education_grant']));
            if ($total == 0.0 && (float) ($row['allocated'] ?? 0) == 0.0) continue; // already retired
            $orphans[] = ['id'=>(int) $row['id'], 'code'=>(string) $row['code'], 'name'=>(string) $row['name'], 'directorate_id'=>(int) $row['directorate_id'], 'budget_category'=>$row['budget_category'],
                'total'=>self::money($total), 'allocated'=>self::money($row['allocated'] ?? 0)];
        }
        $summary['orphans'] = count($orphans);
        return ['lines'=>array_values($planned), 'orphans'=>$orphans, 'summary'=>$summary];
    }

    /** A fingerprint of the plan, so confirmation can refuse when the database changed after the preview. */
    public static function fingerprint(array $plan): string
    {
        $parts = [];
        foreach ($plan['lines'] as $line) $parts[] = [$line['key'], $line['action'], $line['budget_line_id'], $line['changes'], $line['errors']];
        $parts[] = array_column($plan['orphans'], 'id');
        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function compatible(array $line, array $row): bool
    {
        return $row['budget_category'] === null || $row['budget_category'] === $line['category'];
    }

    public static function key(string $value): string
    {
        $value = mb_strtoupper(Cyrillic::fold(trim($value)));
        $value = strtr($value, ['Ë'=>'E', 'Ç'=>'C']);
        return (string) preg_replace('/[^A-Z0-9]+/u', '', $value);
    }

    private static function name(string $value): string
    {
        return ApprovedBudgetWorkbook::norm($value);
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
