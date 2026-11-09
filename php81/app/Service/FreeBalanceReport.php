<?php

declare(strict_types=1);

namespace EKabineti\Service;

/**
 * Parser for the FreeBalance "Summary Report" export (sheet FreeBalanceSummaryReport).
 *
 * The report is a tree encoded by the leading spaces of the first cell of the Përshkrimi column:
 *   4 CAT (funding source) · 6 RESP (organisation) · 8 PCLASS (program) · 10 MOBJ (economic category) · 12 PROJ (project) · 14 CLAS (function).
 * Every description is split into Code (first word) and Description (the rest). The six levels are filled down from the rows
 * above; a new parent resets the levels below it, so e.g. a new CAT row starts with empty RESP..CLAS until its own children appear.
 * Only the CLAS rows are detail lines; every other row is a subtotal of its children. Amount columns:
 *   A allocated, B actual (paid expenditure), C obligations, D = A-(B+C) unobligated funds, E commitments, F = D-E balance.
 * Percent columns are ignored; they are derived values.
 */
final class FreeBalanceReport
{
    public const LEVELS = ['fund', 'organization', 'program', 'economic', 'project', 'function'];
    /** Leading spaces in Përshkrimi → level index (CAT, RESP, PCLASS, MOBJ, PROJ, CLAS). */
    public const INDENTS = [4=>0, 6=>1, 8=>2, 10=>3, 12=>4, 14=>5];
    public const SEGMENTS = ['CAT', 'RESP', 'PCLASS', 'MOBJ', 'PROJ', 'CLAS'];
    public const AMOUNTS = ['allocated', 'actual', 'obligations', 'unobligated', 'commitments', 'balance'];

    private const HEADERS = [
        'shuma_e_alokuar'=>'allocated', 'aktuali'=>'actual', 'obligimet'=>'obligations',
        'fondet_jo_e_obliguar'=>'unobligated', 'zotimet'=>'commitments', 'bilanci'=>'balance',
    ];
    /** 1 cent, in units of 1/10000. */
    private const TOLERANCE = 100;

