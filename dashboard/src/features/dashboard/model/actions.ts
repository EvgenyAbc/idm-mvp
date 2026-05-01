import { type ActionFunctionArgs } from 'react-router-dom'
import { api } from '../../../api/client'
import {
  GENERIC_REQUEST_FAILED_EN,
  isGenericRequestFailedMessage,
  type ActionMessage,
} from '../../../shared/i18n'
import { hasPerm, requireSession } from '../../../shared/lib/authSession'
import { PERMISSIONS } from '../../../shared/lib/permissions'

function asString(v: FormDataEntryValue | null): string {
  return String(v ?? '').trim()
}

function actionErrorMessage(error: unknown): string {
  if (error instanceof Error && error.message) return error.message
  return GENERIC_REQUEST_FAILED_EN
}

function fromError(error: unknown): ActionMessage {
  const msg = actionErrorMessage(error)
  if (isGenericRequestFailedMessage(msg)) {
    return { messageKey: 'actions.requestFailed' }
  }
  return { messageKey: 'actions.requestFailedDetail', params: { detail: msg } }
}

export async function operationsAction({ request }: ActionFunctionArgs): Promise<ActionMessage> {
  try {
    const session = await requireSession()
    const form = await request.formData()
    const intent = asString(form.get('intent'))

    if (intent === 'run-provision') {
      if (!hasPerm(session, PERMISSIONS.dashboardOperationsView)) {
        return { messageKey: 'actions.operationsMissingDashboardOps' }
      }
      if (!hasPerm(session, PERMISSIONS.provisionRun)) {
        return { messageKey: 'actions.operationsProvisionPerm' }
      }
      const data = await api.runPoll()
      return { messageKey: 'actions.operationsProvisionFinished', params: { runId: data.run_id } }
    }

    if (intent === 'run-reconcile') {
      if (!hasPerm(session, PERMISSIONS.dashboardOperationsView)) {
        return { messageKey: 'actions.operationsMissingDashboardOps' }
      }
      if (!hasPerm(session, PERMISSIONS.reconcileRun)) {
        return { messageKey: 'actions.operationsReconcilePerm' }
      }
      const syncPasswords = form.get('syncPasswords') === 'on'
      const data = await api.reconcile({ syncPasswords })
      const result = (data as { result?: Record<string, number> }).result ?? {}
      if (typeof result.checked !== 'number') {
        return { messageKey: 'actions.operationsReconcileFinished', params: { runId: data.run_id } }
      }
      return {
        messageKey: 'actions.operationsReconcileFinishedDetail',
        params: {
          runId: data.run_id,
          checked: result.checked,
          quarantined: result.quarantined ?? 0,
          drift: result.drift_detected ?? 0,
          labeledUriRemediated: result.drift_remediated ?? 0,
          passwordsSynced: result.passwords_remediated ?? 0,
        },
      }
    }

    if (intent === 'delete-source-user') {
      if (!hasPerm(session, PERMISSIONS.dashboardOperationsView)) {
        return { messageKey: 'actions.operationsMissingDashboardOps' }
      }
      if (!hasPerm(session, PERMISSIONS.provisionRun)) {
        return { messageKey: 'actions.operationsManageSourcePerm' }
      }
      const user = asString(form.get('existingUser') ?? form.get('user'))
      if (!user) return { messageKey: 'actions.operationsSourceUserRequired' }
      await api.deleteSourceUser(user)
      return { messageKey: 'actions.operationsSourceDeleted', params: { user } }
    }

    if (intent === 'save-source-user') {
      if (!hasPerm(session, PERMISSIONS.dashboardOperationsView)) {
        return { messageKey: 'actions.operationsMissingDashboardOps' }
      }
      if (!hasPerm(session, PERMISSIONS.provisionRun)) {
        return { messageKey: 'actions.operationsManageSourcePerm' }
      }
      const existingUser = asString(form.get('existingUser'))
      const user = asString(form.get('user'))
      const password = asString(form.get('password'))
      const httpUrl = asString(form.get('httpUrl'))
      if (!user || !password || !httpUrl) {
        return { messageKey: 'actions.operationsSourceFieldsRequired' }
      }
      if (existingUser && existingUser !== user) {
        return { messageKey: 'actions.operationsUsernameImmutable' }
      }
      if (existingUser) {
        await api.updateSourceUser(existingUser, { password, httpUrl })
      } else {
        await api.createSourceUser({ user, password, httpUrl })
      }
      return { messageKey: 'actions.operationsSourceSaved', params: { user } }
    }

    return { messageKey: 'actions.operationsUnknownAction' }
  } catch (error) {
    return fromError(error)
  }
}

export async function approvalsAction({ request }: ActionFunctionArgs): Promise<ActionMessage> {
  try {
    const session = await requireSession()
    if (!hasPerm(session, PERMISSIONS.dashboardApprovalsView)) {
      return { messageKey: 'actions.approvalsMissingView' }
    }
    if (!hasPerm(session, PERMISSIONS.approvalDecide)) {
      return { messageKey: 'actions.approvalsMissingDecide' }
    }
    const form = await request.formData()
    const decision = asString(form.get('decision'))
    const ids = form.getAll('id').map((id) => String(id).trim()).filter(Boolean)
    if (!ids.length) {
      return { messageKey: 'actions.approvalsSelectOne' }
    }
    for (const id of ids) {
      if (decision === 'approve') await api.approve(id)
      else await api.reject(id)
    }
    return decision === 'approve'
      ? { messageKey: 'actions.approvalsApproved', params: { count: ids.length } }
      : { messageKey: 'actions.approvalsRejected', params: { count: ids.length } }
  } catch (error) {
    return fromError(error)
  }
}

export async function usersAction({ request }: ActionFunctionArgs): Promise<ActionMessage> {
  try {
    const session = await requireSession()
    if (!hasPerm(session, PERMISSIONS.dashboardUsersView)) {
      return { messageKey: 'actions.usersMissingView' }
    }
    if (!hasPerm(session, PERMISSIONS.usersPasswordChange)) {
      return { messageKey: 'actions.usersPasswordPerm' }
    }
    const form = await request.formData()
    const username = asString(form.get('username'))
    const newPassword = asString(form.get('newPassword'))
    if (!username || !newPassword) {
      return { messageKey: 'actions.usersFieldsRequired' }
    }
    await api.changeUserPassword(username, newPassword)
    return { messageKey: 'actions.usersPasswordUpdated', params: { username } }
  } catch (error) {
    return fromError(error)
  }
}
