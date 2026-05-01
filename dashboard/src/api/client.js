const defaultApiHost = typeof window !== 'undefined' ? window.location.hostname : '127.0.0.1'
const API_BASE = import.meta.env.VITE_API_BASE ?? `http://${defaultApiHost}:8080`

async function jsonFetch(path, options = {}) {
  const token = localStorage.getItem('idm_token')
  const res = await fetch(`${API_BASE}${path}`, {
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers ?? {}),
    },
    ...options,
  })
  const text = await res.text()
  let data = {}
  const trimmed = text.trim()
  if (trimmed !== '') {
    try {
      data = JSON.parse(trimmed)
    } catch {
      data = { message: trimmed.slice(0, 500) || 'Request failed' }
    }
  }
  if (!res.ok) {
    throw new Error(data.message ?? `Request failed (${res.status})`)
  }
  return data
}

async function blobFetch(path, options = {}) {
  const token = localStorage.getItem('idm_token')
  const res = await fetch(`${API_BASE}${path}`, {
    headers: {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers ?? {}),
    },
    ...options,
  })
  if (!res.ok) {
    let message = 'Request failed'
    try {
      const data = await res.json()
      message = data.message ?? message
    } catch {
      // Keep generic message when server does not return JSON.
    }
    throw new Error(message)
  }
  return res.blob()
}

export const api = {
  login: (username, password) =>
    jsonFetch('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    }),
  me: () => jsonFetch('/api/auth/me'),
  metrics: () => jsonFetch('/api/metrics'),
  users: () => jsonFetch('/api/users'),
  ldapTree: (baseDn = '') => {
    const dn = String(baseDn ?? '').trim()
    const query = dn ? `?baseDn=${encodeURIComponent(dn)}` : ''
    return jsonFetch(`/api/ldap/tree${query}`)
  },
  ldapSubtree: (dn) => jsonFetch(`/api/ldap/subtree?dn=${encodeURIComponent(String(dn ?? '').trim())}`),
  ldapSubtreeByPath: (dn) => jsonFetch(`/api/ldap/subtree/${encodeURIComponent(String(dn ?? '').trim())}`),
  ldapTreeNode: (dn) => jsonFetch(`/api/ldap/tree/node?dn=${encodeURIComponent(String(dn ?? '').trim())}`),
  ldapSelfNode: () => jsonFetch('/api/ldap/self/node'),
  ldapSearch: (q) => jsonFetch(`/api/ldap/search?q=${encodeURIComponent(q)}`),
  ldapUpdateEntry: (dn, changes) =>
    jsonFetch('/api/ldap/entry/update', {
      method: 'POST',
      body: JSON.stringify({ dn, changes }),
    }),
  ldapExport: (q) => blobFetch(`/api/ldap/export?q=${encodeURIComponent(q ?? '')}`),
  changeUserPassword: (username, newPassword) =>
    jsonFetch(`/api/users/${encodeURIComponent(username)}/password`, {
      method: 'POST',
      body: JSON.stringify({ newPassword }),
    }),
  approvals: () => jsonFetch('/api/approvals'),
  approve: (id) => jsonFetch(`/api/approvals/${id}/approve`, { method: 'POST' }),
  reject: (id) => jsonFetch(`/api/approvals/${id}/reject`, { method: 'POST' }),
  sourceUsers: () => jsonFetch('/api/source-users'),
  createSourceUser: (item) =>
    jsonFetch('/api/source-users', {
      method: 'POST',
      body: JSON.stringify(item),
    }),
  updateSourceUser: (user, item) =>
    jsonFetch(`/api/source-users/${encodeURIComponent(user)}`, {
      method: 'PUT',
      body: JSON.stringify(item),
    }),
  deleteSourceUser: (user) =>
    jsonFetch(`/api/source-users/${encodeURIComponent(user)}`, {
      method: 'DELETE',
    }),
  runPoll: () =>
    jsonFetch('/api/provision/run-poll', {
      method: 'POST',
      body: JSON.stringify({}),
    }),
  reconcile: (opts = {}) =>
    jsonFetch('/api/reconcile/run', {
      method: 'POST',
      body: JSON.stringify({
        ...(typeof opts.syncPasswords === 'boolean' ? { syncPasswords: opts.syncPasswords } : {}),
      }),
    }),
}
