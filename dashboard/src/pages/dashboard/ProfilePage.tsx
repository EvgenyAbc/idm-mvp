import { useState, type FormEvent } from 'react'
import { useLoaderData, useRouteLoaderData } from 'react-router-dom'
import { useT } from '../../shared/i18n'
import type { LdapUserRow } from '../../api/client'
import { api } from '../../api/client'
import type { Session } from '../../shared/lib/authSession'
import { useModal } from '../../shared/ui/modal/useModal'

const EDITABLE_ATTRIBUTES = ['mail', 'telephoneNumber', 'labeledURI']

function normalizeAttributes(attributes: Record<string, unknown> = {}): Record<string, unknown> {
  const normalized = { ...attributes }
  if (Array.isArray(normalized.labeledURI) && !normalized.httpUrl) {
    normalized.httpUrl = normalized.labeledURI
  }
  return normalized
}

function normalizeAttributesWithDn(attributes: Record<string, unknown> = {}, dn = ''): Record<string, unknown> {
  const normalized = normalizeAttributes(attributes)
  if (!dn || normalized.ou) return normalized
  const ou = dn
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part.toLowerCase().startsWith('ou='))
    .map((part) => part.slice(3))
    .join(' / ')
  if (ou) normalized.ou = [ou]
  return normalized
}

function canonicalEditableField(key: string): string | null {
  return EDITABLE_ATTRIBUTES.find((a) => a.toLowerCase() === String(key).toLowerCase()) ?? null
}

function partitionProfileAttributes(normalizedEntries: Record<string, unknown>): {
  contact: Array<[string, unknown]>
  account: Array<[string, unknown]>
  other: Array<[string, unknown]>
} {
  const contactKeys = new Set(EDITABLE_ATTRIBUTES.map((a) => a.toLowerCase()))
  contactKeys.add('httpurl')
  const accountHints = new Set(['uid', 'cn', 'sn', 'givenname', 'ou', 'uidnumber', 'gidnumber', 'homedirectory', 'loginshell', 'objectclass'])
  const contact: Array<[string, unknown]> = []
  const account: Array<[string, unknown]> = []
  const other: Array<[string, unknown]> = []
  for (const [key, values] of Object.entries(normalizedEntries)) {
    const lk = key.toLowerCase()
    if (lk === 'userpassword') {
      other.push([key, values])
      continue
    }
    if (contactKeys.has(lk)) contact.push([key, values])
    else if (accountHints.has(lk)) account.push([key, values])
    else other.push([key, values])
  }
  const sortByKey = (a: [string, unknown], b: [string, unknown]) => a[0].localeCompare(b[0])
  contact.sort(sortByKey)
  account.sort(sortByKey)
  other.sort(sortByKey)
  return { contact, account, other }
}

type ProfileLoaderData = {
  username: string
  canViewAttributes: boolean
  showLdapExplorer: boolean
  editableAttributes: string[]
  self: LdapUserRow | null
  selfNode: { dn?: string; attributes?: Record<string, unknown> } | null
}

function ProfileEditModal({
  attribute,
  currentValue,
  entryDn,
  onClose,
}: {
  attribute: string
  currentValue: string
  entryDn: string
  onClose: () => void
}) {
  const t = useT()
  const [value, setValue] = useState(currentValue)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [pendingNotice, setPendingNotice] = useState<string | null>(null)

  const inputType = attribute === 'mail' ? 'email' : attribute === 'telephoneNumber' ? 'tel' : 'text'

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setError(null)
    try {
      const res = await api.ldapUpdateEntry(entryDn, { [attribute]: value })
      if (res.pending_approval) {
        const ids =
          res.approval_fields ??
          (res.approval_field != null && res.approval_field !== '' ? [res.approval_field] : [])
        const fields = ids.join(', ')
        setPendingNotice(t('profile.pendingApproval', { fields }))
        return
      }
      onClose()
      window.location.reload()
    } catch (err) {
      setError(err instanceof Error ? err.message : t('profile.failedUpdate'))
    } finally {
      setSaving(false)
    }
  }

  if (pendingNotice) {
    return (
      <>
        <p className="banner-message" role="status">
          {pendingNotice}
        </p>
        <div className="modal-actions">
          <button
            type="button"
            onClick={() => {
              onClose()
              window.location.reload()
            }}
          >
            {t('common.close')}
          </button>
        </div>
      </>
    )
  }

  return (
    <>
      <h3>{t('profile.modalTitleEdit', { attribute })}</h3>
      <p className="muted">{t('profile.updateHint')}</p>
      <form onSubmit={submit}>
        <input type={inputType} value={value} onChange={(event) => setValue(event.target.value)} autoFocus />
        {error ? <p className="banner-message">{error}</p> : null}
        <div className="modal-actions">
          <button type="submit" disabled={saving}>
            {saving ? t('profile.saving') : t('common.save')}
          </button>
          <button type="button" onClick={onClose} disabled={saving}>
            {t('common.cancel')}
          </button>
        </div>
      </form>
    </>
  )
}

