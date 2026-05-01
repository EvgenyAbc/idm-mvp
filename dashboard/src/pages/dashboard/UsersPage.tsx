import { Form, Link, useActionData, useLoaderData, useNavigation, useRouteLoaderData } from 'react-router-dom'
import type { ActionMessage } from '../../shared/i18n'
import { useT } from '../../shared/i18n'
import type { LdapUserRow } from '../../api/client'
import { useModal } from '../../shared/ui/modal/useModal'

export function UsersPage() {
  const t = useT()
  const { users, canChangePasswords } = useLoaderData() as { users: LdapUserRow[]; canChangePasswords: boolean }
  const actionData = useActionData() as ActionMessage | undefined
  const busy = useNavigation().state !== 'idle'
  const { openModal, closeModal } = useModal()
  const canChange = canChangePasswords
  const ouFromDn = (dn = ''): string => {
    if (!dn) return ''
    return dn
      .split(',')
      .map((part) => part.trim())
      .filter((part) => part.toLowerCase().startsWith('ou='))
      .map((part) => part.slice(3))
      .join(' / ')
  }

  const openPasswordModal = (user: LdapUserRow): void => {
    const key = `user-password-${user.uid}`
    openModal({
      key,
      title: t('users.modalTitle', { username: user.uid }),
      content: (
        <>
          <h3>{t('users.modalHeading')}</h3>
          <p className="muted users-modal-meta">{user.uid}</p>
          <Form method="post" onSubmit={() => closeModal(key)}>
            <input type="hidden" name="username" value={user.uid} />
            <input name="newPassword" placeholder={t('users.placeholderNewPassword', { username: user.uid })} autoFocus />
            <div className="modal-actions">
              <button type="submit" disabled={busy}>
                {t('users.savePassword')}
              </button>
              <button type="button" onClick={() => closeModal(key)}>
                {t('common.cancel')}
              </button>
            </div>
          </Form>
        </>
      ),
    })
  }

  return (
    <section className="card page-shell dashboard-page users-page">
      <div className="page-shell-header">
        <header className="users-page-header">
          <div>
            <h2>{t('users.title')}</h2>
          </div>
          <div className="users-page-stats">
            <span className="users-stat-chip">{t('users.statTotal', { count: users.length })}</span>
            <span className="users-stat-chip">{canChange ? t('users.statPasswordEnabled') : t('users.statReadOnly')}</span>
          </div>
        </header>
        {actionData?.messageKey ? (
          <p className="banner-message muted">{t(actionData.messageKey, actionData.params)}</p>
        ) : null}
      </div>
      <div className="page-shell-body users-list">
        {users.map((u) => (
          <div key={u.uid} className="users-row">
            <div className="users-row-main">
              <Link className="users-user-link" to={`/ldap/explorer?dn=${encodeURIComponent(u.dn)}`}>
                {u.uid}
              </Link>
              <p className="users-row-meta">{t('users.dnLabel', { dn: u.dn })}</p>
              {ouFromDn(u.dn) ? <p className="users-row-meta">{t('users.ouLabel', { ou: ouFromDn(u.dn) })}</p> : null}
              {u.labeledURI ? <p className="users-row-meta">{t('users.urlLabel', { url: u.labeledURI })}</p> : null}
            </div>
            {canChange ? (
              <div className="users-row-actions">
                <button type="button" onClick={() => openPasswordModal(u)} disabled={busy}>
                  {t('users.changePassword')}
                </button>
              </div>
            ) : (
              <span className="users-readonly-badge">{t('users.readOnlyBadge')}</span>
            )}
          </div>
        ))}
      </div>
    </section>
  )
}
