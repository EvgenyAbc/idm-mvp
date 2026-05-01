<?php

declare(strict_types=1);

namespace IDM\Infrastructure\Persistence;

use PDO;

final class ApprovalRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $username, string $field, ?string $oldValue, string $newValue, string $reason): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO approvals (username, field_name, old_value, new_value, status, reason, requested_at)
             VALUES (:username, :field_name, :old_value, :new_value, "pending", :reason, :requested_at)'
        );
        $stmt->execute([
            ':username' => $username,
            ':field_name' => $field,
            ':old_value' => $oldValue,
            ':new_value' => $newValue,
            ':reason' => $reason,
            ':requested_at' => gmdate('c'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function pending(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM approvals WHERE status = "pending" ORDER BY id DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM approvals WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function decide(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE approvals SET status = :status, decided_at = :decided_at WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':decided_at' => gmdate('c'),
            ':id' => $id,
        ]);
    }
}
