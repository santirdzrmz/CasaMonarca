import { translate as t } from '../lib/i18n'
import type {
  AuthenticatedUser,
  SessionModuleCapabilities,
  UserRole,
} from '../lib/auth'
import { arcoEnabled } from './env'

export const LOGIN_PATH = '/login'
export const REGISTER_PATH = '/register'
export const RESET_PASSWORD_PATH = '/reset-password'
export const APP_HOME_PATH = '/app'
export const APP_UPLOAD_PATH = '/app/upload'
export const APP_DOCUMENTS_PATH = '/app/documents'
export const APP_INVITES_PATH = '/app/invites'
export const APP_LOGGING_PATH = '/app/logging'
export const APP_ADMIN_PATH = '/app/admin'
export const APP_MIGRANT_REGISTRY_PATH = '/app/migrants/registry'
export const APP_MIGRANT_REGISTRATIONS_PATH = '/app/migrants/registrations'
export const APP_MIGRANT_APPROVALS_PATH = '/app/migrants/approvals'
export const APP_MIGRANT_ARCO_PATH = '/app/migrants/arco'
export const APP_MIGRANT_STATISTICS_PATH = '/app/migrants/statistics'
export const FORBIDDEN_PATH = '/403'

export type AppWorkspace = 'internal' | 'migrant'

export type AppRouteConfig = {
  copy: string
  kicker: string
  label: string
  path: string
  requiredModule: keyof SessionModuleCapabilities
  allowedRoles?: UserRole[]
  enabled?: boolean
  hidden?: boolean
  workspace: AppWorkspace
}

export const APP_ROUTE_CONFIG: AppRouteConfig[] = [
  {
    path: APP_HOME_PATH,
    label: t("Dashboard", "Panel"),
    kicker: t("Access overview", "Resumen de acceso"),
    copy: t("Current session, credential state, and role-aware module access.", "Sesión actual, estado de credenciales y acceso a módulos según el rol."),
    requiredModule: 'dashboard',
    workspace: 'internal',
  },
  {
    path: APP_UPLOAD_PATH,
    label: t("Document Upload", "Carga de documentos"),
    kicker: t("Submission intake", "Recepción de envíos"),
    copy: t("Private document intake with confidential handling from the first upload.", "Recepción privada de documentos con manejo confidencial desde la primera carga."),
    requiredModule: 'upload',
    workspace: 'internal',
  },
  {
    path: APP_DOCUMENTS_PATH,
    label: t("Documents / VCS", "Documentos / VCS"),
    kicker: t("View, sign, version", "Ver, firmar, versionar"),
    copy: t("Role-aware document review, revision signing, verification, and version history.", "Consulta de documentos, firma de versiones, verificación e historial según el rol."),
    requiredModule: 'documents',
    workspace: 'internal',
  },
  {
    path: APP_INVITES_PATH,
    label: t("Invites", "Invitaciones"),
    kicker: t("Account onboarding", "Alta de cuentas"),
    copy: t("Role-bound invite lifecycle for coordinator, non coordinator, and volunteer account provisioning.", "Ciclo de vida de invitaciones por rol para crear cuentas de coordinación, no coordinación y voluntariado."),
    requiredModule: 'invites',
    workspace: 'internal',
  },
  {
    path: APP_LOGGING_PATH,
    label: t("Audit Log", "Bitácora"),
    kicker: t("Admin audit", "Auditoría administrativa"),
    copy: t("Restricted operational logs and future privileged audit views.", "Eventos operativos y de auditoría con acceso restringido."),
    requiredModule: 'logging',
    workspace: 'internal',
  },
  {
    path: APP_ADMIN_PATH,
    label: t("Admin Panel", "Panel de administración"),
    kicker: t("Admin only", "Solo administración"),
    copy: t("System administration and restricted configuration workflows.", "Administración del sistema y flujos de configuración restringidos."),
    requiredModule: 'admin',
    workspace: 'internal',
  },
  {
    path: APP_MIGRANT_REGISTRY_PATH,
    label: t("Migrant Registration", "Registro de migrantes"),
    kicker: t("Migrant intake", "Recepción de migrantes"),
    copy: t("Structured migrant registration intake submitted for review and approval.", "Captura estructurada de registros de migrantes para su revisión y aprobación."),
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator', 'volunteer'],
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_REGISTRATIONS_PATH,
    label: t("Current Registrations", "Registros actuales"),
    kicker: t("Migrant directory", "Directorio de migrantes"),
    copy: t("Search, filter, and review current migrant registrations across the shared registry.", "Busca, filtra y revisa los registros actuales de migrantes en el registro compartido."),
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator'],
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_APPROVALS_PATH,
    label: t("Review & Approval", "Revisión y aprobación"),
    kicker: t("Migrant validation", "Validación de migrantes"),
    copy: t("Non-coordinator review followed by passkey-backed coordinator approval for migrant registrations.", "Revisión por personal no coordinador y aprobación final por coordinación mediante llave de acceso."),
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator'],
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_ARCO_PATH,
    label: t("ARCO Requests", "Solicitudes ARCO"),
    kicker: t("Privacy rights", "Derechos de privacidad"),
    copy: t("Signed privacy-rights requests, review, resolution, and evidence for migrant records.", "Solicitudes firmadas de derechos de privacidad, revisión, resolución y evidencia para expedientes de migrantes."),
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator'],
    enabled: arcoEnabled,
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_STATISTICS_PATH,
    label: t("Statistics", "Estadísticas"),
    kicker: t("Registry analysis", "Análisis de registros"),
    copy: t("Arrivals, demographics, and travel times from approved migrant registrations.", "Llegadas, perfil demográfico y tiempos de trayecto a partir de los registros aprobados."),
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator'],
    workspace: 'migrant',
  },
]

