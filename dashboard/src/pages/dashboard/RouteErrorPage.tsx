import { isRouteErrorResponse, useRouteError } from 'react-router-dom'
import { useT } from '../../shared/i18n'

export function RouteErrorPage() {
  const t = useT()
  const error = useRouteError()
  let message = t('errors.routeDefault')

  if (isRouteErrorResponse(error)) {
    message = `${error.status} ${error.statusText}`
  } else if (error instanceof Error && error.message) {
    message = error.message
  }

  return (
    <section className="card">
      <h2>{t('errors.routeTitle')}</h2>
      <p className="muted">{message}</p>
    </section>
  )
}
