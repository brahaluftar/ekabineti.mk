<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Core\Logger;

/** Moves quarantined attachments to clean storage after a ClamAV scan (clamscan or clamdscan). */
final class DocumentScanner
{
    public function __construct(private readonly Database $db, private readonly Logger $logger, private readonly string $privateRoot, private readonly ?string $binary) {}

    public function configured(): bool
    {
        return $this->binary !== null && $this->binary !== '' && is_file($this->binary) && is_executable($this->binary);
    }

    /** @return array{status:string,clean:int,infected:int,errors:int,pending:int} */
    public function run(int $limit = 50): array
    {
        $pending = (int) $this->db->scalar("SELECT COUNT(*) FROM attachments WHERE scan_status='pending'");
        if (!$this->configured()) {
            if ($pending > 0) $this->logger->write('warning', 'documents.scanner_unavailable', ['pending' => $pending]);
            return ['status' => 'scanner_unavailable', 'clean' => 0, 'infected' => 0, 'errors' => 0, 'pending' => $pending];
        }
        // clamscan reloads its signatures per call; one scanner at a time (cron CLI or CLI scheduler).
        $lock = fopen(dirname($this->privateRoot).'/scan-documents.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return ['status' => 'busy', 'clean' => 0, 'infected' => 0, 'errors' => 0, 'pending' => $pending];
        try {
            $rows = $this->db->all('SELECT TOP '.max(1, min(200, $limit))." id,storage_key,version FROM attachments WHERE scan_status='pending' ORDER BY id");
            $clean = $infected = $errors = 0;
            foreach ($rows as $attachment) {
                $source = $this->privateRoot.'/'.$attachment['storage_key'];
                if (!is_file($source)) { $this->mark((int) $attachment['id'], 'scan_error'); $errors++; continue; }
                $output = []; $code = 2;
                exec(escapeshellarg((string) $this->binary).' --no-summary '.escapeshellarg($source).' 2>&1', $output, $code);
                if ($code === 0) {
                    $targetKey = 'documents/'.(int) $attachment['id'].'/v'.(int) $attachment['version'].'/'.basename($source);
                    $target = $this->privateRoot.'/'.$targetKey;
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0770, true) && !is_dir(dirname($target))) { $this->mark((int) $attachment['id'], 'scan_error'); $errors++; continue; }
                    if (!rename($source, $target)) { $this->mark((int) $attachment['id'], 'scan_error'); $errors++; continue; }
                    @rmdir(dirname($source));
                    $this->db->execute("UPDATE attachments SET storage_key=:key,scan_status='clean',updated_at=SYSUTCDATETIME() WHERE id=:id", ['key' => $targetKey, 'id' => $attachment['id']]);
                    $clean++;
                } elseif ($code === 1) {
                    $this->mark((int) $attachment['id'], 'infected'); $infected++;
                } else {
                    $this->mark((int) $attachment['id'], 'scan_error'); $errors++;
                    $this->logger->write('error', 'documents.scan_failed', ['attachment_id' => (int) $attachment['id'], 'exit_code' => $code, 'output' => mb_substr(implode(' ', $output), 0, 500)]);
                }
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        $result = ['status' => $errors ? 'partial' : 'ok', 'clean' => $clean, 'infected' => $infected, 'errors' => $errors, 'pending' => max(0, $pending - count($rows))];
        if ($rows) $this->logger->write($errors ? 'warning' : 'info', 'documents.scanned', $result);
        return $result;
    }

    private function mark(int $id, string $status): void
    {
        $this->db->execute('UPDATE attachments SET scan_status=:status,updated_at=SYSUTCDATETIME() WHERE id=:id', ['status' => $status, 'id' => $id]);
    }
}