export const APP_PATHS = APP_ROUTE_CONFIG.map((route) => route.path)

export const getRouteConfig = (pathname: string) =>
  APP_ROUTE_CONFIG.find((route) => route.path === pathname) ?? null

export const getRouteConfigForUser = (
  pathname: string,
  user: AuthenticatedUser,
) => {
  const route = getRouteConfig(pathname)

  if (!route || route.path !== APP_DOCUMENTS_PATH || user.capabilities.modules.history) {
    return route
  }

  return {
    ...route,
    label: t("Document Review", "Revisión de documentos"),
    kicker: t("View, verify", "Ver, verificar"),
    copy: t("Role-aware access to review and verify available documents.", "Acceso según el rol para revisar y verificar documentos disponibles."),
  }
}

export const getRequiredModuleForPath = (pathname: string) =>
  getRouteConfig(pathname)?.requiredModule ?? null

export const isProtectedPath = (pathname: string) => APP_PATHS.includes(pathname)

export const canAccessRoute = (user: AuthenticatedUser, pathname: string) => {
  const route = getRouteConfig(pathname)
  const requiredModule = route?.requiredModule ?? null

  if (!route || !requiredModule || route.enabled === false) {
    return false
  }

  if (route.workspace === 'migrant' && !user.capabilities.security.isFullyEnrolled) {
    return false
  }

  return (
    user.capabilities.modules[requiredModule] &&
    (!route.allowedRoles || route.allowedRoles.includes(user.role))
  )
}

export const getVisibleRoutesForUser = (user: AuthenticatedUser) =>
  APP_ROUTE_CONFIG
    .filter((route) => route.enabled !== false && !route.hidden && canAccessRoute(user, route.path))
    .map((route) => getRouteConfigForUser(route.path, user) ?? route)

export const getRoleLabel = (role: UserRole) => {
  switch (role) {
    case 'admin':
      return t("Admin", "Administrador")
    case 'coordinator':
      return t("Coordinator", "Coordinador")
    case 'non_coordinator':
      return t("Non Coordinator", "No coordinador")
    case 'volunteer':
      return t("Volunteer", "Voluntario")
  }
}
