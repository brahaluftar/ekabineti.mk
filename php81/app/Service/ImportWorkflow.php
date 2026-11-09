<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Core\HttpException;
use EKabineti\Core\Request;
use EKabineti\Security\Scope;

final class ImportWorkflow
{
    public const PROJECTS = 'projects';
    public const PROCUREMENT_PROJECTS = 'procurement_projects';
    public const ANNUAL_BUDGET = 'annual_budget';
    public const CAPITAL_BUDGET = 'capital_investment_budget';
    public const EXPENDITURES = 'budget_expenditures';
    public const ENFORCEMENT_EXECUTIONS = 'enforcement_executions';
    public const FREEBALANCE_SUMMARY = 'freebalance_summary';
    public const APPROVED_BUDGET = 'approved_budget_workbook';

    /** Sub-pages of Imports (/imports/<section>) in menu order, and the section of each import type. */
    public const SECTIONS = [
        'approved-budget'=>'Буџет одобрен од Советот', 'freebalance-summary'=>'FreeBalance извештај', 'annual-budget'=>'Годишен буџет',
        'capital-investments'=>'Капитални инвестиции', 'budget-expenditures'=>'Буџетски расходи', 'procurement-projects'=>'Листа на јавни набавки',
        'enforcement-executions'=>'Табела на извршувања', 'projects'=>'Проекти',
    ];
    public const TYPE_SECTIONS = [
        self::APPROVED_BUDGET=>'approved-budget', self::FREEBALANCE_SUMMARY=>'freebalance-summary', self::ANNUAL_BUDGET=>'annual-budget',
        self::CAPITAL_BUDGET=>'capital-investments', self::EXPENDITURES=>'budget-expenditures', self::PROCUREMENT_PROJECTS=>'procurement-projects',
        self::ENFORCEMENT_EXECUTIONS=>'enforcement-executions', self::PROJECTS=>'projects',
    ];

    private const FUNDING_SOURCES = [
        'own_revenue', 'government_grant', 'health_grant', 'education_grant',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Scope $scope,
        private readonly Audit $audit,
        private readonly int $userId,
    ) {}

    public static function types(): array
    {
        return [self::PROJECTS, self::PROCUREMENT_PROJECTS, self::CAPITAL_BUDGET, self::EXPENDITURES, self::ANNUAL_BUDGET, self::ENFORCEMENT_EXECUTIONS, self::FREEBALANCE_SUMMARY, self::APPROVED_BUDGET];
    }

