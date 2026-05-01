<?php

declare(strict_types=1);

namespace IDM\Tests;

use IDM\Infrastructure\Persistence\AuditRepository;
use IDM\Application\Reconciliation\Reconciler;
use IDM\Tests\Support\FakeDirectoryGateway;
use IDM\Tests\Support\StaticCsvPolicy;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReconcilerTest extends TestCase
{
    private PDO $pdo;

    private AuditRepository $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
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
        $this->pdo->exec(
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
        $this->audit = new AuditRepository($this->pdo);
    }

    /**
     * External password change: LDAP no longer accepts the CSV plaintext → drift_detected, not remediated.
     */
    public function testExternalPasswordChangeCreatesPasswordDriftAndDoesNotRemediate(): void
    {
        $ldap = new FakeDirectoryGateway();
        $ldap->people = [
            ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/users/jdoe'],
        ];
        $ldap->passwordOkForUid['jdoe'] = false;

        $csv = new StaticCsvPolicy([
            ['user' => 'jdoe', 'password' => 'csv-secret', 'httpUrl' => 'https://example.com/users/jdoe'],
        ]);

        $reconciler = new Reconciler($ldap, $csv, $this->audit);
        $result = $reconciler->run('run_test_pw');

        self::assertSame(1, $result['drift_detected']);
        self::assertSame(0, $result['drift_remediated']);
        self::assertSame([], $ldap->setLabeledUriCalls);

        $stmt = $this->pdo->query(
            'SELECT status, field_name, username FROM events WHERE run_id = \'run_test_pw\' ORDER BY id'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('drift_detected', $rows[0]['status']);
        self::assertSame('userPassword', $rows[0]['field_name']);
        self::assertSame('jdoe', $rows[0]['username']);
    }

    /**
     * CSV and LDAP aligned on password and labeledURI → no drift.
     */
    public function testAlignedCsvAndLdapProducesNoDrift(): void
    {
        $ldap = new FakeDirectoryGateway();
        $ldap->people = [
            ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/users/jdoe'],
        ];
        $ldap->passwordOkForUid['jdoe'] = true;

        $csv = new StaticCsvPolicy([
            ['user' => 'jdoe', 'password' => 'csv-secret', 'httpUrl' => 'https://example.com/users/jdoe'],
        ]);

        $reconciler = new Reconciler($ldap, $csv, $this->audit);
        $result = $reconciler->run('run_aligned');

        self::assertSame(0, $result['drift_detected']);
        self::assertSame(0, $result['drift_remediated']);
        self::assertSame([], $ldap->setLabeledUriCalls);
    }

    /**
     * labeledURI differs; CSV URL is valid → LDAP updated from CSV (remediated).
     */
    public function testLabeledUriDriftIsRemediatedFromCsv(): void
    {
        $ldap = new FakeDirectoryGateway();
        $ldap->people = [
            ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://wrong.example/wrong'],
        ];
        $ldap->passwordOkForUid['jdoe'] = true;

        $csv = new StaticCsvPolicy([
            ['user' => 'jdoe', 'password' => 'secret', 'httpUrl' => 'https://example.com/users/jdoe'],
        ]);

        $reconciler = new Reconciler($ldap, $csv, $this->audit);
        $result = $reconciler->run('run_uri');

        self::assertSame(1, $result['drift_detected']);
        self::assertSame(1, $result['drift_remediated']);
        self::assertCount(1, $ldap->setLabeledUriCalls);
        self::assertSame('uid=jdoe,ou=People,dc=example,dc=com', $ldap->setLabeledUriCalls[0]['dn']);
        self::assertSame('https://example.com/users/jdoe', $ldap->setLabeledUriCalls[0]['httpUrl']);
    }

    /** LDAP-only user not in CSV → quarantined. */
    public function testLdapOnlyUserGetsQuarantined(): void
    {
        $ldap = new FakeDirectoryGateway();
        $ldap->people = [
            ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/users/jdoe'],
            ['uid' => 'orphan', 'dn' => 'uid=orphan,ou=People,dc=example,dc=com', 'labeledURI' => 'https://example.com/o'],
        ];
        $ldap->passwordOkForUid['jdoe'] = true;

        $csv = new StaticCsvPolicy([
            ['user' => 'jdoe', 'password' => 'secret', 'httpUrl' => 'https://example.com/users/jdoe'],
        ]);

        $reconciler = new Reconciler($ldap, $csv, $this->audit);
        $result = $reconciler->run('run_q');

        self::assertSame(1, $result['quarantined']);
        self::assertSame(['orphan'], $ldap->quarantineCalls);
    }

    /** Password drift + labeledURI drift both contribute to drift_detected count. */
    public function testPasswordAndLabeledUriDriftBothCounted(): void
    {
        $ldap = new FakeDirectoryGateway();
        $ldap->people = [
            ['uid' => 'jdoe', 'dn' => 'uid=jdoe,ou=People,dc=example,dc=com', 'labeledURI' => 'https://wrong.example'],
        ];
        $ldap->passwordOkForUid['jdoe'] = false;

        $csv = new StaticCsvPolicy([
            ['user' => 'jdoe', 'password' => 'pw', 'httpUrl' => 'https://example.com/users/jdoe'],
        ]);

        $reconciler = new Reconciler($ldap, $csv, $this->audit);
        $result = $reconciler->run('run_both');

        self::assertSame(2, $result['drift_detected']);
        self::assertSame(1, $result['drift_remediated']);
    }
}
