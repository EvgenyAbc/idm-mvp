import { redirect, type ActionFunctionArgs, type LoaderFunctionArgs } from 'react-router-dom'
import { api } from '../../../api/client'
import { clearToken, getToken, requireSession, setToken } from '../../../shared/lib/authSession'

export async function loginAction({ request }: ActionFunctionArgs): Promise<
  Response | { ok: false; messageKey: string; params?: Record<string, string | number> }
> {
  const form = await request.formData()
  const username = String(form.get('username') ?? '').trim()
  const password = String(form.get('password') ?? '')
  if (!username || !password) {
    return { ok: false, messageKey: 'actions.loginCredentialsRequired' }
  }
  try {
    const out = await api.login(username, password)
    setToken(out.token)
    return redirect('/ldap/operations')
  } catch (e) {
    if (e instanceof Error && e.message) {
      return { ok: false, messageKey: 'actions.requestFailedDetail', params: { detail: e.message } }
    }
    return { ok: false, messageKey: 'actions.loginFailed' }
  }
}

export async function logoutAction(): Promise<Response> {
  clearToken()
  return redirect('/login')
}

export async function rootLoader(_args: LoaderFunctionArgs): Promise<{ session: Awaited<ReturnType<typeof requireSession>> }> {
  const token = getToken()
  if (!token) throw redirect('/login')
  const session = await requireSession()
  return { session }
}

export async function loginLoader(_args: LoaderFunctionArgs): Promise<{ ok: true }> {
  if (getToken()) {
    try {
      await api.me()
      throw redirect('/ldap/operations')
    } catch {
      clearToken()
    }
  }
  return { ok: true }
}
