<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Core\HttpException;

/**
 * Database-backed attempt counter (dbo.cache) so limits cannot be bypassed by discarding the session cookie.
 * Each bucket is a fixed window: {count, reset_at}.
 */
final class LoginThrottle
{
    public function __construct(private readonly Database $db) {}

    /** Throws 429 when any of the buckets has reached its limit within the window. */
    public function assertAllowed(array $buckets, int $limit, string $message): void
    {
        foreach ($buckets as $bucket) {
            $state = $this->read($bucket);
            if ($state !== null && $state['count'] >= $limit && $state['reset_at'] > time()) {
                throw new HttpException(429, $message);
            }
        }
    }

    public function hit(array $buckets, int $windowSeconds): void
    {
        foreach ($buckets as $bucket) {
            $state = $this->read($bucket);
            $now = time();
            $state = $state !== null && $state['reset_at'] > $now ? ['count' => $state['count'] + 1, 'reset_at' => $state['reset_at']] : ['count' => 1, 'reset_at' => $now + $windowSeconds];
            $this->db->execute(
                "MERGE cache AS target USING (SELECT :key AS [key], :value AS [value], :expiration AS [expiration]) AS source ON target.[key]=source.[key]
                 WHEN MATCHED THEN UPDATE SET value=source.[value], expiration=source.[expiration]
                 WHEN NOT MATCHED THEN INSERT ([key],value,expiration) VALUES (source.[key],source.[value],source.[expiration]);",
                ['key' => $this->key($bucket), 'value' => json_encode($state), 'expiration' => $state['reset_at']]
            );
        }
    }

    public function clear(array $buckets): void
    {
        foreach ($buckets as $bucket) $this->db->execute('DELETE FROM cache WHERE [key]=:key', ['key' => $this->key($bucket)]);
    }

    /** Removes expired throttle rows; called by the scheduler. */
    public function purgeExpired(): int
    {
        return $this->db->execute("DELETE FROM cache WHERE [key] LIKE 'throttle:%' AND expiration < :now", ['now' => time()]);
    }

    private function read(string $bucket): ?array
    {
        $value = $this->db->scalar('SELECT value FROM cache WHERE [key]=:key', ['key' => $this->key($bucket)]);
        if (!is_string($value)) return null;
        $state = json_decode($value, true);
        return is_array($state) ? ['count' => (int) ($state['count'] ?? 0), 'reset_at' => (int) ($state['reset_at'] ?? 0)] : null;
    }

    private function key(string $bucket): string
    {
        return 'throttle:'.hash('sha256', $bucket);
    }
}
