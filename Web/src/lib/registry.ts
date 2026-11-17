import { apiFetch, ApiRequestError, buildApiUrl } from './api'
import type { WebauthnLoginAssertionPayload, WebauthnLoginOptions } from './auth'
import { getCsrfToken } from './csrf'
import type { PendingMigrantDocument } from './migrantDocuments'
import type { SecurityChallengeSummary } from './securityChallenges'
import type {
  MigrantRegistrationPayload,
  MigrantQuestionnaireDefinition,
  RegistryEntry,
  RegistrySignature,
} from '../types/registry'

export type {
  MigrantRegistrationPayload,
  RegistryEntry,
  RegistryRole,
  RegistrySignature,
  RegistryStatus,
  RegistryStatusHistory,
} from '../types/registry'

export type RegistryListResponse = {
  data: RegistryEntry[]
}

export type RegistryDetailResponse = {
  data: RegistryEntry
  signatures?: RegistrySignature[]
}

export type QuestionnaireDefinitionResponse = { data: MigrantQuestionnaireDefinition }

export type CreateRegistryEntryPayload = {
  payload_json: MigrantRegistrationPayload
}

export type UpdateRegistryEntryPayload = {
  payload_json: Partial<MigrantRegistrationPayload> & Record<string, unknown>
}

export type SubmitRegistryEntryPayload = {
  public_key_ref?: string
  signature_payload: string
}

export type RegistryApprovalDecision = 'approve' | 'reject'

export type RegistryApprovalOptionsPayload = {
  decision: RegistryApprovalDecision
  reason?: string
}

export type RegistryApprovalOptionsResponse = {
  approvalTarget: {
    decision: RegistryApprovalDecision
    entryId: number
    payloadHash: string
  }
  challengeIntent: SecurityChallengeSummary
  message: string
  options: WebauthnLoginOptions
}

export type RegistryApprovalVerifyResponse = {
  challengeIntent: SecurityChallengeSummary | null
  data: RegistryEntry
  message: string
}

export type RegistryBulkApprovalOptionsResponse = {
  approvalTarget: {
    decision: 'approve'
    entryCount: number
    entryIds: number[]
    expiresAt: string
  }
  challengeIntent: SecurityChallengeSummary
  message: string
  options: WebauthnLoginOptions
}

export type RegistryBulkApprovalVerifyResponse = {
  approvedCount: number
  challengeIntent: SecurityChallengeSummary | null
  data: RegistryEntry[]
  message: string
}

export type RegistryReviewOptionsPayload = {
  reason?: string
}

export type RegistryReviewOptionsResponse = {
  challengeIntent: SecurityChallengeSummary
  message: string
  options: WebauthnLoginOptions
}

export type RegistryReviewVerifyResponse = {
  data: RegistryEntry
  message: string
}

export type RegistryReviewReturnResponse = {
  data: RegistryEntry
  message: string
}

export type DeleteRegistryEntryResponse = {
  message: string
}

export type RegistryPdfDownloadOptionsResponse = {
  challengeIntent: SecurityChallengeSummary
  message: string
  options: WebauthnLoginOptions
}

export async function getRegistryEntries() {
  return apiFetch<RegistryListResponse>('/registry/migrants')
}

export async function startRegistryPdfDownload(id: number) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryPdfDownloadOptionsResponse>(
    `/registry/migrants/${id}/pdf/options`,
    {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken },
    },
  )
}

export async function verifyRegistryPdfDownload(
  id: number,
  assertion: WebauthnLoginAssertionPayload,
) {
  const { csrfToken } = await getCsrfToken()
  const response = await fetch(buildApiUrl(`/registry/migrants/${id}/pdf/verify`), {
    method: 'POST',
    credentials: 'include',
    headers: {
      Accept: 'application/pdf',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(assertion),
  })

  if (!response.ok) {
    const payload = await response.json().catch(() => null) as { message?: unknown } | null
    const message = typeof payload?.message === 'string'
      ? payload.message
      : `Request failed with status ${response.status}`

    throw new ApiRequestError(message, response.status)
  }

  return response.blob()
}

let questionnaireDefinitionRequest: Promise<QuestionnaireDefinitionResponse> | null = null

export function getCurrentMigrantQuestionnaire() {
  questionnaireDefinitionRequest ??= apiFetch<QuestionnaireDefinitionResponse>('/registry/migrants/questionnaires/current')
  return questionnaireDefinitionRequest
}

export async function getRegistryDrafts() {
  return apiFetch<RegistryListResponse>('/registry/migrants/drafts')
}

export async function createRegistryDraft(payload_json: MigrantRegistrationPayload) {
  const { csrfToken } = await getCsrfToken()
  return apiFetch<RegistryDetailResponse>('/registry/migrants/drafts', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    body: JSON.stringify({ payload_json }),
  })
}

