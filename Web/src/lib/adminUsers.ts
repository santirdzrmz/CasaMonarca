import { apiFetch } from './api'
import type { SecurityChallengeSummary } from './securityChallenges'
import type {
  SessionSecurityCapabilities,
  UserRole,
  WebauthnLoginAssertionPayload,
  WebauthnLoginOptions,
} from './auth'
import { getCsrfToken } from './csrf'

export type AdminUserStatus = 'active' | 'suspended'

export type AdminUserEnrollment = SessionSecurityCapabilities & {
  passkeyCount: number
}

export type AdminUserDevice = {
  alias?: string | null
  deviceId: string
  firstSeenAt?: string | null
  id: number
  lastIpAddress?: string | null
  lastLoginAt?: string | null
  lastSeenAt?: string | null
  revokedAt?: string | null
  trustedAt?: string | null
}

export type AdminUserSummary = {
  createdAt?: string | null
  curp?: string | null
  devices: {
    count: number
    recent: AdminUserDevice[]
  }
  departments?: string[]
  email: string
  emailVerifiedAt?: string | null
  enrollment: AdminUserEnrollment
  id: number
  lastSignInAt?: string | null
  name: string
  role: UserRole
  status: AdminUserStatus | string
  suspendedAt?: string | null
  suspensionReason?: string | null
  updatedAt?: string | null
}

export type AdminUserCurpUpdatePayload = {
  curp: string | null
}

export type AdminUserCurpUpdateOptionsResponse = {
  curpUpdate: {
    expiresAt?: string | null
    previousCurp?: string | null
    targetCurp?: string | null
    targetUserId: number
  }
  message: string
  options: WebauthnLoginOptions
}

export type AdminUserCurpUpdateResponse = {
  message: string
  user: AdminUserSummary
}

export type AdminUserListResponse = {
  message: string
  users: AdminUserSummary[]
}

export type AssignableUserRole = 'coordinator' | 'non_coordinator' | 'volunteer'

export type AdminUserRoleUpdatePayload = {
  reason?: string | null
  role: AssignableUserRole
}

export type AdminUserRoleUpdateOptionsResponse = {
  assignment: {
    expiresAt?: string | null
    previousRole: UserRole
    reason?: string | null
    targetRole: AssignableUserRole
    targetUserId: number
  }
  message: string
  options: WebauthnLoginOptions
}

export type AdminUserRoleUpdateResponse = {
  message: string
  user: AdminUserSummary
}

export type AdminUserRecoveryAction = 'reset_password' | 'reset_totp' | 'revoke_passkeys'

export type AdminUserRecoveryPayload = {
  action: AdminUserRecoveryAction
  reason?: string | null
}

export type AdminUserRecoveryOptionsResponse = {
  message: string
  options: WebauthnLoginOptions
  recovery: {
    action: AdminUserRecoveryAction
    expiresAt?: string | null
    reason?: string | null
    targetUserId: number
  }
}

export type AdminUserRecoveryResponse = {
  message: string
  passwordReset?: {
    email: string
    expiresAt?: string | null
    resetPath: string
    token: string
  }
  user: AdminUserSummary
}

export type AdminUserStatusAction = 'suspend' | 'reactivate'

export type AdminUserStatusUpdatePayload = {
  action: AdminUserStatusAction
  reason?: string | null
}

export type AdminUserStatusUpdateOptionsResponse = {
  challengeIntent?: SecurityChallengeSummary
  message: string
  options: WebauthnLoginOptions
  statusChange: {
    action: AdminUserStatusAction
    expiresAt?: string | null
    previousStatus: AdminUserStatus | string
    targetUserId: number
  }
}

export type AdminUserStatusUpdateResponse = {
  message: string
  user: AdminUserSummary
}

export type VerificationPackageSigningKeySummary = {
  algorithm: string
  configured: boolean
  configCached: boolean
  envWritable: boolean
  keyId?: string | null
  privateKeyConfigured: boolean
  publicKeyConfigured: boolean
  publicKeyFingerprint?: string | null
  rotationSupported: boolean
}

export type VerificationPackageSigningKeyResponse = {
  message: string
  signingKey: VerificationPackageSigningKeySummary
}

export type VerificationPackageSigningKeyRotationPayload = {
  bits?: 2048 | 3072 | 4096
  keyId: string
  reason: string
}

export type VerificationPackageSigningKeyRotationOptionsResponse = {
  message: string
  options: WebauthnLoginOptions
  rotation: {
    bits: number
    expiresAt?: string | null
    previousKeyId?: string | null
    previousPublicKeyFingerprint?: string | null
    targetKeyId: string
  }
}

export type VerificationPackageSigningKeyRotationVerifyResponse = {
  message: string
  previousSigningKey: VerificationPackageSigningKeySummary
  signingKey: VerificationPackageSigningKeySummary
}

export type SigningLedgerSignature = {
  credential?: {
    id?: string | null
    name?: string | null
    publicKeyFingerprintSha256?: string | null
  } | null
  expiresAt?: string | null
  id: number
  signedBy?: {
    email: string
    id: number
    name: string
    role: UserRole
  } | null
  signatureHash?: string | null
  signatureType: string
  signedAt?: string | null
  verificationStatus: string
}

