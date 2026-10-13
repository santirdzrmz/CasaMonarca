import { translate as t } from './lib/i18n'
import { useCallback, useEffect, useState } from 'react'

import './App.css'
import {
  APP_HOME_PATH,
  APP_DOCUMENTS_PATH,
  APP_MIGRANT_ARCO_PATH,
  APP_MIGRANT_REGISTRY_PATH,
  canAccessRoute,
  FORBIDDEN_PATH,
  isProtectedPath,
  LOGIN_PATH,
  REGISTER_PATH,
  RESET_PASSWORD_PATH,
} from './config/appRoutes'
import { appChannel } from './config/env'
import { ApiRequestError } from './lib/api'
import { getCurrentUser, type AuthenticatedUser, type UserRole } from './lib/auth'
import { syncActingDepartment } from './lib/departments'
import { AppShellPage } from './pages/AppShellPage'
import { ForbiddenPage } from './pages/ForbiddenPage'
import { LoginPage } from './pages/LoginPage'
import { RegisterPage } from './pages/RegisterPage'
import { ResetPasswordPage } from './pages/ResetPasswordPage'

const LOGIN_REASON_KEY = 'reason'
const LOGIN_EMAIL_KEY = 'email'
const LOGIN_REASON_SIGNED_OUT = 'signed-out'
const LOGIN_REASON_SESSION_EXPIRED = 'session-expired'
const LOGIN_REASON_REGISTERED = 'registered'
const LOGIN_REASON_PASSWORD_RESET = 'password-reset'
const DEV_ROLE_OVERRIDE_STORAGE_KEY = 'casamonarca.dev.mockRole'
const DEV_ROLE_REAL_VALUE = '__real__'
const MOCKABLE_ROLES: UserRole[] = ['admin', 'coordinator', 'non_coordinator', 'volunteer']

type LoginNoticeTone = 'online' | 'offline'

type LoginNotice = {
  message: string
  tone: LoginNoticeTone
}

type AppLocation = {
  pathname: string
  search: string
}

const isValidMockRole = (value: string): value is UserRole =>
  MOCKABLE_ROLES.includes(value as UserRole)

const isDevChannel = appChannel?.toLowerCase() === 'dev'

const getStoredDevRoleOverride = (): UserRole | null => {
  if (!isDevChannel) {
    return null
  }

  const stored = window.localStorage.getItem(DEV_ROLE_OVERRIDE_STORAGE_KEY)

  if (!stored || !isValidMockRole(stored)) {
    return null
  }

  return stored
}

const normalizePathname = (pathname: string) => {
  const cleanPathname = pathname.split('?')[0]?.split('#')[0] ?? pathname
  const normalized =
    cleanPathname.endsWith('/') && cleanPathname !== '/'
    ? cleanPathname.slice(0, -1)
    : cleanPathname

  return normalized === '/' ? APP_HOME_PATH : normalized
}

const getSafePostLoginPath = (search: string) => {
  const nextPath = new URLSearchParams(search).get('next')

  if (!nextPath || !nextPath.startsWith('/') || nextPath.startsWith('//')) {
    return null
  }

  const normalizedNextPath = normalizeLegacyProtectedPath(normalizePathname(nextPath))

  if (normalizedNextPath === LOGIN_PATH || !isProtectedPath(normalizedNextPath)) {
    return null
  }

  return normalizedNextPath
}

const normalizeLegacyProtectedPath = (pathname: string) =>
  pathname === '/app/history' ? APP_DOCUMENTS_PATH : pathname

const getLoginReason = (search: string) => {
  const reason = new URLSearchParams(search).get(LOGIN_REASON_KEY)

  if (
    reason !== LOGIN_REASON_SIGNED_OUT &&
    reason !== LOGIN_REASON_SESSION_EXPIRED &&
    reason !== LOGIN_REASON_REGISTERED &&
    reason !== LOGIN_REASON_PASSWORD_RESET
  ) {
    return null
  }

  return reason
}

const getLoginEmail = (search: string) => {
  const email = new URLSearchParams(search).get(LOGIN_EMAIL_KEY)

  return email?.trim() ? email.trim() : null
}

const getInviteToken = (search: string) => {
  const token = new URLSearchParams(search).get('inviteToken')

  return token?.trim() ? token.trim() : null
}

const getPasswordResetParams = (search: string) => {
  const params = new URLSearchParams(search)
  const email = params.get('email')?.trim() || null
  const token = params.get('token')?.trim() || null

  return { email, token }
}