export async function updateRegistryDraft(id: number, payload_json: MigrantRegistrationPayload) {
  const { csrfToken } = await getCsrfToken()
  return apiFetch<RegistryDetailResponse>(`/registry/migrants/drafts/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    body: JSON.stringify({ payload_json }),
  })
}

export async function discardRegistryDraft(id: number) {
  const { csrfToken } = await getCsrfToken()
  return apiFetch<{ message: string }>(`/registry/migrants/drafts/${id}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-TOKEN': csrfToken },
  })
}

export async function submitRegistryDraft(
  id: number,
  payload_json: MigrantRegistrationPayload,
  documents: PendingMigrantDocument[] = [],
) {
  const { csrfToken } = await getCsrfToken()
  const formData = new FormData()
  formData.set('payload_json', JSON.stringify(payload_json))
  documents.forEach(({ file, label }) => {
    formData.append('documents[]', file)
    formData.append('document_labels[]', label.trim())
  })
  return apiFetch<RegistryDetailResponse>(`/registry/migrants/drafts/${id}/submit`, {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': csrfToken },
    body: formData,
  })
}

export async function getPendingRegistryApprovals() {
  return apiFetch<RegistryListResponse>('/registry/migrants/pending-approval')
}

export async function getPendingRegistryReviews() {
  return apiFetch<RegistryListResponse>('/registry/migrants/pending-review')
}

export async function getRegistryCorrections() {
  return apiFetch<RegistryListResponse>('/registry/migrants/corrections')
}

export async function getRegistryEntryById(id: number) {
  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}`)
}

export async function createRegistryEntry(
  payload: CreateRegistryEntryPayload,
  documents: PendingMigrantDocument[] = [],
) {
  const { csrfToken } = await getCsrfToken()

  if (documents.length > 0) {
    const formData = new FormData()

    formData.set('payload_json', JSON.stringify(payload.payload_json))
    documents.forEach(({ file, label }) => {
      formData.append('documents[]', file)
      formData.append('document_labels[]', label.trim())
    })

    return apiFetch<RegistryDetailResponse>('/registry/migrants', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
      body: formData,
    })
  }

  return apiFetch<RegistryDetailResponse>('/registry/migrants', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function updateRegistryEntry(
  id: number,
  payload: UpdateRegistryEntryPayload,
  documents: PendingMigrantDocument[] = [],
) {
  const { csrfToken } = await getCsrfToken()

  if (documents.length > 0) {
    const formData = new FormData()

    formData.set('_method', 'PATCH')
    formData.set('payload_json', JSON.stringify(payload.payload_json))
    documents.forEach(({ file, label }) => {
      formData.append('documents[]', file)
      formData.append('document_labels[]', label.trim())
    })

    return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
      body: formData,
    })
  }

  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}`, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function deleteRegistryEntry(id: number) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<DeleteRegistryEntryResponse>(`/registry/migrants/${id}`, {
    method: 'DELETE',
    headers: {
      'X-CSRF-TOKEN': csrfToken,
    },
  })
}

export async function submitRegistryEntry(
  id: number,
  payload: SubmitRegistryEntryPayload,
) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}/submit`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function startRegistryApproval(
  id: number,
  payload: RegistryApprovalOptionsPayload,
) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryApprovalOptionsResponse>(
    `/registry/migrants/${id}/approval/options`,
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

export async function verifyRegistryApproval(
  id: number,
  payload: WebauthnLoginAssertionPayload,
) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryApprovalVerifyResponse>(
    `/registry/migrants/${id}/approval/verify`,
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

export async function startRegistryBulkApproval(entryIds: number[]) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryBulkApprovalOptionsResponse>(
    '/registry/migrants/bulk-approval/options',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({ entry_ids: entryIds }),
    },
  )
}

export async function verifyRegistryBulkApproval(payload: WebauthnLoginAssertionPayload) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryBulkApprovalVerifyResponse>(
    '/registry/migrants/bulk-approval/verify',
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

export async function startRegistryReview(
  id: number,
  payload: RegistryReviewOptionsPayload,
) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryReviewOptionsResponse>(
    `/registry/migrants/${id}/review/options`,
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

export async function verifyRegistryReview(
  id: number,
  payload: WebauthnLoginAssertionPayload,
) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryReviewVerifyResponse>(
    `/registry/migrants/${id}/review/verify`,
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

export async function returnRegistryForCorrections(id: number, reason: string) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryReviewReturnResponse>(`/registry/migrants/${id}/review/return`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify({ reason }),
  })
}

export type RegistryExitResponse = {
  data: RegistryEntry
  message: string
}

export async function recordRegistryExit(id: number, exitedAt: string, reason: string) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryExitResponse>(`/registry/migrants/${id}/exit`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify({ exited_at: exitedAt, reason }),
  })
}

export async function undoRegistryExit(id: number) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryExitResponse>(`/registry/migrants/${id}/exit`, {
    method: 'DELETE',
    headers: {
      'X-CSRF-TOKEN': csrfToken,
    },
  })
}

export { ApiRequestError }
