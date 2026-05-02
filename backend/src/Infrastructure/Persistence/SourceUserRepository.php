<?php

declare(strict_types=1);

namespace IDM\Infrastructure\Persistence;

use PDO;

final class SourceUserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array{user:string,password:string,httpUrl:string,mail:string,telephoneNumber:string}>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT username, password, http_url, mail, telephone_number FROM source_users ORDER BY username ASC'
        );
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'user' => (string) ($row['username'] ?? ''),
                'password' => (string) ($row['password'] ?? ''),
                'httpUrl' => (string) ($row['http_url'] ?? ''),
                'mail' => (string) ($row['mail'] ?? ''),
                'telephoneNumber' => (string) ($row['telephone_number'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{user:string,password:string,httpUrl:string,mail:string,telephoneNumber:string}>
     */
    public function replaceAll(array $rows): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM source_users');
            $stmt = $this->pdo->prepare(
                'INSERT INTO source_users (username, password, http_url, mail, telephone_number, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $now = gmdate('c');
            foreach ($rows as $row) {
                $user = trim((string) ($row['user'] ?? ''));
                if ($user === '') {
                    continue;
                }
                $stmt->execute([
                    $user,
                    (string) ($row['password'] ?? ''),
                    trim((string) ($row['httpUrl'] ?? '')),
                    trim((string) ($row['mail'] ?? '')),
                    trim((string) ($row['telephoneNumber'] ?? '')),
                    $now,
                    $now,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->all();
    }

    public function upsert(
        string $user,
        string $password,
        string $httpUrl,
        string $mail = '',
        string $telephoneNumber = ''
    ): void {
        $sql = 'INSERT INTO source_users (username, password, http_url, mail, telephone_number, created_at, updated_at)
                VALUES (:username, :password, :http_url, :mail, :telephone_number, :created_at, :updated_at)
                ON CONFLICT(username) DO UPDATE SET
                    password = excluded.password,
                    http_url = excluded.http_url,
                    mail = excluded.mail,
                    telephone_number = excluded.telephone_number,
                    updated_at = excluded.updated_at';
        $stmt = $this->pdo->prepare($sql);
        $now = gmdate('c');
        $stmt->execute([
            ':username' => trim($user),
            ':password' => $password,
            ':http_url' => trim($httpUrl),
            ':mail' => trim($mail),
            ':telephone_number' => trim($telephoneNumber),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /**
     * @return array{user:string,password:string,httpUrl:string,mail:string,telephoneNumber:string}|null
     */
    public function findByUser(string $user): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT username, password, http_url, mail, telephone_number FROM source_users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([trim($user)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'user' => (string) ($row['username'] ?? ''),
            'password' => (string) ($row['password'] ?? ''),
            'httpUrl' => (string) ($row['http_url'] ?? ''),
            'mail' => (string) ($row['mail'] ?? ''),
            'telephoneNumber' => (string) ($row['telephone_number'] ?? ''),
        ];
    }

    public function updateHttpUrl(string $user, string $httpUrl): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE source_users SET http_url = ?, updated_at = ? WHERE username = ?'
        );
        $stmt->execute([trim($httpUrl), gmdate('c'), trim($user)]);
    }

    public function updateMail(string $user, string $mail): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE source_users SET mail = ?, updated_at = ? WHERE username = ?'
        );
        $stmt->execute([trim($mail), gmdate('c'), trim($user)]);
    }

    public function updateTelephoneNumber(string $user, string $telephoneNumber): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE source_users SET telephone_number = ?, updated_at = ? WHERE username = ?'
        );
        $stmt->execute([trim($telephoneNumber), gmdate('c'), trim($user)]);
    }

    public function delete(string $user): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM source_users WHERE username = ?');
        $stmt->execute([trim($user)]);
    }
}
