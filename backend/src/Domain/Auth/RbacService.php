<?php

declare(strict_types=1);

namespace IDM\Domain\Auth;

use IDM\Shared\Config\Config;
use IDM\Infrastructure\Ldap\LdapGateway;

final class RbacService
{
    public const PERM_DASHBOARD_OPERATIONS_VIEW = 'dashboard.operations.view';
    public const PERM_DASHBOARD_METRICS_VIEW = 'dashboard.metrics.view';
    public const PERM_DASHBOARD_APPROVALS_VIEW = 'dashboard.approvals.view';
    public const PERM_DASHBOARD_USERS_VIEW = 'dashboard.users.view';
    public const PERM_DASHBOARD_LDAP_VIEW = 'dashboard.ldap.view';
    public const PERM_DASHBOARD_PROFILE_VIEW = 'dashboard.profile.view';
    public const PERM_DASHBOARD_EVENTS_VIEW = 'dashboard.events.view';
    public const PERM_LDAP_SEARCH = 'ldap.search';
    public const PERM_LDAP_BROWSE = 'ldap.browse';
    public const PERM_LDAP_VIEW_ATTRIBUTES = 'ldap.view_attributes';
    public const PERM_LDAP_EDIT = 'ldap.edit';
    public const PERM_LDAP_EXPORT = 'ldap.export';
    public const PERM_PROVISION_RUN = 'provision.run';
    public const PERM_RECONCILE_RUN = 'reconcile.run';
    public const PERM_APPROVAL_DECIDE = 'approval.decide';
    public const PERM_USERS_PASSWORD_CHANGE = 'users.password.change';
    public const PERM_METRICS_EVENTS_VIEW = 'metrics.events.view';

    private const ALL_PERMISSIONS = [
        self::PERM_DASHBOARD_OPERATIONS_VIEW,
        self::PERM_DASHBOARD_METRICS_VIEW,
        self::PERM_DASHBOARD_APPROVALS_VIEW,
        self::PERM_DASHBOARD_USERS_VIEW,
        self::PERM_DASHBOARD_LDAP_VIEW,
        self::PERM_DASHBOARD_PROFILE_VIEW,
        self::PERM_DASHBOARD_EVENTS_VIEW,
        self::PERM_LDAP_SEARCH,
        self::PERM_LDAP_BROWSE,
        self::PERM_LDAP_VIEW_ATTRIBUTES,
        self::PERM_LDAP_EDIT,
        self::PERM_LDAP_EXPORT,
        self::PERM_PROVISION_RUN,
        self::PERM_RECONCILE_RUN,
        self::PERM_APPROVAL_DECIDE,
        self::PERM_USERS_PASSWORD_CHANGE,
        self::PERM_METRICS_EVENTS_VIEW,
    ];

    public function __construct(private LdapGateway $ldap)
    {
    }

    public function authContextForUsername(string $username): array
    {
        if ($username === '') {
            return [
                'username' => '',
                'groups' => [],
                'permissions' => [],
            ];
        }

        $groups = $this->ldap->groupsForUser($username);
        $permissions = [];
        $mapping = $this->groupPermissionMap();
        foreach ($groups as $group) {
            foreach ($mapping[$group] ?? [] as $permission) {
                $permissions[$permission] = true;
            }
        }

        if ($this->isBootstrapAdmin($username)) {
            foreach (self::ALL_PERMISSIONS as $permission) {
                $permissions[$permission] = true;
            }
        }

        return [
            'username' => $username,
            'groups' => $groups,
            'permissions' => array_values(array_keys($permissions)),
        ];
    }

    private function isBootstrapAdmin(string $username): bool
    {
        $enabled = strtolower((string) Config::get('RBAC_BOOTSTRAP_ADMIN', 'true'));
        $adminUsername = (string) Config::get('ADMIN_USERNAME', 'alphaadmin');
        return $enabled === 'true'
            && $username !== ''
            && strcasecmp($username, $adminUsername) === 0;
    }

    private function groupPermissionMap(): array
    {
        return [
            'idm-ldap-viewers' => [
                self::PERM_DASHBOARD_METRICS_VIEW,
                self::PERM_DASHBOARD_LDAP_VIEW,
                self::PERM_DASHBOARD_PROFILE_VIEW,
                self::PERM_LDAP_SEARCH,
                self::PERM_LDAP_BROWSE,
                self::PERM_LDAP_VIEW_ATTRIBUTES,
            ],
            'idm-ldap-editors' => [
                self::PERM_LDAP_EDIT,
            ],
            'idm-ldap-exporters' => [
                self::PERM_LDAP_EXPORT,
            ],
            'idm-ops-admins' => [
                self::PERM_DASHBOARD_OPERATIONS_VIEW,
                self::PERM_DASHBOARD_METRICS_VIEW,
                self::PERM_DASHBOARD_APPROVALS_VIEW,
                self::PERM_DASHBOARD_USERS_VIEW,
                self::PERM_DASHBOARD_LDAP_VIEW,
                self::PERM_DASHBOARD_PROFILE_VIEW,
                self::PERM_DASHBOARD_EVENTS_VIEW,
                self::PERM_LDAP_SEARCH,
                self::PERM_LDAP_BROWSE,
                self::PERM_LDAP_VIEW_ATTRIBUTES,
                self::PERM_LDAP_EDIT,
                self::PERM_LDAP_EXPORT,
                self::PERM_PROVISION_RUN,
                self::PERM_RECONCILE_RUN,
                self::PERM_APPROVAL_DECIDE,
                self::PERM_USERS_PASSWORD_CHANGE,
                self::PERM_METRICS_EVENTS_VIEW,
            ],
        ];
    }
}
