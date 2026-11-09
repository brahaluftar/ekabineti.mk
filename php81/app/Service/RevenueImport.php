<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Core\HttpException;

/**
 * Import of the daily revenue report: preview (revenue_import_rows) → confirmation (revenue_transactions).
 *
 * Row status in the preview: new (imported on confirmation), existing (already in the system: same report or an
 * overlapping one), duplicate_in_file (identical to an earlier row of the same file) and invalid (date or amount cannot
 * be read). Only new rows are imported.
 *
 * The uploaded file is read in the background, never in the user's request: batch status queued → processing → ready /
 * unchanged / invalid / failed. One report is processed at a time (file lock); a batch left in processing by a worker
 * that died is queued again by processQueue() (scheduler or bin/process-revenue-imports.php).
 */
final class RevenueImport
{
    public const TYPE = 'revenue_daily';

    /** Columns shared by revenue_import_rows and revenue_transactions. */
    public const FIELDS = [
        'raw_row_number','raw_amount','raw_beneficiary','raw_payer','raw_revenue_group','raw_description','raw_payment_date','raw_uniref',
        'payment_date','amount','revenue_group','uniref','uniref_valid','uniref_municipality','uniref_type','uniref_subtype','uniref_reference',
        'uniref_generic','payer_name','payer_type','identification_number','identification_type','invoice_number','taxpayer_number',
        'property_number','plate_number','case_reference','channel','category_id','classification_method','extraction','warnings',
    ];

    /** Staging columns and their SQL Server types (OPENJSON … WITH). */
    private const STAGING_TYPES = [
        'import_batch_id'=>'uniqueidentifier', 'source_row_number'=>'int', 'row_status'=>'nvarchar(20)', 'errors'=>'nvarchar(300)', 'source_key'=>'nvarchar(80)', 'content_key'=>'char(64)', 'loose_key'=>'char(64)', 'occurrence'=>'int',
        'raw_row_number'=>'nvarchar(300)', 'raw_amount'=>'nvarchar(300)', 'raw_beneficiary'=>'nvarchar(300)', 'raw_payer'=>'nvarchar(300)', 'raw_revenue_group'=>'nvarchar(300)',
        'raw_description'=>'nvarchar(2000)', 'raw_payment_date'=>'nvarchar(300)', 'raw_uniref'=>'nvarchar(300)', 'payment_date'=>'date', 'amount'=>'decimal(19,2)',
        'revenue_group'=>'char(2)', 'uniref'=>'nvarchar(20)', 'uniref_valid'=>'bit', 'uniref_municipality'=>'char(2)', 'uniref_type'=>'nvarchar(2)', 'uniref_subtype'=>'nchar(1)',
        'uniref_reference'=>'nvarchar(10)', 'uniref_generic'=>'bit', 'payer_name'=>'nvarchar(200)', 'payer_type'=>'nvarchar(20)', 'identification_number'=>'nvarchar(20)',
        'identification_type'=>'nvarchar(20)', 'invoice_number'=>'nvarchar(40)', 'taxpayer_number'=>'nvarchar(20)', 'property_number'=>'nvarchar(40)', 'plate_number'=>'nvarchar(15)',
        'case_reference'=>'nvarchar(40)', 'channel'=>'nvarchar(30)', 'category_id'=>'int', 'classification_method'=>'nvarchar(20)', 'extraction'=>'nvarchar(1000)', 'warnings'=>'nvarchar(300)',
    ];

    /** Largest report accepted (rows). */
    public const MAX_ROWS = 300000;

    public const ERRORS = ['invalid_date'=>'Датумот не може да се прочита', 'invalid_amount'=>'Износот недостасува или не е позитивен'];
    public const WARNINGS = [
        'invalid_uniref'=>'UNIREF недостасува или не е валиден', 'incomplete_uniref'=>'Нецелосен UNIREF', 'other_municipality'=>'UNIREF од друга општина',
        'possible_duplicate'=>'Можна двојна уплата (се разликува само во големи/мали букви или празни места)',
        'unclassified'=>'Видот на приходот не е препознаен',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Audit $audit,
        private readonly int $userId,
    ) {}

