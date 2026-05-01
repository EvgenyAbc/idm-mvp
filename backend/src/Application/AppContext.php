<?php

declare(strict_types=1);

namespace IDM\Application;

use IDM\Infrastructure\Persistence\ApprovalRepository;
use IDM\Domain\Auth\RbacService;
use IDM\Infrastructure\Persistence\AuditRepository;
use IDM\Infrastructure\Ldap\AuthService;
use IDM\Infrastructure\Ldap\LdapGateway;
use IDM\Application\Provisioning\CsvProvisioner;
use IDM\Application\Reconciliation\Reconciler;
use IDM\Infrastructure\Persistence\SourceUserRepository;
use IDM\Infrastructure\Persistence\Database;

final class AppContext
{
    public function __construct(
        public AuditRepository $audit,
        public ApprovalRepository $approvals,
        public SourceUserRepository $sourceUsers,
        public LdapGateway $ldap,
        public CsvProvisioner $provisioner,
        public Reconciler $reconciler,
        public AuthService $auth,
        public RbacService $rbac
    ) {
    }

    public static function bootstrap(): self
    {
        $pdo = Database::connect();
        $audit = new AuditRepository($pdo);
        $approvals = new ApprovalRepository($pdo);
        $sourceUsers = new SourceUserRepository($pdo);
        $ldap = new LdapGateway();
        $provisioner = new CsvProvisioner($ldap, $audit, $approvals, $sourceUsers);
        $reconciler = new Reconciler($ldap, $provisioner, $audit);
        $auth = new AuthService();
        $rbac = new RbacService($ldap);

        return new self($audit, $approvals, $sourceUsers, $ldap, $provisioner, $reconciler, $auth, $rbac);
    }
}
