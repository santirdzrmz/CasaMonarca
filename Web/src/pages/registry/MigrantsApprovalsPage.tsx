import { translate as t } from '../../lib/i18n'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { AppIcon } from '../../components/ui/AppIcon'
import { MigrantQuestionnaireViewer } from '../../components/registry/MigrantQuestionnaireViewer'
import type { AuthenticatedUser } from '../../lib/auth'
import { CoordinatorAccessPanel } from '../../components/registry/CoordinatorAccessPanel'
import { OperatorAccessPanel } from '../../components/registry/OperatorAccessPanel'
import { formatRegistryValue } from '../../lib/registryDisplay'
import {
  ApiRequestError,
  getPendingRegistryApprovals,
  getPendingRegistryReviews,
  returnRegistryForCorrections,
  startRegistryApproval,
  startRegistryBulkApproval,
  startRegistryReview,
  type RegistryApprovalDecision,
  type RegistryEntry,
  verifyRegistryApproval,
  verifyRegistryBulkApproval,
  verifyRegistryReview,
} from '../../lib/registry'
import { cancelSecurityChallenge } from '../../lib/securityChallenges'
import { getWebauthnAssertion, isIpHostname } from '../../lib/webauthn'

type MigrantsApprovalsPageProps = {
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type QueueAction = 'approve' | 'forward' | 'reject' | 'return'

type ActionState = {
  entryId: number
  action: QueueAction
} | null

const canApprove = (role: AuthenticatedUser['role']) =>
  role === 'admin' || role === 'coordinator'

const getApprovalPayload = (entry: RegistryEntry) =>
  entry.pending_action === 'update' && entry.pending_payload_json
    ? entry.pending_payload_json
    : entry.payload_json

const formatEntryName = (entry: RegistryEntry) => {
  const payload = getApprovalPayload(entry)

  return String(payload.fullName || payload.full_name || t(`Registration #${entry.id}`, `Registro #${entry.id}`))
}

const formatEntrySubtitle = (entry: RegistryEntry) => {
  const payload = getApprovalPayload(entry)
  const country = formatRegistryValue(payload.countryOfOrigin, t('Country unavailable', 'País no disponible'))
  const group = formatRegistryValue(payload.populationGroup, t('Population group unavailable', 'Grupo poblacional no disponible'))

  return `${country} · ${group}`
}

const getLocalDateValue = (dateValue: string) => {
  const date = new Date(dateValue)

  if (Number.isNaN(date.getTime())) {
    return ''
  }

  const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)

  return localDate.toISOString().slice(0, 10)
}

const ensurePasskeySupport = () => {
  if (!window.isSecureContext || !('PublicKeyCredential' in window)) {
    return t('A passkey action requires a secure context and supported browser.', 'Esta acción con llave de acceso requiere un contexto seguro y un navegador compatible.')
  }

  if (isIpHostname(window.location.hostname)) {
    return t('Passkey actions require localhost or a domain name, not an IP address.', 'Las acciones con llave de acceso requieren localhost o un nombre de dominio, no una dirección IP.')
  }

  return null
}

