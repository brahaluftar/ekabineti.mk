<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Core\Request;

final class Audit
{
    public function __construct(private readonly Database $db, private readonly Request $request) {}

    public function record(?int $actorId, string $action, string $entityType, int|string|null $entityId, array $before = [], array $after = [], ?string $reason = null): void
    {
        $this->db->execute('INSERT INTO audit_events (actor_id,action,entity_type,entity_id,before_state,after_state,ip_address,correlation_id,reason,occurred_at) VALUES (:actor,:action,:type,:id,:before,:after,:ip,:cid,:reason,SYSUTCDATETIME())', [
            'actor' => $actorId, 'action' => $action, 'type' => $entityType, 'id' => $entityId === null ? null : (string) $entityId,
            'before' => $before === [] ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === [] ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'ip' => $this->request->ip(), 'cid' => $this->request->correlationId, 'reason' => $reason,
        ]);
    }
}