function AllPermissionsModalBody({ permissions, onClose }: { permissions: string[]; onClose: () => void }) {
  const t = useT()
  return (
    <>
      <h3>{t('profile.sessionPermissionsModalTitle')}</h3>
      {permissions.length === 0 ? (
        <p className="muted">{t('profile.noPermissions')}</p>
      ) : (
        <div className="profile-rbac-modal-scroll">
          <ul className="profile-rbac-groups-list">
            {permissions.map((permission) => (
              <li key={permission} className="profile-rbac-groups-li">
                {permission}
              </li>
            ))}
          </ul>
        </div>
      )}
      <div className="modal-actions">
        <button type="button" onClick={onClose}>
          {t('common.close')}
        </button>
      </div>
    </>
  )
}

function AllGroupsModalBody({ groups, onClose }: { groups: string[]; onClose: () => void }) {
  const t = useT()
  return (
    <>
      <h3>{t('profile.authGroupsModalTitle')}</h3>
      {groups.length === 0 ? (
        <p className="muted">{t('profile.noGroups')}</p>
      ) : (
        <ul className="profile-rbac-groups-list">
          {groups.map((group) => (
            <li key={group} className="profile-rbac-groups-li">
              {group}
            </li>
          ))}
        </ul>
      )}
      <div className="modal-actions">
        <button type="button" onClick={onClose}>
          {t('common.close')}
        </button>
      </div>
    </>
  )
}

function ProfileAttrRows({
  entries,
  canViewAttributes,
  onEdit,
}: {
  entries: Array<[string, unknown]>
  canViewAttributes: boolean
  onEdit: (attribute: string, currentValue: string) => void
}) {
  const t = useT()
  return (
    <dl className="profile-dl">
      {entries.map(([key, raw]) => {
        const values = Array.isArray(raw) ? raw : [raw]
        const display = values.map((v) => String(v ?? '')).filter(Boolean).join(', ')
        const editable = canonicalEditableField(key)
        return (
          <div key={key} className="profile-dl-row">
            <dt>{key}</dt>
            <dd>
              {editable && canViewAttributes ? (
                <button type="button" className="profile-edit-trigger" onClick={() => onEdit(editable, display)}>
                  <span className="profile-edit-trigger-main">{display || '—'}</span>
                  <span className="profile-edit-trigger-meta" aria-hidden="true">
                    <span className="profile-edit-trigger-icon">✎</span>
                    {t('profile.editable')}
                  </span>
                </button>
              ) : (
                <span className="profile-value-readonly">{display || '—'}</span>
              )}
            </dd>
          </div>
        )
      })}
    </dl>
  )
}