export function MigrantsApprovalsPage({ onSessionExpired, user }: MigrantsApprovalsPageProps) {
  const [reviewEntries, setReviewEntries] = useState<RegistryEntry[]>([])
  const [approvalEntries, setApprovalEntries] = useState<RegistryEntry[]>([])
  const [isReviewsLoading, setIsReviewsLoading] = useState(true)
  const [isApprovalsLoading, setIsApprovalsLoading] = useState(canApprove(user.role))
  const [reviewError, setReviewError] = useState<string | null>(null)
  const [approvalError, setApprovalError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [actionState, setActionState] = useState<ActionState>(null)
  const [approvalDateFrom, setApprovalDateFrom] = useState('')
  const [approvalDateTo, setApprovalDateTo] = useState('')
  const [approvalTypeFilter, setApprovalTypeFilter] = useState<'all' | 'create' | 'update'>('all')
  const [selectedApprovalIds, setSelectedApprovalIds] = useState<Set<number>>(() => new Set())
  const [isBulkApproving, setIsBulkApproving] = useState(false)
  const selectAllRef = useRef<HTMLInputElement>(null)

  const loadReviews = useCallback(async () => {
    setIsReviewsLoading(true)
    setReviewError(null)

    try {
      const response = await getPendingRegistryReviews()
      setReviewEntries(response.data)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setReviewError(error instanceof Error ? error.message : t('Unable to load pending migrant reviews.', 'No se pudieron cargar las revisiones pendientes.'))
    } finally {
      setIsReviewsLoading(false)
    }
  }, [onSessionExpired])

  const loadApprovals = useCallback(async () => {
    if (!canApprove(user.role)) {
      setApprovalEntries([])
      setIsApprovalsLoading(false)
      return
    }

    setIsApprovalsLoading(true)
    setApprovalError(null)

    try {
      const response = await getPendingRegistryApprovals()
      setApprovalEntries(response.data)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setApprovalError(error instanceof Error ? error.message : t('Unable to load pending migrant approvals.', 'No se pudieron cargar las aprobaciones pendientes.'))
    } finally {
      setIsApprovalsLoading(false)
    }
  }, [onSessionExpired, user.role])

  const refreshQueues = useCallback(async () => {
    await Promise.all([loadReviews(), loadApprovals()])
  }, [loadApprovals, loadReviews])

  useEffect(() => {
    void refreshQueues()
  }, [refreshQueues])

  const filteredApprovalEntries = useMemo(() => approvalEntries.filter((entry) => {
    const queuedDate = getLocalDateValue(entry.updated_at)

    return (
      (approvalTypeFilter === 'all' || entry.pending_action === approvalTypeFilter) &&
      (approvalDateFrom === '' || queuedDate >= approvalDateFrom) &&
      (approvalDateTo === '' || queuedDate <= approvalDateTo)
    )
  }), [approvalDateFrom, approvalDateTo, approvalEntries, approvalTypeFilter])
  const allFilteredApprovalsSelected = filteredApprovalEntries.length > 0 &&
    filteredApprovalEntries.every((entry) => selectedApprovalIds.has(entry.id))
  const someFilteredApprovalsSelected = filteredApprovalEntries.some((entry) =>
    selectedApprovalIds.has(entry.id),
  )

  useEffect(() => {
    if (selectAllRef.current) {
      selectAllRef.current.indeterminate = someFilteredApprovalsSelected && !allFilteredApprovalsSelected
    }
  }, [allFilteredApprovalsSelected, someFilteredApprovalsSelected])

  useEffect(() => {
    const availableIds = new Set(approvalEntries.map((entry) => entry.id))

    setSelectedApprovalIds((current) => {
      const next = new Set([...current].filter((entryId) => availableIds.has(entryId)))

      return next.size === current.size ? current : next
    })
  }, [approvalEntries])

  const clearApprovalFilters = () => {
    setApprovalDateFrom('')
    setApprovalDateTo('')
    setApprovalTypeFilter('all')
    setSelectedApprovalIds(new Set())
  }

  const toggleApprovalSelection = (entryId: number) => {
    setSelectedApprovalIds((current) => {
      const next = new Set(current)

      if (next.has(entryId)) {
        next.delete(entryId)
      } else {
        next.add(entryId)
      }

      return next
    })
  }

  const toggleAllFilteredApprovals = () => {
    setSelectedApprovalIds((current) => {
      const next = new Set(current)

      if (allFilteredApprovalsSelected) {
        filteredApprovalEntries.forEach((entry) => next.delete(entry.id))
      } else {
        filteredApprovalEntries.forEach((entry) => next.add(entry.id))
      }

      return next
    })
  }

  const handleReviewForward = async (entry: RegistryEntry) => {
    const supportError = ensurePasskeySupport()

    if (supportError) {
      setReviewError(supportError)
      return
    }

    setActionState({ entryId: entry.id, action: 'forward' })
    setReviewError(null)
    setMessage(null)
    let challengeIntentId: string | null = null

    try {
      const reason = window.prompt(t("Optional review note", "Nota de revisión opcional"))?.trim() || undefined
      const optionsResponse = await startRegistryReview(entry.id, { reason })
      challengeIntentId = optionsResponse.challengeIntent.id
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      await verifyRegistryReview(entry.id, assertion)
      setMessage(t('Registration reviewed and forwarded for approval.', 'Registro revisado y enviado a aprobación.'))
      await refreshQueues()
    } catch (error) {
      if (challengeIntentId && error instanceof DOMException && error.name === 'NotAllowedError') {
        await cancelSecurityChallenge(challengeIntentId)
      }

      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setReviewError(
        error instanceof Error && error.name === 'NotAllowedError'
          ? t("Passkey review was cancelled.", "Se canceló la revisión con llave de acceso.")
          : error instanceof Error
            ? error.message
            : t("Unable to forward the migrant registration for approval.", "No se pudo enviar el registro de migrante a aprobación."),
      )
    } finally {
      setActionState(null)
    }
  }

  const handleReviewReturn = async (entry: RegistryEntry) => {
    const reason = window.prompt(t("Required correction notes", "Notas de corrección obligatorias"))?.trim()

    if (!reason) {
      return
    }

    setActionState({ entryId: entry.id, action: 'return' })
    setReviewError(null)
    setMessage(null)

    try {
      await returnRegistryForCorrections(entry.id, reason)
      setMessage(t('Registration returned for corrections.', 'Registro devuelto para correcciones.'))
      await refreshQueues()
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setReviewError(error instanceof Error ? error.message : t("Unable to return the registration for corrections.", "No se pudo devolver el registro para correcciones."))
    } finally {
      setActionState(null)
    }
  }

  const handleApprovalDecision = async (entry: RegistryEntry, decision: RegistryApprovalDecision) => {
    const supportError = ensurePasskeySupport()

    if (supportError) {
      setApprovalError(supportError)
      return
    }

    const reason = decision === 'reject' ? window.prompt(t("Rejection reason", "Motivo de rechazo"))?.trim() : undefined

    if (decision === 'reject' && !reason) {
      return
    }

    setActionState({ entryId: entry.id, action: decision })
    setApprovalError(null)
    setMessage(null)
    let challengeIntentId: string | null = null

    try {
      const optionsResponse = await startRegistryApproval(entry.id, { decision, reason })
      challengeIntentId = optionsResponse.challengeIntent.id
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      await verifyRegistryApproval(entry.id, assertion)
      setMessage(decision === 'approve'
        ? t('Registration approved.', 'Registro aprobado.')
        : t('Registration rejected.', 'Registro rechazado.'))
      await refreshQueues()
    } catch (error) {
      if (challengeIntentId && error instanceof DOMException && error.name === 'NotAllowedError') {
        await cancelSecurityChallenge(challengeIntentId)
      }

      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setApprovalError(
        error instanceof Error && error.name === 'NotAllowedError'
          ? t("Passkey approval was cancelled.", "Se canceló la aprobación con llave de acceso.")
          : error instanceof Error
            ? error.message
            : t("Unable to complete the approval decision.", "No se pudo completar la decisión de aprobación."),
      )
    } finally {
      setActionState(null)
    }
  }

  const handleBulkApproval = async () => {
    const supportError = ensurePasskeySupport()

    if (supportError) {
      setApprovalError(supportError)
      return
    }

    const entryIds = [...selectedApprovalIds].sort((left, right) => left - right)

    if (entryIds.length === 0 || !window.confirm(t(`Approve ${entryIds.length} selected registrations?`, `¿Aprobar ${entryIds.length} registros seleccionados?`))) {
      return
    }

    setIsBulkApproving(true)
    setApprovalError(null)
    setMessage(null)
    let challengeIntentId: string | null = null

    try {
      const optionsResponse = await startRegistryBulkApproval(entryIds)
      challengeIntentId = optionsResponse.challengeIntent.id
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      await verifyRegistryBulkApproval(assertion)
      setMessage(t(`${entryIds.length} registrations approved.`, `Se aprobaron ${entryIds.length} registros.`))
      setSelectedApprovalIds(new Set())
      await refreshQueues()
    } catch (error) {
      if (challengeIntentId && error instanceof DOMException && error.name === 'NotAllowedError') {
        await cancelSecurityChallenge(challengeIntentId)
      }

      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setApprovalError(
        error instanceof Error && error.name === 'NotAllowedError'
          ? t("Bulk passkey approval was cancelled.", "Se canceló la aprobación masiva con llave de acceso.")
          : error instanceof Error
            ? error.message
            : t("Unable to approve the selected registrations.", "No se pudieron aprobar los registros seleccionados."),
      )
    } finally {
      setIsBulkApproving(false)
    }
  }

  return (
    <section className="workspace-stack">
      {user.role === 'non_coordinator' ? <OperatorAccessPanel onSessionExpired={onSessionExpired} /> : null}
      {canApprove(user.role) ? <CoordinatorAccessPanel onSessionExpired={onSessionExpired} /> : null}
      <section className="workspace-panel dashboard-signature-queue">
        <div className="dashboard-signature-queue__header">
          <div>
            <h2 className="workspace-panel__title">{t("Registrations pending review", "Registros pendientes de revisión")}</h2>
            <p className="workspace-panel__copy">
              {t("Forward reviewed registrations with your passkey, or return them to the original submitter for correction. ", "Envía registros revisados con tu llave de acceso o devuélvelos a quien los envió para corrección. ")}</p>
          </div>
          <button className="session-action session-action--quiet" disabled={isReviewsLoading} onClick={() => void refreshQueues()} type="button">
            <AppIcon name="refresh" />
            {isReviewsLoading ? t("Refreshing...", "Actualizando...") : t("Refresh", "Actualizar")}
          </button>
        </div>

        {reviewError ? <div className="login-feedback login-feedback--error">{reviewError}</div> : null}
        {message ? <div className="login-feedback login-feedback--success">{message}</div> : null}
        {!isReviewsLoading && reviewEntries.length === 0 && !reviewError ? (
          <p className="workspace-panel__copy">{t("There are no migrant registrations pending review.", "No hay registros de migrantes pendientes de revisión.")}</p>
        ) : null}

        <div className="signature-queue-list">
          {reviewEntries.map((entry) => {
            const isBusy = actionState?.entryId === entry.id

            return (
              <article className="signature-queue-card registry-approval-card" key={entry.id}>
                <div>
                  <strong>{formatEntryName(entry)}</strong>
                  <span>{formatEntrySubtitle(entry)}</span>
                  <small>{t("Submitted ", "Enviado ")}{new Date(entry.created_at).toLocaleString()} {t("by ", "por ")}{entry.creator?.email ?? entry.created_by_role}</small>
                  <details className="registry-approval-card__details"><summary>{t('View full questionnaire', 'Ver cuestionario completo')}</summary><MigrantQuestionnaireViewer payload={getApprovalPayload(entry)} /></details>
                </div>
                <div className="registry-approval-card__actions">
                  <button className="session-action session-action--quiet" disabled={isBusy} onClick={() => void handleReviewReturn(entry)} type="button">
                    <AppIcon name="document" />
                    {isBusy && actionState?.action === 'return' ? t("Returning...", "Devolviendo...") : t("Request corrections", "Solicitar correcciones")}
                  </button>
                  <button className="session-action" disabled={isBusy} onClick={() => void handleReviewForward(entry)} type="button">
                    <AppIcon name="verify" />
                    {isBusy && actionState?.action === 'forward' ? t("Forwarding...", "Enviando...") : t("Forward to approval", "Enviar a aprobación")}
                  </button>
                </div>
              </article>
            )
          })}
        </div>
      </section>

      {canApprove(user.role) ? (
        <section className="workspace-panel dashboard-signature-queue">
          <div className="dashboard-signature-queue__header">
            <div>
              <h2 className="workspace-panel__title">{t("Registrations pending final approval", "Registros pendientes de aprobación final")}</h2>
              <p className="workspace-panel__copy">{t("Coordinator/admin decisions require the reviewer passkey.", "Las decisiones de coordinación/administración requieren la llave de acceso de la persona revisora.")}</p>
            </div>
            <button className="session-action session-action--quiet" disabled={isApprovalsLoading} onClick={() => void refreshQueues()} type="button">
              <AppIcon name="refresh" />
              {isApprovalsLoading ? t("Refreshing...", "Actualizando...") : t("Refresh", "Actualizar")}
            </button>
          </div>

          <div aria-label={t("Final approval filters", "Filtros de aprobación final")} className="audit-controls registry-approval-filters">
            <label className="audit-control">
              <span>{t("Queued from", "En fila desde")}</span>
              <input
                max={approvalDateTo || undefined}
                onChange={(event) => {
                  setApprovalDateFrom(event.target.value)
                  setSelectedApprovalIds(new Set())
                }}
                type="date"
                value={approvalDateFrom}
              />
            </label>

            <label className="audit-control">
              <span>{t("Queued through", "En fila hasta")}</span>
              <input
                min={approvalDateFrom || undefined}
                onChange={(event) => {
                  setApprovalDateTo(event.target.value)
                  setSelectedApprovalIds(new Set())
                }}
                type="date"
                value={approvalDateTo}
              />
            </label>

            <label className="audit-control">
              <span>{t("Request type", "Tipo de solicitud")}</span>
              <select
                onChange={(event) => {
                  setApprovalTypeFilter(event.target.value as 'all' | 'create' | 'update')
                  setSelectedApprovalIds(new Set())
                }}
                value={approvalTypeFilter}
              >
                <option value="all">{t("All requests", "Todas las solicitudes")}</option>
                <option value="create">{t("New registrations", "Nuevos registros")}</option>
                <option value="update">{t("Modifications", "Modificaciones")}</option>
              </select>
            </label>

            <button
              className="audit-controls__reset"
              disabled={approvalDateFrom === '' && approvalDateTo === '' && approvalTypeFilter === 'all'}
              onClick={clearApprovalFilters}
              type="button"
            >
              {t("Clear filters ", "Limpiar filtros ")}</button>
          </div>

          <div className="registry-bulk-approval-toolbar">
            <label className="registry-bulk-approval-toolbar__select-all">
              <input
                checked={allFilteredApprovalsSelected}
                disabled={filteredApprovalEntries.length === 0 || isBulkApproving}
                onChange={toggleAllFilteredApprovals}
                ref={selectAllRef}
                type="checkbox"
              />
              <span>{t("Select all filtered (", "Seleccionar todos los filtrados (")}{filteredApprovalEntries.length})</span>
            </label>
            <span className="registry-bulk-approval-toolbar__count">
              {selectedApprovalIds.size} {t("selected ", "seleccionados ")}</span>
            <button
              className="session-action"
              disabled={selectedApprovalIds.size === 0 || isBulkApproving || actionState !== null}
              onClick={() => void handleBulkApproval()}
              type="button"
            >
              <AppIcon name="verify" />
              {isBulkApproving
                ? t("Approving selected...", "Aprobando seleccionados...")
                : t(`Approve selected (${selectedApprovalIds.size})`, `Aprobar seleccionados (${selectedApprovalIds.size})`)}
            </button>
          </div>

          {approvalError ? <div className="login-feedback login-feedback--error">{approvalError}</div> : null}
          {!isApprovalsLoading && approvalEntries.length === 0 && !approvalError ? (
            <p className="workspace-panel__copy">{t("There are no migrant registrations pending final approval.", "No hay registros de migrantes pendientes de aprobación final.")}</p>
          ) : null}
          {!isApprovalsLoading && approvalEntries.length > 0 && filteredApprovalEntries.length === 0 && !approvalError ? (
            <p className="workspace-panel__copy">{t("No pending registrations match the current filters.", "Ningún registro pendiente coincide con los filtros actuales.")}</p>
          ) : null}

          <div className="signature-queue-list">
            {filteredApprovalEntries.map((entry) => {
              const isBusy = actionState?.entryId === entry.id
              const isSelected = selectedApprovalIds.has(entry.id)

              return (
                <article className={`signature-queue-card registry-approval-card${isSelected ? ' registry-approval-card--selected' : ''}`} key={entry.id}>
                  <label className="registry-approval-card__selector">
                    <input
                      aria-label={t(`Select ${formatEntryName(entry)} for bulk approval`, `Seleccionar ${formatEntryName(entry)} para aprobación masiva`)}
                      checked={isSelected}
                      disabled={isBusy || isBulkApproving}
                      onChange={() => toggleApprovalSelection(entry.id)}
                      type="checkbox"
                    />
                  </label>
                  <div>
                    <strong>{formatEntryName(entry)}</strong>
                    <span>{formatEntrySubtitle(entry)}</span>
                    <span>{entry.pending_action === 'update' ? t("Modification", "Modificación") : t("New registration", "Nuevo registro")}</span>
                    <small>{t("Queued ", "En fila ")}{new Date(entry.updated_at).toLocaleString()} {t("by ", "por ")}{entry.creator?.email ?? entry.created_by_role}</small>
                    <details className="registry-approval-card__details"><summary>{t('View full questionnaire', 'Ver cuestionario completo')}</summary><MigrantQuestionnaireViewer payload={getApprovalPayload(entry)} /></details>
                  </div>
                  <div className="registry-approval-card__actions">
                    <button className="session-action session-action--quiet" disabled={isBusy || isBulkApproving} onClick={() => void handleApprovalDecision(entry, 'reject')} type="button">
                      <AppIcon name="delete" />
                      {isBusy && actionState?.action === 'reject' ? t("Rejecting...", "Rechazando...") : t("Reject", "Rechazar")}
                    </button>
                    <button className="session-action" disabled={isBusy || isBulkApproving} onClick={() => void handleApprovalDecision(entry, 'approve')} type="button">
                      <AppIcon name="verify" />
                      {isBusy && actionState?.action === 'approve' ? t("Approving...", "Aprobando...") : t("Approve", "Aprobar")}
                    </button>
                  </div>
                </article>
              )
            })}
          </div>
        </section>
      ) : null}
    </section>
  )
}
