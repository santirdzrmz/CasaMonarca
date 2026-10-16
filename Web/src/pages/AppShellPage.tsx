import { translate as t } from '../lib/i18n'
import { AppIcon, type AppIconName } from '../components/ui/AppIcon'
import { RoleBadge } from '../components/ui/RoleBadge'
import type { AuthenticatedUser } from '../lib/auth'
import {
  APP_ADMIN_PATH,
  APP_DOCUMENTS_PATH,
  APP_HOME_PATH,
  APP_INVITES_PATH,
  APP_LOGGING_PATH,
  APP_MIGRANT_APPROVALS_PATH,
  APP_MIGRANT_ARCO_PATH,
  APP_MIGRANT_REGISTRY_PATH,
  APP_MIGRANT_REGISTRATIONS_PATH,
  APP_MIGRANT_STATISTICS_PATH,
  APP_UPLOAD_PATH,
  getRouteConfigForUser,
  getVisibleRoutesForUser,
  getRouteConfig,
  type AppWorkspace,
} from '../config/appRoutes'
import { AdminPage } from './AdminPage'
import { DocumentsPage } from './DocumentsPage'
import { DocumentUploadPage } from './DocumentUploadPage'
import { LoggingPage } from './LoggingPage'
import { SessionPage } from './SessionPage'
import { InviteManagementPage } from './InviteManagementPage'
import { MigrantsApprovalsPage } from './registry/MigrantsApprovalsPage'
import { MigrantsArcoPage } from './registry/MigrantsArcoPage'
import { MigrantsRegistryPage } from './registry/MigrantsRegistryPage'
import { MigrantRegistrationsPage } from './registry/MigrantRegistrationsPage'
import { MigrantStatisticsPage } from './registry/MigrantStatisticsPage'

const ROUTE_ICONS: Record<string, AppIconName> = {
  [APP_HOME_PATH]: 'dashboard',
  [APP_UPLOAD_PATH]: 'upload',
  [APP_DOCUMENTS_PATH]: 'document',
  [APP_INVITES_PATH]: 'invite',
  [APP_LOGGING_PATH]: 'logging',
  [APP_ADMIN_PATH]: 'admin',
  [APP_MIGRANT_REGISTRY_PATH]: 'invite',
  [APP_MIGRANT_REGISTRATIONS_PATH]: 'document',
  [APP_MIGRANT_APPROVALS_PATH]: 'verify',
  [APP_MIGRANT_ARCO_PATH]: 'document',
  [APP_MIGRANT_STATISTICS_PATH]: 'chart',
}

const WORKSPACE_LABELS: Record<AppWorkspace, string> = {
  internal: t("Internal workspace", "Espacio interno"),
  migrant: t("Migrant workspace", "Espacio de migrantes"),
}

type AppShellPageProps = {
  currentPath: string
  currentSearch?: string
  onLoggedOut?: () => void
  onNavigate: (to: string) => void
  onSessionExpired?: () => void
  onUserUpdated?: (user: AuthenticatedUser) => void
  user: AuthenticatedUser
}

function renderModule(
  currentPath: string,
  currentSearch: string | undefined,
  onNavigate: (to: string) => void,
  user: AuthenticatedUser,
  onUserUpdated?: (user: AuthenticatedUser) => void,
  onLoggedOut?: () => void,
  onSessionExpired?: () => void,
) {
  switch (currentPath) {
    case APP_UPLOAD_PATH:
      return (
        <DocumentUploadPage
          onNavigate={onNavigate}
          onSessionExpired={onSessionExpired}
          user={user}
        />
      )
    case APP_DOCUMENTS_PATH:
      return <DocumentsPage locationSearch={currentSearch} onSessionExpired={onSessionExpired} user={user} />
    case APP_INVITES_PATH:
      return <InviteManagementPage onSessionExpired={onSessionExpired} user={user} />
    case APP_LOGGING_PATH:
      return <LoggingPage onNavigate={onNavigate} onSessionExpired={onSessionExpired} />
    case APP_ADMIN_PATH:
      return (
        <AdminPage
          locationSearch={currentSearch}
          onNavigate={onNavigate}
          onSessionExpired={onSessionExpired}
          user={user}
        />
      )
    case APP_MIGRANT_REGISTRY_PATH:
      return (
        <MigrantsRegistryPage
          locationSearch={currentSearch}
          onNavigate={onNavigate}
          onSessionExpired={onSessionExpired}
          user={user}
        />
      )
    case APP_MIGRANT_REGISTRATIONS_PATH:
      return (
        <MigrantRegistrationsPage
          onNavigate={onNavigate}
          onSessionExpired={onSessionExpired}
          user={user}
        />
      )
    case APP_MIGRANT_APPROVALS_PATH:
      return (
        <MigrantsApprovalsPage
          onSessionExpired={onSessionExpired}
          user={user}
        />
      )
    case APP_MIGRANT_ARCO_PATH:
      return <MigrantsArcoPage onSessionExpired={onSessionExpired} user={user} />
    case APP_MIGRANT_STATISTICS_PATH:
      return <MigrantStatisticsPage onSessionExpired={onSessionExpired} user={user} />
    default:
      return (
        <SessionPage
          onLoggedOut={onLoggedOut}
          onNavigate={onNavigate}
          onSessionExpired={onSessionExpired}
          onUserUpdated={onUserUpdated}
          user={user}
        />
      )
  }
}

