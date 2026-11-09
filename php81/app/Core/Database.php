<?php

declare(strict_types=1);

namespace EKabineti\Core;

use PDO;
use PDOStatement;

final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        if (!extension_loaded('pdo_sqlsrv')) {
            throw new \RuntimeException('The pdo_sqlsrv extension is required.');
        }
        $host = (string) $config['host'];
        $port = (int) ($config['port'] ?? 1433);
        $server = str_contains($host, '\\') || $port <= 0 ? $host : 'tcp:'.$host.','.$port;
        $dsn = 'sqlsrv:Server='.$server.';Database='.$config['name']
            .';Encrypt='.(($config['encrypt'] ?? true) ? 'true' : 'false')
            .';TrustServerCertificate='.(($config['trust_server_certificate'] ?? false) ? 'true' : 'false')
            .';LoginTimeout='.(int) ($config['login_timeout'] ?? 15)
            .';APP=e-Kabineti-PHP81';
        if (trim((string) ($config['hostname_in_certificate'] ?? '')) !== '') {
            $dsn .= ';HostnameInCertificate='.$config['hostname_in_certificate'];
        }
        $this->pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::SQLSRV_ATTR_DIRECT_QUERY => false,
        ]);
    }

    public function pdo(): PDO { return $this->pdo; }

    public function one(string $sql, array $params = []): ?array
    {
        $statement = $this->statement($sql, $params);
        try {
            $row = $statement->fetch();
            return $row === false ? null : $row;
        } finally {
            $statement->closeCursor();
        }
    }

    public function all(string $sql, array $params = []): array
    {
        $statement = $this->statement($sql, $params);
        try {
            return $statement->fetchAll();
        } finally {
            $statement->closeCursor();
        }
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->statement($sql, $params);
        try {
            return $statement->fetchColumn();
        } finally {
            $statement->closeCursor();
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->statement($sql, $params);
        try {
            return $statement->rowCount();
        } finally {
            $statement->closeCursor();
        }
    }

    public function insertId(string $sql, array $params = []): int
    {
        $statement = $this->statement($sql.'; SELECT CAST(SCOPE_IDENTITY() AS bigint) AS id', $params);
        try {
            do {
                if ($statement->columnCount() > 0) {
                    $row = $statement->fetch();
                    if (is_array($row) && isset($row['id'])) return (int) $row['id'];
                }
            } while ($statement->nextRowset());
            throw new \RuntimeException('SQL Server did not return SCOPE_IDENTITY().');
        } finally {
            $statement->closeCursor();
        }
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function statement(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':'.$key);
            $statement->bindValue($parameter, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $statement->execute();
        return $statement;
    }
}
