import { type LoaderFunctionArgs, redirect } from 'react-router-dom'
import { api, type ApprovalRow, type AuditEventRow, type LdapUserRow, type SourceUserRow } from '../../../api/client'
import { decodeToken, firstAllowedDashboardPath, hasPerm, requireSession, type Session } from '../../../shared/lib/authSession'
import { PERMISSIONS, type Permission } from '../../../shared/lib/permissions'

const ADMIN_USERNAME = import.meta.env.VITE_ADMIN_USERNAME ?? 'alphaadmin'
const DEFAULT_EXPLORER_DN = 'dc=example,dc=com'
const EDITABLE_ATTRIBUTES = ['mail', 'telephoneNumber', 'labeledURI']

type LdapSearchRow = { dn: string; rdn: string }
type LdapNode = { dn?: string; rdn?: string; attributes?: Record<string, unknown>; children?: LdapNode[] | null } | null

export type OperationsCardLabelKey =
  | 'sourceUsers'
  | 'withProfileUrl'
  | 'missingProfileUrl'
  | 'provisioningAccess'
  | 'reconciliationAccess'

export type OperationsLoaderData = {
  sourceUsers: SourceUserRow[]
  canViewOperations: boolean
  canRunProvisioning: boolean
  canRunReconcile: boolean
  usageTimeline: Array<{ label: string; value: number }>
  currentUsage: Array<{ labelKey: OperationsCardLabelKey; value: number }>
}

export type MetricsLoaderData = {
  metrics: Record<string, number>
  canViewMetrics: boolean
  usageTimeline: Array<{ label: string; value: number }>
  eventTypeBreakdown: Array<{ label: string; value: number }>
}

export type ApprovalsLoaderData = {
  approvals: ApprovalRow[]
  canViewApprovals: boolean
  canDecideApprovals: boolean
}

export type UsersLoaderData = {
  users: LdapUserRow[]
  canViewUsers: boolean
  canChangePasswords: boolean
}

export type ExplorerLoaderData = {
  q: string
  dn: string
  search: LdapSearchRow[]
  node: LdapNode
  groups: string[]
  canSearchLdap: boolean
  canBrowseTree: boolean
  canViewAttributes: boolean
  canEditLdap: boolean
  canExportLdap: boolean
  showLdapExplorer: boolean
  isDirectoryAdmin: boolean
  parentDn: string
  ancestorDns: string[]
  children: LdapSearchRow[]
}

export type ProfileLoaderData = {
  username: string
  canViewProfile: boolean
  canViewAttributes: boolean
  showLdapExplorer: boolean
  editableAttributes: string[]
  self: LdapUserRow | null
  selfNode: LdapNode
}

export type EventsLoaderData = {
  events: AuditEventRow[]
  securityEvents: AuditEventRow[]
  canViewEvents: boolean
}

function parseUrl(request: Request): URL {
  return new URL(request.url)
}

function getSessionUsername(session: Session): string {
  return session.username || decodeToken(session.token)
}

function parentDnFromDn(dn = ''): string {
  if (!dn) return ''
  const parts = dn.split(',')
  if (parts.length <= 1) return ''
  return parts.slice(1).join(',').trim()
}

function ancestorDnsFromDn(dn = ''): string[] {
  const ancestors: string[] = []
  let current = parentDnFromDn(dn)
  while (current) {
    ancestors.push(current)
    current = parentDnFromDn(current)
  }
  return ancestors
}

async function tryLoadNode(dn: string): Promise<LdapNode> {
  if (!dn) return null
  try {
    const data = await api.ldapTreeNode(dn)
    return (data.node as LdapNode) ?? null
  } catch {
    return null
  }
}

async function tryLoadChildren(dn: string): Promise<LdapSearchRow[]> {
  if (!dn) return []
  try {
    // Prefer the node endpoint because it returns direct children metadata.
    const data = await api.ldapTreeNode(dn)
    const node = (data.node as { children?: unknown } | null) ?? null
    const children = Array.isArray(node?.children) ? (node.children as Array<{ dn?: unknown; rdn?: unknown }>) : []

    return children
      .map((child) => {
        const childDn = typeof child.dn === 'string' ? child.dn : ''
        if (!childDn) return null
        const rdn = typeof child.rdn === 'string' && child.rdn ? child.rdn : childDn.split(',')[0] ?? childDn
        return { dn: childDn, rdn }
      })
      .filter((row): row is LdapSearchRow => row != null)
  } catch {
    return []
  }
}

async function requirePermOrRedirect(permission: Permission): Promise<Session> {
  const session = await requireSession()
  if (!hasPerm(session, permission)) {
    throw redirect(firstAllowedDashboardPath(session))
  }
  return session
}

