import type { Session } from '../../shared/lib/authSession'

export type RootOutletContext = {
  session: Session
  canViewOperations: boolean
  canViewMetrics: boolean
  canViewApprovals: boolean
  canViewUsers: boolean
  canViewLdap: boolean
  canViewProfile: boolean
  canViewEvents: boolean
}
