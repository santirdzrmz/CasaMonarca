import { apiFetch } from './api'
import { getCsrfToken } from './csrf'

export type AccessResource = 'registry' | 'arco'
export type AccessStatus = 'denied' | 'granted' | 'requested' | 'revoked'

export type AccessRequest = {
  created_at: string
  expires_at: string | null
  id: number
  reason: string | null
  resource_id: number
  resource_type: AccessResource
  status: AccessStatus
}

export type PendingAccessRequest = AccessRequest & {
  fingerprint: string
  operator_name: string
}

export type OperatorAccessState = {
  expires_at?: string
  fingerprint?: string
  mode: string
  requests?: AccessRequest[]
}

const jsonHeaders = (csrfToken: string) => ({ 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken })

/** Operator: opens the session key and returns its fingerprint and the operator's own requests. */
export async function getOperatorAccess() {
  const response = await apiFetch<{ data: OperatorAccessState }>('/registry/access/mine')
  return response.data
}

export async function requestAccess(resource: AccessResource, resourceId: number, reason?: string) {
  const { csrfToken } = await getCsrfToken()
  return apiFetch<{ data: { id: number } }>('/registry/access/requests', {
    method: 'POST',
    headers: jsonHeaders(csrfToken),
    body: JSON.stringify({ reason: reason || null, resource, resource_id: resourceId }),
  })
}

/** Coordinator or admin: requests waiting for a decision, or active grants. */
export async function listAccessRequests(status: 'granted' | 'requested' = 'requested') {
  const response = await apiFetch<{ data: PendingAccessRequest[] }>(`/registry/access/requests?status=${status}`)
  return response.data
}

export async function grantAccess(id: number, fingerprint: string, minutes: number) {
  const { csrfToken } = await getCsrfToken()
  return apiFetch<unknown>(`/registry/access/requests/${id}/grant`, {
    method: 'POST',
    headers: jsonHeaders(csrfToken),
    body: JSON.stringify({ fingerprint, minutes }),
  })
}

export async function denyAccess(id: number) {
  const { csrfToken } = await getCsrfToken()
  return apiFetch<unknown>(`/registry/access/requests/${id}/deny`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken } })
}

export async function revokeAccess(id: number) {
  const { csrfToken } = await getCsrfToken()
  return apiFetch<unknown>(`/registry/access/requests/${id}/revoke`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken } })
}