    public function preview(string $batchId, string $type, string $path, array $options = []): array
    {
        if (!in_array($type, self::types(), true)) {
            throw new HttpException(422, 'Видот на увозот не е валиден.');
        }
        if ($type === self::ANNUAL_BUDGET && (!isset($options['fiscal_year']) || !preg_match('/^\d{4}$/', (string) $options['fiscal_year']) || (int) $options['fiscal_year'] < 2020 || (int) $options['fiscal_year'] > 2100)) {
            throw new HttpException(422, 'Фискалната година не е валидна.');
        }
        if ($type === self::ENFORCEMENT_EXECUTIONS) {
            if (!isset($options['fiscal_year']) || (int) $options['fiscal_year'] < 2020 || (int) $options['fiscal_year'] > 2100) throw new HttpException(422, 'Фискалната година не е валидна.');
            return $this->previewEnforcementExecutions($batchId, $path, (int) $options['fiscal_year'], $options);
        }
        if ($type === self::FREEBALANCE_SUMMARY) {
            if (!self::validDate((string) ($options['as_of_date'] ?? ''))) throw new HttpException(422, 'Датумот на извештајот не е валиден.');
            return $this->previewFreeBalance($batchId, $path, $options);
        }
        if ($type === self::APPROVED_BUDGET) {
            if (!isset($options['fiscal_year']) || (int) $options['fiscal_year'] < 2020 || (int) $options['fiscal_year'] > 2100) throw new HttpException(422, 'Фискалната година не е валидна.');
            if (($options['as_of_date'] ?? '') !== '' && !self::validDate((string) $options['as_of_date'])) throw new HttpException(422, 'Датумот на реализацијата не е валиден.');
            return $this->previewApprovedBudget($batchId, $path, $options);
        }

        $reader = new SpreadsheetReader();
        $rowNumber = 0;
        $valid = 0;
        $invalid = 0;
        $errors = [];
        $headerMap = null;
        $seenKeys = [];
        $seenReferences = [];
        $commitmentUsage = [];
        $totals = ['budgeted'=>0.0, 'available'=>0.0, 'expenditures'=>0.0];

        foreach ($reader->rows($path) as $values) {
            $rowNumber++;
            $values = array_map(static fn($value): string => trim((string) $value), $values);

            if ($rowNumber === 1) {
                [$headerMap, $headerErrors] = $this->headerMap($type, $values);
                foreach ($headerErrors as $error) $errors[] = ['row'=>1] + $error;
                if ($headerErrors !== []) break;
                continue;
            }

            if (!array_filter($values, static fn(string $value): bool => $value !== '')) continue;

            $payload = $this->payload($type, $values, $headerMap ?? [], $rowNumber, $options);
            // The procurement export can contain headings/empty records. A missing procurement
            // code is explicitly ignored and never staged or persisted.
            if (($payload['_skip'] ?? false) === true) continue;
            $rowErrors = match ($type) {
                self::PROJECTS => $this->validateProject($payload),
                self::PROCUREMENT_PROJECTS => $this->validateProcurementProject($payload),
                self::CAPITAL_BUDGET => $this->validateCapitalBudget($payload),
                self::ANNUAL_BUDGET => $this->validateAnnualBudget($payload),
                self::EXPENDITURES => $this->validateExpenditure($payload, $commitmentUsage),
            };

            $key = (string) ($payload['external_row_key'] ?? 'row-'.$rowNumber);
            if (isset($seenKeys[$key])) $rowErrors[] = ['column'=>'external_row_key', 'code'=>'duplicate_in_file'];
            $seenKeys[$key] = true;

            if ($type === self::EXPENDITURES) {
                $sourceReference = (string) ($payload['source_reference'] ?? '');
                if ($sourceReference !== '' && isset($seenReferences[$sourceReference])) {
                    $rowErrors[] = ['column'=>'source_reference', 'code'=>'duplicate_in_file'];
                }
                if ($sourceReference !== '') $seenReferences[$sourceReference] = true;
            }

            $status = $rowErrors === [] ? 'valid' : 'invalid';
            if ($status === 'valid') {
                $valid++;
                if (in_array($type, [self::CAPITAL_BUDGET, self::ANNUAL_BUDGET], true)) {
                    $totals['budgeted'] += (float) $payload['total_budgeted'];
                    $totals['available'] += (float) $payload['total_available'];
                } elseif ($type === self::EXPENDITURES) {
                    $totals['expenditures'] += (float) $payload['amount'];
                }
            } else {
                $invalid++;
            }

            $this->db->execute(
                'INSERT INTO import_rows (import_batch_id,external_row_key,payload,status,errors,created_at,updated_at) VALUES (:batch,:key,:payload,:status,:errors,SYSUTCDATETIME(),SYSUTCDATETIME())',
                [
                    'batch'=>$batchId,
                    // The source key may legitimately repeat in a file. Keep every row in the
                    // preview so duplicates can be reported instead of violating the staging index.
                    'key'=>in_array($type,[self::ANNUAL_BUDGET,self::PROCUREMENT_PROJECTS],true) ? $rowNumber.':'.mb_substr($key,0,130) : mb_substr($key,0,150),
                    'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status'=>$status,
                    'errors'=>$rowErrors === [] ? null : json_encode($rowErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
            foreach ($rowErrors as $error) $errors[] = ['row'=>$rowNumber] + $error;
        }

        if ($valid + $invalid === 0) $errors[] = ['row'=>1, 'column'=>'*', 'code'=>'empty_file'];
        $status = $errors === [] ? 'ready' : 'invalid';
        $summary = [
            'valid'=>$valid,
            'invalid'=>$invalid,
            'type'=>$type,
            'options'=>$options,
            'totals'=>array_map(static fn(float $value): string => number_format($value, 4, '.', ''), $totals),
        ];
        $this->db->execute(
            'UPDATE import_batches SET status=:status,summary=:summary,errors=:errors,updated_at=SYSUTCDATETIME() WHERE id=:id',
            [
                'status'=>$status,
                'summary'=>json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'errors'=>$errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id'=>$batchId,
            ]
        );
        $this->audit->record($this->userId, 'import.previewed', 'App\Models\ImportBatch', $batchId, [], $summary + ['status'=>$status]);

        return $summary + ['status'=>$status];
    }

    public function confirm(array $batch, array $confirmOptions = []): array
    {
        $type = (string) $batch['type'];
        if ($type === self::APPROVED_BUDGET) return $this->confirmApprovedBudget($batch, $confirmOptions);
        if (!in_array($type, self::types(), true)) throw new HttpException(422, 'Видот на пакетот не е поддржан.');
        if ((string) $batch['status'] !== 'ready') throw new HttpException(422, 'Пакетот има критични грешки или е објавен.');
        if ($type !== self::ENFORCEMENT_EXECUTIONS && $this->db->scalar("SELECT TOP 1 1 FROM import_rows WHERE import_batch_id=:id AND status='invalid'", ['id'=>$batch['id']])) {
            throw new HttpException(422, 'Пакетот има невалидни редови.');
        }

        $summary = json_decode((string) ($batch['summary'] ?? '{}'), true) ?: [];
        $options = is_array($summary['options'] ?? null) ? $summary['options'] : [];

        return $this->db->transaction(function () use ($batch, $type, $options): array {
            $locked = $this->db->one('SELECT * FROM import_batches WITH (UPDLOCK,HOLDLOCK) WHERE id=:id', ['id'=>$batch['id']]);
            if (!$locked || (string) $locked['status'] !== 'ready') throw new HttpException(409, 'Пакетот е изменет или е објавен.');

            $rows = $this->db->all("SELECT * FROM import_rows WHERE import_batch_id=:id AND status='valid' ORDER BY id", ['id'=>$batch['id']]);
            $posted = 0;
            $created = 0;
            $updated = 0;
            $unchanged = 0;
            $versionId = in_array($type, [self::CAPITAL_BUDGET, self::ANNUAL_BUDGET], true) ? $this->officialBudgetVersion((int) ($options['fiscal_year'] ?? 0)) : null;
            $execution = $type === self::FREEBALANCE_SUMMARY ? new BudgetExecution($this->db) : null;
            $linkContext = $execution?->linkContext((int) ($options['fiscal_year'] ?? 0));

            foreach ($rows as $row) {
                $data = json_decode((string) $row['payload'], true);
                if (!is_array($data)) throw new HttpException(422, 'Содржината на увозот не е валидна.');

                if ($type === self::PROJECTS) {
                    $result = $this->postProject($data, (string) $batch['id']);
                } elseif ($type === self::PROCUREMENT_PROJECTS) {
                    $result = $this->postProcurementProject($data, (string) $batch['id']);
                } elseif ($type === self::CAPITAL_BUDGET) {
                    $result = $this->postCapitalBudget($data, (int) $versionId, (int) $options['fiscal_year'], (string) $options['as_of_date'], (string) $batch['id']);
                } elseif ($type === self::ANNUAL_BUDGET) {
                    if ($this->validateAnnualBudget($data) !== []) throw new HttpException(422, 'Годишната ставка повеќе не е валидна.');
                    $result = $this->postAnnualBudget($data, (int) $versionId, (string) $batch['id']);
                } elseif ($type === self::ENFORCEMENT_EXECUTIONS) {
                    $result = $this->postEnforcementExecution($data, (string) $batch['id']);
                } elseif ($type === self::FREEBALANCE_SUMMARY) {
                    $result = $this->postFreeBalanceLine($data, (array) $linkContext, (string) $options['as_of_date'], (string) $batch['id']);
                } else {
                    $result = $this->postExpenditure($data, (string) $batch['id']);
                }

                $posted++;
                $created += $result === 'created' ? 1 : 0;
                $updated += $result === 'updated' ? 1 : 0;
                $unchanged += $result === 'unchanged' ? 1 : 0;
                $this->db->execute("UPDATE import_rows SET status='posted',updated_at=SYSUTCDATETIME() WHERE id=:id", ['id'=>$row['id']]);
            }

            $result = ['status'=>'posted', 'posted'=>$posted, 'created'=>$created, 'updated'=>$updated, 'unchanged'=>$unchanged, 'type'=>$type];
            if ($execution !== null) $result += $this->finishFreeBalance($execution, (int) $options['fiscal_year'], (string) $options['as_of_date'], (string) $batch['id']);
            $this->db->execute("UPDATE import_batches SET status='posted',confirmed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id", ['id'=>$batch['id']]);
            $this->audit->record($this->userId, 'import.posted', 'App\Models\ImportBatch', (string) $batch['id'], [], $result);
            return $result;
        });
    }

    private function previewEnforcementExecutions(string $batchId, string $path, int $fiscalYear, array $options): array
    {
        $aliases = [
            'coding_block'=>['blloku_kodues'], 'document_type'=>['lloji_i_kd'], 'document_date'=>['data_e_kd'],
            'beneficiary'=>['perfituesi_klienti_emri_i_bankes'], 'subledger_type'=>['lloji_i_nen_librit_te_llogarive'],
            'obligation_reference'=>['obligim_zotim'], 'period_label'=>['prd'],
            'journal_voucher_number'=>['numri_i_kuponit_te_ditarit'], 'origin'=>['origjina'],
            'source_document_number'=>['numri_i_dokumentit_burimor'],
            'journal_classification'=>['klasifikimi_i_kuponit_te_ditarit'], 'description'=>['pershkrim','pershkrimi'],
            'debit_amount'=>['debi'], 'credit_amount'=>['kredi'],
        ];
        $required = ['document_date','journal_voucher_number','description','debit_amount','credit_amount'];
        $rows = iterator_to_array((new SpreadsheetReader())->rows($path), false);
        $headerMap = null; $headerRow = 0; $fatal = []; $stagedErrors = []; $seen = [];
        $valid = 0; $invalid = 0; $unchanged = 0;
        $sourceDebit = 0.0; $sourceCredit = 0.0; $linkedDebit = 0.0; $linkedCredit = 0.0;
        $controlDebit = null; $controlCredit = null; $controlNet = null;
        $budgetLines = $this->budgetLinesForYear($fiscalYear);

        foreach ($rows as $zeroIndex=>$values) {
            $rowNumber = $zeroIndex + 1;
            $values = array_map(static fn($value): string => trim((string)$value), $values);
            if ($headerMap === null) {
                $normalized = array_map([self::class,'normalizeHeader'], $values);
                $candidate = [];
                foreach ($aliases as $canonical=>$names) foreach ($normalized as $index=>$header) if (in_array($header,$names,true)) { $candidate[$canonical]=$index; break; }
                if (count(array_intersect($required,array_keys($candidate))) === count($required)) { $headerMap=$candidate; $headerRow=$rowNumber; }
                continue;
            }
            if (!array_filter($values, static fn(string $value): bool => $value !== '')) continue;
            $joined = self::normalizeHeader(implode(' ', $values));
            $read = static fn(string $key): string => isset($headerMap[$key]) ? trim((string)($values[$headerMap[$key]] ?? '')) : '';
            if (str_contains($joined, 'totali_per_valute')) {
                $debit = self::normalizeAmount($read('debit_amount')); $credit = self::normalizeAmount($read('credit_amount'));
                if (str_contains($joined, 'debi_kredi')) $controlNet = is_numeric($debit) ? (float)$debit - (is_numeric($credit)?(float)$credit:0.0) : null;
                else { $controlDebit=is_numeric($debit)?(float)$debit:null; $controlCredit=is_numeric($credit)?(float)$credit:null; }
                continue;
            }

            $debit = self::normalizeAmount($read('debit_amount')); $credit = self::normalizeAmount($read('credit_amount'));
            $date = self::normalizeDate($read('document_date')); $voucher = $read('journal_voucher_number');
            if ($voucher === '' && $debit === '' && $credit === '') continue;
            $sourceDebit += is_numeric($debit)?(float)$debit:0.0; $sourceCredit += is_numeric($credit)?(float)$credit:0.0;
            $description = $read('description'); $codingBlock = $read('coding_block');
            $match = $this->matchBudgetLine($description, $codingBlock, $budgetLines);
            $sourceDocument = $read('source_document_number');
            $identity = [$fiscalYear,self::key($voucher),self::key($codingBlock),self::key($sourceDocument)];
            if ($sourceDocument === '') $identity = array_merge($identity,[self::key($date),self::key($read('beneficiary')),$debit,$credit]);
            $sourceKey = 'enf:'.$fiscalYear.':'.hash('sha256',implode('|',$identity));
            $payload = [
                'source_row_number'=>$rowNumber,'fiscal_year'=>$fiscalYear,'currency'=>'MKD','coding_block_original'=>$codingBlock,
                'extracted_budget_code'=>$match['extracted_code'],'budget_line_id'=>$match['budget_line_id'],
                'document_type'=>$read('document_type'),'document_date'=>$date,'beneficiary'=>$read('beneficiary'),
                'subledger_type'=>$read('subledger_type'),'obligation_reference'=>$read('obligation_reference'),'period_label'=>$read('period_label'),
                'journal_voucher_number'=>$voucher,'origin'=>$read('origin'),'source_document_number'=>$sourceDocument,
                'journal_classification'=>$read('journal_classification'),'description'=>$description,
                'debit_amount'=>$debit,'credit_amount'=>$credit,'external_row_key'=>$sourceKey,
            ];
            // The physical Excel row may change between exports; it is provenance,
            // not part of the financial identity used for idempotent re-imports.
            $hashPayload = $payload;
            unset($hashPayload['source_row_number'],$hashPayload['budget_line_id'],$hashPayload['extracted_budget_code']);
            $payload['source_hash'] = hash('sha256',json_encode($hashPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $rowErrors = [];
            if ($voucher === '') $rowErrors[]=['column'=>'journal_voucher_number','code'=>'required'];
            if (!self::validDate($date) || (int)substr($date,0,4) !== $fiscalYear) $rowErrors[]=['column'=>'document_date','code'=>'invalid_date'];
            if (!self::nonNegativeAmount($debit) || !self::nonNegativeAmount($credit) || ((float)$debit<=0 && (float)$credit<=0)) $rowErrors[]=['column'=>'amount','code'=>'invalid_amount'];
            if ($match['status'] !== 'linked') $rowErrors[]=['column'=>'budget_code','code'=>$match['status']];
            if (isset($seen[$sourceKey])) $rowErrors[]=['column'=>'external_row_key','code'=>'duplicate_in_file'];
            $seen[$sourceKey]=true;
            $existing=$this->db->one('SELECT source_hash FROM enforcement_executions WHERE source_key=:key',['key'=>$sourceKey]);
            if ($existing && !hash_equals((string)$existing['source_hash'],$payload['source_hash'])) $rowErrors[]=['column'=>'external_row_key','code'=>'existing_conflict'];
            if ($existing && hash_equals((string)$existing['source_hash'],$payload['source_hash'])) { $payload['_existing_status']='unchanged'; $unchanged++; }
            $status=$rowErrors===[]?'valid':'invalid';
            if($status==='valid'){ $valid++; $linkedDebit+=(float)$debit; $linkedCredit+=(float)$credit; } else $invalid++;
            $this->db->execute('INSERT INTO import_rows(import_batch_id,external_row_key,payload,status,errors,created_at,updated_at) VALUES(:batch,:key,:payload,:status,:errors,SYSUTCDATETIME(),SYSUTCDATETIME())',[
                'batch'=>$batchId,'key'=>'row-'.$rowNumber.':'.$sourceKey,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'status'=>$status,
                'errors'=>$rowErrors?json_encode($rowErrors,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,
            ]);
            foreach($rowErrors as$error)$stagedErrors[]=['row'=>$rowNumber]+$error;
        }
        if ($headerMap===null) $fatal[]=['row'=>1,'column'=>'*','code'=>'missing_header'];
        if ($valid+$invalid===0) $fatal[]=['row'=>$headerRow?:1,'column'=>'*','code'=>'empty_file'];
        if ($controlDebit===null || $controlCredit===null || $controlNet===null) $fatal[]=['row'=>count($rows),'column'=>'totals','code'=>'missing_control_totals'];
        elseif (abs($sourceDebit-$controlDebit)>0.01 || abs($sourceCredit-$controlCredit)>0.01 || abs(($sourceDebit-$sourceCredit)-$controlNet)>0.01) $fatal[]=['row'=>count($rows),'column'=>'totals','code'=>'control_totals_mismatch'];
        $status=$fatal===[]&&$valid>0?'ready':'invalid';
        $summary=['valid'=>$valid,'invalid'=>$invalid,'unchanged'=>$unchanged,'type'=>self::ENFORCEMENT_EXECUTIONS,'options'=>['fiscal_year'=>$fiscalYear,'source_file_name'=>(string)($options['source_file_name']??''),'source_sha256'=>(string)($options['source_sha256']??'')],
            'totals'=>['debit'=>number_format($sourceDebit,4,'.',''),'credit'=>number_format($sourceCredit,4,'.',''),'net'=>number_format($sourceDebit-$sourceCredit,4,'.',''),
                'linked_debit'=>number_format($linkedDebit,4,'.',''),'linked_credit'=>number_format($linkedCredit,4,'.',''),'linked_net'=>number_format($linkedDebit-$linkedCredit,4,'.',''),
                'control_debit'=>$controlDebit===null?null:number_format($controlDebit,4,'.',''),'control_credit'=>$controlCredit===null?null:number_format($controlCredit,4,'.',''),'control_net'=>$controlNet===null?null:number_format($controlNet,4,'.','')],
            'header_row'=>$headerRow];
        $this->db->execute('UPDATE import_batches SET status=:status,summary=:summary,errors=:errors,updated_at=SYSUTCDATETIME() WHERE id=:id',[
            'status'=>$status,'summary'=>json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'errors'=>($fatal||$stagedErrors)?json_encode(array_merge($fatal,$stagedErrors),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,'id'=>$batchId]);
        $this->audit->record($this->userId,'enforcement_import.previewed','App\\Models\\ImportBatch',$batchId,[],$summary+['status'=>$status]);
        return $summary+['status'=>$status];
    }

    private function previewFreeBalance(string $batchId, string $path, array $options): array
    {
        $report = FreeBalanceReport::parse(iterator_to_array((new SpreadsheetReader())->rows($path), false));
        $year = $report['fiscal_year'];
        $asOf = (string) $options['as_of_date'];
        $fatal = $report['errors'];
        if ($year !== null && (int) substr($asOf, 0, 4) !== $year) $fatal[] = ['row'=>1, 'column'=>'as_of_date', 'code'=>'as_of_outside_fiscal_year'];
        if ($year !== null) {
            $latest = $this->db->scalar('SELECT CONVERT(varchar(10),MAX(as_of_date),23) FROM budget_execution_lines WHERE fiscal_year=:year', ['year'=>$year]);
            if (is_string($latest) && $asOf < $latest) $fatal[] = ['row'=>1, 'column'=>'as_of_date', 'code'=>'older_than_current'];
        }
        $context = (new BudgetExecution($this->db))->linkContext((int) $year);
        $valid = 0; $invalid = 0; $unchanged = 0; $linkedProjects = []; $unmapped = []; $stagedErrors = [];
        foreach ($report['lines'] as $line) {
            $rowErrors = $line['errors'];
            unset($line['errors']);
            $hashPayload = $line;
            unset($hashPayload['source_row_number']);
            $line['source_hash'] = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $links = BudgetExecution::links($line, $context);
            $line += ['directorate_id'=>$links['directorate_id'], 'project_id'=>$links['project_id'], 'budget_line_id'=>$links['budget_line_id'], 'warnings'=>$links['warnings'], 'external_row_key'=>$line['source_key']];
            if (in_array('program_unmapped', $links['warnings'], true)) $unmapped[$line['program_code']] = $line['program_name'];
            if ($links['project_id'] !== null) $linkedProjects[$links['project_id']] = true;
            $existing = $this->db->one('SELECT source_hash FROM budget_execution_lines WHERE source_key=:key', ['key'=>$line['source_key']]);
            if ($existing && hash_equals((string) $existing['source_hash'], $line['source_hash'])) { $line['_existing_status'] = 'unchanged'; $unchanged++; }
            $status = $rowErrors === [] ? 'valid' : 'invalid';
            $status === 'valid' ? $valid++ : $invalid++;
            $this->db->execute('INSERT INTO import_rows(import_batch_id,external_row_key,payload,status,errors,created_at,updated_at) VALUES(:batch,:key,:payload,:status,:errors,SYSUTCDATETIME(),SYSUTCDATETIME())', [
                'batch'=>$batchId, 'key'=>mb_substr('row-'.$line['source_row_number'].':'.$line['source_key'], 0, 150),
                'payload'=>json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status'=>$status,
                'errors'=>$rowErrors === [] ? null : json_encode($rowErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            foreach ($rowErrors as $error) $stagedErrors[] = ['row'=>$line['source_row_number']] + $error;
        }
        $status = $fatal === [] && $invalid === 0 && $valid > 0 ? 'ready' : 'invalid';
        ksort($unmapped);
        $summary = [
            'valid'=>$valid, 'invalid'=>$invalid, 'unchanged'=>$unchanged, 'type'=>self::FREEBALANCE_SUMMARY,
            'options'=>['fiscal_year'=>$year, 'as_of_date'=>$asOf, 'source_file_name'=>(string) ($options['source_file_name'] ?? ''), 'source_sha256'=>(string) ($options['source_sha256'] ?? '')],
            'totals'=>['budgeted'=>$report['totals']['allocated'], 'expenditures'=>$report['totals']['actual']] + $report['totals'],
            'control'=>$report['control'], 'linked_projects'=>count($linkedProjects), 'unmapped_programs'=>$unmapped,
        ];
        $this->db->execute('UPDATE import_batches SET status=:status,summary=:summary,errors=:errors,updated_at=SYSUTCDATETIME() WHERE id=:id', [
            'status'=>$status, 'summary'=>json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'errors'=>($fatal || $stagedErrors) ? json_encode(array_merge($fatal, $stagedErrors), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, 'id'=>$batchId,
        ]);
        $this->audit->record($this->userId, 'freebalance_import.previewed', 'App\\Models\\ImportBatch', $batchId, [], $summary + ['status'=>$status]);
        return $summary + ['status'=>$status];
    }

    private function previewApprovedBudget(string $batchId, string $path, array $options): array
    {
        $year = (int) $options['fiscal_year'];
        $hints = [];
        foreach ($this->db->all("SELECT project_code,MAX(program_code) program_code FROM budget_execution_lines WHERE fiscal_year=:year AND project_code NOT IN ('00000','00040') GROUP BY project_code HAVING COUNT(DISTINCT program_code)=1", ['year'=>$year]) as $row) $hints[(string) $row['project_code']] = (string) $row['program_code'];
        return $this->stageApprovedBudget($batchId, ApprovedBudgetWorkbook::parse((new SpreadsheetReader())->sheets($path), $hints), $options);
    }

    /**
     * Stages an approved-budget batch from lines saved earlier (the payload of a previous import), for rebuilding the
     * budget when the original workbook file is no longer available. The batch is then confirmed like any other.
     */
    public function previewApprovedBudgetFromLines(string $batchId, array $lines, array $options): array
    {
        if (!isset($options['fiscal_year']) || (int) $options['fiscal_year'] < 2020 || (int) $options['fiscal_year'] > 2100) throw new HttpException(422, 'Фискалната година не е валидна.');
        $totals = array_fill_keys(['government_grant', 'own_revenue', 'carryover_revenue', 'loan_financing', 'total', 'expenditures'], 0.0);
        foreach ($lines as $line) {
            foreach (['government_grant', 'own_revenue', 'carryover_revenue', 'loan_financing'] as $key) $totals[$key] += (float) ($line[$key] ?? 0);
            $totals['total'] += (float) ($line['total_budgeted'] ?? 0); $totals['expenditures'] += (float) ($line['actual'] ?? 0);
        }
        $parsed = ['fiscal_year'=>(int) $options['fiscal_year'], 'as_of'=>$options['as_of_date'] ?? null, 'lines'=>array_values($lines), 'errors'=>[], 'warnings'=>[], 'controls'=>[],
            'totals'=>array_map(static fn(float $v): string => number_format($v, 4, '.', ''), $totals)];
        return $this->stageApprovedBudget($batchId, $parsed, $options);
    }

    private function stageApprovedBudget(string $batchId, array $parsed, array $options): array
    {
        $year = (int) $options['fiscal_year'];
        $fatal = array_map(static fn(array $e): array => ['row'=>$e['sheet'].'!'.$e['row'], 'column'=>$e['column'], 'code'=>$e['code']] + (isset($e['detail']) ? ['detail'=>$e['detail']] : []), $parsed['errors']);
        if ($parsed['fiscal_year'] !== null && $parsed['fiscal_year'] !== $year) $fatal[] = ['row'=>'totali!1', 'column'=>'fiscal_year', 'code'=>'fiscal_year_mismatch'];
        $asOf = ($options['as_of_date'] ?? '') !== '' ? (string) $options['as_of_date'] : ($parsed['as_of'] ?? null);
        if ($asOf !== null && (int) substr($asOf, 0, 4) !== $year) $fatal[] = ['row'=>'totali', 'column'=>'as_of_date', 'code'=>'as_of_outside_fiscal_year'];

        // Programs become visible on the program → directorate map so Finance can assign them before confirming.
        $programs = [];
        foreach ($parsed['lines'] as $line) $programs[$line['program_code']] = $line['program_name'];
        foreach ($programs as $code => $name) {
            $this->db->execute('INSERT INTO freebalance_program_directorates(program_code,program_name,directorate_id) SELECT :code,:name,NULL WHERE NOT EXISTS (SELECT 1 FROM freebalance_program_directorates WHERE program_code=:code_check)', ['code'=>(string) $code, 'name'=>mb_substr((string) $name, 0, 250), 'code_check'=>(string) $code]);
        }
        $plan = $this->approvedBudgetPlan($parsed['lines'], $year, false);
        $valid = 0; $invalid = 0; $stagedErrors = [];
        foreach ($plan['lines'] as $line) {
            $status = $line['errors'] === [] ? 'valid' : 'invalid';
            $status === 'valid' ? $valid++ : $invalid++;
            $payload = ['external_row_key'=>$line['key'], 'action'=>$line['action'], 'code'=>$line['code'], 'previous_code'=>$line['previous_code'], 'matched_by'=>$line['matched_by'], 'category'=>$line['category'],
                'program_code'=>$line['program_code'], 'description'=>$line['name'], 'total_budgeted'=>$line['total_budgeted'], 'government_grant'=>$line['government_grant'], 'own_revenue'=>$line['own_revenue'],
                'carryover_revenue'=>$line['carryover_revenue'], 'loan_financing'=>$line['loan_financing'], 'remaining'=>$line['remaining'], 'changes'=>$line['changes'], 'line'=>self::approvedLine($line)];
            $this->db->execute('INSERT INTO import_rows(import_batch_id,external_row_key,payload,status,errors,created_at,updated_at) VALUES(:batch,:key,:payload,:status,:errors,SYSUTCDATETIME(),SYSUTCDATETIME())', [
                'batch'=>$batchId, 'key'=>mb_substr($line['key'], 0, 150), 'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status'=>$status,
                'errors'=>$line['errors'] === [] ? null : json_encode($line['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            foreach ($line['errors'] as $error) $stagedErrors[] = ['row'=>$line['code']] + $error;
        }
        // Rows with errors do not block the batch on their own: confirmation can post the valid rows and leave those
        // budget lines untouched ("skip_invalid"). Workbook-level errors (totals, sheets, year) always block.
        $status = $fatal === [] && $valid > 0 ? 'ready' : 'invalid';
        $directorates = [];
        foreach ($this->db->all('SELECT id,name FROM directorates') as $row) $directorates[(int) $row['id']] = (string) $row['name'];
        $summary = [
            'valid'=>$valid, 'invalid'=>$invalid, 'fatal'=>count($fatal), 'type'=>self::APPROVED_BUDGET,
            'options'=>['fiscal_year'=>$year, 'as_of_date'=>$asOf, 'source_file_name'=>(string) ($options['source_file_name'] ?? ''), 'source_sha256'=>(string) ($options['source_sha256'] ?? '')],
            'totals'=>['budgeted'=>$parsed['totals']['total']] + $parsed['totals'], 'plan'=>$plan['summary'], 'controls'=>$parsed['controls'], 'warnings'=>$parsed['warnings'],
            'orphans'=>array_map(static fn(array $o): array => $o + ['directorate'=>$directorates[$o['directorate_id']] ?? ''], $plan['orphans']),
            'unmapped_programs'=>array_values(array_unique(array_map(static fn(array $l): string => $l['program_code'], array_filter($plan['lines'], static fn(array $l): bool => $l['directorate_id'] === null)))),
            'fingerprint'=>ApprovedBudgetSync::fingerprint($plan),
        ];
        $this->db->execute('UPDATE import_batches SET status=:status,summary=:summary,errors=:errors,updated_at=SYSUTCDATETIME() WHERE id=:id', [
            'status'=>$status, 'summary'=>json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'errors'=>($fatal || $stagedErrors) ? json_encode(array_merge($fatal, $stagedErrors), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, 'id'=>$batchId,
        ]);
        $this->audit->record($this->userId, 'approved_budget_import.previewed', 'App\\Models\\ImportBatch', $batchId, [], ['plan'=>$plan['summary'], 'totals'=>$summary['totals'], 'status'=>$status]);
        return $summary + ['status'=>$status];
    }

    /** Resolves directorates through the program map and plans the changes against the official version of the year. */
    private function approvedBudgetPlan(array $lines, int $year, bool $lock, ?int $versionId = null): array
    {
        $map = (new BudgetExecution($this->db))->programMap();
        foreach ($lines as &$line) $line['directorate_id'] = $map[(string) $line['program_code']]['directorate_id'] ?? null;
        unset($line);
        $versionId ??= (int) ($this->db->scalar("SELECT TOP 1 bv.id FROM budget_versions bv JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bc.base_year=:year AND bc.name=N'Буџет одобрен од Советот на општината' AND bv.scenario='approved' AND bv.is_official=1 ORDER BY bv.version_no DESC", ['year'=>$year]) ?: 0);
        $hint = $lock ? ' WITH (UPDLOCK,HOLDLOCK)' : '';
        $existing = $versionId > 0 ? $this->db->all("SELECT bl.id,bl.directorate_id,bl.code,bl.name,bl.budget_category,bl.source_section_code,bl.program_code,bl.approved_line_key,bl.own_revenue,bl.government_grant,bl.health_grant,bl.education_grant,bl.loan_financing,bl.carryover_revenue,COALESCE((SELECT SUM(ba.current_amount) FROM budget_allocations ba WHERE ba.budget_line_id=bl.id),0) allocated FROM budget_lines bl{$hint} WHERE bl.budget_version_id=:version", ['version'=>$versionId]) : [];
        return ApprovedBudgetSync::plan($lines, $existing);
    }

    private static function approvedLine(array $line): array
    {
        return array_intersect_key($line, array_flip(['key','code','category','economic_code','program_code','program_name','project_code','name','funds','government_grant','own_revenue','carryover_revenue','loan_financing','total_budgeted','actual','obligations','commitments','remaining','released','staff_count','court_projects','sources']));
    }

    /** Applies the previewed plan in one transaction; refuses when the budget lines changed since the preview. */
    private function confirmApprovedBudget(array $batch, array $confirmOptions): array
    {
        if ((string) $batch['status'] !== 'ready') throw new HttpException(422, 'Пакетот има критични грешки или е објавен.');
        $summary = json_decode((string) ($batch['summary'] ?? '{}'), true) ?: [];
        $options = is_array($summary['options'] ?? null) ? $summary['options'] : [];
        $year = (int) ($options['fiscal_year'] ?? 0);
        $asOf = $options['as_of_date'] ?? null;
        return $this->db->transaction(function () use ($batch, $summary, $year, $asOf, $confirmOptions): array {
            $locked = $this->db->one('SELECT * FROM import_batches WITH (UPDLOCK,HOLDLOCK) WHERE id=:id', ['id'=>$batch['id']]);
            if (!$locked || (string) $locked['status'] !== 'ready') throw new HttpException(409, 'Пакетот е изменет или е објавен.');
            $rows = $this->db->all('SELECT id,payload FROM import_rows WHERE import_batch_id=:id ORDER BY id', ['id'=>$batch['id']]);
            $lines = [];
            foreach ($rows as $row) { $payload = json_decode((string) $row['payload'], true); if (!is_array($payload['line'] ?? null)) throw new HttpException(422, 'Содржината на увозот не е валидна.'); $lines[] = $payload['line']; }
            $versionId = $this->officialBudgetVersion($year);
            $plan = $this->approvedBudgetPlan($lines, $year, true, $versionId);
            if (!hash_equals((string) ($summary['fingerprint'] ?? ''), ApprovedBudgetSync::fingerprint($plan))) throw new HttpException(409, 'Буџетските ставки или врската на програмите се променија по прегледот. Повторно прикачете ја датотеката за нов преглед.');
            if ($plan['summary']['invalid'] > 0 && empty($confirmOptions['skip_invalid'])) throw new HttpException(422, $plan['summary']['invalid'].' редови имаат грешки. Поправете ги и повторно прикачете ја датотеката, или изберете „Објави ги валидните редови и прескокни ги редовите со грешки“.');
            $result = ['status'=>'posted', 'type'=>self::APPROVED_BUDGET, 'posted'=>0, 'created'=>0, 'updated'=>0, 'unchanged'=>0, 'code_changes'=>0, 'retired'=>0, 'orphans_kept'=>0, 'skipped'=>[]];
            foreach ($plan['lines'] as $line) {
                if ($line['errors'] !== []) {
                    // Skipped rows leave the matched budget line (if any) exactly as it is.
                    $result['skipped'][] = ['key'=>$line['key'], 'code'=>$line['code'], 'budget_line_id'=>$line['budget_line_id'], 'errors'=>array_column($line['errors'], 'code')];
                    continue;
                }
                $this->applyApprovedLine($line, $versionId, $asOf, (string) $batch['id']);
                $result['posted']++; $result[$line['action'] === 'create' ? 'created' : ($line['action'] === 'update' ? 'updated' : 'unchanged')]++;
                if (isset($line['changes']['code'])) $result['code_changes']++;
            }
            foreach ($plan['orphans'] as $orphan) {
                if (empty($confirmOptions['retire_orphans']) || (float) $orphan['allocated'] > 0) { $result['orphans_kept']++; continue; }
                $before = $this->db->one('SELECT own_revenue,government_grant,health_grant,education_grant,loan_financing,carryover_revenue FROM budget_lines WITH (UPDLOCK,HOLDLOCK) WHERE id=:id', ['id'=>$orphan['id']]) ?: [];
                $this->db->execute('UPDATE budget_lines SET own_revenue=0,government_grant=0,health_grant=0,education_grant=0,loan_financing=0,carryover_revenue=0,updated_at=SYSUTCDATETIME() WHERE id=:id', ['id'=>$orphan['id']]);
                $this->audit->record($this->userId, 'budget_line.retired_by_approved_budget', 'App\\Models\\BudgetLine', $orphan['id'], $before, ['code'=>$orphan['code']], 'batch '.$batch['id']);
                $result['retired']++;
            }
            $this->db->execute("UPDATE import_rows SET status=CASE WHEN status='invalid' THEN 'skipped' ELSE 'posted' END,updated_at=SYSUTCDATETIME() WHERE import_batch_id=:id", ['id'=>$batch['id']]);
            $this->db->execute("UPDATE import_batches SET status='posted',confirmed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id", ['id'=>$batch['id']]);
            // FreeBalance lines link to budget lines by code; re-link them and let a newer FreeBalance balance win.
            $execution = new BudgetExecution($this->db);
            $result['freebalance_relinked'] = $execution->relink($year);
            $latest = $this->db->scalar('SELECT CONVERT(varchar(10),MAX(as_of_date),23) FROM budget_execution_lines WHERE fiscal_year=:year', ['year'=>$year]);
            if (is_string($latest)) $execution->refreshBudgetLineSnapshots($year, $latest);
            $this->audit->record($this->userId, 'import.posted', 'App\\Models\\ImportBatch', (string) $batch['id'], [], $result);
            return $result;
        });
    }

    private function applyApprovedLine(array $line, int $versionId, ?string $asOf, string $batchId): void
    {
        $a = $line['target_amounts'];
        $params = ['directorate'=>$line['directorate_id'], 'code'=>$line['code'], 'name'=>$line['name'], 'economic'=>$line['economic_code'], 'category'=>$line['category'],
            'own'=>$a['own_revenue'], 'government'=>$a['government_grant'], 'health'=>$a['health_grant'], 'education'=>$a['education_grant'], 'loan'=>$a['loan_financing'], 'carryover'=>$a['carryover_revenue'],
            'program'=>$line['program_code'], 'project'=>$line['project_code'], 'key'=>$line['key'], 'staff'=>$line['staff_count'] ?? null];
        if ($line['action'] === 'create') {
            $id = $this->db->insertId("INSERT INTO budget_lines(budget_version_id,directorate_id,program_id,code,name,economic_code,funding_source,budget_category,own_revenue,government_grant,health_grant,education_grant,loan_financing,carryover_revenue,source_section_code,program_code,project_code,approved_line_key,staff_count,currency,created_at,updated_at) VALUES(:version,:directorate,NULL,:code,:name,:economic,'combined',:category,:own,:government,:health,:education,:loan,:carryover,:section,:program,:project,:key,:staff,'MKD',SYSUTCDATETIME(),SYSUTCDATETIME())", $params + ['version'=>$versionId, 'section'=>$line['program_code']]);
            $this->audit->record($this->userId, 'budget_line.approved_imported', 'App\\Models\\BudgetLine', $id, [], self::approvedLine($line), 'batch '.$batchId);
        } else {
            $id = (int) $line['budget_line_id'];
            if ($line['action'] === 'update') {
                $this->db->execute('UPDATE budget_lines SET directorate_id=:directorate,code=:code,name=:name,economic_code=:economic,budget_category=:category,own_revenue=:own,government_grant=:government,health_grant=:health,education_grant=:education,loan_financing=:loan,carryover_revenue=:carryover,source_section_code=COALESCE(source_section_code,:section),program_code=:program,project_code=:project,approved_line_key=:key,staff_count=:staff,updated_at=SYSUTCDATETIME() WHERE id=:id', $params + ['section'=>$line['program_code'], 'id'=>$id]);
                $this->audit->record($this->userId, 'budget_line.approved_updated_by_import', 'App\\Models\\BudgetLine', $id, array_map(static fn(array $c): mixed => $c['from'], $line['changes']), array_map(static fn(array $c): mixed => $c['to'], $line['changes']) + ['matched_by'=>$line['matched_by']], 'batch '.$batchId);
                if (isset($line['changes']['code'])) {
                    $this->db->execute('INSERT INTO budget_line_code_changes(budget_line_id,old_code,new_code,matched_by,import_batch_id,changed_by) VALUES(:line,:old,:new,:by,:batch,:user)', ['line'=>$id, 'old'=>(string) $line['changes']['code']['from'], 'new'=>$line['code'], 'by'=>(string) $line['matched_by'], 'batch'=>$batchId, 'user'=>$this->userId]);
                }
            }
        }
        if ($asOf !== null) {
            $this->db->execute('UPDATE budget_lines SET imported_available_amount=:available,imported_available_as_of=:as_of,updated_at=SYSUTCDATETIME() WHERE id=:id AND (imported_available_as_of IS NULL OR imported_available_as_of<=:as_of_check)', [
                'available'=>(float) $line['remaining'] > 0 ? $line['remaining'] : '0', 'as_of'=>$asOf, 'id'=>$id, 'as_of_check'=>$asOf,
            ]);
        }
    }

    /** Upserts one FreeBalance detail line and its dated snapshot. Links are resolved again at posting time. */
    private function postFreeBalanceLine(array $data, array $context, string $asOf, string $batchId): string
    {
        $links = BudgetExecution::links($data, $context);
        $existing = $this->db->one('SELECT * FROM budget_execution_lines WITH (UPDLOCK,HOLDLOCK) WHERE source_key=:key', ['key'=>$data['source_key']]);
        if ($existing && (string) $existing['as_of_date'] > $asOf) throw new HttpException(409, 'Постои понов FreeBalance извештај за ставката '.$data['source_key'].'.');
        $params = [
            'hash'=>$data['source_hash'], 'year'=>$data['fiscal_year'],
            'fund_code'=>$data['fund_code'], 'fund_name'=>$data['fund_name'], 'organization_code'=>$data['organization_code'], 'organization_name'=>$data['organization_name'],
            'program_code'=>$data['program_code'], 'program_name'=>$data['program_name'], 'economic_code'=>$data['economic_code'], 'economic_name'=>$data['economic_name'],
            'project_code'=>$data['project_code'], 'project_name'=>$data['project_name'], 'function_code'=>$data['function_code'], 'function_name'=>$data['function_name'],
            'directorate'=>$links['directorate_id'], 'project'=>$links['project_id'], 'budget_line'=>$links['budget_line_id'],
            'allocated'=>$data['allocated'], 'actual'=>$data['actual'], 'obligations'=>$data['obligations'], 'unobligated'=>$data['unobligated'], 'commitments'=>$data['commitments'], 'balance'=>$data['balance'],
            'as_of'=>$asOf, 'batch'=>$batchId, 'row'=>$data['source_row_number'],
        ];
        if ($existing) {
            $this->db->execute('UPDATE budget_execution_lines SET source_hash=:hash,fiscal_year=:year,fund_code=:fund_code,fund_name=:fund_name,organization_code=:organization_code,organization_name=:organization_name,program_code=:program_code,program_name=:program_name,economic_code=:economic_code,economic_name=:economic_name,project_code=:project_code,project_name=:project_name,function_code=:function_code,function_name=:function_name,directorate_id=:directorate,project_id=:project,budget_line_id=:budget_line,allocated_amount=:allocated,actual_amount=:actual,obligations_amount=:obligations,unobligated_amount=:unobligated,commitments_amount=:commitments,balance_amount=:balance,as_of_date=:as_of,in_latest_report=1,import_batch_id=:batch,source_row_number=:row,updated_at=SYSUTCDATETIME() WHERE id=:id', $params + ['id'=>$existing['id']]);
            $lineId = (int) $existing['id'];
            $result = hash_equals((string) $existing['source_hash'], (string) $data['source_hash']) ? 'unchanged' : 'updated';
        } else {
            $lineId = $this->db->insertId('INSERT INTO budget_execution_lines(source_key,source_hash,fiscal_year,fund_code,fund_name,organization_code,organization_name,program_code,program_name,economic_code,economic_name,project_code,project_name,function_code,function_name,directorate_id,project_id,budget_line_id,allocated_amount,actual_amount,obligations_amount,unobligated_amount,commitments_amount,balance_amount,as_of_date,in_latest_report,import_batch_id,source_row_number) VALUES(:key,:hash,:year,:fund_code,:fund_name,:organization_code,:organization_name,:program_code,:program_name,:economic_code,:economic_name,:project_code,:project_name,:function_code,:function_name,:directorate,:project,:budget_line,:allocated,:actual,:obligations,:unobligated,:commitments,:balance,:as_of,1,:batch,:row)', $params + ['key'=>$data['source_key']]);
            $result = 'created';
        }
        $snapshot = ['line'=>$lineId, 'as_of'=>$asOf, 'allocated'=>$data['allocated'], 'actual'=>$data['actual'], 'obligations'=>$data['obligations'], 'unobligated'=>$data['unobligated'], 'commitments'=>$data['commitments'], 'balance'=>$data['balance'], 'batch'=>$batchId];
        $this->db->execute('MERGE budget_execution_snapshots AS target USING (SELECT :line AS line_id,:as_of AS as_of_date) AS source ON target.budget_execution_line_id=source.line_id AND target.as_of_date=source.as_of_date
            WHEN MATCHED THEN UPDATE SET allocated_amount=:allocated,actual_amount=:actual,obligations_amount=:obligations,unobligated_amount=:unobligated,commitments_amount=:commitments,balance_amount=:balance,import_batch_id=:batch
            WHEN NOT MATCHED THEN INSERT (budget_execution_line_id,as_of_date,allocated_amount,actual_amount,obligations_amount,unobligated_amount,commitments_amount,balance_amount,import_batch_id) VALUES (:line_insert,:as_of_insert,:allocated_insert,:actual_insert,:obligations_insert,:unobligated_insert,:commitments_insert,:balance_insert,:batch_insert);',
            $snapshot + ['line_insert'=>$lineId, 'as_of_insert'=>$asOf, 'allocated_insert'=>$data['allocated'], 'actual_insert'=>$data['actual'], 'obligations_insert'=>$data['obligations'], 'unobligated_insert'=>$data['unobligated'], 'commitments_insert'=>$data['commitments'], 'balance_insert'=>$data['balance'], 'batch_insert'=>$batchId]);
        if ($result !== 'unchanged') {
            $before = $existing ? array_intersect_key($existing, array_flip(['allocated_amount','actual_amount','obligations_amount','unobligated_amount','commitments_amount','balance_amount','as_of_date'])) : [];
            $this->audit->record($this->userId, 'freebalance_line.'.$result, 'BudgetExecutionLine', $lineId, $before, array_intersect_key($data, array_flip([...FreeBalanceReport::AMOUNTS, 'source_key'])) + ['as_of_date'=>$asOf], 'batch '.$batchId);
        }
        return $result;
    }

    /** Lines missing from the newest report are flagged, programs are registered for mapping, linked budget lines get the new balance. */
    private function finishFreeBalance(BudgetExecution $execution, int $year, string $asOf, string $batchId): array
    {
        $absent = $this->db->execute('UPDATE budget_execution_lines SET in_latest_report=0,updated_at=SYSUTCDATETIME() WHERE fiscal_year=:year AND import_batch_id<>:batch AND in_latest_report=1', ['year'=>$year, 'batch'=>$batchId]);
        $this->db->execute('INSERT INTO freebalance_program_directorates(program_code,program_name,directorate_id) SELECT bel.program_code,MAX(bel.program_name),NULL FROM budget_execution_lines bel WHERE bel.import_batch_id=:batch AND NOT EXISTS (SELECT 1 FROM freebalance_program_directorates m WHERE m.program_code=bel.program_code) GROUP BY bel.program_code', ['batch'=>$batchId]);
        $snapshots = $execution->refreshBudgetLineSnapshots($year, $asOf);
        return ['absent_lines'=>$absent, 'budget_lines_refreshed'=>$snapshots];
    }

    private function budgetLinesForYear(int $year): array
    {
        return $this->db->all("SELECT bl.id,bl.code,bl.name,d.code directorate_code FROM budget_lines bl JOIN budget_versions bv ON bv.id=bl.budget_version_id JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id JOIN directorates d ON d.id=bl.directorate_id WHERE bv.is_official=1 AND bc.base_year=:year",['year'=>$year]);
    }

    private function matchBudgetLine(string $description,string $codingBlock,array $budgetLines): array
    {
        $descriptionCandidates=[];$codingCandidates=[];
        if(preg_match_all('/\b\d+(?:\s*\/\s*\d+){1,}\b/u',$description,$found))foreach($found[0] as$value)$descriptionCandidates[]=self::key($value);
        foreach(preg_split('/[^A-Z0-9]+/u',mb_strtoupper(Cyrillic::fold($description)))?:[]as$part)if(strlen($part)>=4&&preg_match('/\d/',$part))$descriptionCandidates[]=self::key($part);
        if($codingBlock!==''){$codingCandidates[]=self::key($codingBlock);foreach(preg_split('/\s*\/\s*/',$codingBlock)?:[] as$part)if(strlen(self::key($part))>=4)$codingCandidates[]=self::key($part);}
        $descriptionCandidates=array_values(array_unique(array_filter($descriptionCandidates)));$codingCandidates=array_values(array_unique(array_filter($codingCandidates)));$candidates=array_values(array_unique(array_merge($descriptionCandidates,$codingCandidates)));
        $matches=[];$descriptionMatches=[];$codingMatches=[];foreach($budgetLines as$line){$key=self::key((string)$line['code']);if(in_array($key,$candidates,true))$matches[(int)$line['id']]=$line;if(in_array($key,$descriptionCandidates,true))$descriptionMatches[(int)$line['id']]=$line;if(in_array($key,$codingCandidates,true))$codingMatches[(int)$line['id']]=$line;}
        if(count($descriptionMatches)===1&&count($codingMatches)===1&&array_key_first($descriptionMatches)!==array_key_first($codingMatches))return['status'=>'budget_code_mismatch','budget_line_id'=>null,'extracted_code'=>(string)array_values($descriptionMatches)[0]['code']];
        if(count($matches)===1){$line=array_values($matches)[0];return['status'=>'linked','budget_line_id'=>(int)$line['id'],'extracted_code'=>(string)$line['code']];}
        return['status'=>$candidates===[]?'budget_code_missing':(count($matches)>1?'budget_code_ambiguous':'budget_code_unknown'),'budget_line_id'=>null,'extracted_code'=>$candidates[0]??''];
    }

    private function postEnforcementExecution(array $data,string $batchId): string
    {
        $existing=$this->db->one('SELECT id,source_hash FROM enforcement_executions WITH (UPDLOCK,HOLDLOCK) WHERE source_key=:key',['key'=>$data['external_row_key']]);
        if($existing){if(hash_equals((string)$existing['source_hash'],(string)$data['source_hash']))return'unchanged';throw new HttpException(409,'Редот на извршувањето е во конфликт со постојниот увоз.');}
        $params=['source_key'=>$data['external_row_key'],'source_hash'=>$data['source_hash'],'batch'=>$batchId,'row'=>$data['source_row_number'],'year'=>$data['fiscal_year'],'currency'=>$data['currency'],
            'coding'=>$data['coding_block_original']?:null,'extracted'=>$data['extracted_budget_code'],'budget_line'=>$data['budget_line_id'],'document_type'=>$data['document_type']?:null,
            'document_date'=>$data['document_date'],'beneficiary'=>$data['beneficiary']?:null,'subledger'=>$data['subledger_type']?:null,'obligation'=>$data['obligation_reference']?:null,
            'period'=>$data['period_label']?:null,'voucher'=>$data['journal_voucher_number'],'origin'=>$data['origin']?:null,'source_document'=>$data['source_document_number']?:null,
            'classification'=>$data['journal_classification']?:null,'description'=>$data['description']?:null,'debit'=>$data['debit_amount'],'credit'=>$data['credit_amount'],'user'=>$this->userId];
        $id=$this->db->insertId('INSERT INTO enforcement_executions(source_key,source_hash,import_batch_id,source_row_number,fiscal_year,currency,coding_block_original,extracted_budget_code,budget_line_id,document_type,document_date,beneficiary,subledger_type,obligation_reference,period_label,journal_voucher_number,origin,source_document_number,journal_classification,description,debit_amount,credit_amount,financial_reviewed_by,financial_reviewed_at) VALUES(:source_key,:source_hash,:batch,:row,:year,:currency,:coding,:extracted,:budget_line,:document_type,:document_date,:beneficiary,:subledger,:obligation,:period,:voucher,:origin,:source_document,:classification,:description,:debit,:credit,:user,SYSUTCDATETIME())',$params);
        $this->audit->record($this->userId,'enforcement_execution.imported','EnforcementExecution',$id,[],$data,'batch '.$batchId);
        return'created';
    }

    private function headerMap(string $type, array $headers): array
    {
        $aliases = match ($type) {
            self::ANNUAL_BUDGET => [
                'external_row_key'=>['id_importit'], 'directorate'=>['drejtoria_kod'],
                'category'=>['kategoria'], 'section'=>['kodi_seksionit'], 'code'=>['numri_kodi'],
                'description'=>['pershkrimi'], 'government_grant'=>['granti_qeveritar'],
                'education_grant'=>['granti_per_arsim'], 'health_grant'=>['granti_per_shendetesi'],
                'loan_financing'=>['financim_huamarrjes'], 'own_revenue'=>['buxheti_vetanak'],
                'total_budgeted'=>['totali'],
            ],
            self::PROJECTS => [
                'external_row_key'=>['external_row_key'], 'directorate_code'=>['directorate_code'],
                'project_code'=>['project_code'], 'project_name'=>['project_name'],
                'has_budget_impact'=>['has_budget_impact'], 'starts_at'=>['starts_at'],
                'ends_at'=>['ends_at'], 'objective'=>['objective'],
            ],
            self::PROCUREMENT_PROJECTS => [
                'contract_code'=>['kodi_kontrate','kodi_kontrates','contract_code'],
                'contract_title'=>['titulli_kontrate','titulli_i_kontrates','contract_title'],
                'contract_type'=>['llojin_e_kontrate','lloji_i_kontrates','contract_type'],
                'contracting_party'=>['pala_kontraktuese','contracting_party'],
                'managers'=>['managers','menaxheret'], 'supervisors'=>['supervisors','mbikeqyresit'],
                'procurement_code'=>['prokurim','kodi_i_prokurimit','kodi_prokurimit','procurement_code'],
                'procurement_name'=>['emertimi_i_prokurimit','emertimi_prokurimit','procurement_name'],
                'currency'=>['valuta','currency'], 'procurement_status'=>['statusi','status'],
                'total'=>['totali','total'], 'economic_codes'=>['kodiekonomik','kodi_ekonomik','economic_codes'],
                'project_codes'=>['kodiprojektit','kodi_projektit','project_codes'],
                'duration_months'=>['kohezgjatja_muaj','kohezgjatja','duration_months'],
                'realized_amount'=>['realizuar_euro','realizuar','realized_amount'],
                'paid_amount'=>['paguar_euro','paguar','paid_amount'],
                'enforced_amount'=>['permbaruar_euro','permbaruar','enforced_amount'],
                'total_paid_amount'=>['totalpaguar_euro','totalpaguar','total_paid_amount'],
            ],
            self::CAPITAL_BUDGET => [
                'external_row_key'=>['external_row_key','externalrowkey','row_key'],
                'code'=>['kodi_i_projektit','kodi','code','project_code','budget_line_code'],
                'description'=>['kapitalet','pershkrimi','description','project_description','name'],
                'total_budgeted'=>['buxheti','totalbudgeted','total_budgeted','budgeted','total_budget'],
                'total_available'=>['mbetja','totalavailable','total_available','available','remaining'],
                'directorate'=>['drejtoria','directorate','directorate_code','directorate_name'],
                'own_revenue'=>['own_revenue','ownrevenue','te_hyra_vetanake'],
                'government_grant'=>['government_grant','governmentgrant','grant_qeveritar'],
                'health_grant'=>['health_grant','healthgrant','grant_shendetesi'],
                'education_grant'=>['education_grant','educationgrant','grant_arsim'],
            ],
            self::EXPENDITURES => [
                'external_row_key'=>['external_row_key','externalrowkey','row_key'],
                'directorate_code'=>['directorate_code','directorate','drejtoria'],
                'budget_line_code'=>['budget_line_code','line_code','project_code','kodi_i_projektit'],
                'project_code'=>['linked_project_code','project','projekti'],
                'commitment_reference'=>['commitment_reference','commitment','zotimi','referenca_e_zotimit'],
                'source_reference'=>['source_reference','document_reference','invoice_reference','referenca_burimore','invoice'],
                'amount'=>['amount','shuma','expenditure','shpenzimi'],
                'accounting_date'=>['accounting_date','date','data_kontabel','data'],
            ],
        };
        $required = match ($type) {
            self::PROJECTS, self::ANNUAL_BUDGET => array_keys($aliases),
            self::PROCUREMENT_PROJECTS => ['procurement_code'],
            self::CAPITAL_BUDGET => ['code','description','total_budgeted','total_available','directorate'],
            self::EXPENDITURES => ['commitment_reference','source_reference','amount','accounting_date'],
        };

        $normalized = array_map([self::class, 'normalizeHeader'], $headers);
        $map = [];
        foreach ($aliases as $canonical=>$names) {
            foreach ($normalized as $index=>$header) {
                if (in_array($header, $names, true)) { $map[$canonical] = $index; break; }
            }
        }
        $errors = [];
        foreach ($required as $column) if (!array_key_exists($column, $map)) $errors[] = ['column'=>$column, 'code'=>'missing_header'];
        return [$map, $errors];
    }

    private function payload(string $type, array $values, array $map, int $rowNumber, array $options): array
    {
        $read = static fn(string $key): string => isset($map[$key]) ? trim((string) ($values[$map[$key]] ?? '')) : '';
        if ($type === self::ANNUAL_BUDGET) {
            $data = [];
            foreach (array_keys($map) as $key) $data[$key] = $read($key);
            foreach ([...self::FUNDING_SOURCES, 'loan_financing', 'total_budgeted'] as $key) $data[$key] = self::normalizeAmount($read($key));
            $data['category'] = match (self::normalizeHeader($read('category'))) {
                'paga_dhe_meditje', 'wages' => 'wages',
                'mallra_dhe_sherbime', 'goods_services' => 'goods_services',
                'shpenzime_komunale', 'utilities' => 'utilities',
                'shpenzime_kapitale', 'capital_investments' => 'capital_investments',
                'subvencione_dhe_transfere', 'subsidies' => 'subsidies',
                default => '',
            };
            $data['source_import_id'] = $read('external_row_key');
            $data['external_row_key'] = ($options['fiscal_year'] ?? '').':'.self::key($read('directorate')).':'.self::key($read('code'));
            $data['total_available'] = null;
            return $data;
        }
        if ($type === self::PROJECTS) {
            $payload = [];
            foreach (array_keys($map) as $key) $payload[$key] = $read($key);
            return $payload;
        }
        if ($type === self::PROCUREMENT_PROJECTS) {
            $payload = [];
            foreach (array_keys($map) as $key) $payload[$key] = $read($key);
            $payload['procurement_code'] = trim($payload['procurement_code'] ?? '');
            if ($payload['procurement_code'] === '') return ['_skip'=>true];
            foreach (['total','realized_amount','paid_amount','enforced_amount','total_paid_amount'] as $key) {
                $payload[$key] = ($payload[$key] ?? '') === '' ? null : self::normalizeAmount((string)$payload[$key]);
            }
            $payload['duration_months'] = ($payload['duration_months'] ?? '') === '' ? null : trim((string)$payload['duration_months']);
            $payload['external_row_key'] = self::key($payload['procurement_code']).':'.self::key((string)($payload['contract_code']??''));
            return $payload;
        }
        if ($type === self::CAPITAL_BUDGET) {
            $code = $read('code');
            $directorate = $read('directorate');
            $budgeted = self::normalizeAmount($read('total_budgeted'));
            $available = self::normalizeAmount($read('total_available'));
            $sources = [];
            $hasDetailedSources = false;
            foreach (self::FUNDING_SOURCES as $source) {
                $raw = $read($source);
                $sources[$source] = self::normalizeAmount($raw === '' ? '0' : $raw);
                if ($raw !== '') $hasDetailedSources = true;
            }
            if (!$hasDetailedSources && in_array($options['default_funding_source'] ?? '', self::FUNDING_SOURCES, true)) {
                $sources[(string) $options['default_funding_source']] = $budgeted;
            }
            return [
                'external_row_key'=>$read('external_row_key') ?: (string) ($options['fiscal_year'] ?? '').':'.self::key($directorate).':'.self::key($code),
                'code'=>$code, 'description'=>$read('description'), 'total_budgeted'=>$budgeted,
                'total_available'=>$available, 'directorate'=>$directorate,
            ] + $sources;
        }

        $commitment = $read('commitment_reference');
        $source = $read('source_reference');
        return [
            'external_row_key'=>$read('external_row_key') ?: ($source !== '' ? $source : 'row-'.$rowNumber),
            'directorate_code'=>$read('directorate_code'),
            'budget_line_code'=>$read('budget_line_code'),
            'project_code'=>$read('project_code'),
            'commitment_reference'=>$commitment,
            'source_reference'=>$source,
            'amount'=>self::normalizeAmount($read('amount')),
            'accounting_date'=>self::normalizeDate($read('accounting_date')),
        ];
    }

    private function validateAnnualBudget(array $data): array
    {
        // Reuse scope/amount checks without inventing an available-balance snapshot.
        $base = $data;
        $base['total_available'] = '0';
        $base['total_budgeted'] = (string) ((float) $data['total_budgeted'] - (float) $data['loan_financing']);
        $errors = array_values(array_filter($this->validateCapitalBudget($base), static fn(array $error): bool => !in_array($error['column'], ['total_budgeted'], true)));
        if (!self::nonNegativeAmount((string) $data['total_budgeted'])) $errors[] = ['column'=>'total_budgeted','code'=>'invalid_amount'];
        if (!self::nonNegativeAmount((string) $data['loan_financing'])) $errors[] = ['column'=>'loan_financing','code'=>'invalid_amount'];
        $sum = 0.0;
        foreach ([...self::FUNDING_SOURCES, 'loan_financing'] as $source) $sum += (float) $data[$source];
        if (abs($sum - (float) $data['total_budgeted']) > 0.0001) $errors[] = ['column'=>'total_budgeted','code'=>'funding_sources_do_not_reconcile'];
        if (!in_array($data['category'], ['wages','goods_services','utilities','capital_investments','subsidies'], true)) $errors[] = ['column'=>'category','code'=>'unknown_category'];
        if ($data['section'] === '' || mb_strlen($data['section']) > 60) $errors[] = ['column'=>'section','code'=>'invalid_section'];
        if ($data['source_import_id'] === '' || mb_strlen($data['source_import_id']) > 150) $errors[] = ['column'=>'source_import_id','code'=>'invalid_id'];
        return $errors;
    }

    private function postAnnualBudget(array $data, int $versionId, string $batchId): string
    {
        $directorate = $this->directorate($data['directorate']);
        if (!$directorate || !$this->scope->allowsFinancial($this->userId, (int) $directorate['id'])) throw new HttpException(403, 'Немате финансиска улога за секторот.');
        $line = $this->db->one("SELECT * FROM budget_lines WITH (UPDLOCK,HOLDLOCK) WHERE budget_version_id=:version AND directorate_id=:directorate AND code=:code AND funding_source='combined'", ['version'=>$versionId,'directorate'=>$directorate['id'],'code'=>$data['code']]);
        $economic = ['wages'=>'PAGA','goods_services'=>'MALLRA_SHERBIME','utilities'=>'SHPENZIME_KOMUNALE','capital_investments'=>'INVESTIME_KAPITALE','subsidies'=>'SUBVENCIONE'];
        $params = ['name'=>$data['description'],'economic'=>$economic[$data['category']],'category'=>$data['category'],'own'=>$data['own_revenue'],'government'=>$data['government_grant'],'health'=>$data['health_grant'],'education'=>$data['education_grant'],'loan'=>$data['loan_financing'],'section'=>$data['section']];
        if ($line) {
            $allocated = (float) $this->db->scalar('SELECT COALESCE(SUM(current_amount),0) FROM budget_allocations WITH (UPDLOCK,HOLDLOCK) WHERE budget_line_id=:line', ['line'=>$line['id']]);
            if ((float) $data['total_budgeted'] + 0.00001 < $allocated) throw new HttpException(422, 'Увезениот збир е помал од постојните распределби: '.$data['code']);
            $this->db->execute('UPDATE budget_lines SET name=:name,economic_code=:economic,budget_category=:category,own_revenue=:own,government_grant=:government,health_grant=:health,education_grant=:education,loan_financing=:loan,source_section_code=:section,updated_at=SYSUTCDATETIME() WHERE id=:id', $params + ['id'=>$line['id']]);
            $lineId = (int) $line['id'];
        } else {
            $lineId = $this->db->insertId("INSERT INTO budget_lines(budget_version_id,directorate_id,code,name,economic_code,funding_source,budget_category,own_revenue,government_grant,health_grant,education_grant,loan_financing,source_section_code,currency,created_at,updated_at) VALUES(:version,:directorate,:code,:name,:economic,'combined',:category,:own,:government,:health,:education,:loan,:section,'MKD',SYSUTCDATETIME(),SYSUTCDATETIME())", $params + ['version'=>$versionId,'directorate'=>$directorate['id'],'code'=>$data['code']]);
        }
        $this->audit->record($this->userId, 'annual_budget.imported', 'App\\Models\\BudgetLine', $lineId, $line ?: [], $data, 'batch '.$batchId);
        return $line ? 'updated' : 'created';
    }

    private function validateProject(array $data): array
    {
        $errors = [];
        foreach (['external_row_key','directorate_code','project_code','project_name','has_budget_impact'] as $column) {
            if (($data[$column] ?? '') === '') $errors[] = ['column'=>$column, 'code'=>'required'];
        }
        $directorate = $this->directorate((string) ($data['directorate_code'] ?? ''));
        if (!$directorate) $errors[] = ['column'=>'directorate_code', 'code'=>'unknown_code'];
        elseif (!$this->scope->allowsDirectorate($this->userId, (int) $directorate['id'])) $errors[] = ['column'=>'directorate_code', 'code'=>'out_of_scope'];
        if (($data['starts_at'] ?? '') && ($data['ends_at'] ?? '') && $data['ends_at'] < $data['starts_at']) $errors[] = ['column'=>'ends_at', 'code'=>'invalid_date_range'];
        return $errors;
    }

    private function validateProcurementProject(array $data): array
    {
        $errors = [];
        if (($data['procurement_code'] ?? '') === '' || mb_strlen((string)$data['procurement_code']) > 120) $errors[]=['column'=>'procurement_code','code'=>'required'];
        foreach (['total','realized_amount','paid_amount','enforced_amount','total_paid_amount'] as $column) {
            if (($data[$column] ?? null) !== null && !self::nonNegativeAmount((string)$data[$column])) $errors[]=['column'=>$column,'code'=>'invalid_amount'];
        }
        if (($data['duration_months'] ?? null) !== null && !preg_match('/^\d{1,4}$/',(string)$data['duration_months'])) $errors[]=['column'=>'duration_months','code'=>'invalid_number'];
        return $errors;
    }

    private function postProcurementProject(array $data, string $batchId): string
    {
        $existing=$this->db->one('SELECT * FROM procurement_projects WITH (UPDLOCK,HOLDLOCK) WHERE procurement_code=:code AND ((contract_code=:contract) OR (contract_code IS NULL AND :contract_null IS NULL))',['code'=>$data['procurement_code'],'contract'=>($data['contract_code']??'')!==''?$data['contract_code']:null,'contract_null'=>($data['contract_code']??'')!==''?$data['contract_code']:null]);
        $fields=['contract_code','contract_title','contract_type','contracting_party','managers','supervisors','procurement_name','currency','procurement_status','total','economic_codes','project_codes','duration_months','realized_amount','paid_amount','enforced_amount','total_paid_amount'];
        $changed=[];$before=[];
        foreach($fields as$field){$new=$data[$field]??null;$old=$existing[$field]??null;if((string)$old!==(string)$new){$changed[$field]=$new;$before[$field]=$old;}}
        if(!$existing){$columns=implode(',',$fields);$marks=implode(',',array_map(static fn($field)=>':'.$field,$fields));$params=['procurement_code'=>$data['procurement_code']];foreach($fields as$field)$params[$field]=$data[$field]??null;$id=$this->db->insertId("INSERT INTO procurement_projects(procurement_code,{$columns}) VALUES(:procurement_code,{$marks})",$params);$result='created';}
        elseif(!$changed){return 'unchanged';}
        else{$sets=[];foreach($changed as$field=>$value)$sets[]=$field.'=:'.$field;$this->db->execute('UPDATE procurement_projects SET '.implode(',',$sets).',last_imported_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id',$changed+['id'=>$existing['id']]);$id=(int)$existing['id'];$result='updated';}
        $this->audit->record($this->userId,'procurement_project.'.$result,'ProcurementProject',$id,$before,$changed?:$data,'batch '.$batchId);
        return $result;
    }

    private function validateCapitalBudget(array $data): array
    {
        $errors = [];
        foreach (['external_row_key','code','description','total_budgeted','total_available','directorate'] as $column) {
            if (($data[$column] ?? '') === '') $errors[] = ['column'=>$column, 'code'=>'required'];
        }
        if (mb_strlen((string) ($data['code'] ?? '')) > 60) $errors[] = ['column'=>'code', 'code'=>'too_long'];
        if (mb_strlen((string) ($data['description'] ?? '')) > 250) $errors[] = ['column'=>'description', 'code'=>'too_long'];
        if (!self::positiveAmount((string) ($data['total_budgeted'] ?? ''))) $errors[] = ['column'=>'total_budgeted', 'code'=>'invalid_amount'];
        if (!self::nonNegativeAmount((string) ($data['total_available'] ?? ''))) $errors[] = ['column'=>'total_available', 'code'=>'invalid_amount'];
        if (self::nonNegativeAmount((string) ($data['total_available'] ?? '')) && self::positiveAmount((string) ($data['total_budgeted'] ?? '')) && (float) $data['total_available'] > (float) $data['total_budgeted'] + 0.00001) {
            $errors[] = ['column'=>'total_available', 'code'=>'exceeds_budget'];
        }
        $sourceTotal = 0.0;
        foreach (self::FUNDING_SOURCES as $source) {
            if (!self::nonNegativeAmount((string) ($data[$source] ?? ''))) $errors[] = ['column'=>$source, 'code'=>'invalid_amount'];
            $sourceTotal += (float) ($data[$source] ?? 0);
        }
        if (self::positiveAmount((string) ($data['total_budgeted'] ?? '')) && abs($sourceTotal - (float) $data['total_budgeted']) > 0.0001) {
            $errors[] = ['column'=>'total_budgeted', 'code'=>'funding_sources_do_not_reconcile'];
        }
        $directorate = $this->directorate((string) ($data['directorate'] ?? ''));
        if (!$directorate) $errors[] = ['column'=>'directorate', 'code'=>'unknown_directorate'];
        elseif (!$this->scope->allowsFinancial($this->userId, (int) $directorate['id'])) $errors[] = ['column'=>'directorate', 'code'=>'financial_scope_required'];
        return $errors;
    }

    private function validateExpenditure(array $data, array &$commitmentUsage): array
    {
        $errors = [];
        foreach (['external_row_key','commitment_reference','source_reference','amount','accounting_date'] as $column) {
            if (($data[$column] ?? '') === '') $errors[] = ['column'=>$column, 'code'=>'required'];
        }
        if (!self::positiveAmount((string) ($data['amount'] ?? ''))) $errors[] = ['column'=>'amount', 'code'=>'invalid_amount'];
        if (!self::validDate((string) ($data['accounting_date'] ?? ''))) $errors[] = ['column'=>'accounting_date', 'code'=>'invalid_date'];
        if (mb_strlen((string) ($data['source_reference'] ?? '')) > 120) $errors[] = ['column'=>'source_reference', 'code'=>'too_long'];
        if ($this->db->scalar('SELECT TOP 1 1 FROM expenditures WHERE source_reference=:reference', ['reference'=>$data['source_reference'] ?? ''])) {
            $errors[] = ['column'=>'source_reference', 'code'=>'already_posted'];
        }

        $commitment = $this->commitmentContext((string) ($data['commitment_reference'] ?? ''));
        if (!$commitment) {
            $errors[] = ['column'=>'commitment_reference', 'code'=>'unknown_commitment'];
            return $errors;
        }
        if ((string) $commitment['status'] !== 'active') $errors[] = ['column'=>'commitment_reference', 'code'=>'commitment_not_active'];
        if (!$this->scope->allowsFinancial($this->userId, (int) $commitment['directorate_id'])) $errors[] = ['column'=>'directorate_code', 'code'=>'financial_scope_required'];
        foreach (['directorate_code','budget_line_code','project_code'] as $column) {
            if (($data[$column] ?? '') !== '' && self::key((string) $data[$column]) !== self::key((string) ($commitment[$column] ?? ''))) {
                $errors[] = ['column'=>$column, 'code'=>'does_not_match_commitment'];
            }
        }
        $used = (float) ($commitmentUsage[(int) $commitment['commitment_id']] ?? 0);
        $remaining = (float) $commitment['amount'] - (float) $commitment['spent'] - $used;
        if (self::positiveAmount((string) ($data['amount'] ?? '')) && (float) $data['amount'] > $remaining + 0.00001) {
            $errors[] = ['column'=>'amount', 'code'=>'exceeds_commitment_balance'];
        }
        if ($errors === []) $commitmentUsage[(int) $commitment['commitment_id']] = $used + (float) $data['amount'];
        return $errors;
    }

    private function postProject(array $data, string $batchId): string
    {
        $directorate = $this->directorate((string) $data['directorate_code']);
        if (!$directorate || !$this->scope->allowsDirectorate($this->userId, (int) $directorate['id'])) throw new HttpException(403, 'Сектор надвор од опсегот.');
        $project = $this->db->one('SELECT * FROM projects WITH (UPDLOCK,HOLDLOCK) WHERE directorate_id=:directorate AND code=:code', ['directorate'=>$directorate['id'], 'code'=>$data['project_code']]);
        $params = ['directorate'=>$directorate['id'], 'code'=>$data['project_code'], 'name'=>$data['project_name'], 'objective'=>$data['objective'] ?: null, 'starts'=>$data['starts_at'] ?: null, 'ends'=>$data['ends_at'] ?: null, 'budget'=>filter_var($data['has_budget_impact'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0, 'user'=>$this->userId];
        if ($project) {
            // Re-import updates only the imported descriptive columns; status, progress and the responsible person stay untouched.
            $updateParams = $params; unset($updateParams['directorate'], $updateParams['code'], $updateParams['user']);
            $this->db->execute('UPDATE projects SET name=:name,objective=:objective,starts_at=:starts,ends_at=:ends,has_budget_impact=:budget,updated_at=SYSUTCDATETIME() WHERE id=:id', $updateParams + ['id'=>$project['id']]);
            $projectId = (int) $project['id'];
            $result = 'updated';
        } else {
            $projectId = $this->db->insertId("INSERT INTO projects (directorate_id,code,name,objective,starts_at,ends_at,has_budget_impact,status,progress,manager_id,created_at,updated_at) VALUES (:directorate,:code,:name,:objective,:starts,:ends,:budget,'draft',0,:user,SYSUTCDATETIME(),SYSUTCDATETIME())", $params);
            $result = 'created';
        }
        $before = $project ? array_intersect_key($project, array_flip(['directorate_id','code','name','objective','starts_at','ends_at','has_budget_impact','status','progress','manager_id'])) : [];
        $this->audit->record($this->userId, 'project.imported', 'App\Models\Project', $projectId, $before, $params, 'batch '.$batchId);
        return $result;
    }

    private function postCapitalBudget(array $data, int $versionId, int $year, string $asOfDate, string $batchId): string
    {
        $directorate = $this->directorate((string) $data['directorate']);
        if (!$directorate || !$this->scope->allowsFinancial($this->userId, (int) $directorate['id'])) throw new HttpException(403, 'Немате финансиска улога за секторот на ставката.');
        $line = $this->db->one("SELECT * FROM budget_lines WITH (UPDLOCK,HOLDLOCK) WHERE budget_version_id=:version AND directorate_id=:directorate AND code=:code AND funding_source='combined'", ['version'=>$versionId, 'directorate'=>$directorate['id'], 'code'=>$data['code']]);
        $params = [
            'version'=>$versionId, 'directorate'=>$directorate['id'], 'code'=>$data['code'], 'name'=>$data['description'],
            'own'=>$data['own_revenue'], 'government'=>$data['government_grant'], 'health'=>$data['health_grant'], 'education'=>$data['education_grant'],
            'available'=>$data['total_available'], 'as_of'=>$asOfDate,
        ];
        if ($line) {
            $allocated = (float) $this->db->scalar('SELECT COALESCE(SUM(current_amount),0) FROM budget_allocations WITH (UPDLOCK,HOLDLOCK) WHERE budget_line_id=:line', ['line'=>$line['id']]);
            if ((float) $data['total_budgeted'] + 0.00001 < $allocated) throw new HttpException(422, 'Увезениот збир за ставката '.$data['code'].' е помал од постојните распределби.');
            $this->db->execute("UPDATE budget_lines SET name=:name,economic_code='INVESTIME_KAPITALE',budget_category='capital_investments',own_revenue=:own,government_grant=:government,health_grant=:health,education_grant=:education,imported_available_amount=:available,imported_available_as_of=:as_of,updated_at=SYSUTCDATETIME() WHERE id=:id", $params + ['id'=>$line['id']]);
            $lineId = (int) $line['id'];
            $result = 'updated';
        } else {
            $lineId = $this->db->insertId("INSERT INTO budget_lines(budget_version_id,directorate_id,program_id,code,name,economic_code,funding_source,budget_category,own_revenue,government_grant,health_grant,education_grant,imported_available_amount,imported_available_as_of,currency,created_at,updated_at) VALUES(:version,:directorate,NULL,:code,:name,'INVESTIME_KAPITALE','combined','capital_investments',:own,:government,:health,:education,:available,:as_of,'MKD',SYSUTCDATETIME(),SYSUTCDATETIME())", $params);
            $result = 'created';
        }
        $this->audit->record($this->userId, 'capital_budget.imported', 'App\Models\BudgetLine', $lineId, $line ?: [], $data + ['fiscal_year'=>$year, 'as_of_date'=>$asOfDate], 'batch '.$batchId);
        return $result;
    }

    private function postExpenditure(array $data, string $batchId): string
    {
        $context = $this->commitmentContext((string) $data['commitment_reference'], true);
        if (!$context) throw new HttpException(422, 'Обврска '.$data['commitment_reference'].' не постои.');
        if ((string) $context['status'] !== 'active') throw new HttpException(422, 'Обврска '.$data['commitment_reference'].' не е активен.');
        if (!$this->scope->allowsFinancial($this->userId, (int) $context['directorate_id'])) throw new HttpException(403, 'Немате финансиска улога за расходот.');
        if ($this->db->scalar('SELECT TOP 1 1 FROM expenditures WITH (UPDLOCK,HOLDLOCK) WHERE source_reference=:reference', ['reference'=>$data['source_reference']])) throw new HttpException(409, 'Референца '.$data['source_reference'].' е веќе објавен.');
        $spent = (float) $this->db->scalar('SELECT COALESCE(SUM(amount),0) FROM expenditures WITH (UPDLOCK,HOLDLOCK) WHERE commitment_id=:commitment', ['commitment'=>$context['commitment_id']]);
        if ((float) $data['amount'] > (float) $context['amount'] - $spent + 0.00001) throw new HttpException(422, 'Расход '.$data['source_reference'].' го надминува салдото на обврската.');
        $id = $this->db->insertId('INSERT INTO expenditures(commitment_id,budget_allocation_id,source_reference,amount,accounting_date,created_by,created_at,updated_at) VALUES(:commitment,:allocation,:reference,:amount,:date,:user,SYSUTCDATETIME(),SYSUTCDATETIME())', [
            'commitment'=>$context['commitment_id'], 'allocation'=>$context['allocation_id'], 'reference'=>$data['source_reference'],
            'amount'=>$data['amount'], 'date'=>$data['accounting_date'], 'user'=>$this->userId,
        ]);
        $key = Request::uuid();
        $this->db->execute('INSERT INTO budget_transactions(budget_allocation_id,type,amount,direction,reference_type,reference_id,idempotency_key,posted_by,posted_at,created_at,updated_at) VALUES(:allocation,\'expenditure\',:amount,\'D\',\'App\\Models\\Expenditure\',:reference_id,:key,:user,SYSUTCDATETIME(),SYSUTCDATETIME(),SYSUTCDATETIME())', [
            'allocation'=>$context['allocation_id'], 'amount'=>$data['amount'], 'reference_id'=>$id, 'key'=>$key, 'user'=>$this->userId,
        ]);
        $this->audit->record($this->userId, 'expenditure.imported', 'App\Models\Expenditure', $id, [], $data + ['commitment_id'=>$context['commitment_id'], 'allocation_id'=>$context['allocation_id']], 'batch '.$batchId);
        return 'created';
    }

    private function officialBudgetVersion(int $year): int
    {
        if ($year < 2020 || $year > 2100) throw new HttpException(422, 'Фискалната година не е валидна.');
        $cycle = $this->db->one("SELECT TOP 1 id FROM budget_cycles WITH (UPDLOCK,HOLDLOCK) WHERE base_year=:year AND name=N'Буџет одобрен од Советот на општината'", ['year'=>$year]);
        if ($cycle) $cycleId = (int) $cycle['id'];
        else $cycleId = $this->db->insertId("INSERT INTO budget_cycles(name,base_year,horizon,status,starts_at,ends_at,created_at,updated_at) VALUES(N'Буџет одобрен од Советот на општината',:year,1,'approved',:starts,:ends,SYSUTCDATETIME(),SYSUTCDATETIME())", ['year'=>$year, 'starts'=>$year.'-01-01', 'ends'=>$year.'-12-31']);
        $version = $this->db->one("SELECT TOP 1 id FROM budget_versions WITH (UPDLOCK,HOLDLOCK) WHERE budget_cycle_id=:cycle AND scenario='approved' AND is_official=1 ORDER BY version_no DESC", ['cycle'=>$cycleId]);
        if ($version) return (int) $version['id'];
        return $this->db->insertId("INSERT INTO budget_versions(budget_cycle_id,scenario,version_no,status,is_official,approved_at,approved_by,created_at,updated_at) VALUES(:cycle,'approved',1,'approved',1,SYSUTCDATETIME(),:user,SYSUTCDATETIME(),SYSUTCDATETIME())", ['cycle'=>$cycleId, 'user'=>$this->userId]);
    }

    private function directorate(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') return null;
        $rows = $this->db->all('SELECT id,code,name,active FROM directorates WHERE active=1');
        $valueKey = self::key($value);
        $prefix = null;
        if (preg_match('/^([A-Za-z0-9_]+)\s*[-·]/u', $value, $match)) $prefix = self::key($match[1]);
        foreach ($rows as $row) {
            $code = self::key((string) $row['code']);
            $name = self::key((string) $row['name']);
            if ($valueKey === $code || $valueKey === $name || $valueKey === self::key($row['code'].' '.$row['name']) || ($prefix !== null && $prefix === $code)) return $row;
        }
        return null;
    }

    private function commitmentContext(string $reference, bool $lock = false): ?array
    {
        $hint = $lock ? ' WITH (UPDLOCK,HOLDLOCK)' : '';
        return $this->db->one("SELECT c.id commitment_id,c.reference,c.amount,c.status,ba.id allocation_id,ba.fiscal_year,bl.directorate_id,d.code directorate_code,bl.code budget_line_code,p.code project_code,COALESCE((SELECT SUM(e.amount) FROM expenditures e WHERE e.commitment_id=c.id),0) spent FROM commitments c{$hint} JOIN budget_allocations ba ON ba.id=c.budget_allocation_id JOIN budget_lines bl ON bl.id=ba.budget_line_id JOIN directorates d ON d.id=bl.directorate_id LEFT JOIN projects p ON p.id=ba.project_id WHERE c.reference=:reference", ['reference'=>$reference]);
    }

    private static function normalizeHeader(string $value): string
    {
        $value = Cyrillic::headerFold($value);
        $value = strtr($value, ['ë'=>'e','ç'=>'c']);
        return trim((string) preg_replace('/[^a-z0-9]+/u', '_', $value), '_');
    }

    private static function key(string $value): string
    {
        $value = mb_strtoupper(Cyrillic::fold(trim($value)));
        $value = strtr($value, ['Ë'=>'E','Ç'=>'C']);
        return (string) preg_replace('/[^A-Z0-9]+/u', '', $value);
    }

    private static function normalizeAmount(string $value): string
    {
        $value = trim(str_replace(["\xC2\xA0", ' '], '', $value));
        if ($value === '') return '';
        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) $value = str_replace('.', '', str_replace(',', '.', $value));
            else $value = str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        if (!is_numeric($value)) return $value;
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (preg_match('/^\d+(?:\.\d+)?$/', $value) && (float) $value > 1 && (float) $value < 100000) {
            return (new \DateTimeImmutable('1899-12-30'))->modify('+'.(int) floor((float) $value).' days')->format('Y-m-d');
        }
        foreach (['!Y-m-d','!d.m.Y','!d/m/Y','!m/d/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) return $date->format('Y-m-d');
        }
        return $value;
    }

    private static function positiveAmount(string $value): bool
    {
        return (bool) preg_match('/^\d{1,15}(?:\.\d{1,4})?$/', $value) && (float) $value > 0;
    }

    private static function nonNegativeAmount(string $value): bool
    {
        return (bool) preg_match('/^\d{1,15}(?:\.\d{1,4})?$/', $value) && (float) $value >= 0;
    }

    private static function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
