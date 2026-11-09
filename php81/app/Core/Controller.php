<?php

declare(strict_types=1);

namespace EKabineti\Core;

use EKabineti\Security\Auth;
use EKabineti\Security\Scope;
use EKabineti\Service\Audit;
use EKabineti\Service\Notifications;

abstract class Controller
{
    public function __construct(
        protected readonly Database $db,
        protected readonly Request $request,
        protected readonly Auth $auth,
        protected readonly Scope $scope,
        protected readonly Audit $audit,
        protected readonly Notifications $notifications,
        protected readonly Logger $logger,
        protected readonly array $config,
    ) {}

    protected function userId(): int { return (int) ($this->auth->user()['id'] ?? 0); }
    protected function requireField(string $name, int $max = 0): string { $value = trim((string) $this->request->input($name, '')); if ($value === '') throw new HttpException(422, "Област {$name} е задолжително."); if ($max > 0 && mb_strlen($value) > $max) throw new HttpException(422, "Област {$name} е предолго."); return $value; }
    protected function intField(string $name, int $min, int $max): int { $value = filter_var($this->request->input($name), FILTER_VALIDATE_INT); if ($value === false || $value < $min || $value > $max) throw new HttpException(422, "Област {$name} не е валиден."); return $value; }
    protected function amount(string $name): string { $value = trim((string) $this->request->input($name)); if (!preg_match('/^\d{1,15}(?:\.\d{1,4})?$/', $value) || (float) $value <= 0) throw new HttpException(422, "Износ {$name} не е валиден."); return $value; }
    protected function date(string $name, bool $required = true): ?string { $value = trim((string) $this->request->input($name, '')); if ($value === '' && !$required) return null; $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value); if (!$date || $date->format('Y-m-d') !== $value) throw new HttpException(422, "Датум {$name} не е валиден."); return $value; }
    protected function find(string $table, int $id): array { $allowed = ['projects','project_tasks','risks','budget_allocations','budget_lines','budget_change_requests','workflow_instances','attachments','import_batches','commitments']; if (!in_array($table, $allowed, true)) throw new \LogicException('Unsafe table.'); $row = $this->db->one("SELECT * FROM {$table} WHERE id=:id", ['id' => $id]); if (!$row) throw new HttpException(404, 'Записот не е пронајден.'); return $row; }
    protected function redirect(string $path, string $message): never { $_SESSION['_flash'] = $message; header('Location: '.$path, true, 303); exit; }
    protected function json(array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store, private'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
}
