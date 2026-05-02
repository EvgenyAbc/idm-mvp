import { useMemo, useRef, useState } from 'react'
import { Form, Link, NavLink, useActionData, useLoaderData, useLocation, useNavigation, useRevalidator, useSubmit } from 'react-router-dom'
import type { ActionMessage } from '../../shared/i18n'
import { useT } from '../../shared/i18n'
import type { OperationsLoaderData } from '../../features/dashboard/model/loaders'
import { useModal } from '../../shared/ui/modal/useModal'

function sourceUserDn(user: string): string {
  return `uid=${user},ou=People,dc=example,dc=com`
}

export function OperationsPage() {
  const t = useT()
  const data = useLoaderData() as OperationsLoaderData
  const actionData = useActionData() as ActionMessage | undefined
  const location = useLocation()
  const navigation = useNavigation()
  const submit = useSubmit()
  const revalidator = useRevalidator()
  const { openModal, closeModal } = useModal()
  const busy = navigation.state !== 'idle'
  const [syncPasswords, setSyncPasswords] = useState(false)
  const actionsMenuRef = useRef<HTMLDetailsElement | null>(null)

  const closeActionsMenu = (): void => {
    if (actionsMenuRef.current) {
      actionsMenuRef.current.open = false
    }
  }

  const run = (intent: 'run-provision' | 'run-reconcile'): void => {
    const form = new FormData()
    form.set('intent', intent)
    if (syncPasswords) form.set('syncPasswords', 'on')
    submit(form, { method: 'post' })
  }

  const normalizedPathname = location.pathname.replace(/\/+$/, '') || '/'
  const showProvisioning = normalizedPathname.endsWith('/operations/provisioning')
  const showReconciliation = normalizedPathname.endsWith('/operations/reconciliation')
  const showOverview = !showProvisioning && !showReconciliation
  const usersWithUrl = data.sourceUsers.filter((row) => Boolean(row.httpUrl?.trim())).length
  const usersMissingUrl = data.sourceUsers.length - usersWithUrl
  const timelineMax = Math.max(1, ...data.usageTimeline.map((item) => item.value))
  const currentMax = Math.max(1, ...data.currentUsage.map((item) => item.value))

  const overviewCards = useMemo(() => {
    const allowed = t('operations.status.allowed')
    const denied = t('operations.status.denied')
    const on = t('operations.status.on')
    const off = t('operations.status.off')
    return [
      { cardKey: 'sourceUsers' as const, value: String(data.sourceUsers.length), tone: 'primary' as const },
      { cardKey: 'withProfileUrl' as const, value: String(usersWithUrl), tone: 'success' as const },
      {
        cardKey: 'missingProfileUrl' as const,
        value: String(usersMissingUrl),
        tone: usersMissingUrl === 0 ? ('success' as const) : ('warning' as const),
      },
      {
        cardKey: 'provisioningAccess' as const,
        value: data.canRunProvisioning ? allowed : denied,
        tone: data.canRunProvisioning ? ('success' as const) : ('danger' as const),
      },
      {
        cardKey: 'reconciliationAccess' as const,
        value: data.canRunReconcile ? allowed : denied,
        tone: data.canRunReconcile ? ('success' as const) : ('danger' as const),
      },
      {
        cardKey: 'syncPasswords' as const,
        value: syncPasswords ? on : off,
        tone: syncPasswords ? ('primary' as const) : ('neutral' as const),
      },
    ]
  }, [data.canRunProvisioning, data.canRunReconcile, data.sourceUsers.length, syncPasswords, t, usersMissingUrl, usersWithUrl])

  const openCreateModal = (): void => {
    closeActionsMenu()
    const key = 'source-create'
    openModal({
      key,
      title: t('operations.modalCreateTitle'),
      content: (
        <>
          <h3>{t('operations.modalCreateHeading')}</h3>
          <Form method="post" action={normalizedPathname}>
            <input type="hidden" name="intent" value="save-source-user" />
            <input name="user" placeholder={t('operations.placeholderUser')} />
            <input name="password" placeholder={t('operations.placeholderPassword')} />
            <input name="httpUrl" placeholder={t('operations.placeholderHttpUrl')} />
            <input name="mail" type="email" autoComplete="off" placeholder={t('operations.placeholderMail')} />
            <input name="telephoneNumber" autoComplete="off" placeholder={t('operations.placeholderTelephoneNumber')} />
            <div className="modal-actions">
              <button type="submit" disabled={busy}>
                {t('common.create')}
              </button>
              <button type="button" onClick={() => closeModal(key)}>
                {t('common.close')}
              </button>
            </div>
          </Form>
        </>
      ),
    })
  }

  const openSourceUserModal = (item: OperationsLoaderData['sourceUsers'][number]): void => {
    const key = `source-user-${item.user}`
    openModal({
      key,
      title: t('operations.modalEditTitle'),
      content: (
        <>
          <h3>{t('operations.modalEditHeading')}</h3>
          <p className="muted">
            {t('operations.ldapDnLabel')}{' '}
            <Link className="user-link" to={`/ldap/explorer?dn=${encodeURIComponent(sourceUserDn(item.user))}`}>
              {sourceUserDn(item.user)}
            </Link>
          </p>
          <Form method="post" action={normalizedPathname}>
            <input type="hidden" name="intent" value="save-source-user" />
            <input type="hidden" name="existingUser" value={item.user} />
            <input name="user" defaultValue={item.user} />
            <input name="password" defaultValue={item.password} />
            <input name="httpUrl" defaultValue={item.httpUrl} />
            <input name="mail" type="email" autoComplete="off" defaultValue={item.mail ?? ''} placeholder={t('operations.placeholderMail')} />
            <input
              name="telephoneNumber"
              autoComplete="off"
              defaultValue={item.telephoneNumber ?? ''}
              placeholder={t('operations.placeholderTelephoneNumber')}
            />
            <div className="modal-actions">
              <button type="submit" disabled={busy}>
                {t('common.save')}
              </button>
              <button type="button" onClick={() => closeModal(key)}>
                {t('common.close')}
              </button>
            </div>
          </Form>
        </>
      ),
    })
  }

  return (
    <section className="card page-shell dashboard-page operations-page">
      <div className="page-shell-header operations-sticky-header">
        <header className="operations-page-header">
          <div>
            <h2>{t('operations.title')}</h2>
            <p className="muted">{t('operations.lead')}</p>
          </div>
          <div className="operations-page-stats">
            {showReconciliation ? (
              <>
                <span className="operations-stat-chip">
                  {t('operations.chipReconciliation', {
                    status: data.canRunReconcile ? t('operations.status.allowed') : t('operations.status.denied'),
                  })}
                </span>
                <span className="operations-stat-chip">
                  {t('operations.chipSyncPasswords', {
                    status: syncPasswords ? t('operations.status.on') : t('operations.status.off'),
                  })}
                </span>
              </>
            ) : (
              <>
                <span className="operations-stat-chip">
                  {t('operations.chipSourceUsers', { count: data.sourceUsers.length })}
                </span>
                <span className="operations-stat-chip">{t('operations.chipWithUrl', { count: usersWithUrl })}</span>
                <span className="operations-stat-chip">{t('operations.chipMissingUrl', { count: usersMissingUrl })}</span>
              </>
            )}
          </div>
        </header>
        {actionData?.messageKey ? (
          <p className="banner-message muted">{t(actionData.messageKey, actionData.params)}</p>
        ) : null}
        <div className="operations-tabs-row">
          <div className="operations-tabs" role="tablist" aria-label={t('operations.tabsAria')}>
            <NavLink
              to="/ldap/operations"
              end
              role="tab"
              className={({ isActive }) => `operations-tab${isActive ? ' operations-tab-active' : ''}`}
            >
              {t('operations.tabProvisionReconcile')}
            </NavLink>
            <NavLink
              to="/ldap/operations/provisioning"
              role="tab"
              className={({ isActive }) => `operations-tab${isActive ? ' operations-tab-active' : ''}`}
            >
              {t('operations.tabProvisioning')}
            </NavLink>
            <NavLink
              to="/ldap/operations/reconciliation"
              role="tab"
              className={({ isActive }) => `operations-tab${isActive ? ' operations-tab-active' : ''}`}
            >
              {t('operations.tabReconciliation')}
            </NavLink>
          </div>
          {showProvisioning ? (
            <details className="operations-actions-menu" ref={actionsMenuRef}>
              <summary className="operations-actions-trigger">
                <span aria-hidden="true" className="operations-actions-cog">
                  ⚙
                </span>
                {t('common.actions')}
              </summary>
              <div className="operations-actions-popup">
                <button
                  type="button"
                  onClick={() => {
                    closeActionsMenu()
                    revalidator.revalidate()
                  }}
                  disabled={busy}
                >
                  {t('common.refresh')}
                </button>
                <button type="button" onClick={openCreateModal} disabled={busy || !data.canRunProvisioning}>
                  {t('operations.modalCreateHeading')}
                </button>
                {data.canRunProvisioning ? (
                  <button
                    type="button"
                    onClick={() => {
                      closeActionsMenu()
                      run('run-provision')
                    }}
                    disabled={busy}
                  >
                    {t('operations.runProvisioning')}
                  </button>
                ) : null}
              </div>
            </details>
          ) : null}
        </div>
      </div>
      <div className="page-shell-body">
        {showOverview ? (
          <div className="operations-section">
            <div className="section-hero">
              <h3>{t('operations.overviewTitle')}</h3>
              <p className="muted">{t('operations.overviewSubtitle')}</p>
            </div>
            <div className="metric-grid metric-grid-strong">
              {overviewCards.map((card) => (
                <article className={`metric metric-strong metric-${card.tone}`} key={card.cardKey}>
                  <strong>{t(`operations.cards.${card.cardKey}`)}</strong>
                  <span className="metric-value">{card.value}</span>
                </article>
              ))}
            </div>
            <div className="operations-charts-grid">
              <section className="operations-chart-card">
                <h4>{t('operations.chartUsageTitle')}</h4>
                <p className="muted">{t('operations.chartUsageSubtitle')}</p>
                <div className="operations-bars operations-bars-timeline" role="img" aria-label={t('operations.ariaUsageOverTime')}>
                  {data.usageTimeline.map((item) => {
                    const height = Math.max(8, Math.round((item.value / timelineMax) * 100))
                    return (
                      <div key={item.label} className="operations-bar-col">
                        <span className="operations-bar-value">{item.value}</span>
                        <div className="operations-bar-track">
                          <div className="operations-bar-fill" style={{ height: `${height}%` }} />
                        </div>
                        <span className="operations-bar-label">{item.label}</span>
                      </div>
                    )
                  })}
                </div>
              </section>
              <section className="operations-chart-card">
                <h4>{t('operations.chartCurrentTitle')}</h4>
                <p className="muted">{t('operations.chartCurrentSubtitle')}</p>
                <div className="operations-current-list" role="img" aria-label={t('operations.ariaCurrentUsage')}>
                  {data.currentUsage.map((item) => {
                    const width = Math.max(6, Math.round((item.value / currentMax) * 100))
                    return (
                      <div key={item.labelKey} className="operations-current-row">
                        <span className="operations-current-label">{t(`operations.cards.${item.labelKey}`)}</span>
                        <div className="operations-current-track">
                          <div className="operations-current-fill" style={{ width: `${width}%` }} />
                        </div>
                        <span className="operations-current-value">{item.value}</span>
                      </div>
                    )
                  })}
                </div>
              </section>
            </div>
          </div>
        ) : null}

        {showProvisioning ? (
          <div className="operations-section">
            <h3>{t('operations.sectionProvisioning')}</h3>
            <div className="operations-users-list">
              {data.sourceUsers.map((item) => (
                <div key={item.user} className="operations-user-row">
                  <div className="operations-user-main">
                    <Link className="operations-user-link" to={`/ldap/explorer?dn=${encodeURIComponent(sourceUserDn(item.user))}`}>
                      {item.user}
                    </Link>
                    <span className="muted operations-user-url">{t('operations.userRowDn', { dn: sourceUserDn(item.user) })}</span>
                    <span className="muted operations-user-url">
                      {t('operations.userRowUrl', { url: item.httpUrl || t('operations.noUrl') })}
                    </span>
                    {item.mail?.trim() ? (
                      <span className="muted operations-user-url">{t('operations.userRowMail', { mail: item.mail })}</span>
                    ) : null}
                    {item.telephoneNumber?.trim() ? (
                      <span className="muted operations-user-url">
                        {t('operations.userRowTelephone', { phone: item.telephoneNumber })}
                      </span>
                    ) : null}
                  </div>
                  <div className="operations-user-actions">
                    <button type="button" onClick={() => openSourceUserModal(item)} disabled={busy}>
                      {t('common.view')}
                    </button>
                    <Form method="post">
                      <input type="hidden" name="user" value={item.user} />
                      <button type="submit" name="intent" value="delete-source-user" disabled={busy}>
                        {t('common.delete')}
                      </button>
                    </Form>
                  </div>
                </div>
              ))}
            </div>

            {!data.canRunProvisioning ? <p className="muted">{t('operations.provisionNoPerm')}</p> : null}
          </div>
        ) : null}

        {showReconciliation ? (
          <div className="operations-section">
            <div className="section-hero">
              <h3>{t('operations.sectionReconciliation')}</h3>
              <p className="muted">{t('operations.reconcileLead')}</p>
            </div>
            <div className="reconcile-panel">
              <label className="reconcile-sync-passwords">
                <input type="checkbox" checked={syncPasswords} onChange={(e) => setSyncPasswords(e.target.checked)} />
                <span>
                  <strong>{t('operations.syncPasswordsLabel')}</strong>
                  <small className="muted">{t('operations.syncPasswordsHint')}</small>
                </span>
              </label>
              {data.canRunReconcile ? (
                <div className="reconcile-actions">
                  <button type="button" onClick={() => run('run-reconcile')} disabled={busy}>
                    {t('operations.runReconciliation')}
                  </button>
                </div>
              ) : (
                <p className="muted">{t('operations.reconcileNoPerm')}</p>
              )}
            </div>
            {data.canRunReconcile ? <p className="muted reconcile-hint">{t('operations.reconcileTip')}</p> : null}
          </div>
        ) : null}

        {!data.canRunProvisioning && !data.canRunReconcile ? (
          <p className="muted">{t('operations.bothNoPerm')}</p>
        ) : null}
      </div>
    </section>
  )
}
