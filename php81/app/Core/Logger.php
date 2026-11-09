<?php

declare(strict_types=1);

namespace EKabineti\Core;

final class Logger
{
    public function __construct(private readonly string $directory) {}

    public function write(string $level, string $message, array $context = []): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0770, true);
        }
        foreach (['password', 'token', 'client_secret', 'authorization'] as $secret) {
            unset($context[$secret]);
        }
        $record = ['datetime' => gmdate('c'), 'level_name' => strtoupper($level), 'message' => $message, 'context' => $context];
        file_put_contents($this->directory.'/application-'.date('Y-m-d').'.log', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