export function AppShellPage({
  currentPath,
  currentSearch,
  onLoggedOut,
  onNavigate,
  onSessionExpired,
  onUserUpdated,
  user,
}: AppShellPageProps) {
  const route =
    getRouteConfigForUser(currentPath, user) ??
    getRouteConfigForUser('/app', user) ??
    getRouteConfig('/app')
  const visibleRoutes = getVisibleRoutesForUser(user)

  if (!route) {
    return null
  }

  const activeRouteIcon = ROUTE_ICONS[route.path] ?? 'document'
  const groupedRoutes = visibleRoutes.reduce<Record<AppWorkspace, typeof visibleRoutes>>(
    (groups, navRoute) => {
      groups[navRoute.workspace].push(navRoute)
      return groups
    },
    {
      internal: [],
      migrant: [],
    },
  )

  return (
    <main className="workspace-shell">
      <aside className="workspace-sidebar">
        <div className="workspace-sidebar__brand">
          <p className="workspace-sidebar__eyebrow">Casa Monarca</p>
          <h1 className="workspace-sidebar__title">{t("Access control workspace", "Espacio de trabajo interno")}</h1>
          <p className="workspace-sidebar__copy">
            {t("Role-aware shell for the first document, history, logging, and admin modules. ", "Entorno por rol para los módulos de documentos, historial, bitácora y administración. ")}</p>
        </div>

        <section className="workspace-sidebar__role">
          <span className="workspace-sidebar__role-label">{t("Current role", "Rol actual")}</span>
          <RoleBadge className="workspace-sidebar__role-value" role={user.role} />
        </section>

        <nav aria-label={t("App navigation", "Navegación de la aplicación")} className="workspace-nav">
          {(['internal', 'migrant'] as AppWorkspace[]).map((workspace) => {
            const routes = groupedRoutes[workspace]

            if (routes.length === 0) {
              return null
            }

            return (
              <details
                className="workspace-nav__group"
                key={workspace}
                open
              >
                <summary>{WORKSPACE_LABELS[workspace]}</summary>
                <div className="workspace-nav__group-items">
                  {routes.map((navRoute) => {
                    const isActive = navRoute.path === currentPath

                    return (
                      <a
                        key={navRoute.path}
                        className={`workspace-nav__item${isActive ? ' workspace-nav__item--active' : ''}`}
                        href={navRoute.path}
                        onClick={(event) => {
                          event.preventDefault()
                          onNavigate(navRoute.path)
                        }}
                      >
                        <span className="workspace-nav__icon">
                          <AppIcon name={ROUTE_ICONS[navRoute.path] ?? 'document'} />
                        </span>
                        <span className="workspace-nav__content">
                          <span className="workspace-nav__eyebrow">{navRoute.kicker}</span>
                          <strong className="workspace-nav__label">{navRoute.label}</strong>
                        </span>
                      </a>
                    )
                  })}
                </div>
              </details>
            )
          })}
        </nav>
      </aside>

      <section className="workspace-main">
        <header className="workspace-header">
          <div>
            <p className="workspace-header__eyebrow">{route.kicker}</p>
            <div className="workspace-header__title-row">
              <span className="workspace-header__icon">
                <AppIcon name={activeRouteIcon} size={22} />
              </span>
              <h2 className="workspace-header__title">{route.label}</h2>
            </div>
          </div>
          <p className="workspace-header__copy">{route.copy}</p>
        </header>

        <section className="workspace-content">
          {renderModule(
            currentPath,
            currentSearch,
            onNavigate,
            user,
            onUserUpdated,
            onLoggedOut,
            onSessionExpired,
          )}
        </section>
      </section>
    </main>
  )
}
