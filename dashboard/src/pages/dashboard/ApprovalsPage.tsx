import { useEffect, useRef, useState } from 'react'
import { Form, useActionData, useFetcher, useLoaderData, useNavigation } from 'react-router-dom'
import type { ActionMessage } from '../../shared/i18n'
import { useT } from '../../shared/i18n'
import type { ApprovalRow } from '../../api/client'

export function ApprovalsPage() {
  const t = useT()
  const { approvals, canDecideApprovals } = useLoaderData() as { approvals: ApprovalRow[]; canDecideApprovals: boolean }
  const actionData = useActionData() as ActionMessage | undefined
  const fetcher = useFetcher<ActionMessage>()
  const navigation = useNavigation()
  const busy = navigation.state !== 'idle'
  const fetcherBusy = fetcher.state !== 'idle'
  const anyBusy = busy || fetcherBusy
  const notice = actionData ?? fetcher.data

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const selectAllRef = useRef<HTMLInputElement | null>(null)

  useEffect(() => {
    const valid = new Set(approvals.map((a) => String(a.id)))
    setSelectedIds((prev) => new Set([...prev].filter((id) => valid.has(id))))
  }, [approvals])

  useEffect(() => {
    const el = selectAllRef.current
    if (!el || approvals.length === 0) return
    const allOn = approvals.every((a) => selectedIds.has(String(a.id)))
    const someOn = approvals.some((a) => selectedIds.has(String(a.id)))
    el.indeterminate = someOn && !allOn
  }, [approvals, selectedIds])

  const allSelected = approvals.length > 0 && approvals.every((a) => selectedIds.has(String(a.id)))
  const toggleSelectAll = (): void => {
    if (allSelected) setSelectedIds(new Set())
    else setSelectedIds(new Set(approvals.map((a) => String(a.id))))
  }

  const toggleOne = (id: number): void => {
    const key = String(id)
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  const submitOne = (id: number, decision: 'approve' | 'reject'): void => {
    const fd = new FormData()
    fd.append('decision', decision)
    fd.append('id', String(id))
    fetcher.submit(fd, { method: 'post' })
  }

  return (
    <section className="card page-shell dashboard-page approvals-card">
      <Form method="post" className="approvals-form">
        <div className="page-shell-header">
          <h2>{t('approvals.title')}</h2>
          {notice?.messageKey ? (
            <p className="banner-message muted">{t(notice.messageKey, notice.params)}</p>
          ) : null}
          <div className="approvals-actions-bar">
            <label className="approvals-select-all">
              <input
                ref={selectAllRef}
                type="checkbox"
                checked={allSelected}
                onChange={toggleSelectAll}
                disabled={approvals.length === 0}
                aria-label={t('approvals.selectAllAria')}
              />
              <span>{t('approvals.selectAll', { count: approvals.length })}</span>
            </label>
            <div className="approvals-bulk-actions">
              <button type="submit" name="decision" value="approve" disabled={anyBusy || selectedIds.size === 0 || !canDecideApprovals}>
                {t('approvals.approveSelected')}
              </button>
              <button type="submit" name="decision" value="reject" disabled={anyBusy || selectedIds.size === 0 || !canDecideApprovals}>
                {t('approvals.rejectSelected')}
              </button>
            </div>
          </div>
          {!canDecideApprovals ? <p className="muted">{t('approvals.decideHint')}</p> : null}
        </div>
        <div className="page-shell-body">
          {approvals.length === 0 ? (
            <p className="muted approvals-empty">{t('approvals.empty')}</p>
          ) : (
            <ul className="approvals-list">
              {approvals.map((a) => (
                <li key={a.id} className="approval-row approvals-list-item">
                  <label className="approval-row-select">
                    <input
                      type="checkbox"
                      name="id"
                      value={a.id}
                      checked={selectedIds.has(String(a.id))}
                      onChange={() => toggleOne(a.id)}
                    />
                  </label>
                  <div className="approval-row-body">
                    <div className="approval-row-title">
                      <span className="approval-row-id">#{a.id}</span>
                      <span className="approval-row-user">{a.username}</span>
                    </div>
                    <div className="approval-row-meta muted">
                      <span>{a.field_name}</span>
                      <span className="approval-row-reason">{a.reason?.trim() ? a.reason : '—'}</span>
                    </div>
                  </div>
                  <div className="approval-row-actions">
                    <button
                      type="button"
                      className="approvals-btn-approve"
                      disabled={anyBusy || !canDecideApprovals}
                      onClick={() => submitOne(a.id, 'approve')}
                    >
                      {t('approvals.approve')}
                    </button>
                    <button
                      type="button"
                      className="approvals-btn-reject"
                      disabled={anyBusy || !canDecideApprovals}
                      onClick={() => submitOne(a.id, 'reject')}
                    >
                      {t('approvals.reject')}
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Form>
    </section>
  )
}