export function ProfilePage() {
  const t = useT()
  const { self, username, canViewAttributes, selfNode, showLdapExplorer, editableAttributes } = useLoaderData() as ProfileLoaderData
  const rootData = useRouteLoaderData('root') as { session: Session } | undefined
  const { openModal, closeModal } = useModal()
  const activePermissions = rootData?.session.permissions ?? []
  const activeGroups = rootData?.session.groups ?? []

  const permissionsModalKey = 'profile-all-permissions'
  const groupsModalKey = 'profile-all-groups'

  const permissionsBlock = (
    <div className="profile-permissions-toolbar">
      <button
        type="button"
        className="profile-permissions-open"
        aria-haspopup="dialog"
        aria-label={t('profile.rbacAllPermissionsAria')}
        onClick={() =>
          openModal({
            key: permissionsModalKey,
            title: t('profile.sessionPermissionsModalTitle'),
            ariaLabel: t('profile.rbacAllPermissionsAria'),
            content: (
              <AllPermissionsModalBody permissions={activePermissions} onClose={() => closeModal(permissionsModalKey)} />
            ),
          })
        }
      >
        <span>{t('profile.sessionPermissionsLabel')}</span>
        <span className="profile-permissions-count">({activePermissions.length})</span>
      </button>
      <button
        type="button"
        className="profile-permissions-open"
        aria-haspopup="dialog"
        aria-label={t('profile.rbacAllGroupsAria')}
        onClick={() =>
          openModal({
            key: groupsModalKey,
            title: t('profile.authGroupsModalTitle'),
            ariaLabel: t('profile.rbacAllGroupsAria'),
            content: <AllGroupsModalBody groups={activeGroups} onClose={() => closeModal(groupsModalKey)} />,
          })
        }
      >
        <span>{t('profile.authGroupsLabel')}</span>
        <span className="profile-permissions-count">({activeGroups.length})</span>
      </button>
    </div>
  )

  if (!canViewAttributes) {
    return (
      <section className="card page-shell dashboard-page profile-page">
        <div className="page-shell-header">
          <h2>{t('profile.title')}</h2>
          {permissionsBlock}
        </div>
        <div className="page-shell-body">
          <p className="muted">{t('profile.noLdapView')}</p>
        </div>
      </section>
    )
  }

  const entryDn = selfNode?.dn ?? self?.dn ?? ''
  const hasFullNode = Boolean(selfNode?.attributes)
  const hasListFallback = Boolean(self?.dn)

  const openProfileEditModal = (attribute: string, currentValue: string) => {
    if (!entryDn || !canViewAttributes) return
    const key = `profile-edit-${attribute}`
    openModal({
      key,
      title: t('profile.editAttribute', { attribute }),
      content: (
        <ProfileEditModal attribute={attribute} currentValue={currentValue} entryDn={entryDn} onClose={() => closeModal(key)} />
      ),
    })
  }

  if (!hasFullNode && !hasListFallback) {
    return (
      <section className="card page-shell dashboard-page profile-page">
        <div className="page-shell-header">
          <h2>{t('profile.title')}</h2>
          {permissionsBlock}
        </div>
        <div className="page-shell-body">
          <p className="muted">{t('profile.noEntry', { username: username || t('users.unknownUser') })}</p>
        </div>
      </section>
    )
  }

  const normalized = hasFullNode
    ? normalizeAttributesWithDn(selfNode?.attributes ?? {}, selfNode?.dn ?? '')
    : {
        uid: self?.uid,
        dn: self?.dn,
        ...(self?.labeledURI ? { labeledURI: self.labeledURI } : {}),
      }
  const { contact, account, other } = partitionProfileAttributes(normalized)

  return (
    <section className="card page-shell dashboard-page profile-page">
      <div className="page-shell-header">
        <header className="profile-page-header">
          <h2>{t('profile.title')}</h2>
          <p className="muted profile-lead">
            {t('profile.lead', { username })}
            {entryDn ? (
              <>
                {' '}
                — <span className="profile-dn">{entryDn}</span>
              </>
            ) : null}
          </p>
          <p className="muted profile-policy">{t('profile.policy', { attributes: editableAttributes.join(', ') })}</p>
          {permissionsBlock}
          {!showLdapExplorer ? (
            <p className="banner-message profile-banner" role="status">
              {t('profile.bannerExplorer')}
            </p>
          ) : null}
        </header>
      </div>
      <div className="page-shell-body">
        {hasFullNode ? (
          <>
            {contact.length > 0 ? (
              <section className="profile-section">
                <h3>{t('profile.sectionContact')}</h3>
                <ProfileAttrRows entries={contact} canViewAttributes={canViewAttributes} onEdit={openProfileEditModal} />
              </section>
            ) : null}
            {account.length > 0 ? (
              <section className="profile-section">
                <h3>{t('profile.sectionAccount')}</h3>
                <ProfileAttrRows entries={account} canViewAttributes={canViewAttributes} onEdit={openProfileEditModal} />
              </section>
            ) : null}
            {other.length > 0 ? (
              <section className="profile-section">
                <h3>{t('profile.sectionOther')}</h3>
                <ProfileAttrRows entries={other} canViewAttributes={canViewAttributes} onEdit={openProfileEditModal} />
              </section>
            ) : null}
          </>
        ) : (
          <section className="profile-section">
            <h3>{t('profile.sectionSummary')}</h3>
            <ProfileAttrRows entries={Object.entries(normalized)} canViewAttributes={canViewAttributes} onEdit={openProfileEditModal} />
          </section>
        )}
      </div>
    </section>
  )
}
