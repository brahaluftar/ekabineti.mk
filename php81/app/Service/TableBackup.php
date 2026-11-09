<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;

/**
 * Row backups for maintenance scripts: every selected row is written to a JSON file (with a manifest and SHA-256 sums)
 * and copied into a database table dbo.bak_<id>_<table>, so it can be restored with the same ids.
 */
final class TableBackup
{
    public function __construct(private readonly Database $db) {}

    public static function newId(): string
    {
        return gmdate('Ymd_His');
    }

    /** Copyable columns of a table (no rowversion and no computed columns). */
    public function columns(string $table): array
    {
        return array_column($this->db->all("SELECT c.name FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id WHERE c.object_id=OBJECT_ID(:table) AND c.is_computed=0 AND t.name NOT IN ('timestamp','rowversion') ORDER BY c.column_id", ['table'=>'dbo.'.$table]), 'name');
    }

    /**
     * @param array<string,string> $tables table => WHERE condition (trusted SQL built by the caller)
     * @return array{rows:array<string,int>,files:array<string,string>,tables:array<string,string>}
     */
    public function backup(string $id, string $directory, array $tables): array
    {
        if (!preg_match('/^\d{8}_\d{6}$/', $id)) throw new \InvalidArgumentException('Invalid backup id.');
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new \RuntimeException('Cannot create backup directory '.$directory);
        $result = ['rows'=>[], 'files'=>[], 'tables'=>[]];
        foreach ($tables as $table => $where) {
            $list = $this->columnList($table);
            $rows = $this->db->all("SELECT {$list} FROM dbo.{$table} WHERE {$where} ORDER BY 1");
            $file = $directory.'/'.$table.'.json';
            if (file_put_contents($file, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) throw new \RuntimeException('Cannot write '.$file);
            $copy = 'bak_'.$id.'_'.$table;
            if ($this->db->scalar('SELECT OBJECT_ID(:name)', ['name'=>'dbo.'.$copy])) throw new \RuntimeException('Backup table dbo.'.$copy.' already exists.');
            $this->db->execute("SELECT {$list} INTO dbo.{$copy} FROM dbo.{$table} WHERE {$where}");
            $copied = (int) $this->db->scalar("SELECT COUNT(*) FROM dbo.{$copy}");
            if ($copied !== count($rows)) throw new \RuntimeException("Backup of {$table}: {$copied} rows copied instead of ".count($rows).'.');
            $result['rows'][$table] = count($rows);
            $result['files'][$table] = hash_file('sha256', $file) ?: '';
            $result['tables'][$table] = 'dbo.'.$copy;
        }
        return $result;
    }

    public function writeManifest(string $directory, array $manifest): void
    {
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public function exists(string $id, string $table): bool
    {
        return preg_match('/^\d{8}_\d{6}$/', $id) === 1 && (bool) $this->db->scalar('SELECT OBJECT_ID(:name)', ['name'=>'dbo.bak_'.$id.'_'.$table]);
    }

    /** Backup ids that contain a copy of $table, newest first. */
    public function ids(string $table): array
    {
        $ids = [];
        foreach ($this->db->all("SELECT name FROM sys.tables WHERE name LIKE 'bak[_]%' ORDER BY name DESC") as $row) {
            if (preg_match('/^bak_(\d{8}_\d{6})_'.preg_quote($table, '/').'$/', (string) $row['name'], $m)) $ids[] = $m[1];
        }
        return $ids;
    }

    /**
     * Puts the rows of dbo.bak_<id>_<table> back: rows whose key exists are overwritten with the backed-up values, missing
     * rows are inserted (with IDENTITY_INSERT when the table has an identity). Returns [updated, inserted].
     * Must run inside the caller's transaction.
     * @param list<string> $keys key columns (default id)
     */
    public function restore(string $id, string $table, array $keys = ['id']): array
    {
        $copy = 'bak_'.$id.'_'.$table;
        $columns = array_values(array_intersect($this->columns($table), $this->columns($copy)));
        $set = implode(',', array_map(static fn(string $c): string => 't.['.$c.']=b.['.$c.']', array_filter($columns, static fn(string $c): bool => !in_array($c, $keys, true))));
        $list = implode(',', array_map(static fn(string $c): string => '['.$c.']', $columns));
        $join = implode(' AND ', array_map(static fn(string $k): string => 'b.['.$k.']=t.['.$k.']', $keys));
        $updated = $set === '' ? 0 : $this->db->execute("UPDATE t SET {$set} FROM dbo.{$table} t JOIN dbo.{$copy} b ON {$join}");
        $identity = (bool) $this->db->scalar("SELECT OBJECTPROPERTY(OBJECT_ID(:table),'TableHasIdentity')", ['table'=>'dbo.'.$table]);
        if ($identity) $this->db->execute('SET IDENTITY_INSERT dbo.'.$table.' ON');
        $inserted = $this->db->execute("INSERT INTO dbo.{$table} ({$list}) SELECT {$list} FROM dbo.{$copy} b WHERE NOT EXISTS (SELECT 1 FROM dbo.{$table} t WHERE {$join})");
        if ($identity) $this->db->execute('SET IDENTITY_INSERT dbo.'.$table.' OFF');
        return [$updated, $inserted];
    }

    private function columnList(string $table): string
    {
        return implode(',', array_map(static fn(string $c): string => '['.$c.']', $this->columns($table)));
    }
}
