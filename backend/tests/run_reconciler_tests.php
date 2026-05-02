#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reconciliation scenarios without PHPUnit (no Composer required).
 * Usage: php backend/tests/run_reconciler_tests.php
 */

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    require dirname(__DIR__) . '/bootstrap_autoload.php';
}

use IDM\Infrastructure\Persistence\AuditRepository;
use IDM\Application\Reconciliation\Reconciler;
use IDM\Tests\Support\FakeDirectoryGateway;
use IDM\Tests\Support\StaticCsvPolicy;

/** @param callable(): void $fn */
function ok(string $label, callable $fn): void
{
    try {
        $fn();
        fwrite(STDOUT, "[pass] {$label}\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "[fail] {$label}: {$e->getMessage()}\n");
        exit(1);
    }
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($msg !== '' ? $msg : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function memory_audit(): AuditRepository
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

    return new AuditRepository($pdo);
}

ok('external password mismatch → password drift_detected, no labeledURI remediation', static function (): void {
    $ldap = new FakeDirectoryGateway();
    $ldap->people = [
        ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/users/jdoe'],
    ];
    $ldap->passwordOkForUid['jdoe'] = false;

    $csv = new StaticCsvPolicy([
        ['user' => 'jdoe', 'password' => 'csv-secret', 'httpUrl' => 'https://example.com/users/jdoe'],
    ]);

    $reconciler = new Reconciler($ldap, $csv, memory_audit());
    $result = $reconciler->run('native_pw');

    assert_same(1, $result['drift_detected']);
    assert_same(0, $result['drift_remediated']);
    assert_same([], $ldap->setLabeledUriCalls);
});

ok('CSV and LDAP aligned → zero drift', static function (): void {
    $ldap = new FakeDirectoryGateway();
    $ldap->people = [
        ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/users/jdoe'],
    ];
    $ldap->passwordOkForUid['jdoe'] = true;

    $csv = new StaticCsvPolicy([
        ['user' => 'jdoe', 'password' => 'csv-secret', 'httpUrl' => 'https://example.com/users/jdoe'],
    ]);

    $reconciler = new Reconciler($ldap, $csv, memory_audit());
    $result = $reconciler->run('native_aligned');

    assert_same(0, $result['drift_detected']);
    assert_same(0, $result['drift_remediated']);
});

ok('labeledURI drift → remediated from CSV', static function (): void {
    $ldap = new FakeDirectoryGateway();
    $ldap->people = [
        ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://wrong.example/wrong'],
    ];
    $ldap->passwordOkForUid['jdoe'] = true;

    $csv = new StaticCsvPolicy([
        ['user' => 'jdoe', 'password' => 'secret', 'httpUrl' => 'https://example.com/users/jdoe'],
    ]);

    $reconciler = new Reconciler($ldap, $csv, memory_audit());
    $result = $reconciler->run('native_uri');

    assert_same(1, $result['drift_detected']);
    assert_same(1, $result['drift_remediated']);
    assert_same(1, count($ldap->setLabeledUriCalls));
    assert_same('https://example.com/users/jdoe', $ldap->setLabeledUriCalls[0]['httpUrl']);
});

ok('LDAP-only user not in CSV → quarantine', static function (): void {
    $ldap = new FakeDirectoryGateway();
    $ldap->people = [
        ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/users/jdoe'],
        ['uid' => 'orphan', 'dn' => 'uid=orphan,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/o'],
    ];
    $ldap->passwordOkForUid['jdoe'] = true;

    $csv = new StaticCsvPolicy([
        ['user' => 'jdoe', 'password' => 'secret', 'httpUrl' => 'https://example.com/users/jdoe'],
    ]);

    $reconciler = new Reconciler($ldap, $csv, memory_audit());
    $result = $reconciler->run('native_q');

    assert_same(1, $result['quarantined']);
    assert_same(['orphan'], $ldap->quarantineCalls);
});

ok('syncPasswords repairs mismatch (no drift after remediate)', static function (): void {
    $ldap = new FakeDirectoryGateway();
    $ldap->people = [
        ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/users/jdoe'],
    ];
    $ldap->passwordOkForUid['jdoe'] = false;

    $csv = new StaticCsvPolicy([
        ['user' => 'jdoe', 'password' => 'fixed', 'httpUrl' => 'https://example.com/users/jdoe'],
    ]);

    $reconciler = new Reconciler($ldap, $csv, memory_audit());
    $result = $reconciler->run('native_sync', ['syncPasswords' => true]);

    assert_same(0, $result['drift_detected']);
    assert_same(1, $result['passwords_remediated']);
    assert_same(0, $result['password_remediate_failed']);
    assert_same(['jdoe'], $ldap->passwordSyncCalls);
});

ok('password drift + labeledURI drift both counted', static function (): void {
    $ldap = new FakeDirectoryGateway();
    $ldap->people = [
        ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://wrong.example'],
    ];
    $ldap->passwordOkForUid['jdoe'] = false;

    $csv = new StaticCsvPolicy([
        ['user' => 'jdoe', 'password' => 'pw', 'httpUrl' => 'https://example.com/users/jdoe'],
    ]);

    $reconciler = new Reconciler($ldap, $csv, memory_audit());
    $result = $reconciler->run('native_both');

    assert_same(2, $result['drift_detected']);
    assert_same(1, $result['drift_remediated']);
});

ok('mail drift → remediated from CSV', static function (): void {
    $ldap = new FakeDirectoryGateway();
    $ldap->people = [
        ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/users/jdoe', 'mail' => 'old@wrong.example'],
    ];
    $ldap->passwordOkForUid['jdoe'] = true;

    $csv = new StaticCsvPolicy([
        ['user' => 'jdoe', 'password' => 'secret', 'httpUrl' => 'https://example.com/users/jdoe', 'mail' => 'jdoe@example.com'],
    ]);

    $reconciler = new Reconciler($ldap, $csv, memory_audit());
    $result = $reconciler->run('native_mail');

    assert_same(1, $result['drift_detected']);
    assert_same(1, $result['drift_remediated']);
    assert_same(1, count($ldap->setMailCalls));
    assert_same('jdoe@example.com', $ldap->setMailCalls[0]['mail']);
});

ok('telephoneNumber drift → remediated from CSV', static function (): void {
    $ldap = new FakeDirectoryGateway();
    $ldap->people = [
        [
            'uid' => 'jdoe',
            'dn' => 'uid=jdoe,ou=People,dc=example,dc=com',
            'labeledURI' => 'https://example.com/users/jdoe',
            'telephoneNumber' => '+1999',
        ],
    ];
    $ldap->passwordOkForUid['jdoe'] = true;

    $csv = new StaticCsvPolicy([
        [
            'user' => 'jdoe',
            'password' => 'secret',
            'httpUrl' => 'https://example.com/users/jdoe',
            'telephoneNumber' => '+15551234567',
        ],
    ]);

    $reconciler = new Reconciler($ldap, $csv, memory_audit());
    $result = $reconciler->run('native_tel');

    assert_same(1, $result['drift_detected']);
    assert_same(1, $result['drift_remediated']);
    assert_same(1, count($ldap->setTelephoneNumberCalls));
    assert_same('+15551234567', $ldap->setTelephoneNumberCalls[0]['telephoneNumber']);
});

fwrite(STDOUT, "\nAll reconciler scenario tests passed.\n");
