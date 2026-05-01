<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/src/Shared/Config/Config.php';
require_once __DIR__ . '/../backend/src/Domain/Reconciliation/DirectoryGateway.php';
require_once __DIR__ . '/../backend/src/Domain/Provisioning/SourcePolicy.php';
require_once __DIR__ . '/../backend/src/Infrastructure/Persistence/Database.php';
require_once __DIR__ . '/../backend/src/Infrastructure/Persistence/SourceUserRepository.php';
require_once __DIR__ . '/../backend/src/Infrastructure/Persistence/AuditRepository.php';
require_once __DIR__ . '/../backend/src/Infrastructure/Persistence/ApprovalRepository.php';
require_once __DIR__ . '/../backend/src/Infrastructure/Ldap/LdapGateway.php';
require_once __DIR__ . '/../backend/src/Application/Provisioning/CsvProvisioner.php';

use IDM\Application\Provisioning\CsvProvisioner;
use IDM\Infrastructure\Ldap\LdapGateway;
use IDM\Infrastructure\Persistence\ApprovalRepository;
use IDM\Infrastructure\Persistence\AuditRepository;
use IDM\Infrastructure\Persistence\Database;
use IDM\Infrastructure\Persistence\SourceUserRepository;

function assertTrue(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$drivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
if (!in_array('sqlite', $drivers, true)) {
    echo "alpha_smoke: SKIPPED (pdo_sqlite driver not available)\n";
    exit(0);
}

$pdo = Database::connect();
$audit = new AuditRepository($pdo);
$approvals = new ApprovalRepository($pdo);
$ldap = new LdapGateway();
$sourceUsers = new SourceUserRepository($pdo);
$provisioner = new CsvProvisioner($ldap, $audit, $approvals, $sourceUsers);

$sourceUsers->replaceAll([
    ['user' => 'jdoe', 'password' => '123', 'httpUrl' => 'https://example.com/users/jdoe'],
    ['user' => 'asmith', 'password' => '123', 'httpUrl' => 'https://example.com/users/asmith'],
]);
$rows = $provisioner->rows();
assertTrue(count($rows) >= 2, 'Source rows should return at least two rows');
assertTrue(isset($rows[0]['user'], $rows[0]['password'], $rows[0]['httpUrl']), 'Source mapped fields should exist');

$metrics = $audit->metrics();
assertTrue(is_array($metrics), 'Audit metrics should be an array');
assertTrue(array_key_exists('pending_approvals_total', $metrics), 'Metrics should include pending approvals total');

echo "alpha_smoke: OK\n";
