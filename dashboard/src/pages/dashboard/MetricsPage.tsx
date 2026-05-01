import { useLoaderData } from 'react-router-dom'
import { useT } from '../../shared/i18n'
import type { MetricsLoaderData } from '../../features/dashboard/model/loaders'

export function MetricsPage() {
  const t = useT()
  const { metrics, usageTimeline, eventTypeBreakdown } = useLoaderData() as MetricsLoaderData
  const timelineMax = Math.max(1, ...usageTimeline.map((item) => item.value))
  const breakdownMax = Math.max(1, ...eventTypeBreakdown.map((item) => item.value))
  const metricCards = Object.entries(metrics).map(([label, value]) => {
    const tone =
      label === 'denied' || label === 'rejected' || label === 'quarantined'
        ? 'danger'
        : label === 'approved' || label === 'allowed' || label === 'remediated'
          ? 'success'
          : label === 'pending_approval' || label === 'pending_approvals_total'
            ? 'warning'
            : label === 'attempted' || label === 'drift_detected'
              ? 'primary'
              : 'neutral'
    return { label, value, tone }
  })

  const tileLabel = (key: string): string => {
    const k = `metrics.tiles.${key}` as const
    return t(k, { defaultValue: key })
  }

  return (
    <section className="card page-shell dashboard-page">
      <div className="page-shell-header">
        <h2>{t('metrics.title')}</h2>
      </div>
      <div className="page-shell-body">
        <div className="section-hero">
          <h3>{t('metrics.heroTitle')}</h3>
          <p className="muted">{t('metrics.heroSubtitle')}</p>
        </div>
        <div className="metrics-charts-grid">
          <section className="metrics-chart-card">
            <h3>{t('metrics.chartActivityTitle')}</h3>
            <p className="muted">{t('metrics.chartActivitySubtitle')}</p>
            <div className="metrics-bars" role="img" aria-label={t('metrics.ariaActivityByDay')}>
              {usageTimeline.map((item) => {
                const height = Math.max(8, Math.round((item.value / timelineMax) * 100))
                return (
                  <div key={item.label} className="metrics-bar-col">
                    <span className="metrics-bar-value">{item.value}</span>
                    <div className="metrics-bar-track">
                      <div className="metrics-bar-fill" style={{ height: `${height}%` }} />
                    </div>
                    <span className="metrics-bar-label">{item.label}</span>
                  </div>
                )
              })}
            </div>
          </section>
          <section className="metrics-chart-card">
            <h3>{t('metrics.chartDistributionTitle')}</h3>
            <p className="muted">{t('metrics.chartDistributionSubtitle')}</p>
            <div className="metrics-breakdown-list" role="img" aria-label={t('metrics.ariaEventTypeDistribution')}>
              {eventTypeBreakdown.length === 0 ? (
                <p className="muted">{t('metrics.noBreakdown')}</p>
              ) : (
                eventTypeBreakdown.map((item) => {
                  const width = Math.max(6, Math.round((item.value / breakdownMax) * 100))
                  return (
                    <div key={item.label} className="metrics-breakdown-row">
                      <span className="metrics-breakdown-label">{tileLabel(item.label)}</span>
                      <div className="metrics-breakdown-track">
                        <div className="metrics-breakdown-fill" style={{ width: `${width}%` }} />
                      </div>
                      <span className="metrics-breakdown-value">{item.value}</span>
                    </div>
                  )
                })
              )}
            </div>
          </section>
        </div>
        <div className="metric-grid metric-grid-strong">
          {metricCards.map((card) => (
            <article className={`metric metric-strong metric-${card.tone}`} key={card.label}>
              <strong>{tileLabel(card.label)}</strong>
              <span className="metric-value">{card.value}</span>
            </article>
          ))}
        </div>
      </div>
    </section>
  )
}
