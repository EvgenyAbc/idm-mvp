import { useEffect, useRef, useState } from 'react'
import { Form, useActionData, useLoaderData, useNavigation } from 'react-router-dom'
import type { ActionMessage } from '../../shared/i18n'
import { useT } from '../../shared/i18n'
import type { ApprovalRow } from '../../api/client'

export function ApprovalsPage() {
  const t = useT()
  const { approvals, canDecideApprovals } = useLoaderData() as { approvals: ApprovalRow[]; canDecideApprovals: boolean }
  const actionData = useActionData() as ActionMessage | undefined
  const navigation = useNavigation()
  const busy = navigation.state !== 'idle'
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

  return (
    <section className="card page-shell dashboard-page approvals-card">
      <Form method="post" className="approvals-form">
        <div className="page-shell-header">
          <h2>{t('approvals.title')}</h2>
          {actionData?.messageKey ? (
            <p className="banner-message muted">{t(actionData.messageKey, actionData.params)}</p>
          ) : null}
          <div className="approvals-toolbar row">
            <label className="approvals-select-all">
              <input
                ref={selectAllRef}
                type="checkbox"
                checked={allSelected}
                onChange={toggleSelectAll}
                aria-label={t('approvals.selectAllAria')}
              />
              <span>{t('approvals.selectAll', { count: approvals.length })}</span>
            </label>
          </div>
          <div className="approvals-bulk-actions">
            <button name="decision" value="approve" disabled={busy || selectedIds.size === 0 || !canDecideApprovals}>
              {t('approvals.approveSelected')}
            </button>
            <button name="decision" value="reject" disabled={busy || selectedIds.size === 0 || !canDecideApprovals}>
              {t('approvals.rejectSelected')}
            </button>
          </div>
          {!canDecideApprovals ? <p className="muted">{t('approvals.decideHint')}</p> : null}
        </div>
        <div className="page-shell-body">
          <div className="events">
            {approvals.map((a) => (
              <div key={a.id} className="row approval approval-row">
                <label className="approval-row-main">
                  <input type="checkbox" name="id" value={a.id} checked={selectedIds.has(String(a.id))} onChange={() => toggleOne(a.id)} />
                  <span>
                    #{a.id} {a.username} {a.field_name}: {a.reason ?? '-'}
                  </span>
                </label>
              </div>
            ))}
          </div>
        </div>
      </Form>
    </section>
  )
}
