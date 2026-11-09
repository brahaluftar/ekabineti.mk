<?php

declare(strict_types=1);

namespace EKabineti\Security;

use EKabineti\Core\Database;

final class Auth
{
    private ?array $user = null;

    public function __construct(private readonly Database $db, private readonly array $appConfig)
    {
        if (PHP_SAPI !== 'cli') {
            session_name((string) ($appConfig['session_name'] ?? 'ekabineti_session'));
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }
    }

    public function user(): ?array
    {
        if ($this->user === null && isset($_SESSION['user_id'])) {
            $user = $this->db->one('SELECT id, name, username, email, locale, status, session_epoch FROM users WHERE id = :id AND status = :status', ['id' => (int) $_SESSION['user_id'], 'status' => 'active']);
            // A password change/reset or access change increments session_epoch and invalidates every older session.
            if ($user && (int) $user['session_epoch'] !== (int) ($_SESSION['session_epoch'] ?? -1)) {
                $this->logout();
                return null;
            }
            $this->user = $user;
        }
        return $this->user;
    }

    public function attempt(string $login, string $password): bool
    {
        $login = mb_strtolower(trim($login));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = $this->db->one("SELECT id, name, username, email, locale, status, password, session_epoch FROM users WHERE LOWER({$field}) = :login", ['login' => $login]);
        // Always run a bcrypt verification so response time does not reveal whether the account exists.
        $hash = $user ? (string) $user['password'] : '$2y$12$ZRNO/fINuCdbJiraK0.o4uYMlkkH2YPWtSb6pdYr16wY2/QgDOm6e';
        $valid = password_verify($password, $hash);
        if (!$user || $user['status'] !== 'active' || !$valid) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['session_epoch'] = (int) $user['session_epoch'];
        $this->db->execute('UPDATE users SET last_login_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME() WHERE id = :id', ['id' => (int) $user['id']]);
        unset($user['password']);
        $this->user = $user;
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->user = null;
    }

    public function csrf(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public function verifyCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    public function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Unread in-app notifications of the current user (header bell). */
    public function unreadNotifications(): int
    {
        $user = $this->user();
        if (!$user) return 0;
        return (int) $this->db->scalar("SELECT COUNT(*) FROM notifications WHERE recipient_id=:id AND channel='in_app' AND read_at IS NULL", ['id' => (int) $user['id']]);
    }

    /** Invalidates all sessions of a user; keeps the current session valid when it belongs to that user. */
    public function revokeSessions(int $userId): void
    {
        $this->db->execute('UPDATE users SET session_epoch = session_epoch + 1, updated_at = SYSUTCDATETIME() WHERE id = :id', ['id' => $userId]);
        if ((int) ($_SESSION['user_id'] ?? 0) === $userId) {
            $_SESSION['session_epoch'] = (int) $this->db->scalar('SELECT session_epoch FROM users WHERE id = :id', ['id' => $userId]);
            $this->regenerateSession();
        }
    }

    private static function https(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on' || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