const buildLoginPath = (options?: {
  email?: string
  nextPath?: string
  reason?: string
}) => {
  const params = new URLSearchParams()

  if (options?.email) {
    params.set(LOGIN_EMAIL_KEY, options.email)
  }

  if (options?.nextPath) {
    params.set('next', options.nextPath)
  }

  if (options?.reason) {
    params.set(LOGIN_REASON_KEY, options.reason)
  }

  const serializedParams = params.toString()

  return serializedParams ? `${LOGIN_PATH}?${serializedParams}` : LOGIN_PATH
}

function NotFoundPage({ onNavigate }: { onNavigate: (to: string) => void }) {
  return (
    <main className="route-shell">
      <section className="route-card route-card--compact">
        <p className="route-kicker">{t("CasaMonarca", "Casa Monarca")}</p>
        <h1 className="route-title">{t("Route pending", "Ruta pendiente")}</h1>
        <p className="route-copy">
          {t("This route does not exist in the web client yet. The current entry point is the authenticated app shell or the sign-in screen. ", "Esta ruta todavía no existe en el cliente web. El punto de entrada actual es la aplicación autenticada o la pantalla de inicio de sesión. ")}</p>
        <a
          className="route-link"
          href="/login"
          onClick={(event) => {
            event.preventDefault()
            onNavigate(LOGIN_PATH)
          }}
        >
          {t("Go to /login ", "Ir a /login ")}</a>
      </section>
    </main>
  )
}

function SessionBootstrapPage() {
  return (
    <main className="route-shell">
      <section className="route-card route-card--compact">
        <p className="route-kicker">{t("CasaMonarca", "Casa Monarca")}</p>
        <h1 className="route-title">{t("Checking session", "Verificando sesión")}</h1>
        <p className="route-copy">
          {t("Restoring the current browser session before rendering the web client. ", "Restaurando la sesión actual del navegador antes de cargar el cliente web. ")}</p>
      </section>
    </main>
  )
}

function RedirectPage({
  onNavigate,
  to,
}: {
  onNavigate: (to: string, options?: { replace?: boolean }) => void
  to: string
}) {
  useEffect(() => {
    onNavigate(to, { replace: true })
  }, [onNavigate, to])

  return (
    <main className="route-shell">
      <section className="route-card route-card--compact">
        <p className="route-kicker">{t("CasaMonarca", "Casa Monarca")}</p>
        <h1 className="route-title">{t("Redirecting", "Redirigiendo")}</h1>
        <p className="route-copy">{t("Applying route guard rules...", "Aplicando reglas de protección de rutas...")}</p>
      </section>
    </main>
  )
}

type AuthBootstrapState = {
  error: string | null
  status: 'checking' | 'authenticated' | 'unauthenticated'
  user: AuthenticatedUser | null
}

const buildMockCapabilities = (
  role: UserRole,
): AuthenticatedUser['capabilities'] => {
  switch (role) {
    case 'admin':
      return {
        modules: {
          admin: true,
          dashboard: true,
          documents: true,
          history: true,
          invites: true,
          logging: true,
          upload: true,
        },
        security: {
          enrolled: {
            passkey: true,
            totp: true,
          },
          enforced: false,
          isFullyEnrolled: true,
          missing: {
            passkey: false,
            totp: false,
          },
          requires: {
            passkey: false,
            totp: false,
          },
        },
      }
    case 'coordinator':
      return {
        modules: {
          admin: false,
          dashboard: true,
          documents: true,
          history: true,
          invites: true,
          logging: false,
          upload: true,
        },
        security: {
          enrolled: {
            passkey: true,
            totp: true,
          },
          enforced: true,
          isFullyEnrolled: true,
          missing: {
            passkey: false,
            totp: false,
          },
          requires: {
            passkey: true,
            totp: true,
          },
        },
      }
    case 'non_coordinator':
      return {
        modules: {
          admin: false,
          dashboard: true,
          documents: true,
          history: false,
          invites: false,
          logging: false,
          upload: true,
        },
        security: {
          enrolled: {
            passkey: false,
            totp: true,
          },
          enforced: true,
          isFullyEnrolled: true,
          missing: {
            passkey: false,
            totp: false,
          },
          requires: {
            passkey: false,
            totp: true,
          },
        },
      }
    case 'volunteer':
      return {
        modules: {
          admin: false,
          dashboard: true,
          documents: false,
          history: false,
          invites: false,
          logging: false,
          upload: true,
        },
        security: {
          enrolled: {
            passkey: false,
            totp: true,
          },
          enforced: true,
          isFullyEnrolled: true,
          missing: {
            passkey: false,
            totp: false,
          },
          requires: {
            passkey: false,
            totp: true,
          },
        },
      }
  }
}