    /**
     * @param iterable<array<int,string>> $rows raw cell values; leading spaces must be preserved
     * @return array{fiscal_year:?int,lines:list<array>,errors:list<array>,totals:array<string,string>,control:?array<string,string>}
     */
    public static function parse(iterable $rows): array
    {
        $fiscalYear = null; $descriptionColumn = null; $columns = []; $errors = [];
        $nodes = []; $control = null; $controlRow = null;

        foreach ($rows as $index => $values) {
            $rowNumber = $index + 1;
            $values = array_map(static fn($value): string => str_replace("\xC2\xA0", ' ', (string) $value), $values);
            if ($fiscalYear === null) {
                foreach ($values as $value) if (preg_match('/Viti\s+Fiskal\s+(\d{4})/iu', $value, $match)) $fiscalYear = (int) $match[1];
            }
            if ($descriptionColumn === null) {
                foreach ($values as $column => $value) {
                    $header = self::normalize($value);
                    if ($header === 'pershkrimi') $descriptionColumn = $column;
                    if (isset(self::HEADERS[$header])) $columns[self::HEADERS[$header]] = $column;
                }
                if ($descriptionColumn !== null && count($columns) !== count(self::AMOUNTS)) {
                    foreach (self::AMOUNTS as $amount) if (!isset($columns[$amount])) $errors[] = ['row'=>$rowNumber, 'column'=>$amount, 'code'=>'missing_header'];
                    return self::result($fiscalYear, [], $errors, null);
                }
                continue;
            }

            $description = (string) ($values[$descriptionColumn] ?? '');
            if (trim($description) === '' || str_starts_with(self::normalize($description), 'cat_resp')) continue;
            $amounts = [];
            foreach ($columns as $key => $column) $amounts[$key] = self::amount((string) ($values[$column] ?? ''));
            if (str_starts_with(self::normalize($description), 'totali')) { $control = $amounts; $controlRow = $rowNumber; continue; }
            $label = ltrim($description, ' ');
            [$code, $name] = array_pad(preg_split('/\s+/u', trim($label), 2) ?: [], 2, '');
            $nodes[] = ['row'=>$rowNumber, 'indent'=>strlen($description) - strlen($label), 'code'=>$code, 'name'=>trim((string) preg_replace('/\s+/u', ' ', $name)), 'amounts'=>$amounts];
        }

        if ($descriptionColumn === null) return self::result($fiscalYear, [], [['row'=>1, 'column'=>'*', 'code'=>'missing_header']], null);
        if ($fiscalYear === null) $errors[] = ['row'=>1, 'column'=>'fiscal_year', 'code'=>'missing_fiscal_year'];
        if ($nodes === []) return self::result($fiscalYear, [], [...$errors, ['row'=>1, 'column'=>'*', 'code'=>'empty_file']], null);

        $unknown = array_filter($nodes, static fn(array $node): bool => !isset(self::INDENTS[$node['indent']]));
        if ($unknown !== []) {
            foreach ($unknown as $node) $errors[] = ['row'=>$node['row'], 'column'=>'description', 'code'=>'unexpected_indent', 'detail'=>$node['indent'].' празни места'];
            return self::result($fiscalYear, [], $errors, null);
        }

        // Rebuild the tree; each subtotal must equal the sum of its direct children.
        $lines = []; $path = []; $stack = []; $roots = array_fill_keys(self::AMOUNTS, 0); $seen = [];
        $close = static function (int $level) use (&$stack, &$errors): void {
            while ($stack !== [] && array_key_last($stack) >= $level) {
                $node = array_pop($stack);
                if ($node['children'] === 0) {
                    if ($node['level'] < count(self::LEVELS) - 1) $errors[] = ['row'=>$node['row'], 'column'=>'description', 'code'=>'subtotal_without_lines'];
                    continue;
                }
                foreach (self::AMOUNTS as $key) {
                    if ($node['amounts'][$key] === null || abs($node['sum'][$key] - $node['amounts'][$key]) > self::TOLERANCE) {
                        $errors[] = ['row'=>$node['row'], 'column'=>$key, 'code'=>'subtotal_mismatch'];
                        break;
                    }
                }
            }
        };
        foreach ($nodes as $node) {
            $level = self::INDENTS[$node['indent']];
            $close($level);
            if ($level > 0 && !isset($stack[$level - 1])) { $errors[] = ['row'=>$node['row'], 'column'=>'description', 'code'=>'hierarchy_gap']; continue; }
            $path = array_slice($path, 0, $level);
            $path[$level] = ['code'=>$node['code'], 'name'=>$node['name']];
            $rowErrors = [];
            foreach (self::AMOUNTS as $key) if ($node['amounts'][$key] === null) $rowErrors[] = ['column'=>$key, 'code'=>'invalid_amount'];
            if ($level > 0) {
                $stack[$level - 1]['children']++;
                foreach (self::AMOUNTS as $key) $stack[$level - 1]['sum'][$key] += (int) $node['amounts'][$key];
            } else {
                foreach (self::AMOUNTS as $key) $roots[$key] += (int) $node['amounts'][$key];
            }
            if ($level < count(self::LEVELS) - 1) {
                $stack[$level] = ['row'=>$node['row'], 'level'=>$level, 'amounts'=>$node['amounts'], 'sum'=>array_fill_keys(self::AMOUNTS, 0), 'children'=>0];
                foreach ($rowErrors as $error) $errors[] = ['row'=>$node['row']] + $error;
                continue;
            }

            $a = $node['amounts'];
            if ($rowErrors === [] && (abs($a['allocated'] - $a['actual'] - $a['obligations'] - $a['unobligated']) > self::TOLERANCE || abs($a['unobligated'] - $a['commitments'] - $a['balance']) > self::TOLERANCE)) {
                $rowErrors[] = ['column'=>'balance', 'code'=>'formula_mismatch'];
            }
            $line = ['source_row_number'=>$node['row'], 'fiscal_year'=>$fiscalYear];
            foreach (self::LEVELS as $i => $name) { $line[$name.'_code'] = $path[$i]['code']; $line[$name.'_name'] = $path[$i]['name']; }
            $line['source_key'] = implode(':', array_merge([(string) $fiscalYear], array_map(static fn(string $level): string => $line[$level.'_code'], self::LEVELS)));
            if (isset($seen[$line['source_key']])) $rowErrors[] = ['column'=>'source_key', 'code'=>'duplicate_in_file'];
            $seen[$line['source_key']] = true;
            foreach (self::AMOUNTS as $key) $line[$key] = $a[$key] === null ? '' : self::format($a[$key]);
            $line['errors'] = $rowErrors;
            $lines[] = $line;
        }
        $close(0);

        if ($control === null) $errors[] = ['row'=>$nodes[array_key_last($nodes)]['row'], 'column'=>'totals', 'code'=>'missing_control_totals'];
        else foreach (self::AMOUNTS as $key) {
            if ($control[$key] === null || abs($roots[$key] - $control[$key]) > self::TOLERANCE) { $errors[] = ['row'=>$controlRow, 'column'=>$key, 'code'=>'control_totals_mismatch']; break; }
        }
        return self::result($fiscalYear, $lines, $errors, $control);
    }

    private static function result(?int $fiscalYear, array $lines, array $errors, ?array $control): array
    {
        $totals = array_fill_keys(self::AMOUNTS, 0);
        foreach ($lines as $line) foreach (self::AMOUNTS as $key) $totals[$key] += self::units((string) $line[$key]);
        return [
            'fiscal_year'=>$fiscalYear, 'lines'=>$lines, 'errors'=>$errors,
            'totals'=>array_map([self::class, 'format'], $totals),
            'control'=>$control === null ? null : array_map(static fn(?int $value): ?string => $value === null ? null : self::format($value), $control),
        ];
    }

    /** Amount in 1/10000 units, or null when the cell is not a number. */
    private static function amount(string $value): ?int
    {
        $value = trim(str_replace(' ', '', $value));
        if ($value === '') return 0;
        if (str_contains($value, ',') && !str_contains($value, '.')) $value = str_replace(',', '.', $value);
        elseif (str_contains($value, ',')) $value = str_replace(',', '', $value);
        if (!is_numeric($value)) return null;
        return (int) round((float) $value * 10000);
    }

    private static function units(string $value): int
    {
        return $value === '' ? 0 : (int) round((float) $value * 10000);
    }

    public static function format(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);
        return $sign.intdiv($units, 10000).'.'.str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }

    private static function normalize(string $value): string
    {
        $value = Cyrillic::headerFold($value);
        $value = strtr($value, ['ë'=>'e', 'ç'=>'c']);
        return trim((string) preg_replace('/[^a-z0-9]+/u', '_', $value), '_');
    }
}
