<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;

final class UserPreferences
{
    public const DEFAULT_WIDGETS = ['agenda', 'metrics', 'projects', 'updates', 'risks'];

    public function __construct(private readonly Database $db) {}

    public function get(int $userId): array
    {
        $row = $this->db->one('SELECT notification_email_mode,notification_digest_time,notification_digest_weekday,notification_window_start,notification_window_end,dashboard_widgets FROM users WHERE id=:id', ['id' => $userId]) ?: [];
        $widgets = json_decode((string) ($row['dashboard_widgets'] ?? ''), true);
        return [
            'notification_email_mode' => in_array($row['notification_email_mode'] ?? null, ['immediate', 'daily', 'weekly', 'time_window'], true) ? $row['notification_email_mode'] : 'immediate',
            'notification_digest_time' => (string) ($row['notification_digest_time'] ?: '09:00'),
            'notification_digest_weekday' => max(1, min(7, (int) ($row['notification_digest_weekday'] ?: 1))),
            'notification_window_start' => (string) ($row['notification_window_start'] ?: '08:00'),
            'notification_window_end' => (string) ($row['notification_window_end'] ?: '16:00'),
            'dashboard_widgets' => is_array($widgets) ? array_values(array_intersect($widgets, self::DEFAULT_WIDGETS)) : self::DEFAULT_WIDGETS,
        ];
    }

    public function save(int $userId, array $preferences): void
    {
        $this->db->execute('UPDATE users SET notification_email_mode=:mode,notification_digest_time=:digest_time,notification_digest_weekday=:weekday,notification_window_start=:window_start,notification_window_end=:window_end,dashboard_widgets=:widgets,updated_at=SYSUTCDATETIME() WHERE id=:id', [
            'mode' => $preferences['notification_email_mode'],
            'digest_time' => $preferences['notification_digest_time'],
            'weekday' => $preferences['notification_digest_weekday'],
            'window_start' => $preferences['notification_window_start'],
            'window_end' => $preferences['notification_window_end'],
            'widgets' => json_encode($preferences['dashboard_widgets'], JSON_UNESCAPED_UNICODE),
            'id' => $userId,
        ]);
    }
}