const buildMockUser = (
  user: AuthenticatedUser,
  roleOverride: UserRole | null,
): AuthenticatedUser => {
  if (!roleOverride || roleOverride === user.role) {
    return user
  }

  return {
    ...user,
    role: roleOverride,
    capabilities: buildMockCapabilities(roleOverride),
  }
}

function EnvironmentBadge() {
  if (!appChannel) {
    return null
  }

  return (
    <aside
      aria-label={t(`Current app channel: ${appChannel}`, `Canal actual de la aplicación: ${appChannel}`)}
      className="environment-badge"
    >
      <span aria-hidden="true" className="environment-badge__dot" />
      <span className="environment-badge__label">
        {t("branch ", "Rama ")}{appChannel.toUpperCase()}
      </span>
    </aside>
  )
}

function DevRoleViewSwitcher({
  actualRole,
  onRoleChange,
  roleOverride,
}: {
  actualRole: UserRole
  onRoleChange: (role: UserRole | null) => void
  roleOverride: UserRole | null
}) {
  const selectedValue = roleOverride ?? DEV_ROLE_REAL_VALUE

  return (
    <aside className="dev-role-switcher" aria-label={t("Development role mock switcher", "Selector de rol simulado de desarrollo")}>
      <p className="dev-role-switcher__label">{t("Dev role view", "Vista de rol dev")}</p>
      <select
        className="dev-role-switcher__select"
        onChange={(event) => {
          const nextValue = event.target.value

          if (nextValue === DEV_ROLE_REAL_VALUE) {
            onRoleChange(null)
            return
          }

          if (isValidMockRole(nextValue)) {
            onRoleChange(nextValue === actualRole ? null : nextValue)
          }
        }}
        value={selectedValue}
      >
        <option value={DEV_ROLE_REAL_VALUE}>{t("Real session (", "Sesión real (")}{actualRole})</option>
        <option value="admin">{t("Mock: admin", "Simulado: admin")}</option>
        <option value="coordinator">{t("Mock: coordinator", "Simulado: coordinación")}</option>
        <option value="non_coordinator">{t("Mock: non coordinator", "Simulado: no coordinación")}</option>
        <option value="volunteer">{t("Mock: volunteer", "Simulado: voluntariado")}</option>
      </select>
      <p className="dev-role-switcher__hint">{t("UI mock only. Backend authorization is unchanged.", "Solo simula la interfaz. La autorización del backend no cambia.")}</p>
    </aside>
  )
}

const getCurrentLocation = (): AppLocation => ({
  pathname: window.location.pathname,
  search: window.location.search,
})

