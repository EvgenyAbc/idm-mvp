import { createBrowserRouter, redirect } from 'react-router-dom'
import { loginAction, loginLoader, logoutAction, rootLoader } from '../features/auth/model/routeHandlers'
import { approvalsAction, operationsAction, usersAction } from '../features/dashboard/model/actions'
import { approvalsLoader, eventsLoader, explorerLoader, metricsLoader, operationsLoader, profileLoader, usersLoader } from '../features/dashboard/model/loaders'
import { firstAllowedDashboardPath, requireSession } from '../shared/lib/authSession'
import { ApprovalsPage } from '../pages/dashboard/ApprovalsPage'
import { EventsPage } from '../pages/dashboard/EventsPage'
import { ExplorerPage } from '../pages/dashboard/ExplorerPage'
import { LoginPage } from '../pages/dashboard/LoginPage'
import { MetricsPage } from '../pages/dashboard/MetricsPage'
import { OperationsPage } from '../pages/dashboard/OperationsPage'
import { ProfilePage } from '../pages/dashboard/ProfilePage'
import { RouteErrorPage } from '../pages/dashboard/RouteErrorPage'
import { RootLayoutPage } from '../pages/dashboard/RootLayoutPage'
import { UsersPage } from '../pages/dashboard/UsersPage'

export const router = createBrowserRouter([
  {
    path: '/',
    loader: async () => {
      const session = await requireSession()
      return redirect(firstAllowedDashboardPath(session))
    },
  },
  {
    path: '/login',
    loader: loginLoader,
    action: loginAction,
    Component: LoginPage,
    ErrorBoundary: RouteErrorPage,
  },
  {
    path: '/logout',
    action: logoutAction,
  },
  {
    id: 'root',
    path: '/ldap',
    loader: rootLoader,
    Component: RootLayoutPage,
    ErrorBoundary: RouteErrorPage,
    children: [
      {
        index: true,
        loader: async () => {
          const session = await requireSession()
          return redirect(firstAllowedDashboardPath(session))
        },
      },
      {
        path: 'operations',
        loader: operationsLoader,
        action: operationsAction,
        Component: OperationsPage,
        ErrorBoundary: RouteErrorPage,
      },
      {
        path: 'operations/provisioning',
        loader: operationsLoader,
        action: operationsAction,
        Component: OperationsPage,
        ErrorBoundary: RouteErrorPage,
      },
      {
        path: 'operations/reconciliation',
        loader: operationsLoader,
        action: operationsAction,
        Component: OperationsPage,
        ErrorBoundary: RouteErrorPage,
      },
      {
        path: 'metrics',
        loader: metricsLoader,
        Component: MetricsPage,
        ErrorBoundary: RouteErrorPage,
      },
      {
        path: 'approvals',
        loader: approvalsLoader,
        action: approvalsAction,
        Component: ApprovalsPage,
        ErrorBoundary: RouteErrorPage,
      },
      {
        path: 'users',
        loader: usersLoader,
        action: usersAction,
        Component: UsersPage,
        ErrorBoundary: RouteErrorPage,
      },
      {
        path: 'explorer',
        loader: explorerLoader,
        Component: ExplorerPage,
        ErrorBoundary: RouteErrorPage,
      },
      {
        path: 'profile',
        loader: profileLoader,
        Component: ProfilePage,
        ErrorBoundary: RouteErrorPage,
      },
      {
        path: 'events',
        loader: eventsLoader,
        Component: EventsPage,
        ErrorBoundary: RouteErrorPage,
      },
    ],
  },
])
