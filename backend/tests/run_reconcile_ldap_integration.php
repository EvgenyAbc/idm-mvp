#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Live LDAP + SQLite-source reconciliation check (optional). Use after forcing an external
 * password change with ops/test_reconcile_external_password.sh or ldappasswd.
 *
 * Usage:
 *   php backend/tests/run_reconcile_ldap_integration.php
 *
 * Expects LDAP_* / LDAP_URI in the environment (see backend/.env.example).
 */

$syncPasswords = false;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--sync-passwords') {
        $syncPasswords = true;
    }
}

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    require dirname(__DIR__) . '/bootstrap_autoload.php';
}

use IDM\Infrastructure\Persistence\ApprovalRepository;
use IDM\Infrastructure\Persistence\AuditRepository;
use IDM\Infrastructure\Ldap\LdapGateway;
use IDM\Application\Provisioning\CsvProvisioner;
use IDM\Application\Reconciliation\Reconciler;
use IDM\Infrastructure\Persistence\SourceUserRepository;

function pdoMemoryWithSchema(): \PDO
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
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

    return $pdo;
}

$pdo = pdoMemoryWithSchema();
$audit = new AuditRepository($pdo);
$approvals = new ApprovalRepository($pdo);
$sourceUsers = new SourceUserRepository($pdo);
$ldap = new LdapGateway();
$provisioner = new CsvProvisioner($ldap, $audit, $approvals, $sourceUsers);
$reconciler = new Reconciler($ldap, $provisioner, $audit);

$runId = 'ldap_integration_' . gmdate('Ymd_His');
$opts = $syncPasswords ? ['syncPasswords' => true] : null;
$result = $reconciler->run($runId, $opts);

echo json_encode(['ok' => true, 'run_id' => $runId, 'result' => $result], JSON_PRETTY_PRINT) . "\n";
