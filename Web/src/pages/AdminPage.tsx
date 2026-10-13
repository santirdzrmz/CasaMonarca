import { translate as t } from '../lib/i18n'
import { getCurrentUser, type AuthenticatedUser } from '../lib/auth'
import { AdminUserDepartmentsEditor } from '../components/AdminUserDepartmentsEditor'
import { AppIcon } from '../components/ui/AppIcon'
import { RoleBadge } from '../components/ui/RoleBadge'
import { type CSSProperties, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { ApiRequestError } from '../lib/api'
import { APP_DOCUMENTS_PATH } from '../config/appRoutes'
import {
  getAdminUsers,
  getMigrantSigningLedger,
  getSigningLedger,
  getVerificationPackageSigningKey,
  startAdminUserRecovery,
  startAdminUserCurpUpdate,
  startAdminUserRoleUpdate,
  startAdminUserStatusUpdate,
  startVerificationPackageSigningKeyRotation,
  verifyAdminUserRecovery,
  verifyAdminUserCurpUpdate,
  verifyAdminUserRoleUpdate,
  verifyAdminUserStatusUpdate,
  verifyVerificationPackageSigningKeyRotation,
  type AssignableUserRole,
  type AdminUserRecoveryAction,
  type AdminUserListResponse,
  type AdminUserStatusAction,
  type AdminUserSummary,
  type MigrantSigningLedgerRegistration,
  type MigrantSigningLedgerSigner,
  type SigningLedgerDocument,
  type SigningLedgerRevision,
  type SigningLedgerSigner,
  type SigningLedgerSignature,
  type VerificationPackageSigningKeySummary,
} from '../lib/adminUsers'
import { getRoleLabel } from '../config/appRoutes'
import { cancelSecurityChallenge } from '../lib/securityChallenges'
import { isValidCurp, normalizeCurp } from '../lib/curp'
import { getWebauthnAssertion } from '../lib/webauthn'

type AdminPageProps = {
  locationSearch?: string
  onNavigate?: (to: string) => void
  onSessionExpired?: () => void
  onUserUpdated?: (user: AuthenticatedUser) => void
  user: AuthenticatedUser
}

const formatOptionalDate = (value?: string | null) => {
  if (!value) {
    return 'Not recorded yet'
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

const formatFingerprint = (value?: string | null) => value || 'Not configured'

const formatLedgerLabel = (value: string) =>
  value
    .split('_')
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')

const getDefaultSigningKeyId = () => `cm-package-${new Date().toISOString().slice(0, 10)}`

const getSigningKeyProvisionCommand = (keyId?: string | null) =>
  `php artisan verification-packages:generate-signing-key --key-id=${keyId || getDefaultSigningKeyId()} --write-env`

const getEnrollmentLabel = (account: AdminUserSummary) => {
  if (account.enrollment.isFullyEnrolled) {
    return 'Complete'
  }

  const missing = [
    account.enrollment.missing.totp ? 'TOTP' : null,
    account.enrollment.missing.passkey ? 'Passkey' : null,
  ].filter(Boolean)

  return missing.length > 0 ? `Missing ${missing.join(' + ')}` : 'Not required'
}

const ASSIGNABLE_ROLES: AssignableUserRole[] = [
  'coordinator',
  'non_coordinator',
  'volunteer',
]

type AdminTabId = 'accounts' | 'security' | 'signing' | 'migrant-signing' | 'system' | 'audit'

type CurpUpdateFeedback = {
  kind: 'error' | 'status' | 'success'
  message: string
}

const ADMIN_TABS: Array<{
  copy: string
  id: AdminTabId
  label: string
}> = [
  {
    id: 'accounts',
    label: t("Account Management", "Gestión de cuentas"),
    copy: t("Users, roles, enrollment recovery, and account status.", "Usuarios, roles, recuperación de inscripción y estado de cuenta."),
  },
  {
    id: 'security',
    label: t("Security Keys", "Llaves de seguridad"),
    copy: t("Package signing trust anchor and key rotation.", "Ancla de confianza para firma de paquetes y rotación de llaves."),
  },
  {
    id: 'signing',
    label: t("Signing Trust", "Confianza de firma"),
    copy: t("Users, signatures, and signed document revisions.", "Usuarios, firmas y versiones de documentos firmadas."),
  },
  {
    id: 'migrant-signing',
    label: t("Migrant Signing Trust", "Confianza de firma de migrantes"),
    copy: t("Registration submissions, reviews, approvals, and signing keys.", "Envíos de registros, revisiones, aprobaciones y llaves de firma."),
  },
  {
    id: 'system',
    label: t("System Configuration", "Configuración del sistema"),
    copy: t("Privileged security, invite, and operational policies.", "Políticas privilegiadas de seguridad, invitaciones y operación."),
  },
  {
    id: 'audit',
    label: t("Audit Overview", "Resumen de auditoría"),
    copy: t("Admin-facing summaries for privileged activity.", "Resúmenes administrativos de actividad privilegiada."),
  },
]

const ADMIN_ACTIVE_TAB_SESSION_KEY_PREFIX = 'casa-monarca.admin.activeTab'
const ADMIN_ACCOUNT_FOCUS_SESSION_KEY_PREFIX = 'casa-monarca.admin.accountFocus'

const isAdminTabId = (value: string | null): value is AdminTabId =>
  ADMIN_TABS.some((tab) => tab.id === value)

const getAdminActiveTabSessionKey = (userId: number) =>
  `${ADMIN_ACTIVE_TAB_SESSION_KEY_PREFIX}:${userId}`

const getAdminAccountFocusSessionKey = (viewerUserId: number, focusToken: string) =>
  `${ADMIN_ACCOUNT_FOCUS_SESSION_KEY_PREFIX}:${viewerUserId}:${focusToken}`

const readStoredAdminTab = (userId: number): AdminTabId => {
  if (typeof window === 'undefined') {
    return 'accounts'
  }

  try {
    const storedTab = window.sessionStorage.getItem(getAdminActiveTabSessionKey(userId))

    return isAdminTabId(storedTab) ? storedTab : 'accounts'
  } catch {
    return 'accounts'
  }
}

const storeAdminTab = (userId: number, tab: AdminTabId) => {
  try {
    window.sessionStorage.setItem(getAdminActiveTabSessionKey(userId), tab)
  } catch {
    // Session storage is a convenience only; tab switching must still work if it is unavailable.
  }
}

const parsePositiveIntegerParam = (params: URLSearchParams, key: string) => {
  const value = params.get(key)

  if (!value || !/^\d+$/.test(value)) {
    return null
  }

  const parsedValue = Number(value)

  return Number.isSafeInteger(parsedValue) && parsedValue > 0 ? parsedValue : null
}

const parseAccountFocusTokenParam = (params: URLSearchParams) => {
  const value = params.get('focus')

  return value && /^[a-zA-Z0-9_-]{1,96}$/.test(value) ? value : null
}

const consumeAccountFocusToken = (viewerUserId: number, focusToken: string | null) => {
  if (!focusToken || typeof window === 'undefined') {
    return false
  }

  try {
    const storageKey = getAdminAccountFocusSessionKey(viewerUserId, focusToken)

    if (window.sessionStorage.getItem(storageKey)) {
      return false
    }

    window.sessionStorage.setItem(storageKey, 'consumed')
    return true
  } catch {
    return true
  }
}

const getRoleSelectValue = (
  account: AdminUserSummary,
  roleDraftByUserId: Record<number, AssignableUserRole>,
) => {
  if (account.role === 'admin') {
    return 'admin'
  }

  return roleDraftByUserId[account.id] ?? 'non_coordinator'
}

const isCoordinatorPromotionBlocked = (
  account: AdminUserSummary,
  roleDraftByUserId: Record<number, AssignableUserRole>,
) =>
  account.role !== 'coordinator' &&
  getRoleSelectValue(account, roleDraftByUserId) === 'coordinator' &&
  account.enrollment.passkeyCount === 0

type SigningLedgerGraphEdge = {
  document: SigningLedgerDocument
  id: string
  revision: SigningLedgerRevision
  signature: SigningLedgerSignature | null
  signer: {
    email: string
    id: number
    name: string
    role: SigningLedgerSigner['role']
    signatureCount?: number
  } | null
}

type SigningLedgerGraphRevision = {
  document: SigningLedgerDocument
  edges: SigningLedgerGraphEdge[]
  id: string
  revision: SigningLedgerRevision
}

type SigningLedgerGraphDocument = {
  document: SigningLedgerDocument
  id: string
  revisions: SigningLedgerGraphRevision[]
}

type SigningLedgerGraphSigner = {
  edges: SigningLedgerGraphEdge[]
  signer: NonNullable<SigningLedgerGraphEdge['signer']>
}

type SigningLedgerConnectorPath = {
  d: string
  id: string
  variant: 'key-revision' | 'user-key'
}

type SigningLedgerConnectorBounds = {
  height: number
  width: number
}

type SigningLedgerNodeOffsets = {
  key: Record<string, number>
  user: Record<string, number>
}

const areSigningLedgerNodeOffsetsEqual = (
  current: SigningLedgerNodeOffsets,
  next: SigningLedgerNodeOffsets,
) => {
  const currentKeyEntries = Object.entries(current.key)
  const nextKeyEntries = Object.entries(next.key)
  const currentUserEntries = Object.entries(current.user)
  const nextUserEntries = Object.entries(next.user)

  if (
    currentKeyEntries.length !== nextKeyEntries.length ||
    currentUserEntries.length !== nextUserEntries.length
  ) {
    return false
  }

  return nextKeyEntries.every(([key, value]) => current.key[key] === value) &&
    nextUserEntries.every(([key, value]) => current.user[key] === value)
}

const formatSecurityKeyIdentifier = (value?: string | null) => {
  if (!value) {
    return 'not recorded'
  }

  return value.length > 20 ? `${value.slice(0, 12)}...${value.slice(-6)}` : value
}

const getSigningKeyIdentifier = (edges: SigningLedgerGraphEdge[]) => {
  const credentialIds = Array.from(new Set(edges
    .map((edge) => edge.signature?.credential?.id)
    .filter((value): value is string => typeof value === 'string' && value.trim() !== '')))

  if (credentialIds.length > 0) {
    return {
      label: credentialIds.length > 1
        ? `${formatSecurityKeyIdentifier(credentialIds[0])} +${credentialIds.length - 1}`
        : formatSecurityKeyIdentifier(credentialIds[0]),
      title: credentialIds.join(', '),
    }
  }

  const fingerprints = Array.from(new Set(edges
    .map((edge) => edge.signature?.credential?.publicKeyFingerprintSha256)
    .filter((value): value is string => typeof value === 'string' && value.trim() !== '')))

  return {
    label: fingerprints.length > 0 ? formatSecurityKeyIdentifier(fingerprints[0]) : 'not recorded',
    title: fingerprints.join(', ') || 'No credential id recorded for these signatures',
  }
}

const buildSigningLedgerGraph = (
  documents: SigningLedgerDocument[],
  signers: SigningLedgerSigner[],
) => {
  const documentGroups: SigningLedgerGraphDocument[] = []
  const signerGroups: SigningLedgerGraphSigner[] = []
  const signersById = new Map(signers.map((signer) => [signer.id, signer]))
  const signerGroupsById = new Map<number, SigningLedgerGraphSigner>()

  documents.forEach((document) => {
    const documentGroup: SigningLedgerGraphDocument = {
      id: String(document.id),
      document,
      revisions: [],
    }

    document.revisions.forEach((revision) => {
      const revisionGroup: SigningLedgerGraphRevision = {
        id: `${document.id}:${revision.id}`,
        document,
        revision,
        edges: [],
      }
      const signatures = revision.signatures.length > 0 ? revision.signatures : [null]

      signatures.forEach((signature) => {
        const signer = signature?.signedBy
          ? signersById.get(signature.signedBy.id) ?? signature.signedBy
          : null

        revisionGroup.edges.push({
          id: `${signer?.id ?? 'unsigned'}:${document.id}:${revision.id}:${signature?.id ?? 'missing'}`,
          signer,
          document,
          revision,
          signature,
        })

        if (signer && signature) {
          let signerGroup = signerGroupsById.get(signer.id)

          if (!signerGroup) {
            const nextSignerGroup = {
              signer: signersById.get(signer.id) ?? signer,
              edges: [],
            }
            signerGroupsById.set(signer.id, nextSignerGroup)
            signerGroups.push(nextSignerGroup)
            signerGroup = nextSignerGroup
          }

          signerGroup.edges.push({
            id: `${signer.id}:${document.id}:${revision.id}:${signature.id}`,
            signer,
            document,
            revision,
            signature,
          })
        }
      })

      documentGroup.revisions.push(revisionGroup)
    })

    documentGroups.push(documentGroup)
  })

  return {
    documentGroups,
    signerGroups,
    unsignedSigners: signers.filter((signer) => signer.signatureCount === 0),
  }
}

export function AdminPage({ locationSearch, onNavigate, onSessionExpired, onUserUpdated, user }: AdminPageProps) {
  const adminQuery = useMemo(() => new URLSearchParams(locationSearch ?? ''), [locationSearch])
  const requestedTabParam = adminQuery.get('tab')
  const requestedAdminTab = isAdminTabId(requestedTabParam) ? requestedTabParam : null
  const requestedAccountId = parsePositiveIntegerParam(adminQuery, 'userId')
  const requestedAccountFocusToken = parseAccountFocusTokenParam(adminQuery)
  const requestedAccountFocusKey = requestedAccountFocusToken
    ? `${requestedAccountId ?? 'none'}:${requestedAccountFocusToken}`
    : null
  const [activeTab, setActiveTab] = useState<AdminTabId>(() => readStoredAdminTab(user.id))
  const [highlightedAccountId, setHighlightedAccountId] = useState<number | null>(null)
  const [highlightToken, setHighlightToken] = useState(0)
  const [directory, setDirectory] = useState<AdminUserSummary[]>([])
  const [directoryError, setDirectoryError] = useState<string | null>(null)
  const [curpDraftByUserId, setCurpDraftByUserId] = useState<Record<number, string>>({})
  const [curpUpdateFeedbackByUserId, setCurpUpdateFeedbackByUserId] = useState<
    Record<number, CurpUpdateFeedback>
  >({})
  const [updatingCurpUserId, setUpdatingCurpUserId] = useState<number | null>(null)
  const [isDirectoryLoading, setIsDirectoryLoading] = useState(true)
  const [roleDraftByUserId, setRoleDraftByUserId] = useState<Record<number, AssignableUserRole>>({})
  const [roleUpdateError, setRoleUpdateError] = useState<string | null>(null)
  const [roleUpdateMessage, setRoleUpdateMessage] = useState<string | null>(null)
  const [passwordResetLink, setPasswordResetLink] = useState<string | null>(null)
  const [recoveryAction, setRecoveryAction] = useState<AdminUserRecoveryAction | null>(null)
  const [recoveringUserId, setRecoveringUserId] = useState<number | null>(null)
  const [statusAction, setStatusAction] = useState<AdminUserStatusAction | null>(null)
  const [signingKey, setSigningKey] = useState<VerificationPackageSigningKeySummary | null>(null)
  const [signingKeyError, setSigningKeyError] = useState<string | null>(null)
  const [signingKeyMessage, setSigningKeyMessage] = useState<string | null>(null)
  const [isSigningKeyLoading, setIsSigningKeyLoading] = useState(true)
  const [isRotatingSigningKey, setIsRotatingSigningKey] = useState(false)
  const [signingLedgerDocuments, setSigningLedgerDocuments] = useState<SigningLedgerDocument[]>([])
  const [signingLedger, setSigningLedger] = useState<SigningLedgerSigner[]>([])
  const [signingLedgerError, setSigningLedgerError] = useState<string | null>(null)
  const [isSigningLedgerLoading, setIsSigningLedgerLoading] = useState(true)
  const [migrantSigningRegistrations, setMigrantSigningRegistrations] = useState<MigrantSigningLedgerRegistration[]>([])
  const [migrantSigningSigners, setMigrantSigningSigners] = useState<MigrantSigningLedgerSigner[]>([])
  const [migrantSigningError, setMigrantSigningError] = useState<string | null>(null)
  const [isMigrantSigningLoading, setIsMigrantSigningLoading] = useState(true)
  const [updatingStatusUserId, setUpdatingStatusUserId] = useState<number | null>(null)
  const [updatingRoleUserId, setUpdatingRoleUserId] = useState<number | null>(null)
  const signingGraphBodyRef = useRef<HTMLDivElement | null>(null)
  const accountCardRefs = useRef<Map<number, HTMLElement>>(new Map())
  const signingUserNodeRefs = useRef<Map<string, HTMLElement>>(new Map())
  const signingKeyNodeRefs = useRef<Map<string, HTMLElement>>(new Map())
  const signingRevisionNodeRefs = useRef<Map<string, HTMLElement>>(new Map())
  const [signingLedgerConnectorPaths, setSigningLedgerConnectorPaths] = useState<SigningLedgerConnectorPath[]>([])
  const [signingLedgerConnectorBounds, setSigningLedgerConnectorBounds] = useState<SigningLedgerConnectorBounds>({
    height: 1,
    width: 1,
  })
  const [signingLedgerGraphBodyMinHeight, setSigningLedgerGraphBodyMinHeight] = useState(1)
  const [signingLedgerNodeOffsets, setSigningLedgerNodeOffsets] = useState<SigningLedgerNodeOffsets>({
    key: {},
    user: {},
  })
  const signingLedgerNodeOffsetsRef = useRef<SigningLedgerNodeOffsets>({
    key: {},
    user: {},
  })

  const setSigningUserNodeRef = useCallback((id: string, node: HTMLElement | null) => {
    if (node) {
      signingUserNodeRefs.current.set(id, node)
    } else {
      signingUserNodeRefs.current.delete(id)
    }
  }, [])

  const setSigningKeyNodeRef = useCallback((id: string, node: HTMLElement | null) => {
    if (node) {
      signingKeyNodeRefs.current.set(id, node)
    } else {
      signingKeyNodeRefs.current.delete(id)
    }
  }, [])

  const setSigningRevisionNodeRef = useCallback((id: string, node: HTMLElement | null) => {
    if (node) {
      signingRevisionNodeRefs.current.set(id, node)
    } else {
      signingRevisionNodeRefs.current.delete(id)
    }
  }, [])

  const setAccountCardRef = useCallback((id: number, node: HTMLElement | null) => {
    if (node) {
      accountCardRefs.current.set(id, node)
    } else {
      accountCardRefs.current.delete(id)
    }
  }, [])

  const handleAdminTabSelect = useCallback((tab: AdminTabId) => {
    setActiveTab(tab)
    storeAdminTab(user.id, tab)
  }, [user.id])

  const getRevisionDocumentUrl = useCallback((
    documentId: number,
    revisionId: number,
  ) => {
    const params = new URLSearchParams({
      documentId: String(documentId),
      revisionId: String(revisionId),
    })

    return `${APP_DOCUMENTS_PATH}?${params.toString()}`
  }, [])

  useEffect(() => {
    if (!requestedAdminTab) {
      return
    }

    setActiveTab(requestedAdminTab)
    storeAdminTab(user.id, requestedAdminTab)
  }, [requestedAdminTab, user.id])

  useEffect(() => {
    if (!requestedAccountId || activeTab !== 'accounts') {
      return
    }

    let animationFrameId: number | null = null
    let timeoutId: number | null = null

    const scrollFrameId = window.requestAnimationFrame(() => {
      const accountCard = accountCardRefs.current.get(requestedAccountId)

      if (!accountCard) {
        return
      }

      accountCard.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
      })

      if (consumeAccountFocusToken(user.id, requestedAccountFocusToken)) {
        setHighlightedAccountId(null)

        animationFrameId = window.requestAnimationFrame(() => {
          setHighlightedAccountId(requestedAccountId)
          setHighlightToken((currentToken) => currentToken + 1)
        })

        timeoutId = window.setTimeout(() => {
          setHighlightedAccountId((currentAccountId) =>
            currentAccountId === requestedAccountId ? null : currentAccountId,
          )
        }, 2500)
      }
    })

    return () => {
      window.cancelAnimationFrame(scrollFrameId)
      if (animationFrameId !== null) {
        window.cancelAnimationFrame(animationFrameId)
      }
      if (timeoutId !== null) {
        window.clearTimeout(timeoutId)
      }
    }
  }, [activeTab, directory, requestedAccountFocusKey, requestedAccountFocusToken, requestedAccountId, user.id])

  const loadDirectory = useCallback(async () => {
    setIsDirectoryLoading(true)
    setDirectoryError(null)

    try {
      const response: AdminUserListResponse = await getAdminUsers()
      setDirectory(response.users)
      setCurpDraftByUserId(
        Object.fromEntries(response.users.map((account) => [account.id, account.curp ?? ''])),
      )
      setCurpUpdateFeedbackByUserId({})
      setRoleDraftByUserId(
        Object.fromEntries(
          response.users
            .filter((account) => ASSIGNABLE_ROLES.includes(account.role as AssignableUserRole))
            .map((account) => [account.id, account.role as AssignableUserRole]),
        ),
      )
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setDirectoryError(
        error instanceof Error ? error.message : t("Unable to load account directory.", "No se pudo cargar el directorio de cuentas."),
      )
    } finally {
      setIsDirectoryLoading(false)
    }
  }, [onSessionExpired])

  const handleCurpUpdate = async (account: AdminUserSummary) => {
    const normalizedCurp = normalizeCurp(curpDraftByUserId[account.id] ?? '')
    const nextCurp = normalizedCurp || null

    if (nextCurp === (account.curp ?? null)) {
      return
    }

    if (nextCurp !== null && !isValidCurp(nextCurp)) {
      setCurpUpdateFeedbackByUserId((currentFeedback) => ({
        ...currentFeedback,
        [account.id]: {
          kind: 'error',
          message: t(
            'The CURP format, birth date, or check digit is invalid.',
            'El formato, la fecha de nacimiento o el dígito verificador de la CURP no es válido.',
          ),
        },
      }))
      return
    }

    const confirmed = window.confirm(
      nextCurp
        ? t(`Authenticate to update the CURP for ${account.email}?`, `¿Autenticar para actualizar la CURP de ${account.email}?`)
        : t(`Authenticate to clear the CURP for ${account.email}?`, `¿Autenticar para borrar la CURP de ${account.email}?`),
    )

    if (!confirmed) {
      return
    }

    setUpdatingCurpUserId(account.id)
    setCurpUpdateFeedbackByUserId((currentFeedback) => ({
      ...currentFeedback,
      [account.id]: {
        kind: 'status',
        message: t(
          'Confirm the CURP change with your admin security key.',
          'Confirma el cambio de CURP con tu llave de seguridad administrativa.',
        ),
      },
    }))

    try {
      const optionsResponse = await startAdminUserCurpUpdate(account.id, { curp: nextCurp })
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyAdminUserCurpUpdate(account.id, assertion)

      setDirectory((currentDirectory) =>
        currentDirectory.map((currentAccount) =>
          currentAccount.id === response.user.id ? response.user : currentAccount,
        ),
      )
      setCurpDraftByUserId((currentDrafts) => ({
        ...currentDrafts,
        [response.user.id]: response.user.curp ?? '',
      }))
      setCurpUpdateFeedbackByUserId((currentFeedback) => ({
        ...currentFeedback,
        [account.id]: {
          kind: 'success',
          message: t('CURP updated successfully.', 'La CURP se actualizó correctamente.'),
        },
      }))
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      const fieldError = error instanceof ApiRequestError
        ? error.errors?.curp?.[0]
        : null
      const message = fieldError?.includes('already assigned')
        ? t(
            'This CURP is already assigned to another account.',
            'Esta CURP ya está asignada a otra cuenta.',
          )
        : error instanceof DOMException && error.name === 'NotAllowedError'
          ? t(
              'Security key authentication was cancelled.',
              'Se canceló la autenticación con la llave de seguridad.',
            )
          : error instanceof Error
            ? error.message
            : t(
                'Unable to update the account CURP.',
                'No se pudo actualizar la CURP de la cuenta.',
              )

      setCurpUpdateFeedbackByUserId((currentFeedback) => ({
        ...currentFeedback,
        [account.id]: { kind: 'error', message },
      }))
    } finally {
      setUpdatingCurpUserId(null)
    }
  }

  const loadSigningKey = useCallback(async () => {
    setIsSigningKeyLoading(true)
    setSigningKeyError(null)

    try {
      const response = await getVerificationPackageSigningKey()
      setSigningKey(response.signingKey)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setSigningKeyError(
        error instanceof Error
          ? error.message
          : t("Unable to load verification package signing key.", "No se pudo cargar la llave de firma del paquete de verificación."),
      )
    } finally {
      setIsSigningKeyLoading(false)
    }
  }, [onSessionExpired])

  const loadSigningLedger = useCallback(async () => {
    setIsSigningLedgerLoading(true)
    setSigningLedgerError(null)

    try {
      const response = await getSigningLedger()
      setSigningLedger(response.signers)
      setSigningLedgerDocuments(response.documents)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setSigningLedgerError(
        error instanceof Error
          ? error.message
          : t("Unable to load signing trust ledger.", "No se pudo cargar el libro de confianza de firma."),
      )
    } finally {
      setIsSigningLedgerLoading(false)
    }
  }, [onSessionExpired])

  const loadMigrantSigningLedger = useCallback(async () => {
    setIsMigrantSigningLoading(true)
    setMigrantSigningError(null)

    try {
      const response = await getMigrantSigningLedger()
      setMigrantSigningRegistrations(response.registrations)
      setMigrantSigningSigners(response.signers)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setMigrantSigningError(
        error instanceof Error
          ? error.message
          : t("Unable to load migrant signing trust ledger.", "No se pudo cargar el libro de confianza de firma de migrantes."),
      )
    } finally {
      setIsMigrantSigningLoading(false)
    }
  }, [onSessionExpired])

  const handleRoleUpdate = async (account: AdminUserSummary) => {
    const nextRole = roleDraftByUserId[account.id]

    if (!nextRole || nextRole === account.role) {
      return
    }

    const confirmed = window.confirm(
      t(`Change ${account.email} from ${getRoleLabel(account.role)} to ${getRoleLabel(nextRole)}?`, `¿Cambiar ${account.email} de ${getRoleLabel(account.role)} a ${getRoleLabel(nextRole)}?`),
    )

    if (!confirmed) {
      return
    }

    const reason = window.prompt(t("Optional audit reason for this role change.", "Motivo de auditoría opcional para este cambio de rol."))

    setUpdatingRoleUserId(account.id)
    setRoleUpdateError(null)
    setRoleUpdateMessage(t("Confirm the role change with your admin security key.", "Confirma el cambio de rol con tu llave de seguridad administrativa."))

    try {
      const optionsResponse = await startAdminUserRoleUpdate(account.id, {
        reason: reason?.trim() || null,
        role: nextRole,
      })
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyAdminUserRoleUpdate(account.id, assertion)
      setDirectory((currentDirectory) =>
        currentDirectory.map((currentAccount) =>
          currentAccount.id === response.user.id ? response.user : currentAccount,
        ),
      )
      setRoleDraftByUserId((currentDrafts) => ({
        ...currentDrafts,
        [response.user.id]: response.user.role as AssignableUserRole,
      }))
      setRoleUpdateMessage(response.message)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setRoleUpdateError(
        error instanceof Error ? error.message : t("Unable to update user role.", "No se pudo actualizar el rol del usuario."),
      )
    } finally {
      setUpdatingRoleUserId(null)
    }
  }

  const handleRecovery = async (
    account: AdminUserSummary,
    action: AdminUserRecoveryAction,
  ) => {
    const actionLabel = action === 'reset_totp'
      ? t("reset TOTP enrollment", "restablecer la inscripción TOTP")
      : action === 'reset_password'
        ? t("issue a password reset link", "emitir un enlace para restablecer contraseña")
        : t("revoke all passkeys", "revocar todas las llaves de acceso")
    const confirmed = window.confirm(
      t(`Authenticate to ${actionLabel} for ${account.email}? This will force the user through enrollment again.`, `¿Autenticar para ${actionLabel} de ${account.email}? Esto obligará al usuario a pasar de nuevo por inscripción.`),
    )

    if (!confirmed) {
      return
    }

    const reason = window.prompt(t(`Optional audit reason to ${actionLabel} for ${account.email}.`, `Motivo de auditoría opcional para ${actionLabel} de ${account.email}.`))

    setRecoveringUserId(account.id)
    setRecoveryAction(action)
    setRoleUpdateError(null)
    setPasswordResetLink(null)
    setRoleUpdateMessage(t("Confirm account recovery with your admin security key.", "Confirma la recuperación de cuenta con tu llave de seguridad administrativa."))

    try {
      const optionsResponse = await startAdminUserRecovery(account.id, {
        action,
        reason: reason?.trim() || null,
      })
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyAdminUserRecovery(account.id, assertion)
      setDirectory((currentDirectory) =>
        currentDirectory.map((currentAccount) =>
          currentAccount.id === response.user.id ? response.user : currentAccount,
        ),
      )
      setRoleUpdateMessage(response.message)
      setPasswordResetLink(
        response.passwordReset
          ? new URL(response.passwordReset.resetPath, window.location.origin).toString()
          : null,
      )
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setRoleUpdateError(
        error instanceof Error ? error.message : t("Unable to complete account recovery.", "No se pudo completar la recuperación de cuenta."),
      )
    } finally {
      setRecoveringUserId(null)
      setRecoveryAction(null)
    }
  }

  const handleStatusUpdate = async (
    account: AdminUserSummary,
    action: AdminUserStatusAction,
  ) => {
    const actionLabel = action === 'suspend' ? t("suspend", "suspensión") : t("reactivate", "reactivación")
    let reason: string | null = null

    if (action === 'suspend') {
      const promptedReason = window.prompt(
        t(`Reason for suspending ${account.email}? This is stored in the audit trail and account directory.`, `¿Motivo para suspender ${account.email}? Esto se guarda en la auditoría y en el directorio de cuentas.`),
      )

      if (promptedReason === null) {
        return
      }

      reason = promptedReason.trim() || null
    } else {
      const confirmed = window.confirm(t(`Authenticate to reactivate ${account.email}?`, `¿Autenticar para reactivar ${account.email}?`))

      if (!confirmed) {
        return
      }
    }

    setUpdatingStatusUserId(account.id)
    setStatusAction(action)
    setRoleUpdateError(null)
    setRoleUpdateMessage(t(`Confirm account ${actionLabel} with your admin security key.`, `Confirma la ${actionLabel} de cuenta con tu llave de seguridad administrativa.`))
    let challengeIntentId: string | null = null

    try {
      const optionsResponse = await startAdminUserStatusUpdate(account.id, {
        action,
        reason,
      })
      challengeIntentId = optionsResponse.challengeIntent?.id ?? null
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyAdminUserStatusUpdate(account.id, assertion)
      setDirectory((currentDirectory) =>
        currentDirectory.map((currentAccount) =>
          currentAccount.id === response.user.id ? response.user : currentAccount,
        ),
      )
      setRoleUpdateMessage(response.message)
    } catch (error) {
      if (
        challengeIntentId &&
        error instanceof DOMException &&
        error.name === 'NotAllowedError'
      ) {
        await cancelSecurityChallenge(challengeIntentId).catch(() => undefined)
      }

      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setRoleUpdateError(
        error instanceof Error ? error.message : t("Unable to update account status.", "No se pudo actualizar el estado de la cuenta."),
      )
    } finally {
      setUpdatingStatusUserId(null)
      setStatusAction(null)
    }
  }

  const handleSigningKeyRotation = async () => {
    const isInitialGeneration = signingKey ? !signingKey.configured : false
    const defaultKeyId = signingKey?.keyId || getDefaultSigningKeyId()
    const actionLabel = isInitialGeneration ? t("generate", "generar") : t("rotate", "rotar")
    const keyId = window.prompt(
      t(`${isInitialGeneration ? 'New' : 'Replacement'} package signing key id. Use a stable id that can be published with the fingerprint.`, `${isInitialGeneration ? 'Nuevo' : 'Reemplazo de'} ID de llave de firma de paquete. Usa un ID estable que pueda publicarse con la huella.`),
      defaultKeyId,
    )

    if (keyId === null) {
      return
    }

    const normalizedKeyId = keyId.trim()

    if (!/^[A-Za-z0-9._-]+$/.test(normalizedKeyId)) {
      setSigningKeyError(t("Key id can only contain letters, numbers, dots, underscores, and hyphens.", "El ID de llave solo puede contener letras, números, puntos, guiones bajos y guiones."))
      return
    }

    const reason = window.prompt(
      t(`Reason for ${actionLabel}ing the verification package signing key. This is stored in the audit trail.`, `Motivo para ${actionLabel} la llave de firma del paquete de verificación. Esto se guarda en auditoría.`),
      isInitialGeneration ? t("Initial verification package signing key generation", "Generación inicial de llave de firma del paquete de verificación") : undefined,
    )

    if (reason === null) {
      return
    }

    const normalizedReason = reason.trim()

    if (normalizedReason.length < 8) {
      setSigningKeyError(t("Rotation reason must be at least 8 characters.", "El motivo de rotación debe tener al menos 8 caracteres."))
      return
    }

    const confirmed = window.confirm(
      isInitialGeneration
        ? t("Generate the verification package signing key? Newly exported packages will become server-signed.", "¿Generar la llave de firma del paquete de verificación? Los paquetes exportados nuevos quedarán firmados por el servidor.")
        : t("Rotate the verification package signing key? Newly exported packages will use the new fingerprint. Keep old fingerprints for old exports.", "¿Rotar la llave de firma del paquete de verificación? Los paquetes exportados nuevos usarán la nueva huella. Conserva las huellas anteriores para exportaciones antiguas."),
    )

    if (!confirmed) {
      return
    }

    setIsRotatingSigningKey(true)
    setSigningKeyError(null)
    setSigningKeyMessage(t(`Confirm package signing key ${isInitialGeneration ? 'generation' : 'rotation'} with your admin security key.`, `Confirma la ${isInitialGeneration ? 'generación' : 'rotación'} de la llave de firma de paquetes con tu llave de seguridad administrativa.`))

    try {
      const optionsResponse = await startVerificationPackageSigningKeyRotation({
        bits: 3072,
        keyId: normalizedKeyId,
        reason: normalizedReason,
      })
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyVerificationPackageSigningKeyRotation(assertion)
      setSigningKey(response.signingKey)
      setSigningKeyMessage(response.message)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setSigningKeyError(
        error instanceof Error
          ? error.message
          : t("Unable to rotate verification package signing key.", "No se pudo rotar la llave de firma del paquete de verificación."),
      )
    } finally {
      setIsRotatingSigningKey(false)
    }
  }

  useEffect(() => {
    void loadDirectory()
  }, [loadDirectory])

  useEffect(() => {
    void loadSigningKey()
  }, [loadSigningKey])

  useEffect(() => {
    void loadSigningLedger()
  }, [loadSigningLedger])

  useEffect(() => {
    if (activeTab === 'migrant-signing' && migrantSigningRegistrations.length === 0) {
      void loadMigrantSigningLedger()
    }
  }, [activeTab, loadMigrantSigningLedger, migrantSigningRegistrations.length])

  const signingLedgerGraph = useMemo(
    () => buildSigningLedgerGraph(signingLedgerDocuments, signingLedger),
    [signingLedgerDocuments, signingLedger],
  )
  const signingLedgerSignedEdges = useMemo(
    () => signingLedgerGraph.signerGroups.flatMap(({ edges }) => edges),
    [signingLedgerGraph],
  )

  useLayoutEffect(() => {
    if (activeTab !== 'signing') {
      setSigningLedgerConnectorPaths([])
      setSigningLedgerGraphBodyMinHeight(1)
      return
    }

    const graphBody = signingGraphBodyRef.current

    if (!graphBody) {
      setSigningLedgerConnectorPaths([])
      setSigningLedgerConnectorBounds({ height: 1, width: 1 })
      setSigningLedgerGraphBodyMinHeight(1)
      return
    }

    const getNodeBox = (node: HTMLElement, graphRect: DOMRect) => {
      const rect = node.getBoundingClientRect()
      const transform = window.getComputedStyle(node).transform
      const transformMatrix = transform === 'none' ? null : new DOMMatrixReadOnly(transform)
      const transformX = transformMatrix?.m41 ?? 0
      const transformY = transformMatrix?.m42 ?? 0

      return {
        centerY: rect.top + rect.height / 2 - graphRect.top - transformY,
        height: rect.height,
        left: rect.left - graphRect.left - transformX,
        right: rect.right - graphRect.left - transformX,
      }
    }

    const getPoint = (
      box: ReturnType<typeof getNodeBox>,
      side: 'left' | 'right',
      offset = 0,
    ) => ({
      x: side === 'left' ? box.left : box.right,
      y: box.centerY + offset,
    })

    const getCurve = (
      start: { x: number, y: number },
      end: { x: number, y: number },
    ) => {
      const handle = Math.max(36, Math.abs(end.x - start.x) * 0.5)

      return `M ${start.x} ${start.y} C ${start.x + handle} ${start.y} ${end.x - handle} ${end.y} ${end.x} ${end.y}`
    }

    const packNodeCenters = (
      items: Array<{
        box: ReturnType<typeof getNodeBox>
        id: string
        targetCenter: number
      }>,
    ) => {
      const packedCenters = new Map<string, number>()
      const nodeGap = 12
      const sortedItems = [...items].sort((first, second) => first.targetCenter - second.targetCenter)
      const clusters: typeof sortedItems[] = []

      sortedItems.forEach((item) => {
        const currentCluster = clusters.at(-1)
        const previousItem = currentCluster?.at(-1)

        if (
          !currentCluster ||
          !previousItem ||
          item.targetCenter - previousItem.targetCenter >
            previousItem.box.height / 2 + nodeGap + item.box.height / 2
        ) {
          clusters.push([item])
          return
        }

        currentCluster.push(item)
      })

      clusters.forEach((cluster) => {
        const totalHeight = cluster.reduce((total, item) => total + item.box.height, 0) +
          Math.max(0, cluster.length - 1) * nodeGap
        const targetCenter = cluster.reduce((total, item) => total + item.targetCenter, 0) / cluster.length
        let nextTop = Math.max(0, targetCenter - totalHeight / 2)

        cluster.forEach((item) => {
          const center = nextTop + item.box.height / 2
          packedCenters.set(item.id, center)
          nextTop += item.box.height + nodeGap
        })
      })

      return packedCenters
    }

    const animationFrames = new Set<number>()
    const timeouts = new Set<number>()
    let scheduleCalculatePaths: () => void = () => undefined

    const calculatePaths = () => {
      const graphRect = graphBody.getBoundingClientRect()
      const nextPaths: SigningLedgerConnectorPath[] = []
      const keyBoxes = new Map<string, ReturnType<typeof getNodeBox>>()
      const userBoxes = new Map<string, ReturnType<typeof getNodeBox>>()
      const revisionBoxes = new Map<string, ReturnType<typeof getNodeBox>>()
      const currentOffsets = signingLedgerNodeOffsetsRef.current
      const nextOffsets: SigningLedgerNodeOffsets = {
        key: {},
        user: {},
      }

      signingLedgerGraph.signerGroups.forEach(({ signer }) => {
        const keyNode = signingKeyNodeRefs.current.get(String(signer.id))

        if (keyNode) {
          keyBoxes.set(String(signer.id), getNodeBox(keyNode, graphRect))
        }
      })

      signingLedgerSignedEdges.forEach((edge) => {
        const revisionNode = signingRevisionNodeRefs.current.get(`${edge.document.id}:${edge.revision.id}`)

        if (!revisionNode) {
          return
        }

        revisionBoxes.set(
          `${edge.document.id}:${edge.revision.id}`,
          getNodeBox(revisionNode, graphRect),
        )
      })

      signingLedgerGraph.signerGroups.forEach(({ signer }) => {
        const userNode = signingUserNodeRefs.current.get(String(signer.id))

        if (userNode) {
          userBoxes.set(String(signer.id), getNodeBox(userNode, graphRect))
        }
      })

      signingLedgerGraph.unsignedSigners.forEach((signer) => {
        const userNode = signingUserNodeRefs.current.get(String(signer.id))

        if (userNode) {
          userBoxes.set(String(signer.id), getNodeBox(userNode, graphRect))
        }
      })

      const signerTargetCenters = signingLedgerGraph.signerGroups.flatMap(({ edges, signer }) => {
        const signerId = String(signer.id)
        const userBox = userBoxes.get(signerId)
        const keyBox = keyBoxes.get(signerId)
        const ownedRevisionCenters = edges
          .map((edge) => {
            const revisionBox = revisionBoxes.get(`${edge.document.id}:${edge.revision.id}`)

            return revisionBox?.centerY ?? null
          })
          .filter((center): center is number => center !== null)

        if (!userBox || !keyBox || ownedRevisionCenters.length === 0) {
          return []
        }

        const averageRevisionCenter = ownedRevisionCenters.reduce((total, center) => total + center, 0) / ownedRevisionCenters.length
        return [{
          id: signerId,
          targetCenter: averageRevisionCenter,
        }]
      })
      const userTargetCenters = signerTargetCenters

      const packedUserCenters = packNodeCenters(userTargetCenters.flatMap((item) => {
        const box = userBoxes.get(item.id)

        return box ? [{ ...item, box }] : []
      }))
      const packedKeyCenters = packNodeCenters(signerTargetCenters.flatMap((item) => {
        const box = keyBoxes.get(item.id)

        return box ? [{ ...item, box }] : []
      }))

      userTargetCenters.forEach(({ id }) => {
        const userBox = userBoxes.get(id)
        const userCenter = packedUserCenters.get(id)

        if (userBox && userCenter !== undefined) {
          nextOffsets.user[id] = Math.round(userCenter - userBox.centerY)
        }
      })

      signerTargetCenters.forEach(({ id }) => {
        const keyBox = keyBoxes.get(id)
        const keyCenter = packedKeyCenters.get(id)

        if (keyBox && keyCenter !== undefined) {
          nextOffsets.key[id] = Math.round(keyCenter - keyBox.centerY)
        }
      })

      const transformedNodeBottoms = [
        ...Array.from(userBoxes.entries()).map(([id, box]) =>
          box.centerY + (nextOffsets.user[id] ?? 0) + box.height / 2,
        ),
        ...Array.from(keyBoxes.entries()).map(([id, box]) =>
          box.centerY + (nextOffsets.key[id] ?? 0) + box.height / 2,
        ),
        ...Array.from(revisionBoxes.values()).map((box) => box.centerY + box.height / 2),
      ]
      const graphHeight = Math.max(
        1,
        graphBody.scrollHeight,
        graphRect.height,
        ...transformedNodeBottoms.map((bottom) => Math.ceil(bottom + 8)),
      )

      setSigningLedgerGraphBodyMinHeight(graphHeight)
      setSigningLedgerConnectorBounds({
        height: graphHeight,
        width: Math.max(1, graphBody.scrollWidth, graphRect.width),
      })

      signingLedgerGraph.signerGroups.forEach(({ signer }) => {
        const userBox = userBoxes.get(String(signer.id))
        const keyBox = keyBoxes.get(String(signer.id))

        if (!userBox || !keyBox) {
          return
        }

        const userOffset = nextOffsets.user[String(signer.id)] ?? 0
        const keyOffset = nextOffsets.key[String(signer.id)] ?? 0

        nextPaths.push({
          id: `user-key:${signer.id}`,
          variant: 'user-key',
          d: getCurve(
            getPoint(userBox, 'right', userOffset),
            getPoint(keyBox, 'left', keyOffset),
          ),
        })
      })

      signingLedgerSignedEdges.forEach((edge) => {
        if (!edge.signer || !edge.signature) {
          return
        }

        const keyBox = keyBoxes.get(String(edge.signer.id))
        const revisionBox = revisionBoxes.get(`${edge.document.id}:${edge.revision.id}`)

        if (!keyBox || !revisionBox) {
          return
        }

        const keyOffset = nextOffsets.key[String(edge.signer.id)] ?? 0

        nextPaths.push({
          id: `key-revision:${edge.id}`,
          variant: 'key-revision',
          d: getCurve(
            getPoint(keyBox, 'right', keyOffset),
            getPoint(revisionBox, 'left'),
          ),
        })
      })

      if (!areSigningLedgerNodeOffsetsEqual(currentOffsets, nextOffsets)) {
        signingLedgerNodeOffsetsRef.current = nextOffsets
        setSigningLedgerNodeOffsets(nextOffsets)
      }

      setSigningLedgerConnectorPaths(nextPaths)
    }

    scheduleCalculatePaths = () => {
      const animationFrame = window.requestAnimationFrame(() => {
        animationFrames.delete(animationFrame)
        calculatePaths()
      })
      animationFrames.add(animationFrame)
    }

    const scheduleDeferredCalculatePaths = (delay: number) => {
      const timeout = window.setTimeout(() => {
        timeouts.delete(timeout)
        scheduleCalculatePaths()
      }, delay)
      timeouts.add(timeout)
    }

    scheduleCalculatePaths()
    scheduleDeferredCalculatePaths(0)
    scheduleDeferredCalculatePaths(120)
    const resizeObserver = new ResizeObserver(calculatePaths)
    resizeObserver.observe(graphBody)
    signingUserNodeRefs.current.forEach((node) => resizeObserver.observe(node))
    signingKeyNodeRefs.current.forEach((node) => resizeObserver.observe(node))
    signingRevisionNodeRefs.current.forEach((node) => resizeObserver.observe(node))
    window.addEventListener('resize', calculatePaths)

    return () => {
      animationFrames.forEach((animationFrame) => window.cancelAnimationFrame(animationFrame))
      timeouts.forEach((timeout) => window.clearTimeout(timeout))
      resizeObserver.disconnect()
      window.removeEventListener('resize', calculatePaths)
    }
  }, [activeTab, signingLedgerGraph, signingLedgerSignedEdges])

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <h2 className="workspace-panel__title">{t("Admin-only module", "Módulo solo para administración")}</h2>
        <p className="workspace-panel__copy">
          {t("This route remains restricted to admins while the rest of the shell becomes role-aware. ", "Esta ruta permanece restringida a administración mientras el resto del entorno responde por rol. ")}</p>
        <ul className="route-checklist">
          <li>{t("Name: ", "Nombre: ")}{user.name}</li>
          <li>{t("Email: ", "Correo: ")}{user.email}</li>
          <li>
            {t("Role: ", "Rol: ")}<RoleBadge role={user.role} />
          </li>
        </ul>
      </section>

      <nav className="admin-tabs" aria-label={t('Admin panel sections', 'Secciones del panel de administración')}>
        {ADMIN_TABS.map((tab) => (
          <button
            aria-current={activeTab === tab.id ? 'page' : undefined}
            className={`admin-tabs__button${activeTab === tab.id ? ' admin-tabs__button--active' : ''}`}
            key={tab.id}
            onClick={() => handleAdminTabSelect(tab.id)}
            type="button"
          >
            <span>{tab.label}</span>
            <small>{tab.copy}</small>
          </button>
        ))}
      </nav>

      {activeTab === 'system' ? (
        <section className="workspace-panel workspace-panel--accent">
          <h2 className="workspace-panel__title">{t("System Configuration", "Configuración del sistema")}</h2>
          <p className="workspace-panel__copy">
            {t("Privileged settings, including invite creation and redemption policy, should stay explicit, audited, and passkey-gated before becoming mutable from the browser. ", "Las configuraciones privilegiadas, incluida la creación y canje de invitaciones, deben seguir siendo explícitas, auditadas y protegidas con llave de acceso antes de poder modificarse desde el navegador. ")}</p>

          <div className="admin-surface-grid">
            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="verify" />
              </span>
              <div>
                <h3>{t("Signature policy", "Política de firma")}</h3>
                <p>{t("Signature validity duration, verification package export rules, and future manifest trust history.", "Duración de validez de firmas, reglas de exportación de paquetes de verificación e historial futuro de confianza del manifiesto.")}</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="key" />
              </span>
              <div>
                <h3>{t("Authentication policy", "Política de autenticación")}</h3>
                <p>{t("Coordinator/admin passkey requirements, TOTP enforcement, session limits, and recovery constraints.", "Requisitos de llave de acceso para coordinación/administración, exigencia TOTP, límites de sesión y restricciones de recuperación.")}</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="upload" />
              </span>
              <div>
                <h3>{t("Document policy", "Política documental")}</h3>
                <p>{t("Allowed file types, maximum upload size, retention rules, and irreversible deletion controls.", "Tipos de archivo permitidos, tamaño máximo de carga, reglas de retención y controles de eliminación irreversible.")}</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="admin" />
              </span>
              <div>
                <h3>{t("Admin safeguards", "Salvaguardas administrativas")}</h3>
                <p>{t("Future two-person rules, admin creation restrictions, and sensitive-setting approval windows.", "Reglas futuras de doble aprobación, restricciones de creación de administradores y ventanas de aprobación para configuraciones sensibles.")}</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="invite" />
              </span>
              <div>
                <h3>{t("Invite policy", "Política de invitaciones")}</h3>
                <p>{t("Allowed invite roles, expiration windows, single-use redemption, email binding, and invite audit alerts.", "Roles permitidos en invitaciones, ventanas de vencimiento, canje de un solo uso, vinculación por correo y alertas de auditoría.")}</p>
              </div>
            </article>
          </div>
        </section>
      ) : null}

      {activeTab === 'audit' ? (
        <section className="workspace-panel workspace-panel--accent">
          <h2 className="workspace-panel__title">{t("Audit Overview", "Resumen de auditoría")}</h2>
          <p className="workspace-panel__copy">
            {t("Summary cards should complement the full Logging module instead of duplicating the raw event feed. ", "Las tarjetas de resumen deben complementar el módulo completo de bitácora en lugar de duplicar el flujo de eventos sin procesar. ")}</p>

          <div className="admin-surface-grid">
            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="admin" />
              </span>
              <div>
                <h3>{t("Privileged changes", "Cambios privilegiados")}</h3>
                <p>{t("Recent role updates, suspensions, recovery actions, and system configuration changes.", "Actualizaciones recientes de rol, suspensiones, acciones de recuperación y cambios de configuración del sistema.")}</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="key" />
              </span>
              <div>
                <h3>{t("Authentication risk", "Riesgo de autenticación")}</h3>
                <p>{t("Failed login spikes, passkey challenge failures, missing enrollment, and suspicious recovery activity.", "Picos de inicios fallidos, fallas de retos con llave de acceso, inscripción faltante y actividad sospechosa de recuperación.")}</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="document" />
              </span>
              <div>
                <h3>{t("Document trust", "Confianza documental")}</h3>
                <p>{t("Unsigned revisions, expired signatures, verification-package exports, and signing activity.", "Versiones sin firma, firmas vencidas, exportaciones de paquetes de verificación y actividad de firma.")}</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="verify" />
              </span>
              <div>
                <h3>{t("Invite lifecycle", "Ciclo de vida de invitaciones")}</h3>
                <p>{t("Created, verified, issued, redeemed, expired, and revoked invitation activity.", "Actividad de invitaciones creadas, verificadas, emitidas, canjeadas, vencidas y revocadas.")}</p>
              </div>
            </article>
          </div>
        </section>
      ) : null}

      {activeTab === 'security' ? (
        <section className="workspace-panel" aria-label={t('Verification package signing key', 'Llave de firma de paquetes de verificación')}>
          <div className="admin-directory-toolbar">
            <div>
              <h2 className="workspace-panel__title">{t("Security Keys", "Llaves de seguridad")}</h2>
              <p className="workspace-panel__copy">
                {t("Published trust anchors and rotation controls for verification packages. Compare the fingerprint with external review evidence. ", "Anclas de confianza publicadas y controles de rotación para paquetes de verificación. Compara la huella con evidencia externa de revisión. ")}</p>
            </div>
            <button
              className="audit-toolbar__button"
              disabled={isSigningKeyLoading}
              onClick={() => void loadSigningKey()}
              type="button"
            >
              <AppIcon name="refresh" size={18} />
              {isSigningKeyLoading ? t("Refreshing...", "Actualizando...") : t("Refresh key", "Actualizar llave")}
            </button>
          </div>

          {signingKeyError ? (
            <div className="auth-feedback auth-feedback--error">
              <span aria-hidden="true" />
              <p>{signingKeyError}</p>
            </div>
          ) : null}

          {signingKeyMessage ? (
            <div className="auth-feedback auth-feedback--success">
              <span aria-hidden="true" />
              <p>{signingKeyMessage}</p>
            </div>
          ) : null}

          {signingKey ? (
            <div className="admin-signing-key">
              <div className="admin-signing-key__facts">
                <span>
                  <small>{t("Status", "Estado")}</small>
                  <strong>{signingKey.configured ? t("Configured", "Configurada") : t("No signing key", "Sin llave de firma")}</strong>
                </span>
                <span>
                  <small>{t("Key ID", "ID de llave")}</small>
                  <strong>{signingKey.keyId || t("Not configured", "No configurada")}</strong>
                </span>
                <span>
                  <small>{t("Algorithm", "Algoritmo")}</small>
                  <strong>{signingKey.algorithm}</strong>
                </span>
                <span>
                  <small>{t("Rotation", "Rotación")}</small>
                  <strong>{signingKey.rotationSupported ? t("Available", "Disponible") : t("Manual only", "Solo manual")}</strong>
                </span>
              </div>

              <div className="admin-signing-key__fingerprint">
                <small>{t("Public key SHA-256 fingerprint", "Huella SHA-256 de llave pública")}</small>
                <code>{formatFingerprint(signingKey.publicKeyFingerprint)}</code>
              </div>

              <ul className="admin-user-enrollment" aria-label={t('Package signing key state', 'Estado de la llave de firma de paquetes')}>
                <li className={signingKey.privateKeyConfigured ? 'is-complete' : 'is-missing'}>
                  {t("Private key ", "Llave privada ")}{signingKey.privateKeyConfigured ? t("configured", "configurada") : t("missing", "faltante")}
                </li>
                <li className={signingKey.publicKeyConfigured ? 'is-complete' : 'is-missing'}>
                  {t("Public key ", "Llave pública ")}{signingKey.publicKeyConfigured ? t("configured", "configurada") : t("missing", "faltante")}
                </li>
                <li className={signingKey.envWritable ? 'is-complete' : 'is-missing'}>
                  .env {signingKey.envWritable ? t("writable", "escribible") : t("not writable", "no escribible")}
                </li>
                <li className={signingKey.configCached ? 'is-missing' : 'is-complete'}>
                  {t("Config ", "Configuración ")}{signingKey.configCached ? t("cached", "en caché") : t("live", "activa")}
                </li>
              </ul>

              <div className="admin-signing-key__actions">
                <button
                  className="admin-role-assignment__button"
                  disabled={!signingKey.rotationSupported || isRotatingSigningKey}
                  onClick={() => void handleSigningKeyRotation()}
                  type="button"
                >
                  <AppIcon name="key" size={17} />
                  {isRotatingSigningKey
                    ? t("Authenticating...", "Autenticando...")
                    : signingKey.configured
                      ? t("Rotate key", "Rotar llave")
                      : t("Generate key", "Generar llave")}
                </button>
                <p className="admin-role-assignment__note">
                  {signingKey.configured
                    ? t("Rotation is passkey-gated and writes a new keypair to ", "La rotación está protegida con llave de acceso y escribe un nuevo par de llaves en ")
                    : t("Generation is passkey-gated and writes the first keypair to ", "La generación está protegida con llave de acceso y escribe el primer par de llaves en ")}
                  <code>.env</code>
                  {signingKey.configured
                    ? t(". Keep old fingerprints for previously exported packages.", ". Conserva las huellas anteriores para paquetes exportados previamente.")
                    : t(". Exported packages will stay unsigned until this key exists.", ". Los paquetes exportados permanecerán sin firma hasta que exista esta llave.")}
                </p>
              </div>

              {!signingKey.configured && !signingKey.rotationSupported ? (
                <div className="admin-signing-key__manual">
                  <strong>{t("Manual provisioning required", "Se requiere aprovisionamiento manual")}</strong>
                  <p>
                    {t("The app process cannot write ", "El proceso de la app no puede escribir en ")}<code>.env</code>{t(", so generate the first package signing key from the API checkout and then clear config. ", ", así que genera la primera llave de firma de paquetes desde el checkout de la API y luego limpia la configuración. ")}</p>
                  <pre><code>{getSigningKeyProvisionCommand(signingKey.keyId)}</code></pre>
                  <pre><code>php artisan optimize:clear</code></pre>
                </div>
              ) : null}
            </div>
          ) : (
            <p className="workspace-panel__copy">{t("Loading package signing key...", "Cargando llave de firma de paquetes...")}</p>
          )}
        </section>
      ) : null}

      {activeTab === 'signing' ? (
        <section className="workspace-panel" aria-label={t('Signing trust ledger', 'Registro de confianza de firmas')}>
          <div className="admin-directory-toolbar">
            <div>
              <h2 className="workspace-panel__title">{t("Signing Trust", "Confianza de firma")}</h2>
              <p className="workspace-panel__copy">
                {t("Trace each signing-capable user to their document signatures, grouped by document and revision. ", "Consulta las firmas de cada persona, agrupadas por documento y versión. ")}</p>
            </div>
            <button
              className="audit-toolbar__button"
              disabled={isSigningLedgerLoading}
              onClick={() => void loadSigningLedger()}
              type="button"
            >
              <AppIcon name="refresh" size={18} />
              {isSigningLedgerLoading ? t("Refreshing...", "Actualizando...") : t("Refresh ledger", "Actualizar libro")}
            </button>
          </div>

          {signingLedgerError ? (
            <div className="auth-feedback auth-feedback--error">
              <span aria-hidden="true" />
              <p>{signingLedgerError}</p>
            </div>
          ) : null}

          {isSigningLedgerLoading && signingLedger.length === 0 && signingLedgerDocuments.length === 0 ? (
            <p className="workspace-panel__copy">{t("Loading signing ledger...", "Cargando libro de firmas...")}</p>
          ) : null}

          {!isSigningLedgerLoading && signingLedger.length === 0 && signingLedgerDocuments.length === 0 && !signingLedgerError ? (
            <p className="workspace-panel__copy">{t("No signing ledger records found yet.", "Todavía no se encontraron registros en el libro de firmas.")}</p>
          ) : null}

          {signingLedger.length > 0 || signingLedgerDocuments.length > 0 ? (
            <div className="signing-ledger">
              <div className="signing-ledger__graph-scroll" aria-label={t("Scrollable signing trust graph", "Gráfica desplazable de confianza de firma")} tabIndex={0}>
                <article className="signing-ledger__graph">
                  <div className="signing-ledger__graph-head" aria-hidden="true">
                    <span>{t("User", "Usuario")}</span>
                    <span>{t("Keys", "Llaves")}</span>
                    <span>{t("Document revisions", "Versiones de documentos")}</span>
                  </div>

                  <div
                    className="signing-ledger__graph-body signing-ledger__graph-body--indexed"
                    ref={signingGraphBodyRef}
                    style={{
                      minHeight: `${signingLedgerGraphBodyMinHeight}px`,
                    }}
                  >
                    <svg
                      aria-hidden="true"
                      className="signing-ledger__connector-layer"
                      style={{
                        height: `${signingLedgerConnectorBounds.height}px`,
                        width: `${signingLedgerConnectorBounds.width}px`,
                      }}
                      viewBox={`0 0 ${signingLedgerConnectorBounds.width} ${signingLedgerConnectorBounds.height}`}
                    >
                      {signingLedgerConnectorPaths.map((path) => (
                        <path
                          className={`signing-ledger__connector signing-ledger__connector--${path.variant}`}
                          data-connector-id={path.id}
                          data-connector-variant={path.variant}
                          d={path.d}
                          key={path.id}
                        />
                      ))}
                    </svg>
                    <section className="signing-ledger__user-column" aria-label={t("Signing users", "Usuarios firmantes")}>
                      {signingLedgerGraph.signerGroups.map(({ signer }) => (
                        <section
                          className="signing-ledger__user-node"
                          aria-label={t(`${signer.name} signer`, `${signer.name} firmante`)}
                          data-signer-id={signer.id}
                          key={signer.id}
                          ref={(node) => setSigningUserNodeRef(String(signer.id), node)}
                          style={{
                            '--signing-node-offset': `${signingLedgerNodeOffsets.user[String(signer.id)] ?? 0}px`,
                          } as CSSProperties}
                        >
                          <span className="signing-ledger__node-icon">
                            <AppIcon name="admin" size={17} />
                          </span>
                          <div>
                            <h3>{signer.name}</h3>
                            <p>{signer.email}</p>
                          </div>
                          <div className="signing-ledger__signer-badges">
                            <RoleBadge role={signer.role} />
                            <span>{signer.signatureCount} {t("signatures", "firmas")}</span>
                          </div>
                        </section>
                      ))}

                    </section>

                  <section className="signing-ledger__key-column" aria-label={t("Signature keys", "Llaves de firma")}>
                      {signingLedgerGraph.signerGroups.map(({ edges, signer }) => {
                        const keyIdentifier = getSigningKeyIdentifier(edges)

                        return (
                          <span
                            className="signing-ledger__key-node"
                            data-signer-id={signer.id}
                            key={signer.id}
                            ref={(node) => setSigningKeyNodeRef(String(signer.id), node)}
                            style={{
                              '--signing-node-offset': `${signingLedgerNodeOffsets.key[String(signer.id)] ?? 0}px`,
                            } as CSSProperties}
                          >
                            <strong>{t("security key", "Llave de seguridad de ")}{signer.name}</strong>
                            <span>{t("passkey", "llave de acceso")}</span>
                            <code>{signer.email}</code>
                            <small title={keyIdentifier.title}>{t("key id ", "id de llave ")}{keyIdentifier.label}</small>
                            <small>{edges.length} {t("signed revisions", "versiones firmadas")}</small>
                          </span>
                        )
                      })}
                  </section>

                    <section className="signing-ledger__document-column" aria-label={t("Document revisions", "Versiones de documentos")}>
                      {signingLedgerGraph.documentGroups.map((documentGroup) => (
                        <article className="signing-ledger__document-group" key={documentGroup.id}>
                          <header className="signing-ledger__document-title">
                            <AppIcon name="document" size={18} />
                            <div>
                              <h4>{documentGroup.document.title}</h4>
                              <p>{t("Document #", "Documento #")}{documentGroup.document.id} · {documentGroup.document.status}</p>
                            </div>
                          </header>

                          <div className="signing-ledger__revision-stack">
                            {documentGroup.revisions.map((revisionGroup) => (
                              <details
                                className="signing-ledger__revision-node"
                                data-revision-id={revisionGroup.id}
                                key={revisionGroup.id}
                                ref={(node) => setSigningRevisionNodeRef(revisionGroup.id, node)}
                              >
                                <summary>
                                  <strong>{t("Revision ", "Versión ")}{revisionGroup.revision.revisionNumber}</strong>
                                  <a
                                    className="signing-ledger__revision-link"
                                    href={getRevisionDocumentUrl(documentGroup.document.id, revisionGroup.revision.id)}
                                    onClick={(event) => {
                                      event.stopPropagation()
                                      if (!onNavigate) {
                                        return
                                      }

                                      event.preventDefault()
                                      onNavigate(getRevisionDocumentUrl(documentGroup.document.id, revisionGroup.revision.id))
                                    }}
                                  >
                                    {t("Open ", "Abrir ")}</a>
                                </summary>
                                <div className="signing-ledger__revision-details">
                                  <span>{revisionGroup.revision.originalFileName || t("No file name", "Sin nombre de archivo")}</span>
                                  <code>{revisionGroup.revision.sha256?.slice(0, 16) || t("No hash", "Sin hash")}</code>
                                  <div className="signing-ledger__revision-meta">
                                    {revisionGroup.edges.map((revisionEdge) => (
                                      <span key={revisionEdge.id}>
                                        {revisionEdge.signer
                                          ? t(`${revisionEdge.signer.name} · Signed ${formatOptionalDate(revisionEdge.signature?.signedAt)} · Expires ${formatOptionalDate(revisionEdge.signature?.expiresAt)}`, `${revisionEdge.signer.name} · Firmado ${formatOptionalDate(revisionEdge.signature?.signedAt)} · Vence ${formatOptionalDate(revisionEdge.signature?.expiresAt)}`)
                                          : t(`Unsigned · ${revisionEdge.revision.signatureStatus}`, `Sin firma · ${revisionEdge.revision.signatureStatus}`)}
                                      </span>
                                    ))}
                                  </div>
                                </div>
                              </details>
                            ))}
                          </div>
                        </article>
                      ))}
                    </section>
                  </div>
                </article>
              </div>

              {signingLedgerGraph.unsignedSigners.length > 0 ? (
                <section className="signing-ledger__unsigned" aria-label={t("Signing users without signatures", "Usuarios firmantes sin firmas")}>
                  <header className="signing-ledger__unsigned-header">
                    <h3>{t("Signing-capable users without signatures", "Usuarios con capacidad de firma sin firmas")}</h3>
                    <p>{t("These accounts can sign after enrollment and permissions are complete, but have no signed revisions yet.", "Estas cuentas podrán firmar cuando completen la configuración de seguridad y los permisos, pero aún no tienen versiones firmadas.")}</p>
                  </header>
                  <div className="signing-ledger__unsigned-list">
                    {signingLedgerGraph.unsignedSigners.map((signer) => (
                      <article className="signing-ledger__unsigned-user" key={signer.id}>
                        <span className="signing-ledger__node-icon">
                          <AppIcon name="admin" size={17} />
                        </span>
                        <div>
                          <h4>{signer.name}</h4>
                          <p>{signer.email}</p>
                        </div>
                        <div className="signing-ledger__signer-badges">
                          <RoleBadge role={signer.role} />
                          <span>{signer.signatureCount} {t("signatures", "firmas")}</span>
                        </div>
                      </article>
                    ))}
                  </div>
                </section>
              ) : null}

              <section className="signing-ledger__compact" aria-label={t("Compact signing trust ledger", "Libro compacto de confianza de firma")}>
                {signingLedgerGraph.documentGroups.map((documentGroup) => (
                  <article className="signing-ledger__compact-document" key={documentGroup.id}>
                    <header className="signing-ledger__compact-title">
                      <AppIcon name="document" size={18} />
                      <div>
                        <h3>{documentGroup.document.title}</h3>
                        <p>{t("Document #", "Documento #")}{documentGroup.document.id} · {documentGroup.document.status}</p>
                      </div>
                    </header>

                    <div className="signing-ledger__compact-revisions">
                      {documentGroup.revisions.map((revisionGroup) => (
                        <details className="signing-ledger__compact-revision" key={revisionGroup.id}>
                          <summary>
                            <strong>{t("Revision ", "Versión ")}{revisionGroup.revision.revisionNumber}</strong>
                            <a
                              className="signing-ledger__revision-link"
                              href={getRevisionDocumentUrl(documentGroup.document.id, revisionGroup.revision.id)}
                              onClick={(event) => {
                                event.stopPropagation()
                                if (!onNavigate) {
                                  return
                                }

                                event.preventDefault()
                                onNavigate(getRevisionDocumentUrl(documentGroup.document.id, revisionGroup.revision.id))
                              }}
                            >
                              {t("Open ", "Abrir ")}</a>
                          </summary>
                          <div className="signing-ledger__compact-revision-details">
                            <span>{revisionGroup.revision.originalFileName || t("No file name", "Sin nombre de archivo")}</span>
                            <code>{revisionGroup.revision.sha256?.slice(0, 16) || t("No hash", "Sin hash")}</code>
                            <div className="signing-ledger__compact-signatures">
                              {revisionGroup.edges.map((revisionEdge) => (
                                <span
                                  className={`signing-ledger__compact-signature${revisionEdge.signer ? '' : ' signing-ledger__compact-signature--unsigned'}`}
                                  key={revisionEdge.id}
                                >
                                  {revisionEdge.signer ? (
                                    <>
                                      <strong>{revisionEdge.signer.name}</strong>
                                      <small>{revisionEdge.signer.email}</small>
                                      <small>{t("Signed ", "Firmado ")}{formatOptionalDate(revisionEdge.signature?.signedAt)} {t("· Expires ", "· Vence ")}{formatOptionalDate(revisionEdge.signature?.expiresAt)}</small>
                                    </>
                                  ) : (
                                    <>
                                      <strong>{t("Unsigned", "Sin firma")}</strong>
                                      <small>{revisionEdge.revision.signatureStatus}</small>
                                    </>
                                  )}
                                </span>
                              ))}
                            </div>
                          </div>
                        </details>
                      ))}
                    </div>
                  </article>
                ))}
              </section>
            </div>
          ) : null}
        </section>
      ) : null}

      {activeTab === 'migrant-signing' ? (
        <section className="workspace-panel" aria-label={t("Migrant signing trust ledger", "Libro de confianza de firma de migrantes")}>
          <div className="admin-directory-toolbar">
            <div>
              <h2 className="workspace-panel__title">{t("Migrant Signing Trust", "Confianza de firma de migrantes")}</h2>
              <p className="workspace-panel__copy">
                {t("Trace registration submissions, reviews, and decisions to the user and passkey that signed each action. ", "Rastrea envíos, revisiones y decisiones de registros hasta el usuario y la llave de acceso que firmaron cada acción. ")}</p>
            </div>
            <button
              className="audit-toolbar__button"
              disabled={isMigrantSigningLoading}
              onClick={() => void loadMigrantSigningLedger()}
              type="button"
            >
              <AppIcon name="refresh" size={18} />
              {isMigrantSigningLoading ? t("Refreshing...", "Actualizando...") : t("Refresh ledger", "Actualizar libro")}
            </button>
          </div>

          {migrantSigningError ? (
            <div className="auth-feedback auth-feedback--error">
              <span aria-hidden="true" />
              <p>{migrantSigningError}</p>
            </div>
          ) : null}

          {isMigrantSigningLoading && migrantSigningRegistrations.length === 0 ? (
            <p className="workspace-panel__copy">{t("Loading migrant signing ledger...", "Cargando libro de firma de migrantes...")}</p>
          ) : null}

          {!isMigrantSigningLoading && migrantSigningRegistrations.length === 0 && !migrantSigningError ? (
            <p className="workspace-panel__copy">{t("No migrant registrations found yet.", "Todavía no se encontraron registros de migrantes.")}</p>
          ) : null}

          {migrantSigningRegistrations.length > 0 ? (
            <div className="migrant-signing-ledger">
              <section className="migrant-signing-ledger__signers" aria-label={t("Migrant registration signers", "Firmantes de registros de migrantes")}>
                {migrantSigningSigners.map((signer) => (
                  <article className="migrant-signing-ledger__signer" key={signer.id}>
                    <span className="signing-ledger__node-icon">
                      <AppIcon name="admin" size={17} />
                    </span>
                    <div>
                      <strong>{signer.name}</strong>
                      <small>{signer.email}</small>
                    </div>
                    <div className="signing-ledger__signer-badges">
                      <RoleBadge role={signer.role} />
                      <span>{signer.signatureCount} {t("signatures", "firmas")}</span>
                    </div>
                  </article>
                ))}
              </section>

              <section className="migrant-signing-ledger__registrations" aria-label={t("Signed migrant registrations", "Registros de migrantes firmados")}>
                {migrantSigningRegistrations.map((registration) => (
                  <details className={`migrant-signing-ledger__registration${registration.isPurged ? ' migrant-signing-ledger__registration--purged' : ''}`} key={registration.id}>
                    <summary>
                      <span className="migrant-signing-ledger__registration-title">
                        <AppIcon name="sign" size={18} />
                        <span>
                          <strong>{registration.fullName}</strong>
                          <small>{t("Registration #", "Registro #")}{registration.id} · {formatLedgerLabel(registration.status)}</small>
                        </span>
                      </span>
                      <span className="migrant-signing-ledger__signature-count">
                        {registration.isPurged ? <small>{t("Purged ", "Purgado ")}{formatOptionalDate(registration.purgedAt)}</small> : null}
                        {registration.signatures.length} {t("signatures ", "firmas ")}</span>
                    </summary>

                    <div className="migrant-signing-ledger__chain">
                      {registration.signatures.length > 0 ? registration.signatures.map((signature) => (
                        <article className="migrant-signing-ledger__signature" key={signature.id}>
                          <span className="migrant-signing-ledger__action">{formatLedgerLabel(signature.actionType)}</span>
                          <div className="migrant-signing-ledger__actor">
                            <strong>{signature.actor?.name ?? t("Unknown signer", "Firmante desconocido")}</strong>
                            <small>{signature.actor?.email ?? signature.actor?.role ?? t("Account unavailable", "Cuenta no disponible")}</small>
                            {signature.actor ? <RoleBadge role={signature.actor.role} /> : null}
                          </div>
                          <div className="migrant-signing-ledger__key">
                            <span><AppIcon name="key" size={15} /> {signature.algorithm}</span>
                            <code title={signature.publicKeyRef ?? undefined}>
                              {signature.publicKeyRef ? signature.publicKeyRef.slice(0, 24) : t("Key reference unavailable", "Referencia de llave no disponible")}
                            </code>
                          </div>
                          <time>{formatOptionalDate(signature.verifiedAt)}</time>
                        </article>
                      )) : (
                        <p className="workspace-panel__copy">{t("This registration has no recorded signatures.", "Este registro no tiene firmas registradas.")}</p>
                      )}
                    </div>
                  </details>
                ))}
              </section>
            </div>
          ) : null}
        </section>
      ) : null}

      {activeTab === 'accounts' ? (
        <>
          <section className="workspace-panel workspace-panel--account-directory" aria-label={t("Account directory", "Directorio de cuentas")}>
            <div className="admin-directory-toolbar">
          <div>
            <h2 className="workspace-panel__title">{t("Account directory", "Directorio de cuentas")}</h2>
            <p className="workspace-panel__copy">
              {t("Role, enrollment, last sign-in, and status visibility for current accounts. ", "Visibilidad de rol, inscripción, último inicio de sesión y estado de las cuentas actuales. ")}</p>
          </div>
          <button
            className="audit-toolbar__button"
            disabled={isDirectoryLoading}
            onClick={() => void loadDirectory()}
            type="button"
          >
            <AppIcon name="refresh" size={18} />
            {isDirectoryLoading ? t("Refreshing...", "Actualizando...") : t("Refresh users", "Actualizar usuarios")}
          </button>
        </div>

        {directoryError ? (
          <div className="auth-feedback auth-feedback--error">
            <span aria-hidden="true" />
            <p>{directoryError}</p>
          </div>
        ) : null}

        {roleUpdateError ? (
          <div className="auth-feedback auth-feedback--error">
            <span aria-hidden="true" />
            <p>{roleUpdateError}</p>
          </div>
        ) : null}

                {roleUpdateMessage ? (
                  <div className="auth-feedback auth-feedback--success">
                    <span aria-hidden="true" />
                    <div>
                      <p>{roleUpdateMessage}</p>
                      {passwordResetLink ? (
                        <p>
                          {t("Password reset link:", "Enlace para restablecer contraseña:")}{' '}
                          <a href={passwordResetLink}>{passwordResetLink}</a>
                        </p>
                      ) : null}
                    </div>
                  </div>
                ) : null}

        {isDirectoryLoading && directory.length === 0 ? (
          <p className="workspace-panel__copy">{t("Loading account directory...", "Cargando directorio de cuentas...")}</p>
        ) : null}

        {!isDirectoryLoading && directory.length === 0 && !directoryError ? (
          <p className="workspace-panel__copy">{t("No accounts found yet.", "Todavía no se encontraron cuentas.")}</p>
        ) : null}

        <div className="admin-user-list">
          {directory.map((account) => (
            <article
              className={`admin-user-card${highlightedAccountId === account.id ? ' admin-user-card--highlighted' : ''}`}
              key={`${account.id}:${highlightedAccountId === account.id ? highlightToken : 0}`}
              ref={(node) => setAccountCardRef(account.id, node)}
            >
              <header className="admin-user-card__header">
                <div>
                  <h3>{account.name}</h3>
                  <p>{account.email}</p>
                </div>
                <span
                  className={`admin-user-status admin-user-status--${account.status === 'active' ? 'active' : 'suspended'}`}
                >
                  {account.status}
                </span>
              </header>

              <div className="admin-user-card__body">
                <div className="admin-user-facts">
                  <span>
                    <small>{t("Role", "Rol")}</small>
                    <RoleBadge role={account.role} />
                  </span>
                  <span>
                    <small>{t("Devices", "Dispositivos")}</small>
                    <strong>{account.devices.count}</strong>
                  </span>
                  <span>
                    <small>{t("Last sign-in", "Último inicio de sesión")}</small>
                    <strong>{formatOptionalDate(account.lastSignInAt)}</strong>
                  </span>
                </div>

                <ul className="admin-user-enrollment" aria-label={t(`${account.name} enrollment state`, `Estado de inscripción de ${account.name}`)}>
                  <li className={account.enrollment.isFullyEnrolled ? 'is-complete' : 'is-missing'}>
                    <strong>{getEnrollmentLabel(account)}</strong>
                    <span>
                      TOTP {account.enrollment.enrolled.totp ? t("enabled", "activado") : t("missing", "faltante")} · {account.enrollment.passkeyCount}{' '}
                      {account.enrollment.passkeyCount === 1 ? t("passkey", "llave de acceso") : t("passkeys", "llaves de acceso")}
                    </span>
                  </li>
                  {account.status !== 'active' ? (
                    <li>
                      {t("Suspended ", "Suspendida ")}{formatOptionalDate(account.suspendedAt)}
                    </li>
                  ) : null}
                </ul>

                <div className="admin-user-devices">
                  <small>{t("Recent devices", "Dispositivos recientes")}</small>
                  {account.devices.recent.length > 0 ? (
                    <ul aria-label={t(`${account.name} recent devices`, `Dispositivos recientes de ${account.name}`)}>
                      {account.devices.recent.map((device) => (
                        <li key={device.id}>
                          <strong>{device.alias || t("Unknown browser", "Navegador desconocido")}</strong>
                          <span>#{device.deviceId}</span>
                          <span>{formatOptionalDate(device.lastLoginAt)}</span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p>{t("No remembered devices yet.", "Todavía no hay dispositivos recordados.")}</p>
                  )}
                </div>
              </div>

              <div className="admin-user-actions">
                <div className="admin-role-assignment">
                  <div>
                    <label htmlFor={`curp-${account.id}`}>CURP</label>
                    <input
                      autoCapitalize="characters"
                      disabled={updatingCurpUserId === account.id}
                      id={`curp-${account.id}`}
                      inputMode="text"
                      maxLength={18}
                      onChange={(event) => {
                        const value = event.target.value.toUpperCase().replace(/\s/g, '')
                        setCurpDraftByUserId((currentDrafts) => ({
                          ...currentDrafts,
                          [account.id]: value,
                        }))
                        setCurpUpdateFeedbackByUserId((currentFeedback) => {
                          const nextFeedback = { ...currentFeedback }
                          delete nextFeedback[account.id]
                          return nextFeedback
                        })
                      }}
                      placeholder={t('Not assigned', 'Sin asignar')}
                      spellCheck={false}
                      value={curpDraftByUserId[account.id] ?? account.curp ?? ''}
                    />
                  </div>
                  <button
                    className="admin-role-assignment__button"
                    disabled={
                      updatingCurpUserId === account.id ||
                      (curpDraftByUserId[account.id] ?? account.curp ?? '').trim().toUpperCase() === (account.curp ?? '')
                    }
                    onClick={() => void handleCurpUpdate(account)}
                    type="button"
                  >
                    <AppIcon name="verify" size={17} />
                    {updatingCurpUserId === account.id
                      ? t('Authenticating...', 'Autenticando...')
                      : t('Save CURP', 'Guardar CURP')}
                  </button>
                </div>
                {curpUpdateFeedbackByUserId[account.id] ? (
                  <p
                    className={`admin-field-feedback admin-field-feedback--${curpUpdateFeedbackByUserId[account.id].kind}`}
                    role={curpUpdateFeedbackByUserId[account.id].kind === 'error' ? 'alert' : 'status'}
                  >
                    {curpUpdateFeedbackByUserId[account.id].message}
                  </p>
                ) : null}
                <div className="admin-role-assignment">
                  <div>
                    <label htmlFor={`role-${account.id}`}>{t("Assign role", "Asignar rol")}</label>
                    <select
                      disabled={account.role === 'admin' || account.id === user.id || updatingRoleUserId === account.id}
                      id={`role-${account.id}`}
                      onChange={(event) => {
                        setRoleDraftByUserId((currentDrafts) => ({
                          ...currentDrafts,
                          [account.id]: event.target.value as AssignableUserRole,
                        }))
                      }}
                      value={getRoleSelectValue(account, roleDraftByUserId)}
                    >
                      {account.role === 'admin' ? (
                        <option value="admin">{t('Administrator', 'Administrador')}</option>
                      ) : null}
                      {ASSIGNABLE_ROLES.map((role) => (
                        <option key={role} value={role}>
                          {getRoleLabel(role)}
                        </option>
                      ))}
                    </select>
                  </div>
                  <button
                    className="admin-role-assignment__button"
                    disabled={
                      account.role === 'admin' ||
                      account.id === user.id ||
                      updatingRoleUserId === account.id ||
                      isCoordinatorPromotionBlocked(account, roleDraftByUserId) ||
                      roleDraftByUserId[account.id] === account.role
                    }
                    onClick={() => void handleRoleUpdate(account)}
                    type="button"
                  >
                    <AppIcon name="verify" size={17} />
                    {updatingRoleUserId === account.id ? t("Authenticating...", "Autenticando...") : t("Apply role", "Aplicar rol")}
                  </button>
                </div>
                {isCoordinatorPromotionBlocked(account, roleDraftByUserId) ? (
                  <p className="admin-role-assignment__note admin-role-assignment__note--warning">
                    {t("Coordinator promotion requires the target user to register a passkey first. ", "La promoción a coordinación requiere que el usuario destino registre primero una llave de acceso. ")}</p>
                ) : null}

                <AdminUserDepartmentsEditor
                  account={account}
                  isSelf={account.id === user.id}
                  key={`${account.id}:${(account.departments ?? []).join(',')}`}
                  onUpdated={(updatedAccount) => {
                    setDirectory((currentDirectory) =>
                      currentDirectory.map((currentAccount) =>
                        currentAccount.id === updatedAccount.id ? updatedAccount : currentAccount,
                      ),
                    )

                    // `user` may carry the dev role override; reload the real account instead.
                    if (updatedAccount.id === user.id && onUserUpdated) {
                      void getCurrentUser()
                        .then((response) => onUserUpdated(response.user))
                        .catch(() => undefined)
                    }
                  }}
                />

                <div className="admin-recovery-actions" aria-label={t(`${account.name} recovery and status controls`, `Controles de recuperación y estado de ${account.name}`)}>
                  <button
                    className="admin-recovery-actions__button"
                    disabled={
                      account.role === 'admin' ||
                      account.id === user.id ||
                      recoveringUserId === account.id ||
                      !account.enrollment.enrolled.totp
                    }
                    onClick={() => void handleRecovery(account, 'reset_totp')}
                    type="button"
                  >
                    <AppIcon name="key" size={16} />
                    {recoveringUserId === account.id && recoveryAction === 'reset_totp'
                      ? t("Authenticating...", "Autenticando...")
                      : t("Reset TOTP", "Restablecer TOTP")}
                  </button>
                  <button
                    className="admin-recovery-actions__button"
                    disabled={
                      account.role === 'admin' ||
                      account.id === user.id ||
                      recoveringUserId === account.id
                    }
                    onClick={() => void handleRecovery(account, 'reset_password')}
                    type="button"
                  >
                    <AppIcon name="keyReset" size={16} />
                    {recoveringUserId === account.id && recoveryAction === 'reset_password'
                      ? t("Authenticating...", "Autenticando...")
                      : t("Reset password", "Restablecer contraseña")}
                  </button>
                  <button
                    className="admin-recovery-actions__button admin-recovery-actions__button--destructive"
                    disabled={
                      account.role === 'admin' ||
                      account.id === user.id ||
                      recoveringUserId === account.id ||
                      account.enrollment.passkeyCount === 0
                    }
                    onClick={() => void handleRecovery(account, 'revoke_passkeys')}
                    type="button"
                  >
                    <AppIcon name="delete" size={16} />
                    {recoveringUserId === account.id && recoveryAction === 'revoke_passkeys'
                      ? t("Authenticating...", "Autenticando...")
                      : t("Revoke keys", "Revocar llaves")}
                  </button>
                  {account.status === 'active' ? (
                    <button
                      className="admin-recovery-actions__button admin-recovery-actions__button--suspend"
                      disabled={
                        account.role === 'admin' ||
                        account.id === user.id ||
                        updatingStatusUserId === account.id
                      }
                      onClick={() => void handleStatusUpdate(account, 'suspend')}
                      type="button"
                    >
                      <AppIcon name="suspend" size={16} />
                      {updatingStatusUserId === account.id && statusAction === 'suspend'
                        ? t("Authenticating...", "Autenticando...")
                        : t("Suspend", "Suspender")}
                    </button>
                  ) : (
                    <button
                      className="admin-recovery-actions__button"
                      disabled={
                        account.role === 'admin' ||
                        account.id === user.id ||
                        updatingStatusUserId === account.id
                      }
                      onClick={() => void handleStatusUpdate(account, 'reactivate')}
                      type="button"
                    >
                      <AppIcon name="verify" size={16} />
                      {updatingStatusUserId === account.id && statusAction === 'reactivate'
                        ? t("Authenticating...", "Autenticando...")
                        : t("Reactivate", "Reactivar")}
                    </button>
                  )}
                </div>
              </div>

              {account.status !== 'active' && account.suspensionReason ? (
                <p className="admin-role-assignment__note">
                  {t("Suspension reason: ", "Motivo de suspensión: ")}{account.suspensionReason}
                </p>
              ) : null}

              {account.role === 'admin' ? (
                <p className="admin-role-assignment__note">
                  {t("Admin account role, recovery, and status changes are locked for the later hardened admin-account flow. ", "Los cambios de rol, recuperación y estado de cuentas administradoras están bloqueados para el flujo reforzado posterior de cuentas administradoras. ")}</p>
              ) : null}
            </article>
          ))}
            </div>
          </section>
        </>
      ) : null}
    </section>
  )
}
