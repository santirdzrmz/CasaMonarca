import { apiFetch, type ApiFieldErrors } from './api'
import { getCsrfToken } from './csrf'

export type LoginCredentials = {
  email: string
  password: string
}

export type TotpCredentials = {
  code: string
}

export type PasswordResetCredentials = {
  email: string
  password: string
  password_confirmation: string
  token: string
}

export type UserRole =
  | 'admin'
  | 'coordinator'
  | 'non_coordinator'
  | 'volunteer'

export type WebauthnRegistrationOptions = {
  challenge: string
  rp: {
    id: string
    name: string
  }
  user: {
    id: string
    name: string
    displayName: string
  }
  pubKeyCredParams: Array<{
    type: 'public-key'
    alg: number
  }>
  timeout?: number
  attestation?: AttestationConveyancePreference
  authenticatorSelection?: AuthenticatorSelectionCriteria
  excludeCredentials?: Array<{
    id: string
    type: 'public-key'
  }>
}

export type WebauthnLoginOptions = {
  challenge: string
  rpId: string
  timeout?: number
  userVerification?: UserVerificationRequirement
  allowCredentials?: Array<{
    id: string
    type: 'public-key'
    transports?: AuthenticatorTransport[] | null
  }>
}

export type WebauthnCredentialRegistrationPayload = {
  id: string
  rawId: string
  type: 'public-key'
  response: {
    clientDataJSON: string
    attestationObject: string
    authenticatorData: string
    publicKey: string
    publicKeyAlgorithm: number
  }
  transports?: string[]
  name?: string
}

export type WebauthnLoginAssertionPayload = {
  id: string
  rawId: string
  type: 'public-key'
  response: {
    clientDataJSON: string
    authenticatorData: string
    signature: string
    userHandle?: string
  }
}

export type WebauthnCredentialSummary = {
  id: string
  name?: string | null
  transports?: string[] | null
  createdAt?: string | null
  lastUsedAt?: string | null
}

export type SessionModuleCapabilities = {
  admin: boolean
  dashboard: boolean
  documents: boolean
  history: boolean
  invites: boolean
  logging: boolean
  upload: boolean
}

export type SessionSecurityCapabilities = {
  enrolled: {
    passkey: boolean
    totp: boolean
  }
  enforced: boolean
  isFullyEnrolled: boolean
  missing: {
    passkey: boolean
    totp: boolean
  }
  requires: {
    passkey: boolean
    totp: boolean
  }
}

export type AuthenticatedUser = {
  capabilities: {
    modules: SessionModuleCapabilities
    security: SessionSecurityCapabilities
  }
  departments?: string[]
  id: number
  email: string
  name: string
  role: UserRole
}

export type LoginSuccessResponse = {
  message: string
  requiresTwoFactor: false
  user: AuthenticatedUser
}

export type LoginTotpRequiredResponse = {
  message: string
  requiresTwoFactor: true
}

export type LoginResponse = LoginSuccessResponse | LoginTotpRequiredResponse

export type CurrentUserResponse = {
  message: string
  user: AuthenticatedUser
}

export type LogoutResponse = {
  message: string
}

export type WebauthnCredentialDeleteResponse = {
  message: string
}

export type LoginErrorResponse = {
  errors?: Partial<Record<keyof (LoginCredentials & TotpCredentials), string[]>>
  message: string
}

export type WebauthnRegistrationOptionsResponse = {
  message: string
  options: WebauthnRegistrationOptions
}

export type WebauthnLoginOptionsResponse = {
  message: string
  options: WebauthnLoginOptions
}

export type WebauthnRegistrationVerifyResponse = {
  message: string
  credential: WebauthnCredentialSummary
}

export type WebauthnCredentialListResponse = {
  message: string
  credentials: WebauthnCredentialSummary[]
}

export type TotpEnrollmentOptionsResponse = {
  message: string
  enrollment: {
    accountName: string
    issuer: string
    otpauthUri: string
    secret: string
  }
}

export type TotpEnrollmentVerifyResponse = {
  message: string
  user: AuthenticatedUser
}

export type PasswordResetCompleteResponse = {
  message: string
}

export const getFirstFieldError = (
  fieldErrors: ApiFieldErrors | undefined,
  field: keyof (LoginCredentials & TotpCredentials),
) => fieldErrors?.[field]?.[0]

export async function login(
  credentials: LoginCredentials,
): Promise<LoginResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<LoginResponse>('/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(credentials),
  })
}

export async function completeTotpLogin(
  credentials: TotpCredentials,
): Promise<LoginSuccessResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<LoginSuccessResponse>('/login/totp', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(credentials),
  })
}

export async function startTotpEnrollment(): Promise<TotpEnrollmentOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<TotpEnrollmentOptionsResponse>('/totp/enroll/options', {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': csrfToken,
    },
  })
}

export async function verifyTotpEnrollment(
  credentials: TotpCredentials,
): Promise<TotpEnrollmentVerifyResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<TotpEnrollmentVerifyResponse>('/totp/enroll/verify', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(credentials),
  })
}

export async function completePasswordReset(
  credentials: PasswordResetCredentials,
): Promise<PasswordResetCompleteResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<PasswordResetCompleteResponse>('/password-reset/complete', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(credentials),
  })
}

export function getWebauthnCredentials(): Promise<WebauthnCredentialListResponse> {
  return apiFetch<WebauthnCredentialListResponse>('/webauthn/credentials')
}

export async function removeWebauthnCredential(
  credentialId: string,
): Promise<WebauthnCredentialDeleteResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<WebauthnCredentialDeleteResponse>(`/webauthn/credentials/${encodeURIComponent(credentialId)}`, {
    method: 'DELETE',
    headers: {
      'X-CSRF-TOKEN': csrfToken,
    },
  })
}

export async function startWebauthnRegistration(): Promise<WebauthnRegistrationOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<WebauthnRegistrationOptionsResponse>('/webauthn/register/options', {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': csrfToken,
    },
  })
}

export async function startWebauthnLogin(
  email?: string,
): Promise<WebauthnLoginOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<WebauthnLoginOptionsResponse>('/webauthn/login/options', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(email ? { email } : {}),
  })
}

export async function verifyWebauthnRegistration(
  payload: WebauthnCredentialRegistrationPayload,
): Promise<WebauthnRegistrationVerifyResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<WebauthnRegistrationVerifyResponse>('/webauthn/register/verify', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function verifyWebauthnLogin(
  payload: WebauthnLoginAssertionPayload,
): Promise<LoginSuccessResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<LoginSuccessResponse>('/webauthn/login/verify', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export function getCurrentUser(): Promise<CurrentUserResponse> {
  return apiFetch<CurrentUserResponse>('/me')
}

export async function logout(): Promise<LogoutResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<LogoutResponse>('/logout', {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': csrfToken,
    },
  })
}