export async function operationsLoader({ request }: LoaderFunctionArgs): Promise<OperationsLoaderData> {
  const session = await requirePermOrRedirect(PERMISSIONS.dashboardOperationsView)
  const canRunProvisioning = hasPerm(session, PERMISSIONS.provisionRun)
  const canRunReconcile = hasPerm(session, PERMISSIONS.reconcileRun)
  const pathname = parseUrl(request).pathname
  const normalizedPathname = pathname.replace(/\/+$/, '') || '/'
  const isOverviewPath = normalizedPathname.endsWith('/operations')
  const isProvisioningPath = normalizedPathname.endsWith('/operations/provisioning')
  const shouldLoadSourceUsers = isOverviewPath || isProvisioningPath
  const shouldLoadMetrics = isOverviewPath
  const buildCurrentUsage = (sourceUsers: SourceUserRow[]): OperationsLoaderData['currentUsage'] => {
    const withUrl = sourceUsers.filter((item) => Boolean(item.httpUrl?.trim())).length
    return [
      { labelKey: 'sourceUsers', value: sourceUsers.length },
      { labelKey: 'withProfileUrl', value: withUrl },
      { labelKey: 'missingProfileUrl', value: Math.max(0, sourceUsers.length - withUrl) },
      { labelKey: 'provisioningAccess', value: canRunProvisioning ? 1 : 0 },
      { labelKey: 'reconciliationAccess', value: canRunReconcile ? 1 : 0 },
    ]
  }
  const buildUsageTimeline = (events: AuditEventRow[]): Array<{ label: string; value: number }> => {
    const now = new Date()
    const counts = new Map<string, number>()
    for (let idx = 6; idx >= 0; idx -= 1) {
      const day = new Date(now)
      day.setDate(now.getDate() - idx)
      const dayKey = day.toISOString().slice(0, 10)
      counts.set(dayKey, 0)
    }
    for (const event of events) {
      const raw = typeof event.created_at === 'string' ? event.created_at : ''
      if (!raw) continue
      const parsed = new Date(raw)
      if (Number.isNaN(parsed.getTime())) continue
      const key = parsed.toISOString().slice(0, 10)
      if (!counts.has(key)) continue
      counts.set(key, (counts.get(key) ?? 0) + 1)
    }
    return Array.from(counts.entries()).map(([key, value]) => ({ label: key.slice(5), value }))
  }

  const sourceUsers = shouldLoadSourceUsers ? (await api.sourceUsers()).items ?? [] : []
  let usageTimeline = buildUsageTimeline([])
  if (shouldLoadMetrics) {
    try {
      const metrics = await api.metrics()
      const events = [...(metrics.events ?? []), ...(metrics.security_events ?? [])]
      usageTimeline = buildUsageTimeline(events)
    } catch {
      // Operations overview remains available even when metrics endpoint is restricted/unavailable.
    }
  }

  return {
    sourceUsers,
    canViewOperations: true,
    canRunProvisioning,
    canRunReconcile,
    usageTimeline,
    currentUsage: buildCurrentUsage(sourceUsers),
  }
}

export async function metricsLoader(_args: LoaderFunctionArgs): Promise<MetricsLoaderData> {
  await requirePermOrRedirect(PERMISSIONS.dashboardMetricsView)
  const data = await api.metrics()
  const events = [...(data.events ?? []), ...(data.security_events ?? [])]
  const now = new Date()
  const dayCounts = new Map<string, number>()
  for (let idx = 6; idx >= 0; idx -= 1) {
    const day = new Date(now)
    day.setDate(now.getDate() - idx)
    dayCounts.set(day.toISOString().slice(0, 10), 0)
  }
  const typeCounts = new Map<string, number>()
  for (const event of events) {
    const rawDate = typeof event.created_at === 'string' ? event.created_at : ''
    if (rawDate) {
      const parsed = new Date(rawDate)
      if (!Number.isNaN(parsed.getTime())) {
        const key = parsed.toISOString().slice(0, 10)
        if (dayCounts.has(key)) {
          dayCounts.set(key, (dayCounts.get(key) ?? 0) + 1)
        }
      }
    }
    const type = typeof event.event_type === 'string' && event.event_type.trim() ? event.event_type.trim() : 'unknown'
    typeCounts.set(type, (typeCounts.get(type) ?? 0) + 1)
  }
  const usageTimeline = Array.from(dayCounts.entries()).map(([key, value]) => ({ label: key.slice(5), value }))
  const eventTypeBreakdown = Array.from(typeCounts.entries())
    .sort((a, b) => b[1] - a[1])
    .slice(0, 6)
    .map(([label, value]) => ({ label, value }))
  return { metrics: data.metrics ?? {}, canViewMetrics: true, usageTimeline, eventTypeBreakdown }
}