function App() {
  const [authState, setAuthState] = useState<AuthBootstrapState>({
    error: null,
    status: 'checking',
    user: null,
  })
  const [devRoleOverride, setDevRoleOverride] = useState<UserRole | null>(() =>
    getStoredDevRoleOverride(),
  )
  const [locationState, setLocationState] = useState<AppLocation>(
    getCurrentLocation,
  )
  const pathname = normalizeLegacyProtectedPath(normalizePathname(locationState.pathname))
  const postLoginPath = getSafePostLoginPath(locationState.search)
  const loginReason = getLoginReason(locationState.search)
  const loginEmail = getLoginEmail(locationState.search)
  const inviteToken = getInviteToken(locationState.search)
  const passwordResetParams = getPasswordResetParams(locationState.search)
  const [authRedirectReason, setAuthRedirectReason] = useState<string | null>(
    loginReason,
  )
  const activeUser = authState.user
    ? buildMockUser(authState.user, devRoleOverride)
    : null

  useEffect(() => {
    if (!isDevChannel) {
      return
    }

    if (!devRoleOverride) {
      window.localStorage.removeItem(DEV_ROLE_OVERRIDE_STORAGE_KEY)
      return
    }

    window.localStorage.setItem(DEV_ROLE_OVERRIDE_STORAGE_KEY, devRoleOverride)
  }, [devRoleOverride])

  // The API validates departments against the real account, never the dev role override.
  const authenticatedUserId = authState.user?.id ?? null
  const assignedDepartmentsKey = (authState.user?.departments ?? []).join(',')

  useEffect(() => {
    syncActingDepartment(
      authenticatedUserId,
      assignedDepartmentsKey === '' ? [] : assignedDepartmentsKey.split(','),
    )
  }, [authenticatedUserId, assignedDepartmentsKey])

  const navigate = useCallback(
    (to: string, options?: { replace?: boolean }) => {
      const url = new URL(to, window.location.origin)
      const nextLocation = `${url.pathname}${url.search}${url.hash}`
      const currentLocation = `${window.location.pathname}${window.location.search}${window.location.hash}`

      if (currentLocation === nextLocation) {
        return
      }

      if (options?.replace) {
        window.history.replaceState(null, '', nextLocation)
      } else {
        window.history.pushState(null, '', nextLocation)
      }

      setLocationState({
        pathname: url.pathname,
        search: url.search,
      })
    },
    [],
  )

  useEffect(() => {
    const handlePopState = () => {
      setLocationState(getCurrentLocation())
    }

    window.addEventListener('popstate', handlePopState)

    return () => {
      window.removeEventListener('popstate', handlePopState)
    }
  }, [])

  useEffect(() => {
    let isMounted = true

    getCurrentUser()
      .then((response) => {
        if (!isMounted) {
          return
        }

        setAuthState({
          error: null,
          status: 'authenticated',
          user: response.user,
        })
        setAuthRedirectReason(null)
      })
      .catch((error) => {
        if (!isMounted) {
          return
        }

        if (error instanceof ApiRequestError && error.status === 401) {
          setAuthState({
            error: null,
            status: 'unauthenticated',
            user: null,
          })
          return
        }

        setAuthState({
          error:
            error instanceof Error
              ? error.message
              : t("The current session could not be restored.", "No se pudo restaurar la sesión actual."),
          status: 'unauthenticated',
          user: null,
        })
      })

    return () => {
      isMounted = false
    }
  }, [])

  const handleAuthenticated = (user: AuthenticatedUser) => {
    setAuthRedirectReason(null)
    setAuthState({
      error: null,
      status: 'authenticated',
      user,
    })

    if (pathname === LOGIN_PATH) {
      const destination = postLoginPath ?? APP_HOME_PATH
      navigate(destination, { replace: true })
    }
  }

  const handleLoggedOut = (reason: string = LOGIN_REASON_SIGNED_OUT) => {
    setAuthRedirectReason(reason)
    setAuthState({
      error: null,
      status: 'unauthenticated',
      user: null,
    })

    navigate(buildLoginPath({ reason }), { replace: true })
  }

  const handleUserUpdated = (user: AuthenticatedUser) => {
    setAuthState(() => ({
      error: null,
      status: 'authenticated',
      user,
    }))
  }

  const loginNotice: LoginNotice | null =
    authState.error != null
      ? { message: authState.error, tone: 'offline' }
      : loginReason === LOGIN_REASON_SIGNED_OUT
        ? { message: t("Session closed successfully.", "Sesión cerrada correctamente."), tone: 'online' }
        : loginReason === LOGIN_REASON_SESSION_EXPIRED
          ? {
              message: t("Session expired. Sign in again to continue.", "La sesión expiró. Inicia sesión de nuevo para continuar."),
              tone: 'offline',
            }
          : loginReason === LOGIN_REASON_REGISTERED
            ? {
                message: t("Account created. Sign in to finish required security enrollment.", "Cuenta creada. Inicia sesión para completar el registro de seguridad requerido."),
                tone: 'online',
              }
            : loginReason === LOGIN_REASON_PASSWORD_RESET
              ? {
                  message: t("Password reset complete. Sign in with your new password.", "Restablecimiento de contraseña completado. Inicia sesión con tu nueva contraseña."),
                  tone: 'online',
                }
          : null

  if (authState.status === 'checking') {
    return (
      <>
        <EnvironmentBadge />
        <SessionBootstrapPage />
      </>
    )
  }

  if (pathname === LOGIN_PATH) {
    if (authState.status === 'authenticated' && authState.user) {
      return (
        <>
          <EnvironmentBadge />
          <RedirectPage onNavigate={navigate} to={postLoginPath ?? APP_HOME_PATH} />
        </>
      )
    }

    return (
      <>
        <EnvironmentBadge />
        <LoginPage
          initialEmail={loginEmail}
          onAuthenticated={handleAuthenticated}
          sessionNotice={loginNotice}
        />
      </>
    )
  }

  if (pathname === FORBIDDEN_PATH) {
    if (authState.status === 'authenticated' && activeUser) {
      return (
        <>
          <EnvironmentBadge />
          <ForbiddenPage onNavigate={navigate} role={activeUser.role} />
          {isDevChannel && authState.user ? (
            <DevRoleViewSwitcher
              actualRole={authState.user.role}
              onRoleChange={setDevRoleOverride}
              roleOverride={devRoleOverride}
            />
          ) : null}
        </>
      )
    }

    return (
      <>
        <EnvironmentBadge />
        <RedirectPage
          onNavigate={navigate}
          to={buildLoginPath({ nextPath: APP_HOME_PATH })}
        />
      </>
    )
  }

  if (pathname === REGISTER_PATH) {
    if (authState.status === 'authenticated' && activeUser) {
      return (
        <>
          <EnvironmentBadge />
          <RedirectPage onNavigate={navigate} to={APP_HOME_PATH} />
          {isDevChannel && authState.user ? (
            <DevRoleViewSwitcher
              actualRole={authState.user.role}
              onRoleChange={setDevRoleOverride}
              roleOverride={devRoleOverride}
            />
          ) : null}
        </>
      )
    }

    return (
      <>
        <EnvironmentBadge />
        <RegisterPage
          inviteTokenFromQuery={inviteToken}
          loginPathForRegistration={(email) =>
            buildLoginPath({
              email,
              nextPath: APP_HOME_PATH,
              reason: LOGIN_REASON_REGISTERED,
            })
          }
          onNavigate={navigate}
        />
      </>
    )
  }

  if (pathname === RESET_PASSWORD_PATH) {
    return (
      <>
        <EnvironmentBadge />
        <ResetPasswordPage
          emailFromQuery={passwordResetParams.email}
          loginPathForReset={(email) =>
            buildLoginPath({
              email,
              nextPath: APP_HOME_PATH,
              reason: LOGIN_REASON_PASSWORD_RESET,
            })
          }
          onNavigate={navigate}
          tokenFromQuery={passwordResetParams.token}
        />
        {isDevChannel && authState.user ? (
          <DevRoleViewSwitcher
            actualRole={authState.user.role}
            onRoleChange={setDevRoleOverride}
            roleOverride={devRoleOverride}
          />
        ) : null}
      </>
    )
  }

  if (pathname === '/registry/migrants') {
    return (
      <>
        <EnvironmentBadge />
        <RedirectPage onNavigate={navigate} to={APP_MIGRANT_REGISTRY_PATH} />
      </>
    )
  }

  if (pathname === '/registry/migrants/arco') {
    return (
      <>
        <EnvironmentBadge />
        <RedirectPage onNavigate={navigate} to={APP_MIGRANT_ARCO_PATH} />
      </>
    )
  }

  if (isProtectedPath(pathname)) {
    if (authState.status === 'authenticated' && activeUser) {
      if (!canAccessRoute(activeUser, pathname)) {
        return (
          <>
            <EnvironmentBadge />
            <RedirectPage onNavigate={navigate} to={FORBIDDEN_PATH} />
            {isDevChannel && authState.user ? (
              <DevRoleViewSwitcher
                actualRole={authState.user.role}
                onRoleChange={setDevRoleOverride}
                roleOverride={devRoleOverride}
              />
            ) : null}
          </>
        )
      }

      return (
        <>
          <EnvironmentBadge />
          <AppShellPage
            currentPath={pathname}
            currentSearch={locationState.search}
            onLoggedOut={handleLoggedOut}
            onNavigate={navigate}
            onSessionExpired={() =>
              handleLoggedOut(LOGIN_REASON_SESSION_EXPIRED)
            }
            onUserUpdated={handleUserUpdated}
            user={activeUser}
          />
          {isDevChannel && authState.user ? (
            <DevRoleViewSwitcher
              actualRole={authState.user.role}
              onRoleChange={setDevRoleOverride}
              roleOverride={devRoleOverride}
            />
          ) : null}
        </>
      )
    }

    return (
      <>
        <EnvironmentBadge />
        <RedirectPage
          onNavigate={navigate}
          to={buildLoginPath({
            nextPath: pathname,
            reason: authRedirectReason ?? undefined,
          })}
        />
      </>
    )
  }

  return (
    <>
      <EnvironmentBadge />
      <NotFoundPage onNavigate={navigate} />
    </>
  )
}

export default App
