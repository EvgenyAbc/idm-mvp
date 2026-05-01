export const PERMISSIONS = {
  dashboardOperationsView: 'dashboard.operations.view',
  dashboardMetricsView: 'dashboard.metrics.view',
  dashboardApprovalsView: 'dashboard.approvals.view',
  dashboardUsersView: 'dashboard.users.view',
  dashboardLdapView: 'dashboard.ldap.view',
  dashboardProfileView: 'dashboard.profile.view',
  dashboardEventsView: 'dashboard.events.view',
  ldapSearch: 'ldap.search',
  ldapBrowse: 'ldap.browse',
  ldapViewAttributes: 'ldap.view_attributes',
  ldapEdit: 'ldap.edit',
  ldapExport: 'ldap.export',
  provisionRun: 'provision.run',
  reconcileRun: 'reconcile.run',
  approvalDecide: 'approval.decide',
  usersPasswordChange: 'users.password.change',
  metricsEventsView: 'metrics.events.view',
} as const

export type Permission = (typeof PERMISSIONS)[keyof typeof PERMISSIONS]
