import { translate as t } from './i18n'
import { apiFetch } from './api'

export type AuditEventActor = {
  department?: string | null
  email?: string | null
  name?: string | null
  role?: string | null
  userId?: number | null
}

export type AuditEventResource = {
  documentId?: number | null
  id?: number | null
  revisionId?: number | null
  type?: string | null
}

export type AuditEventRequestContext = {
  id?: string | null
  ipAddress?: string | null
  userAgent?: string | null
}

export type AuditEventSummary = {
  actor: AuditEventActor
  eventType: string
  id: string
  metadata: Record<string, unknown>
  occurredAt?: string | null
  outcome: 'denied' | 'failure' | 'success'
  request: AuditEventRequestContext
  resource: AuditEventResource
}

export type AuditEventCategory = 'account' | 'admin' | 'auth' | 'document' | 'security' | 'vcs'

export type AuditEventPagination = {
  hasNextPage: boolean
  hasPreviousPage: boolean
  limit: number
  page: number
  total: number
  totalPages: number
}

export type AuditEventListResponse = {
  events: AuditEventSummary[]
  message: string
  pagination: AuditEventPagination
}

export type AuditEventQuery = {
  category?: AuditEventCategory
  department?: string
  limit?: number
  outcome?: AuditEventSummary['outcome']
  page?: number
  q?: string
}

const DEFAULT_AUDIT_LIMIT = 25

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null

const asNumber = (value: unknown, fallback: number) =>
  typeof value === 'number' && Number.isFinite(value) ? value : fallback

const asPositiveInteger = (value: unknown, fallback: number) => {
  if (typeof value === 'number' && Number.isInteger(value) && value > 0) {
    return value
  }

  return fallback
}

const asBoolean = (value: unknown, fallback: boolean) =>
  typeof value === 'boolean' ? value : fallback

const asString = (value: unknown, fallback: string) =>
  typeof value === 'string' ? value : fallback

const asNullableString = (value: unknown) =>
  typeof value === 'string' ? value : null

const asNullableNumber = (value: unknown) =>
  typeof value === 'number' && Number.isFinite(value) ? value : null

const asMetadata = (value: unknown): Record<string, unknown> =>
  isRecord(value) ? value : {}

const asOutcome = (value: unknown): AuditEventSummary['outcome'] =>
  value === 'denied' || value === 'failure' || value === 'success'
    ? value
    : 'failure'

const normalizeAuditEvent = (
  value: unknown,
  index: number,
): AuditEventSummary => {
  const event = isRecord(value) ? value : {}
  const actor = isRecord(event.actor) ? event.actor : {}
  const request = isRecord(event.request) ? event.request : {}
  const resource = isRecord(event.resource) ? event.resource : {}

  return {
    actor: {
      department: asNullableString(actor.department),
      email: asNullableString(actor.email),
      name: asNullableString(actor.name),
      role: asNullableString(actor.role),
      userId: asNullableNumber(actor.userId),
    },
    eventType: asString(event.eventType, 'audit.unknown'),
    id: String(event.id ?? `unknown-${index}`),
    metadata: asMetadata(event.metadata),
    occurredAt: asNullableString(event.occurredAt),
    outcome: asOutcome(event.outcome),
    request: {
      id: asNullableString(request.id),
      ipAddress: asNullableString(request.ipAddress),
      userAgent: asNullableString(request.userAgent),
    },
    resource: {
      documentId: asNullableNumber(resource.documentId),
      id: asNullableNumber(resource.id),
      revisionId: asNullableNumber(resource.revisionId),
      type: asNullableString(resource.type),
    },
  }
}

const normalizePagination = (
  rawPagination: unknown,
  rawMeta: unknown,
  query: AuditEventQuery,
): AuditEventPagination => {
  const pagination = isRecord(rawPagination) ? rawPagination : {}
  const meta = isRecord(rawMeta) ? rawMeta : {}
  const limit = asPositiveInteger(
    pagination.limit ?? meta.per_page,
    query.limit ?? DEFAULT_AUDIT_LIMIT,
  )
  const page = asPositiveInteger(
    pagination.page ?? meta.current_page,
    query.page ?? 1,
  )
  const total = Math.max(0, asNumber(pagination.total ?? meta.total, 0))
  const totalPages = Math.max(
    1,
    asPositiveInteger(
      pagination.totalPages ?? meta.last_page,
      Math.ceil(total / Math.max(1, limit)) || 1,
    ),
  )

  return {
    hasNextPage: asBoolean(pagination.hasNextPage, page < totalPages),
    hasPreviousPage: asBoolean(pagination.hasPreviousPage, page > 1),
    limit,
    page,
    total,
    totalPages,
  }
}

const normalizeAuditEventListResponse = (
  value: unknown,
  query: AuditEventQuery,
): AuditEventListResponse => {
  const response = isRecord(value) ? value : {}
  const rawEvents = Array.isArray(response.events)
    ? response.events
    : Array.isArray(response.data)
      ? response.data
      : []

  return {
    events: rawEvents.map(normalizeAuditEvent),
    message: asString(response.message, t("Audit events loaded successfully.", "Eventos de auditoría cargados correctamente.")),
    pagination: normalizePagination(response.pagination, response.meta, query),
  }
}

export async function getAuditEvents({
  category,
  department,
  limit = DEFAULT_AUDIT_LIMIT,
  outcome,
  page = 1,
  q,
}: AuditEventQuery = {}): Promise<AuditEventListResponse> {
  const query = {
    category,
    department,
    limit,
    outcome,
    page,
    q,
  }
  const params = new URLSearchParams({
    limit: String(limit),
    page: String(page),
  })

  if (category) {
    params.set('category', category)
  }

  if (outcome) {
    params.set('outcome', outcome)
  }

  if (department) {
    params.set('department', department)
  }

  if (q?.trim()) {
    params.set('q', q.trim())
  }

  const response = await apiFetch<unknown>(`/audit-events?${params.toString()}`)

  return normalizeAuditEventListResponse(response, query)
}
