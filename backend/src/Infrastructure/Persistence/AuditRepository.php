<?php

declare(strict_types=1);

namespace IDM\Infrastructure\Persistence;

use PDO;

final class AuditRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function log(array $event): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO events (run_id, event_type, username, field_name, old_value, new_value, status, reason, payload, created_at)
             VALUES (:run_id, :event_type, :username, :field_name, :old_value, :new_value, :status, :reason, :payload, :created_at)'
        );

        $stmt->execute([
            ':run_id' => $event['run_id'] ?? 'manual',
            ':event_type' => $event['event_type'] ?? 'unknown',
            ':username' => $event['username'] ?? null,
            ':field_name' => $event['field_name'] ?? null,
            ':old_value' => $event['old_value'] ?? null,
            ':new_value' => $event['new_value'] ?? null,
            ':status' => $event['status'] ?? 'info',
            ':reason' => $event['reason'] ?? null,
            ':payload' => isset($event['payload']) ? json_encode($event['payload'], JSON_THROW_ON_ERROR) : null,
            ':created_at' => gmdate('c'),
        ]);
    }

    public function metrics(): array
    {
        $statuses = [
            'allowed',
            'denied',
            'pending_approval',
            'approved',
            'rejected',
            'quarantined',
            'remediated',
            'drift_detected',
        ];
        $metrics = [];
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS count FROM events GROUP BY status');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metrics[$row['status']] = (int) $row['count'];
        }

        $pending = (int) $this->pdo->query('SELECT COUNT(*) FROM approvals WHERE status = "pending"')->fetchColumn();

        foreach ($statuses as $status) {
            $metrics[$status] = $metrics[$status] ?? 0;
        }
        $metrics['pending_approvals_total'] = $pending;

        return $metrics;
    }

    public function recentEvents(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Reconciliation / drift / policy rows only (not noisy LDAP browse-style events). */
    public function recentSecurityEvents(int $limit = 80): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM events WHERE event_type = :etype ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':etype', 'reconciliation', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
