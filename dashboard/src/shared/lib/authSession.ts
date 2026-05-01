import { redirect } from 'react-router-dom'
import { api, type AuthContext } from '../../api/client'
import { PERMISSIONS, type Permission } from './permissions'

export const TOKEN_KEY = 'idm_token'
export const QUICK_LOGIN_ADMIN_USERNAME = import.meta.env.VITE_ADMIN_USERNAME ?? 'alphaadmin'
const ADMIN_USERNAME = QUICK_LOGIN_ADMIN_USERNAME
const SESSION_CACHE_TTL_MS = 30_000

export type Session = AuthContext & { token: string }

type SessionCache = {
  token: string
  session: Session
  expiresAt: number
}

let sessionCache: SessionCache | null = null
let inFlightSession: Promise<Session> | null = null

export const LOGIN_USERS: Array<{ username: string; password: string }> = [
  { username: 'jdoe', password: '123' },
  { username: 'asmith', password: '123' },
  { username: ADMIN_USERNAME, password: '123' },
]

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token)
  sessionCache = null
  inFlightSession = null
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
  sessionCache = null
  inFlightSession = null
}

export function decodeToken(token: string | null): string {
  if (!token) return ''
  try {
    return atob(token)
  } catch {
    return ''
  }
}

export function hasPerm(session: Session | undefined, permission: Permission): boolean {
  return (session?.permissions ?? []).includes(permission)
}

export function hasAnyPerm(session: Session | undefined, permissions: Permission[]): boolean {
  return permissions.some((permission) => hasPerm(session, permission))
}

export function firstAllowedDashboardPath(session: Session): string {
  if (hasPerm(session, PERMISSIONS.dashboardOperationsView)) return '/ldap/operations'
  if (hasPerm(session, PERMISSIONS.dashboardMetricsView)) return '/ldap/metrics'
  if (hasPerm(session, PERMISSIONS.dashboardApprovalsView)) return '/ldap/approvals'
  if (hasPerm(session, PERMISSIONS.dashboardUsersView)) return '/ldap/users'
  if (hasPerm(session, PERMISSIONS.dashboardLdapView)) return '/ldap/explorer'
  if (hasPerm(session, PERMISSIONS.dashboardProfileView)) return '/ldap/profile'
  if (hasPerm(session, PERMISSIONS.dashboardEventsView) && hasPerm(session, PERMISSIONS.metricsEventsView)) return '/ldap/events'
  return '/login'
}

export async function requireSession(): Promise<Session> {
  const token = getToken()
  if (!token) throw redirect('/login')

  if (sessionCache && sessionCache.token === token && Date.now() < sessionCache.expiresAt) {
    return sessionCache.session
  }

  if (inFlightSession) {
    return inFlightSession
  }

  inFlightSession = (async () => {
    try {
      const me = await api.me()
      const session: Session = {
        token,
        username: me.auth?.username ?? decodeToken(token),
        groups: me.auth?.groups ?? [],
        permissions: me.auth?.permissions ?? [],
      }
      sessionCache = {
        token,
        session,
        expiresAt: Date.now() + SESSION_CACHE_TTL_MS,
      }
      return session
    } catch {
      clearToken()
      throw redirect('/login')
    } finally {
      inFlightSession = null
    }
  })()

  try {
    return await inFlightSession
  } catch {
    clearToken()
    throw redirect('/login')
  }
}
