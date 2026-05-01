import { useState } from 'react'
import { useLoaderData, useNavigation, useSubmit } from 'react-router-dom'
import { useT } from '../../shared/i18n'
import { api } from '../../api/client'

const DEFAULT_EXPLORER_DN = 'dc=example,dc=com'

type ExplorerData = {
  showLdapExplorer: boolean
  groups: string[]
  isDirectoryAdmin: boolean
  canSearchLdap: boolean
  canEditLdap: boolean
  canExportLdap: boolean
  search: Array<{ dn: string; rdn: string }>
  node: { dn?: string; attributes?: Record<string, unknown> } | null
  q: string
  dn: string
  parentDn: string
  ancestorDns: string[]
  children: Array<{ dn: string; rdn: string }>
}

export function ExplorerPage() {
  const t = useT()
  const {
    search,
    node,
    q,
    dn,
    showLdapExplorer,
    groups,
    isDirectoryAdmin,
    canSearchLdap,
    canEditLdap,
    canExportLdap,
    parentDn,
    ancestorDns,
    children,
  } = useLoaderData() as ExplorerData
  const submit = useSubmit()
  const busy = useNavigation().state !== 'idle'
  const [query, setQuery] = useState<string>(q)
  const [directDn, setDirectDn] = useState<string>(dn || DEFAULT_EXPLORER_DN)
  const [childrenViewMode, setChildrenViewMode] = useState<'cards' | 'list'>('list')

  if (!showLdapExplorer) {
    return (
      <section className="card page-shell dashboard-page">
        <div className="page-shell-header">
          <h2>{t('explorer.title')}</h2>
        </div>
        <div className="page-shell-body">
          <p className="muted">{t('explorer.noAccess')}</p>
        </div>
      </section>
    )
  }

  const runSearch = (value: string): void => {
    const fd = new FormData()
    fd.set('q', value)
    submit(fd, { method: 'get' })
  }
  const loadDn = (value: string): void => {
    const fd = new FormData()
    if (query) fd.set('q', query)
    fd.set('dn', value)
    submit(fd, { method: 'get' })
  }
  const exportCsv = async (): Promise<void> => {
    const blob = await api.ldapExport(query.trim())
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'ldap_export.csv'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  }

  const groupsDisplay = groups.length ? groups.join(', ') : t('explorer.rbacNone')

  return (
    <section className="card page-shell dashboard-page">
      <div className="page-shell-header">
        <h2>{t('explorer.title')}</h2>
        <p className="muted">{t('explorer.rbacGroups', { groups: groupsDisplay })}</p>
        <div className="explorer-toolbar explorer-toolbar-search">
          {canSearchLdap ? (
            <>
              <input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && runSearch(query)}
                placeholder={t('explorer.placeholderSearch')}
              />
              <button type="button" disabled={busy} onClick={() => runSearch(query)}>
                {t('common.search')}
              </button>
              {canExportLdap ? (
                <button type="button" disabled={busy} onClick={exportCsv}>
                  {t('explorer.exportCsv')}
                </button>
              ) : null}
            </>
          ) : (
            <p className="muted">{t('explorer.searchRequiresPerm')}</p>
          )}
        </div>
        {isDirectoryAdmin ? (
          <div className="explorer-toolbar explorer-toolbar-direct">
            <input
              value={directDn}
              placeholder={DEFAULT_EXPLORER_DN}
              onChange={(e) => setDirectDn(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && loadDn(directDn)}
            />
            <button type="button" disabled={busy} onClick={() => loadDn(directDn)}>
              {t('explorer.loadNode')}
            </button>
          </div>
        ) : null}
      </div>
      <div className="page-shell-body">
        <div className="events">
          {search.map((s) => (
            <button className="user-link" key={s.dn} type="button" onClick={() => loadDn(s.dn)}>
              {s.rdn} - {s.dn}
            </button>
          ))}
        </div>
        <p className="muted">{t('explorer.selectedDn', { dn: dn || `(${t('common.none')})` })}</p>
        {parentDn ? (
          <p className="muted">
            {t('explorer.parent')}{' '}
            <button type="button" className="user-link" onClick={() => loadDn(parentDn)}>
              {parentDn}
            </button>
          </p>
        ) : null}
        {ancestorDns.length ? (
          <section className="explorer-ancestors">
            <h4>{t('explorer.ancestors')}</h4>
            <div className="explorer-ancestors-list">
              {ancestorDns.map((ancestor) => (
                <button key={ancestor} type="button" className="explorer-ancestor-chip" onClick={() => loadDn(ancestor)}>
                  {ancestor}
                </button>
              ))}
            </div>
          </section>
        ) : null}
        {children.length || node ? (
          <section className="explorer-master-detail">
            <div className="explorer-master-panel">
              <div className="explorer-children-header">
                <h3>{t('explorer.directChildren', { count: children.length })}</h3>
                <div className="explorer-children-view-toggle" role="group" aria-label={t('explorer.childrenViewModeAria')}>
                  <button type="button" className={childrenViewMode === 'cards' ? 'is-active' : ''} onClick={() => setChildrenViewMode('cards')}>
                    {t('explorer.cards')}
                  </button>
                  <button type="button" className={childrenViewMode === 'list' ? 'is-active' : ''} onClick={() => setChildrenViewMode('list')}>
                    {t('explorer.list')}
                  </button>
                </div>
              </div>
              {children.length ? (
                <div className={`explorer-children-list ${childrenViewMode === 'list' ? 'is-list' : ''}`}>
                  {children.map((child) => (
                    <button key={child.dn} type="button" className="explorer-child-item" onClick={() => loadDn(child.dn)}>
                      <span className="explorer-child-rdn">{child.rdn}</span>
                      <span className="explorer-child-dn">{child.dn}</span>
                    </button>
                  ))}
                </div>
              ) : (
                <p className="muted">{t('explorer.noChildren')}</p>
              )}
            </div>
            <div className="explorer-detail-panel">
              <h3>{t('explorer.attributes')}</h3>
              {node ? <pre>{JSON.stringify(node.attributes ?? {}, null, 2)}</pre> : <p className="muted">{t('explorer.selectDnHint')}</p>}
              {!canEditLdap ? <p className="muted">{t('explorer.readOnlyHint')}</p> : null}
            </div>
          </section>
        ) : null}
      </div>
    </section>
  )
}
