<?php

declare(strict_types=1);

namespace IDM\Infrastructure\Persistence;

use PDO;
use IDM\Shared\Config\Config;

final class Database
{
    public static function connect(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException(
                'Missing PDO SQLite driver (pdo_sqlite). Install php-sqlite3/php8.x-sqlite3 and restart PHP.'
            );
        }

        $dbPath = self::resolveDbPath();
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::migrate($pdo);
        return $pdo;
    }

    private static function resolveDbPath(): string
    {
        $configuredPath = Config::sqliteDbPath();
        if ($configuredPath === '') {
            throw new \RuntimeException('SQLITE_DB_PATH resolved to an empty path.');
        }

        if (is_file($configuredPath)) {
            if (!is_readable($configuredPath) || !is_writable($configuredPath)) {
                throw new \RuntimeException(sprintf(
                    'SQLite DB "%s" must be readable and writable. Fix file permissions/ownership.',
                    $configuredPath
                ));
            }
            return $configuredPath;
        }

        $dir = dirname($configuredPath);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException(sprintf(
                'SQLite DB directory "%s" is not writable. Fix directory permissions/ownership.',
                $dir
            ));
        }

        return $configuredPath;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id TEXT NOT NULL,
                event_type TEXT NOT NULL,
                username TEXT,
                field_name TEXT,
                old_value TEXT,
                new_value TEXT,
                status TEXT NOT NULL,
                reason TEXT,
                payload TEXT,
                created_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS approvals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                field_name TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                reason TEXT,
                requested_at TEXT NOT NULL,
                decided_at TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS source_users (
                username TEXT PRIMARY KEY,
                password TEXT NOT NULL,
                http_url TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_source_users_updated_at ON source_users(updated_at)'
        );
    }
}