    /** Rules from revenue_category_rules (seeded from RevenueReport::RULES). */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->db->all('SELECT r.uniref_prefix,r.keyword,c.code,r.priority FROM revenue_category_rules r JOIN revenue_categories c ON c.id=r.category_id WHERE r.active=1 AND c.active=1') as $row) {
            $rules[] = [(string) $row['uniref_prefix'], $row['keyword'] === null || $row['keyword'] === '' ? null : (string) $row['keyword'], (string) $row['code'], (int) $row['priority']];
        }
        return $rules;
    }

    /**
     * Reads the report in chunks of 1 000 rows: parse, look up existing keys, write the chunk, keep only counters. Memory
     * stays flat for 300 000 rows; the progress is saved every 10 000 rows for the import page.
     *
     * @param array{duplicates?:string,source_file_name?:string,source_sha256?:string} $options duplicates: "once" (identical
     *        rows of the file are imported once) or "separate" (each identical row is a separate payment).
     */
    public function preview(string $batchId, iterable $sourceRows, array $options = []): array
    {
        $separate = ($options['duplicates'] ?? 'once') === 'separate';
        $categories = [];
        foreach ($this->db->all('SELECT id,code FROM revenue_categories') as $row) $categories[(string) $row['code']] = (int) $row['id'];
        if (!isset($categories['unclassified'])) throw new HttpException(500, 'Категориите на приходите недостасуваат. Извршете ги миграциите.');
        $codes = array_flip($categories);
        $optionsSummary = ['duplicates'=>$separate ? 'separate' : 'once', 'source_file_name'=>(string) ($options['source_file_name'] ?? ''), 'source_sha256'=>(string) ($options['source_sha256'] ?? '')];

        $counts = ['new'=>0, 'existing'=>0, 'duplicate_in_file'=>0, 'invalid'=>0];
        $amounts = ['new'=>0.0, 'existing'=>0.0, 'duplicate_in_file'=>0.0, 'invalid'=>0.0];
        $byCategory = []; $warnings = []; $minDate = null; $maxDate = null;
        $state = RevenueReport::newState();
        $chunk = []; $nextProgress = 10000;
        $flush = function () use (&$chunk, &$counts, &$amounts, &$byCategory, &$warnings, &$minDate, &$maxDate, $separate, $batchId, $categories): void {
            if ($chunk === []) return;
            $existing = $this->existingKeys(array_values(array_filter(array_column($chunk, 'source_key'))));
            $staged = [];
            foreach ($chunk as $row) {
                if ($row['errors'] !== []) $status = 'invalid';
                elseif (!$separate && $row['occurrence'] > 1) $status = 'duplicate_in_file';
                elseif (isset($existing[$row['source_key']])) $status = 'existing';
                else $status = 'new';
                $counts[$status]++;
                $amounts[$status] += $row['amount'] === '' ? 0.0 : (float) $row['amount'];
                if ($status === 'new') {
                    $code = $row['category_code'];
                    $byCategory[$code] = ['count'=>($byCategory[$code]['count'] ?? 0) + 1, 'amount'=>round(($byCategory[$code]['amount'] ?? 0.0) + (float) $row['amount'], 2)];
                    foreach ($row['warnings'] as $warning) $warnings[$warning] = ($warnings[$warning] ?? 0) + 1;
                    $minDate = $minDate === null || $row['payment_date'] < $minDate ? $row['payment_date'] : $minDate;
                    $maxDate = $maxDate === null || $row['payment_date'] > $maxDate ? $row['payment_date'] : $maxDate;
                }
                $staged[] = $this->stagedRow($batchId, $row, $status, $categories);
            }
            foreach (array_chunk($staged, $this->sqlServer() ? 1000 : 500) as $part) $this->insertStaged($part);
            $chunk = [];
        };
        foreach (RevenueReport::stream($sourceRows, $this->rules(), $state) as $row) {
            $chunk[] = $row;
            if (count($chunk) >= 1000) $flush();
            if ($state['read'] >= $nextProgress) {
                $nextProgress += 10000;
                $this->db->execute("UPDATE import_batches SET summary=:summary,updated_at=SYSUTCDATETIME() WHERE id=:id AND status='processing'",
                    ['summary'=>json_encode(['type'=>self::TYPE, 'rows'=>0, 'progress'=>$state['read'], 'options'=>$optionsSummary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id'=>$batchId]);
            }
        }
        $flush();
        unset($state['seen']);
        $fatal = RevenueReport::fatal($state);

        // Rows that differ only in case or spacing (same loose key, different content): possible double payment.
        $this->db->execute("UPDATE revenue_import_rows SET warnings=CASE WHEN warnings IS NULL THEN 'possible_duplicate' ELSE CONCAT(warnings,',possible_duplicate') END
            WHERE import_batch_id=:batch AND loose_key IN (SELECT loose_key FROM revenue_import_rows WHERE import_batch_id=:batch2 AND content_key IS NOT NULL GROUP BY loose_key HAVING COUNT(DISTINCT content_key)>1)",
            ['batch'=>$batchId, 'batch2'=>$batchId]);
        $flagged = (int) $this->db->scalar("SELECT COUNT(*) FROM revenue_import_rows WHERE import_batch_id=:batch AND row_status='new' AND warnings LIKE '%possible_duplicate%'", ['batch'=>$batchId]);
        if ($flagged > 0) $warnings['possible_duplicate'] = $flagged;
        unset($codes);

        $status = $fatal !== [] ? 'invalid' : ($counts['new'] > 0 ? 'ready' : 'unchanged');
        $summary = [
            'type'=>self::TYPE, 'rows'=>array_sum($counts), 'counts'=>$counts, 'amounts'=>array_map(static fn(float $v): string => number_format($v, 2, '.', ''), $amounts),
            'by_category'=>$byCategory, 'warnings'=>$warnings, 'date_from'=>$minDate, 'date_to'=>$maxDate, 'options'=>$optionsSummary,
            // Compatibility with the import history (valid / invalid columns).
            'valid'=>$counts['new'], 'invalid'=>$counts['invalid'],
        ];
        // Only a batch still being read is updated (a batch cancelled meanwhile stays cancelled).
        $this->db->execute("UPDATE import_batches SET status=:status,summary=:summary,errors=:errors,updated_at=SYSUTCDATETIME() WHERE id=:id AND status IN ('preview','processing')", [
            'status'=>$status, 'summary'=>json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'errors'=>$fatal ? json_encode($fatal, JSON_UNESCAPED_UNICODE) : null, 'id'=>$batchId,
        ]);
        $this->audit->record($this->userId, 'revenue_import.previewed', 'App\\Models\\ImportBatch', $batchId, [], ['status'=>$status, 'counts'=>$counts, 'options'=>$optionsSummary]);
        return $summary + ['status'=>$status, 'fatal'=>$fatal];
    }

    /** Registers an uploaded report; it is read by process() after the response has been sent. */
    public function queue(string $batchId, string $storageKey, array $options): void
    {
        $summary = ['type'=>self::TYPE, 'rows'=>0, 'options'=>[
            'duplicates'=>($options['duplicates'] ?? 'once') === 'separate' ? 'separate' : 'once',
            'source_file_name'=>(string) ($options['source_file_name'] ?? ''), 'source_sha256'=>(string) ($options['source_sha256'] ?? ''),
        ]];
        $this->db->execute("INSERT INTO import_batches(id,type,storage_key,status,summary,created_by,created_at,updated_at) VALUES(:id,:type,:key,'queued',:summary,:user,SYSUTCDATETIME(),SYSUTCDATETIME())",
            ['id'=>$batchId, 'type'=>self::TYPE, 'key'=>$storageKey, 'summary'=>json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user'=>$this->userId]);
        $this->audit->record($this->userId, 'revenue_import.queued', 'App\\Models\\ImportBatch', $batchId, [], $summary['options']);
    }

    /** The earlier batch that imported the same file (same SHA-256), if any. */
    public function sameFileImported(string $sha256, string $exceptBatchId): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) return null;
        foreach ($this->db->all("SELECT id,summary,confirmed_at FROM import_batches WHERE type=:type AND status='posted' AND id<>:id ORDER BY confirmed_at DESC", ['type'=>self::TYPE, 'id'=>$exceptBatchId]) as $row) {
            $summary = json_decode((string) $row['summary'], true) ?: [];
            if (($summary['options']['source_sha256'] ?? '') === $sha256) return $row;
        }
        return null;
    }

    /**
     * Reads one queued report and writes its preview. Claims the batch first (queued → processing), so two workers never
     * read the same file; waits up to $waitSeconds for another report to finish. Any failure leaves the batch "failed"
     * with its reason and no preview rows.
     */
    public function process(string $batchId, int $waitSeconds = 300): string
    {
        $lock = self::lock($waitSeconds);
        if ($lock === null) return 'busy';
        self::raiseMemoryLimit();
        try {
            if ($this->db->execute("UPDATE import_batches SET status='processing',updated_at=SYSUTCDATETIME() WHERE id=:id AND type=:type AND status='queued'", ['id'=>$batchId, 'type'=>self::TYPE]) !== 1) return 'not_queued';
            $batch = $this->db->one('SELECT storage_key,summary FROM import_batches WHERE id=:id', ['id'=>$batchId]);
            $options = (array) ((json_decode((string) ($batch['summary'] ?? ''), true) ?: [])['options'] ?? []);
            $path = APP_ROOT.'/storage/private/'.(string) ($batch['storage_key'] ?? '');
            try {
                if (!is_file($path)) throw new \RuntimeException('Прикачената датотека повеќе не се наоѓа на серверот.');
                $result = $this->preview($batchId, self::sourceRows($path), $options);
                return (string) $result['status'];
            } catch (\Throwable $exception) {
                $this->db->execute('DELETE FROM revenue_import_rows WHERE import_batch_id=:batch', ['batch'=>$batchId]);
                $message = $exception instanceof HttpException || $exception instanceof LegacySpreadsheetException || $exception instanceof \RuntimeException ? $exception->getMessage() : 'Неочекувана грешка при читањето на извештајот.';
                $this->db->execute("UPDATE import_batches SET status='failed',errors=:errors,updated_at=SYSUTCDATETIME() WHERE id=:id",
                    ['errors'=>json_encode([['row'=>0, 'code'=>'failed', 'message'=>mb_substr($message, 0, 500)]], JSON_UNESCAPED_UNICODE), 'id'=>$batchId]);
                $this->audit->record($this->userId, 'revenue_import.failed', 'App\\Models\\ImportBatch', $batchId, [], ['error'=>get_class($exception).': '.mb_substr($exception->getMessage(), 0, 500)]);
                return 'failed';
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Fallback for the scheduler / cron: requeues batches whose worker died (processing for more than 15 minutes) and
     * processes up to $limit queued batches that have waited more than a minute.
     */
    public static function processQueue(Database $db, Audit $audit, int $limit = 1): array
    {
        $stale = $db->all("SELECT id FROM import_batches WHERE type=:type AND status='processing' AND updated_at<DATEADD(minute,-15,SYSUTCDATETIME())", ['type'=>self::TYPE]);
        foreach ($stale as $row) {
            $db->execute('DELETE FROM revenue_import_rows WHERE import_batch_id=:batch', ['batch'=>$row['id']]);
            $db->execute("UPDATE import_batches SET status='queued',updated_at=SYSUTCDATETIME() WHERE id=:id AND status='processing'", ['id'=>$row['id']]);
        }
        // A clear operation whose worker died is closed as failed, so it does not block the next one.
        $db->execute("UPDATE import_batches SET status='failed',updated_at=SYSUTCDATETIME() WHERE type=:clear AND status='processing' AND updated_at<DATEADD(minute,-60,SYSUTCDATETIME())", ['clear'=>self::CLEAR_TYPE]);
        // Confirmations interrupted half-way continue where they stopped.
        foreach ($db->all("SELECT id,created_by FROM import_batches WHERE type=:type AND status='posting' AND updated_at<DATEADD(minute,-15,SYSUTCDATETIME())", ['type'=>self::TYPE]) as $row) {
            (new self($db, $audit, (int) $row['created_by']))->post((string) $row['id']);
        }
        $done = [];
        foreach ($db->all("SELECT id,created_by FROM import_batches WHERE type=:type AND status='queued' AND created_at<DATEADD(minute,-1,SYSUTCDATETIME()) ORDER BY created_at", ['type'=>self::TYPE]) as $i => $row) {
            if ($i >= $limit) break;
            $done[(string) $row['id']] = (new self($db, $audit, (int) $row['created_by']))->process((string) $row['id'], 0);
        }
        return ['requeued'=>count($stale), 'processed'=>$done];
    }

    /** Rows of the report keyed by Excel row - 1: XLSX streamed with XMLReader, CSV and XLS through SpreadsheetReader. */
    public static function sourceRows(string $path): iterable
    {
        $reader = new SpreadsheetReader();
        $count = 0;
        foreach (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx' ? $reader->firstSheetRows($path) : $reader->rows($path) as $index => $row) {
            if (++$count > self::MAX_ROWS) throw new \RuntimeException('Извештајот има повеќе од '.self::MAX_ROWS.' редови. Поделете го по период.');
            yield $index => $row;
        }
    }

    /** Large reports keep the shared strings of the workbook in memory; 512 MB covers 300 000 rows. */
    public static function raiseMemoryLimit(): void
    {
        $limit = (string) ini_get('memory_limit');
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) { 'g'=>1073741824, 'm'=>1048576, 'k'=>1024, default=>1 };
        if ($limit !== '-1' && $bytes < 536870912) @ini_set('memory_limit', '512M');
    }

    /** One report at a time: an exclusive lock file, waited for up to $seconds. */
    private static function lock(int $seconds)
    {
        $handle = fopen(APP_ROOT.'/storage/revenue-import.lock', 'c+');
        if ($handle === false) return null;
        $until = time() + $seconds;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
            if (time() >= $until) break;
            sleep(1);
        } while (true);
        fclose($handle);
        return null;
    }

    /** Rows written to revenue_transactions per transaction (below SQL Server's lock escalation threshold of 5 000). */
    private const POST_CHUNK = 4000;

    /** Claims a ready preview for confirmation (ready → posting). The rows are written by post(). */
    public function startPosting(string $batchId): void
    {
        $batch = $this->db->one('SELECT type,status FROM import_batches WHERE id=:id', ['id'=>$batchId]);
        if (!$batch || (string) $batch['type'] !== self::TYPE) throw new HttpException(404, 'Увозот не е пронајден.');
        if ($this->db->execute("UPDATE import_batches SET status='posting',updated_at=SYSUTCDATETIME() WHERE id=:id AND status='ready'", ['id'=>$batchId]) !== 1) {
            throw new HttpException(409, 'Овој увоз е потврден, откажан или нема нови редови.');
        }
    }

    /** Confirms a ready preview and writes its rows now (tests, small reports, the cron script). */
    public function confirm(string $batchId): array
    {
        $this->startPosting($batchId);
        return $this->post($batchId);
    }

    /**
     * Writes the new rows of a batch in posting state, 4 000 rows per transaction, and deletes them from the preview as
     * it goes. If it is interrupted, calling it again continues where it stopped: rows already written are skipped
     * (unique source_key) and are no longer in the preview.
     */
    public function post(string $batchId): array
    {
        $batch = $this->db->one('SELECT id,type,status,summary FROM import_batches WHERE id=:id', ['id'=>$batchId]);
        if (!$batch || (string) $batch['type'] !== self::TYPE || (string) $batch['status'] !== 'posting') throw new HttpException(409, 'Увозот не е во потврдување.');
        $summary = json_decode((string) ($batch['summary'] ?? '{}'), true) ?: [];
        $expected = (int) ($summary['counts']['new'] ?? 0);
        $columns = implode(',', self::FIELDS);
        $selected = implode(',', array_map(static fn(string $c): string => 'r.'.$c, self::FIELDS));
        $lastId = 0;
        while (true) {
            $next = "SELECT id FROM revenue_import_rows WHERE import_batch_id=:batch AND row_status IN ('new','existing') AND id>:after ORDER BY id";
            $next = $this->sqlServer() ? str_replace('SELECT id', 'SELECT TOP ('.self::POST_CHUNK.') id', $next) : $next.' LIMIT '.self::POST_CHUNK;
            $window = $this->db->one('SELECT MIN(id) first_id,MAX(id) last_id FROM ('.$next.') w', ['batch'=>$batchId, 'after'=>$lastId]);
            if (!$window || $window['last_id'] === null) break;
            [$from, $to] = [(int) $window['first_id'], (int) $window['last_id']];
            $this->db->transaction(function () use ($batchId, $from, $to, $columns, $selected): void {
                if ($this->sqlServer()) {
                    // One revenue confirmation at a time (application lock) instead of range locks on revenue_transactions.
                    $this->db->execute('SET LOCK_TIMEOUT 30000');
                    $granted = (int) $this->db->scalar("SET NOCOUNT ON; DECLARE @r int; EXEC @r=sp_getapplock @Resource=N'ekabineti.revenue_import',@LockMode=N'Exclusive',@LockOwner=N'Transaction',@LockTimeout=30000; SELECT @r");
                    if ($granted < 0) throw new HttpException(409, 'Друг увоз на приходи се потврдува. Обидете се малку подоцна.');
                }
                $this->db->execute(
                    "INSERT INTO revenue_transactions(source_key,import_batch_id,source_row_number,{$columns},created_at,updated_at)
                     SELECT r.source_key,r.import_batch_id,r.source_row_number,{$selected},SYSUTCDATETIME(),SYSUTCDATETIME()
                     FROM revenue_import_rows r WHERE r.import_batch_id=:batch AND r.row_status='new' AND r.id BETWEEN :from AND :to
                     AND NOT EXISTS (SELECT 1 FROM revenue_transactions t WHERE t.source_key=r.source_key)",
                    ['batch'=>$batchId, 'from'=>$from, 'to'=>$to]
                );
                // The imported rows now live in revenue_transactions; the preview keeps only what was not imported.
                $this->db->execute("DELETE FROM revenue_import_rows WHERE import_batch_id=:batch AND row_status IN ('new','existing') AND id BETWEEN :from AND :to", ['batch'=>$batchId, 'from'=>$from, 'to'=>$to]);
            });
            $lastId = $to;
            $this->db->execute("UPDATE import_batches SET updated_at=SYSUTCDATETIME() WHERE id=:id AND status='posting'", ['id'=>$batchId]);
        }
        $totals = $this->db->one('SELECT COUNT(*) imported,COALESCE(SUM(amount),0) amount FROM revenue_transactions WHERE import_batch_id=:batch', ['batch'=>$batchId]) ?? ['imported'=>0, 'amount'=>0];
        $summary['posted'] = ['imported'=>(int) $totals['imported'], 'skipped_meanwhile'=>max(0, $expected - (int) $totals['imported']), 'amount'=>number_format((float) $totals['amount'], 2, '.', '')];
        $this->db->execute("UPDATE import_batches SET status='posted',summary=:summary,confirmed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id AND status='posting'",
            ['summary'=>json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id'=>$batchId]);
        $this->audit->record($this->userId, 'revenue_import.posted', 'App\\Models\\ImportBatch', $batchId, [], $summary['posted']);
        return $summary['posted'];
    }

    /** Operation that clears all revenues (import_batches.type), tracked like an import so its page can follow it. */
    public const CLEAR_TYPE = 'revenue_clear';
    private const CLEAR_CHUNK = 5000;

    /** Path of the stored report of a batch, or null when the file is no longer on the server. */
    public function sourcePath(string $batchId): ?string
    {
        $key = (string) $this->db->scalar('SELECT storage_key FROM import_batches WHERE id=:id AND type=:type', ['id'=>$batchId, 'type'=>self::TYPE]);
        if (!preg_match('#^imports/[0-9a-f-]{36}/source\.(xlsx|xls|csv)$#i', $key)) return null;
        $path = APP_ROOT.'/storage/private/'.$key;
        return is_file($path) ? $path : null;
    }

    /**
     * Reads the stored report of an earlier import again, as a new import (new preview). With $autoConfirm the new
     * payments are written at once (used after clearing everything).
     */
    public function reimport(string $sourceBatchId, string $newBatchId, bool $autoConfirm = false): string
    {
        $source = $this->db->one('SELECT summary FROM import_batches WHERE id=:id AND type=:type', ['id'=>$sourceBatchId, 'type'=>self::TYPE]);
        $path = $this->sourcePath($sourceBatchId);
        if (!$source || $path === null) throw new HttpException(404, 'Датотеката од овој увоз повеќе не се наоѓа на серверот.');
        $options = (array) ((json_decode((string) $source['summary'], true) ?: [])['options'] ?? []);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $directory = APP_ROOT.'/storage/private/imports/'.$newBatchId;
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Приватниот директориум не е создаден.');
        if (!copy($path, $directory.'/source.'.$extension)) throw new \RuntimeException('Копирањето на извештајот не успеа.');
        $options['source_file_name'] = (string) ($options['source_file_name'] ?? 'raporti').' (враќање)';
        $options['reimport_of'] = $sourceBatchId;
        $this->queue($newBatchId, 'imports/'.$newBatchId.'/source.'.$extension, $options);
        $status = $this->process($newBatchId);
        if ($autoConfirm && $status === 'ready') { $this->confirm($newBatchId); return 'posted'; }
        return $status;
    }

    /** Batch of a clear operation, created before the work starts so the page can follow it. */
    public function startClear(string $operationId, ?string $restoreBatchId, string $reason): void
    {
        if ((int) $this->db->scalar("SELECT COUNT(*) FROM import_batches WHERE type IN (:type,:clear) AND status IN ('queued','processing','posting')", ['type'=>self::TYPE, 'clear'=>self::CLEAR_TYPE]) > 0) {
            throw new HttpException(409, 'Во тек е увоз или бришење на приходите. Обидете се откако ќе заврши.');
        }
        if ($restoreBatchId !== null && $this->sourcePath($restoreBatchId) === null) throw new HttpException(422, 'Датотеката од увозот избран за враќање не се наоѓа на серверот.');
        $summary = ['type'=>self::CLEAR_TYPE, 'restore_from'=>$restoreBatchId, 'reason'=>$reason];
        $this->db->execute("INSERT INTO import_batches(id,type,storage_key,status,summary,created_by,created_at,updated_at) VALUES(:id,:type,'','processing',:summary,:user,SYSUTCDATETIME(),SYSUTCDATETIME())",
            ['id'=>$operationId, 'type'=>self::CLEAR_TYPE, 'summary'=>json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user'=>$this->userId]);
    }

    /**
     * Deletes every revenue payment (5 000 per statement), marks the confirmed imports "cleared" and drops open previews
     * (their "already in the system" counts are no longer true). Optionally reads one earlier import again and confirms
     * it. Manual reclassifications are lost with the payments; the stored report files are kept.
     */
    public function clearAll(string $operationId): array
    {
        $operation = $this->db->one('SELECT summary FROM import_batches WHERE id=:id AND type=:type', ['id'=>$operationId, 'type'=>self::CLEAR_TYPE]);
        if (!$operation) throw new HttpException(404, 'Дејството не е пронајдено.');
        $summary = json_decode((string) $operation['summary'], true) ?: [];
        $lock = self::lock(300);
        if ($lock === null) {
            $this->finishClear($operationId, $summary + ['error'=>'Се чита увоз на приходи; бришењето не е извршено.'], 'failed');
            return ['status'=>'busy'];
        }
        try {
            $before = $this->db->one('SELECT COUNT(*) payments,COALESCE(SUM(amount),0) amount FROM revenue_transactions') ?? ['payments'=>0, 'amount'=>0];
            $chunk = $this->sqlServer() ? 'DELETE TOP ('.self::CLEAR_CHUNK.') FROM revenue_transactions' : 'DELETE FROM revenue_transactions WHERE id IN (SELECT id FROM revenue_transactions LIMIT '.self::CLEAR_CHUNK.')';
            $deleted = 0;
            do { $n = $this->db->execute($chunk); $deleted += $n; } while ($n > 0);
            $cleared = $this->db->execute("UPDATE import_batches SET status='cleared',updated_at=SYSUTCDATETIME() WHERE type=:type AND status='posted'", ['type'=>self::TYPE]);
            foreach ($this->db->all("SELECT id FROM import_batches WHERE type=:type AND status IN ('ready','unchanged')", ['type'=>self::TYPE]) as $row) {
                $this->db->execute('DELETE FROM revenue_import_rows WHERE import_batch_id=:batch', ['batch'=>$row['id']]);
                $this->db->execute("UPDATE import_batches SET status='cancelled',updated_at=SYSUTCDATETIME() WHERE id=:id", ['id'=>$row['id']]);
            }
            $summary['deleted'] = ['payments'=>$deleted, 'amount'=>number_format((float) $before['amount'], 2, '.', ''), 'imports_cleared'=>$cleared];
            $this->audit->record($this->userId, 'revenue.cleared_all', 'App\\Models\\ImportBatch', $operationId, ['payments'=>(int) $before['payments'], 'amount'=>(string) $before['amount']], $summary['deleted'], (string) ($summary['reason'] ?? ''));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (!empty($summary['restore_from'])) {
            $restored = \EKabineti\Core\Request::uuid();
            $summary['restored_batch'] = $restored;
            $this->finishClear($operationId, $summary, 'processing');
            try { $summary['restore_status'] = $this->reimport((string) $summary['restore_from'], $restored, true); }
            catch (\Throwable $exception) { $summary['restore_status'] = 'failed'; $summary['error'] = mb_substr($exception->getMessage(), 0, 300); }
        }
        $this->finishClear($operationId, $summary, empty($summary['error']) ? 'posted' : 'failed');
        return $summary;
    }

    private function finishClear(string $operationId, array $summary, string $status): void
    {
        $this->db->execute('UPDATE import_batches SET status=:status,summary=:summary,confirmed_at=CASE WHEN :done=1 THEN SYSUTCDATETIME() ELSE confirmed_at END,updated_at=SYSUTCDATETIME() WHERE id=:id',
            ['status'=>$status, 'summary'=>json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'done'=>$status === 'processing' ? 0 : 1, 'id'=>$operationId]);
    }

    public function cancel(string $batchId): void
    {
        $this->db->transaction(function () use ($batchId): void {
            $batch = $this->db->one('SELECT id,type,status FROM import_batches WITH (UPDLOCK,HOLDLOCK) WHERE id=:id', ['id'=>$batchId]);
            if (!$batch || (string) $batch['type'] !== self::TYPE) throw new HttpException(404, 'Увозот не е пронајден.');
            if ((string) $batch['status'] === 'posted') throw new HttpException(409, 'Увозот е потврден и не може да се откаже.');
            if ((string) $batch['status'] === 'processing') throw new HttpException(409, 'Извештајот се чита; откажете го откако ќе заврши читањето.');
            if ((string) $batch['status'] === 'posting') throw new HttpException(409, 'Уплатите се евидентираат и увозот не може да се откаже.');
            $this->db->execute('DELETE FROM revenue_import_rows WHERE import_batch_id=:batch', ['batch'=>$batchId]);
            $this->db->execute("UPDATE import_batches SET status='cancelled',updated_at=SYSUTCDATETIME() WHERE id=:id", ['id'=>$batchId]);
            $this->audit->record($this->userId, 'revenue_import.cancelled', 'App\\Models\\ImportBatch', $batchId);
        });
    }

    /**
     * A new rule also classifies the payments that no rule had classified yet (by group or not at all); payments
     * classified by hand or by an earlier rule keep their category.
     */
    public function applyRule(string $prefix, ?string $keyword, int $categoryId): int
    {
        $params = ['category'=>$categoryId, 'method'=>$keyword === null ? 'uniref' : 'keyword', 'prefix'=>$prefix.'%'];
        $condition = '';
        if ($keyword !== null) { $condition = " AND UPPER(CONCAT(raw_description,' ',raw_payer)) LIKE :keyword"; $params['keyword'] = '%'.mb_strtoupper($keyword).'%'; }
        return $this->db->execute("UPDATE revenue_transactions SET category_id=:category,classification_method=:method,updated_at=SYSUTCDATETIME() WHERE classification_method IN ('group','none') AND uniref_type LIKE :prefix".$condition, $params);
    }

    /**
     * Keys are "rev:" + 64 hex digits (+ "#n"); after that check they are written into the query directly, so 12 000 keys
     * cost a few statements instead of thousands of bound parameters.
     */
    private function existingKeys(array $keys): array
    {
        $found = [];
        $keys = array_values(array_filter(array_unique($keys), static fn(string $key): bool => preg_match('/^rev:[0-9a-f]{64}(?:#\d{1,6})?$/', $key) === 1));
        if ($this->sqlServer()) {
            // One parameter per 5 000 keys and one cached plan (OPENJSON, compatibility level ≥ 130; production is 150).
            foreach (array_chunk($keys, 5000) as $chunk) {
                foreach ($this->db->all('SELECT t.source_key FROM OPENJSON(:keys) WITH (source_key nvarchar(80) \'$\') j JOIN revenue_transactions t ON t.source_key=j.source_key', ['keys'=>json_encode($chunk)]) as $row) $found[(string) $row['source_key']] = true;
            }
            return $found;
        }
        foreach (array_chunk($keys, 1000) as $chunk) {
            foreach ($this->db->all("SELECT source_key FROM revenue_transactions WHERE source_key IN ('".implode("','", $chunk)."')") as $row) $found[(string) $row['source_key']] = true;
        }
        return $found;
    }

    private function stagedRow(string $batchId, array $row, string $status, array $categories): array
    {
        $values = ['import_batch_id'=>$batchId, 'source_row_number'=>$row['source_row_number'], 'row_status'=>$status,
            'errors'=>$row['errors'] ? implode(',', $row['errors']) : null, 'source_key'=>$row['source_key'], 'content_key'=>$row['content_key'], 'loose_key'=>$row['loose_key'], 'occurrence'=>$row['occurrence']];
        foreach (self::FIELDS as $field) {
            $value = match ($field) {
                'category_id' => $categories[$row['category_code']] ?? $categories['unclassified'],
                'extraction' => $row['extraction'] ? json_encode($row['extraction'], JSON_UNESCAPED_UNICODE) : null,
                'warnings' => $row['warnings'] ? implode(',', $row['warnings']) : null,
                default => $row[$field] ?? null,
            };
            $values[$field] = $value === '' && !str_starts_with($field, 'raw_') ? null : $value;
        }
        return $values;
    }

    /**
     * SQL Server: 1 000 rows per INSERT … SELECT FROM OPENJSON(@rows), one parameter and the same statement text every
     * time, so the plan is compiled once and reused. (Binding ~40 parameters per row, or 500-row VALUES lists that SQL
     * Server compiles anew each time, are what made large reports slow.) Other databases (tests): multi-row VALUES.
     */
    private function insertStaged(array $rows): void
    {
        if ($rows === []) return;
        $columns = array_keys($rows[0]);
        $sqlServer = $this->sqlServer();
        if ($sqlServer) {
            $with = [];
            foreach ($columns as $column) $with[] = $column.' '.self::STAGING_TYPES[$column]." '$.".$column."'";
            $this->db->execute('INSERT INTO revenue_import_rows('.implode(',', $columns).') SELECT '.implode(',', $columns).' FROM OPENJSON(:rows) WITH ('.implode(',', $with).')',
                ['rows'=>json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)]);
            return;
        }
        $tuples = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) $values[] = self::literal($row[$column], $sqlServer);
            $tuples[] = '('.implode(',', $values).')';
        }
        $this->db->execute('INSERT INTO revenue_import_rows('.implode(',', $columns).') VALUES '.implode(',', $tuples));
    }

    private function sqlServer(): bool
    {
        return $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlsrv';
    }

    /** NULL, an integer, or a string literal: quotes doubled, NUL removed; on SQL Server N'…' with line breaks as NCHAR() (no "\"+newline continuation). */
    public static function literal(mixed $value, bool $sqlServer): string
    {
        if ($value === null) return 'NULL';
        if (is_int($value)) return (string) $value;
        if (is_bool($value)) return $value ? '1' : '0';
        $text = str_replace(["\0", "'"], ['', "''"], (string) $value);
        if (!$sqlServer) return "'".$text."'";
        return "N'".str_replace(["\r", "\n"], ["'+NCHAR(13)+N'", "'+NCHAR(10)+N'"], $text)."'";
    }
}
