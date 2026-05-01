export type AuthContext = {
  username: string
  groups: string[]
  permissions: string[]
}

export type SourceUserRow = {
  user: string
  password: string
  httpUrl: string
}

export type LdapUserRow = {
  uid: string
  dn: string
  labeledURI?: string
}

export type ApprovalRow = {
  id: number
  username: string
  field_name: string
  reason?: string
}

export type AuditEventRow = {
  id: number
  created_at?: string
  username?: string
  field_name?: string
  payload?: unknown
  status?: string
  reason?: string
  event_type?: string
  [k: string]: unknown
}

export type MetricsResponse = {
  metrics?: Record<string, number>
  events?: AuditEventRow[]
  security_events?: AuditEventRow[]
}

export const api: {
  login(username: string, password: string): Promise<{ token: string; username?: string; permissions?: string[]; groups?: string[] }>
  me(): Promise<{ auth?: AuthContext }>
  metrics(): Promise<MetricsResponse>
  users(): Promise<{ users?: LdapUserRow[] }>
  ldapTree(baseDn?: string): Promise<{ items?: Array<{ dn: string; rdn: string }> }>
  ldapTreeNode(dn: string): Promise<{ node?: { attributes?: Record<string, unknown> } | null }>
  ldapSelfNode(): Promise<{ dn?: string; node?: { attributes?: Record<string, unknown> } | null }>
  ldapSearch(q: string): Promise<{ items?: Array<{ dn: string; rdn: string }> }>
  ldapExport(q: string): Promise<Blob>
  ldapUpdateEntry(dn: string, changes: Record<string, unknown>): Promise<{ pending_approval?: boolean }>
  approvals(): Promise<{ items?: ApprovalRow[] }>
  approve(id: string): Promise<unknown>
  reject(id: string): Promise<unknown>
  sourceUsers(): Promise<{ items?: SourceUserRow[] }>
  createSourceUser(item: SourceUserRow): Promise<unknown>
  updateSourceUser(user: string, item: { password: string; httpUrl: string }): Promise<unknown>
  deleteSourceUser(user: string): Promise<unknown>
  runPoll(): Promise<{ run_id: string }>
  reconcile(opts?: { syncPasswords?: boolean }): Promise<{ run_id: string }>
  changeUserPassword(username: string, newPassword: string): Promise<unknown>
}
