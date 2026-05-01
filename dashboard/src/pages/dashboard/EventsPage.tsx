import { useMemo, useState } from 'react'
import { useLoaderData } from 'react-router-dom'
import { useT } from '../../shared/i18n'
import type { AuditEventRow } from '../../api/client'

function formatAuditEventLine(e: AuditEventRow): string {
  const field = e.field_name ?? '-'
  const reason = e.reason ?? ''
  return `[${e.created_at ?? '-'}] ${e.status ?? '-'} ${e.username ?? '-'} ${field}${reason ? ` - ${reason}` : ''}`
}

function eventMatchesFilters(event: AuditEventRow, statusFilter: string, typeFilter: string, queryFilter: string): boolean {
  if (statusFilter !== 'all' && String(event.status ?? '') !== statusFilter) return false
  if (typeFilter !== 'all' && String(event.event_type ?? '') !== typeFilter) return false
  const q = queryFilter.trim().toLowerCase()
  if (!q) return true
  const haystack = [event.created_at, event.status, event.event_type, event.username, event.field_name, event.reason, event.payload]
    .map((v) => (typeof v === 'string' ? v : JSON.stringify(v ?? '')))
    .join(' ')
    .toLowerCase()
  return haystack.includes(q)
}

export function EventsPage() {
  const t = useT()
  const { events, securityEvents, canViewEvents } = useLoaderData() as {
    events: AuditEventRow[]
    securityEvents: AuditEventRow[]
    canViewEvents: boolean
  }
  const [statusFilter, setStatusFilter] = useState('all')
  const [typeFilter, setTypeFilter] = useState('all')
  const [queryFilter, setQueryFilter] = useState('')

  const statusOptionLabel = (value: string): string => {
    switch (value) {
      case 'all':
        return t('events.optionAll')
      case 'attempted':
        return t('events.statusAttempted')
      case 'allowed':
        return t('events.statusAllowed')
      case 'denied':
        return t('events.statusDenied')
      case 'pending_approval':
        return t('events.statusPendingApproval')
      case 'approved':
        return t('events.statusApproved')
      case 'rejected':
        return t('events.statusRejected')
      case 'quarantined':
        return t('events.statusQuarantined')
      case 'remediated':
        return t('events.statusRemediated')
      case 'drift_detected':
        return t('events.statusDriftDetected')
      default:
        return value
    }
  }

  if (!canViewEvents) {
    return (
      <section className="card page-shell dashboard-page">
        <div className="page-shell-header">
          <h2>{t('events.recentEventsTitle')}</h2>
        </div>
        <div className="page-shell-body">
          <p className="muted">{t('events.noPermission')}</p>
        </div>
      </section>
    )
  }

  const eventTypeOptions = useMemo(
    () => Array.from(new Set(events.map((e) => String(e.event_type ?? '')).filter(Boolean))).sort(),
    [events],
  )
  const filteredAll = events.filter((e) => eventMatchesFilters(e, statusFilter, typeFilter, queryFilter))
  const filteredSecurity = securityEvents.filter((e) => eventMatchesFilters(e, statusFilter, typeFilter, queryFilter))

  const statusValues = [
    'all',
    'attempted',
    'allowed',
    'denied',
    'pending_approval',
    'approved',
    'rejected',
    'quarantined',
    'remediated',
    'drift_detected',
  ]

  return (
    <section className="card page-shell dashboard-page">
      <div className="page-shell-header events-panel">
        <div className="events-filters-head">
          <h2>{t('events.filtersTitle')}</h2>
          <div className="events-filter-stats">
            <span className="events-filter-chip">{t('events.chipAll', { count: filteredAll.length })}</span>
            <span className="events-filter-chip">{t('events.chipSecurity', { count: filteredSecurity.length })}</span>
          </div>
        </div>
        <div className="events-filters-grid">
          <label className="events-filter-field">
            <span className="events-filter-label">{t('events.labelStatus')}</span>
            <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              {statusValues.map((v) => (
                <option key={v} value={v}>
                  {statusOptionLabel(v)}
                </option>
              ))}
            </select>
          </label>
          <label className="events-filter-field">
            <span className="events-filter-label">{t('events.labelEventType')}</span>
            <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
              <option value="all">{t('events.optionAll')}</option>
              {eventTypeOptions.map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </select>
          </label>
          <label className="events-filter-field events-filter-field-search">
            <span className="events-filter-label">{t('events.labelSearch')}</span>
            <input
              type="text"
              value={queryFilter}
              onChange={(e) => setQueryFilter(e.target.value)}
              placeholder={t('events.searchPlaceholder')}
            />
          </label>
          <div className="events-filter-actions">
            <span className="events-filter-label events-filter-label-ghost" aria-hidden="true">
              {t('events.labelActions')}
            </span>
            <button
              type="button"
              className="events-filter-reset"
              onClick={() => {
                setStatusFilter('all')
                setTypeFilter('all')
                setQueryFilter('')
              }}
            >
              {t('events.resetFilters')}
            </button>
          </div>
        </div>
      </div>
      <div className="page-shell-body dashboard-page-body">
        <section className="events-panel">
          <h2>{t('events.panelReconciliation')}</h2>
          <div className="events-feed events-feed-security" role="list">
            {filteredSecurity.length === 0 ? (
              <p className="muted">{t('events.emptySecurity')}</p>
            ) : (
              filteredSecurity.map((e) => (
                <p key={e.id} className="events-line" role="listitem">
                  {formatAuditEventLine(e)}
                </p>
              ))
            )}
          </div>
        </section>

        <section className="events-panel">
          <h2>{t('events.panelAllActivity')}</h2>
          <div className="events-feed events-feed-all" role="list">
            {filteredAll.length === 0 ? (
              <p className="muted">{t('events.emptyAll')}</p>
            ) : (
              filteredAll.map((e) => (
                <p key={e.id} className="events-line" role="listitem">
                  {formatAuditEventLine(e)}
                </p>
              ))
            )}
          </div>
        </section>
      </div>
    </section>
  )
}
