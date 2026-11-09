<?php

declare(strict_types=1);

namespace EKabineti\Core;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    public readonly string $correlationId;
    /** @var list<string> Reverse proxies whose X-Forwarded-For header is trusted (app.trusted_proxies). */
    public static array $trustedProxies = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $this->path = '/'.ltrim($base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path, '/');
        $incoming = $_SERVER['HTTP_X_CORRELATION_ID'] ?? '';
        $this->correlationId = preg_match('/^[a-f0-9-]{36}$/i', $incoming) ? $incoming : self::uuid();
    }

    public function input(string $key, mixed $default = null): mixed { return $_POST[$key] ?? $_GET[$key] ?? $default; }
    public function integer(string $key, int $default = 0): int { $value = filter_var($this->input($key, $default), FILTER_VALIDATE_INT); return $value === false ? $default : $value; }
    public function expectsJson(): bool { return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_starts_with($this->path, '/live/'); }
    public function ip(): ?string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote !== '' && in_array($remote, self::$trustedProxies, true)) {
            foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
            }
        }
        return $remote !== '' ? $remote : null;
    }

    /** Same-origin path from the Referer header, used for safe post-error redirects. */
    public function safeReferer(string $fallback = '/'): string
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer === '') return $fallback;
        $parts = parse_url($referer);
        if ($parts === false) return $fallback;
        $host = strtolower((string) ($parts['host'] ?? ''));
        $ownHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($host !== '' && $host !== $ownHost) return $fallback;
        $path = (string) ($parts['path'] ?? '/');
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) return $fallback;
        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
    public function bearerToken(): string
    {
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return preg_match('/^Bearer\s+(.+)$/i', $authorization, $m) ? trim($m[1]) : '';
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