export type SigningLedgerRevision = {
  id: number
  originalFileName?: string | null
  revisionNumber: number
  sha256?: string | null
  signatureStatus: string
  signatures: SigningLedgerSignature[]
}

export type SigningLedgerDocument = {
  id: number
  revisions: SigningLedgerRevision[]
  status: string
  title: string
}

export type SigningLedgerSigner = {
  documents: SigningLedgerDocument[]
  email: string
  id: number
  name: string
  role: UserRole
  signatureCount: number
}

export type SigningLedgerResponse = {
  documents: SigningLedgerDocument[]
  message: string
  signers: SigningLedgerSigner[]
}

export type MigrantSigningLedgerSigner = {
  email: string
  id: number
  name: string
  role: UserRole
  signatureCount: number
}

export type MigrantSigningLedgerSignature = {
  actionType: string
  actor?: {
    email: string
    id: number
    name: string
    role: UserRole
  } | null
  algorithm: string
  id: number
  publicKeyRef?: string | null
  verifiedAt?: string | null
}

export type MigrantSigningLedgerRegistration = {
  createdAt?: string | null
  fullName: string
  id: number
  isPurged: boolean
  purgedAt?: string | null
  signatures: MigrantSigningLedgerSignature[]
  status: string
  updatedAt?: string | null
}

export type MigrantSigningLedgerResponse = {
  message: string
  registrations: MigrantSigningLedgerRegistration[]
  signers: MigrantSigningLedgerSigner[]
}

export function getAdminUsers(limit = 50): Promise<AdminUserListResponse> {
  const params = new URLSearchParams({
    limit: String(limit),
  })

  return apiFetch<AdminUserListResponse>(`/admin/users?${params.toString()}`)
}

export type AdminUserDepartmentsUpdateResponse = {
  message: string
  user: AdminUserSummary
}

export async function updateAdminUserDepartments(
  userId: number,
  departments: string[],
): Promise<AdminUserDepartmentsUpdateResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AdminUserDepartmentsUpdateResponse>(`/admin/users/${userId}/departments`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify({ departments }),
  })
}

export async function startAdminUserRecovery(
  userId: number,
  payload: AdminUserRecoveryPayload,
): Promise<AdminUserRecoveryOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AdminUserRecoveryOptionsResponse>(`/admin/users/${userId}/recovery/options`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function startAdminUserCurpUpdate(
  userId: number,
  payload: AdminUserCurpUpdatePayload,
): Promise<AdminUserCurpUpdateOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AdminUserCurpUpdateOptionsResponse>(`/admin/users/${userId}/curp/options`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function verifyAdminUserCurpUpdate(
  userId: number,
  payload: WebauthnLoginAssertionPayload,
): Promise<AdminUserCurpUpdateResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AdminUserCurpUpdateResponse>(`/admin/users/${userId}/curp/verify`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function verifyAdminUserRecovery(
  userId: number,
  payload: WebauthnLoginAssertionPayload,
): Promise<AdminUserRecoveryResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AdminUserRecoveryResponse>(`/admin/users/${userId}/recovery/verify`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function startAdminUserRoleUpdate(
  userId: number,
  payload: AdminUserRoleUpdatePayload,
): Promise<AdminUserRoleUpdateOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AdminUserRoleUpdateOptionsResponse>(`/admin/users/${userId}/role/options`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function verifyAdminUserRoleUpdate(
  userId: number,
  payload: WebauthnLoginAssertionPayload,
): Promise<AdminUserRoleUpdateResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AdminUserRoleUpdateResponse>(`/admin/users/${userId}/role/verify`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function startAdminUserStatusUpdate(
  userId: number,
  payload: AdminUserStatusUpdatePayload,
): Promise<AdminUserStatusUpdateOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AdminUserStatusUpdateOptionsResponse>(`/admin/users/${userId}/status/options`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function verifyAdminUserStatusUpdate(
  userId: number,
  payload: WebauthnLoginAssertionPayload,
): Promise<AdminUserStatusUpdateResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AdminUserStatusUpdateResponse>(`/admin/users/${userId}/status/verify`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export function getVerificationPackageSigningKey(): Promise<VerificationPackageSigningKeyResponse> {
  return apiFetch<VerificationPackageSigningKeyResponse>('/admin/verification-package-signing-key')
}

export function getSigningLedger(): Promise<SigningLedgerResponse> {
  return apiFetch<SigningLedgerResponse>('/admin/signing-ledger')
}

export function getMigrantSigningLedger(): Promise<MigrantSigningLedgerResponse> {
  return apiFetch<MigrantSigningLedgerResponse>('/admin/migrant-signing-ledger')
}

export async function startVerificationPackageSigningKeyRotation(
  payload: VerificationPackageSigningKeyRotationPayload,
): Promise<VerificationPackageSigningKeyRotationOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<VerificationPackageSigningKeyRotationOptionsResponse>(
    '/admin/verification-package-signing-key/rotation/options',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify(payload),
    },
  )
}

export async function verifyVerificationPackageSigningKeyRotation(
  payload: WebauthnLoginAssertionPayload,
): Promise<VerificationPackageSigningKeyRotationVerifyResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<VerificationPackageSigningKeyRotationVerifyResponse>(
    '/admin/verification-package-signing-key/rotation/verify',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify(payload),
    },
  )
}
