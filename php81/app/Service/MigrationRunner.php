<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;

final class MigrationRunner
{
    public function __construct(private readonly Database $db, private readonly string $directory) {}

    public function run(): array
    {
        $files = glob($this->directory.'/*.sql') ?: [];
        sort($files, SORT_STRING);

        return $this->db->transaction(function () use ($files): array {
            $this->db->scalar('SELECT COUNT(*) FROM migrations WITH (TABLOCKX,HOLDLOCK)');
            $batch = (int) $this->db->scalar('SELECT COALESCE(MAX(batch),0)+1 FROM migrations');
            $applied = [];
            $skipped = [];

            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if ($this->db->scalar('SELECT TOP 1 1 FROM migrations WHERE migration=:migration', ['migration'=>$name])) {
                    $skipped[] = $name;
                    continue;
                }

                $sql = file_get_contents($file);
                if (!is_string($sql) || trim($sql) === '') {
                    throw new \RuntimeException('Migration file is empty: '.$name);
                }
                $batches = preg_split('/^\s*GO\s*$/mi', $sql) ?: [];
                foreach ($batches as $statement) {
                    if (trim($statement) !== '') $this->db->execute($statement);
                }
                $this->db->execute('INSERT INTO migrations(migration,batch) VALUES(:migration,:batch)', ['migration'=>$name,'batch'=>$batch]);
                $applied[] = $name;
            }

            return ['status'=>'ok','batch'=>$batch,'applied'=>$applied,'skipped'=>$skipped];
        });
    }
}