export async function approvalsLoader(_args: LoaderFunctionArgs): Promise<ApprovalsLoaderData> {
  const session = await requirePermOrRedirect(PERMISSIONS.dashboardApprovalsView)
  const data = await api.approvals()
  return {
    approvals: data.items ?? [],
    canViewApprovals: true,
    canDecideApprovals: hasPerm(session, PERMISSIONS.approvalDecide),
  }
}

export async function usersLoader(_args: LoaderFunctionArgs): Promise<UsersLoaderData> {
  const session = await requirePermOrRedirect(PERMISSIONS.dashboardUsersView)
  const data = await api.users()
  return {
    users: data.users ?? [],
    canViewUsers: true,
    canChangePasswords: hasPerm(session, PERMISSIONS.usersPasswordChange),
  }
}

export async function explorerLoader({ request }: LoaderFunctionArgs): Promise<ExplorerLoaderData> {
  const session = await requirePermOrRedirect(PERMISSIONS.dashboardLdapView)
  const username = getSessionUsername(session)
  const canSearchLdap = hasPerm(session, PERMISSIONS.ldapSearch)
  const canBrowseTree = hasPerm(session, PERMISSIONS.ldapBrowse)
  const canViewAttributes = hasPerm(session, PERMISSIONS.ldapViewAttributes)
  const canEditLdap = hasPerm(session, PERMISSIONS.ldapEdit)
  const canExportLdap = hasPerm(session, PERMISSIONS.ldapExport)
  const showLdapExplorer = canSearchLdap || canBrowseTree
  const isDirectoryAdmin = canBrowseTree || username === ADMIN_USERNAME
  const url = parseUrl(request)
  const q = url.searchParams.get('q')?.trim() ?? ''
  const requestedDn = url.searchParams.get('dn')?.trim() ?? ''
  const dn = requestedDn || (isDirectoryAdmin ? DEFAULT_EXPLORER_DN : '')

  if (!showLdapExplorer && dn) {
    throw redirect('/ldap/operations')
  }

  const search = q && canSearchLdap ? ((await api.ldapSearch(q)).items ?? []) : []
  const node = dn && (showLdapExplorer || isDirectoryAdmin) ? await tryLoadNode(dn) : null
  const parentDn = parentDnFromDn(dn)
  const ancestorDns = ancestorDnsFromDn(dn)
  const children = dn && showLdapExplorer ? await tryLoadChildren(dn) : []

  return {
    q,
    dn,
    search,
    node,
    groups: session.groups ?? [],
    canSearchLdap,
    canBrowseTree,
    canViewAttributes,
    canEditLdap,
    canExportLdap,
    showLdapExplorer,
    isDirectoryAdmin,
    parentDn,
    ancestorDns,
    children,
  }
}

export async function profileLoader(_args: LoaderFunctionArgs): Promise<ProfileLoaderData> {
  const session = await requirePermOrRedirect(PERMISSIONS.dashboardProfileView)
  const username = getSessionUsername(session)
  const canViewAttributes = hasPerm(session, PERMISSIONS.ldapViewAttributes)
  const showLdapExplorer = hasPerm(session, PERMISSIONS.ldapSearch) || hasPerm(session, PERMISSIONS.ldapBrowse)
  if (!canViewAttributes || !username) {
    return {
      username,
      canViewProfile: true,
      canViewAttributes,
      showLdapExplorer,
      editableAttributes: EDITABLE_ATTRIBUTES,
      self: null,
      selfNode: null,
    }
  }

  if (!showLdapExplorer) {
    return {
      username,
      canViewProfile: true,
      canViewAttributes,
      showLdapExplorer,
      editableAttributes: EDITABLE_ATTRIBUTES,
      self: null,
      selfNode: null,
    }
  }

  const data = await api.ldapSelfNode()
  const self = data.dn
    ? {
        uid: username,
        dn: data.dn,
      }
    : null
  const selfNode = (data.node as LdapNode) ?? null
  return {
    username,
    canViewProfile: true,
    canViewAttributes,
    showLdapExplorer,
    editableAttributes: EDITABLE_ATTRIBUTES,
    self,
    selfNode,
  }
}

export async function eventsLoader(_args: LoaderFunctionArgs): Promise<EventsLoaderData> {
  const session = await requirePermOrRedirect(PERMISSIONS.dashboardEventsView)
  if (!hasPerm(session, PERMISSIONS.metricsEventsView)) {
    return { events: [], securityEvents: [], canViewEvents: false }
  }
  const data = await api.metrics()
  return {
    events: data.events ?? [],
    securityEvents: data.security_events ?? [],
    canViewEvents: true,
  }
}
