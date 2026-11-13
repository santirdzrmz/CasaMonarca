import { useCallback, useEffect, useState } from 'react'

import { ApiRequestError } from '../../lib/api'
import { translate as t } from '../../lib/i18n'
import {
  denyAccess,
  grantAccess,
  listAccessRequests,
  revokeAccess,
  type PendingAccessRequest,
} from '../../lib/registryAccess'
import { getRegistryKeyState } from '../../lib/registryKeys'
import { KeyUnlockPanel } from './KeyUnlockPanel'

type CoordinatorAccessPanelProps = {
  onSessionExpired?: () => void
}

const recordLabel = (request: PendingAccessRequest) =>
  `${request.resource_type === 'arco' ? 'ARCO' : t('Registration', 'Registro')} #${request.resource_id}`

/** Coordinator or admin: decides on operators' requests for temporary access. Granting needs unlocked keys. */
export function CoordinatorAccessPanel({ onSessionExpired }: CoordinatorAccessPanelProps) {
  const [enabled, setEnabled] = useState(false)
  const [pending, setPending] = useState<PendingAccessRequest[]>([])
  const [active, setActive] = useState<PendingAccessRequest[]>([])
  const [fingerprints, setFingerprints] = useState<Record<number, string>>({})
  const [minutes, setMinutes] = useState(60)
  const [needsUnlock, setNeedsUnlock] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleError = useCallback(
    (caught: unknown) => {
      if (caught instanceof ApiRequestError && caught.status === 401) {
        onSessionExpired?.()
        return
      }
      if (caught instanceof ApiRequestError && caught.status === 423) {
        setNeedsUnlock(true)
        setError(null)
        return
      }
      setError(caught instanceof Error ? caught.message : t('The action could not be completed.', 'No se pudo completar la acción.'))
    },
    [onSessionExpired],
  )

  const load = useCallback(async () => {
    try {
      const [requested, granted] = await Promise.all([listAccessRequests('requested'), listAccessRequests('granted')])
      setPending(requested)
      setActive(granted)
    } catch (loadError) {
      handleError(loadError)
    }
  }, [handleError])

  useEffect(() => {
    let alive = true
    getRegistryKeyState()
      .then((state) => {
        if (alive && state.mode === 'publickey') {
          setEnabled(true)
          void load()
        }
      })
      .catch(() => undefined)
    return () => {
      alive = false
    }
  }, [load])

  if (!enabled) {
    return null
  }

  const act = async (action: () => Promise<unknown>) => {
    setError(null)
    try {
      await action()
      await load()
    } catch (actionError) {
      handleError(actionError)
    }
  }

  return (
    <section className="workspace-panel">
      <h2 className="workspace-panel__title">{t('Temporary access requests', 'Solicitudes de acceso temporal')}</h2>
      <p className="workspace-panel__copy">
        {t(
          'Before granting, ask the operator to read you their session fingerprint and check that it matches the one shown here.',
          'Antes de conceder, pide al operador que te lea su huella de sesión y comprueba que coincide con la que aparece aquí.',
        )}
      </p>
      {needsUnlock ? <KeyUnlockPanel onSessionExpired={onSessionExpired} onUnlocked={() => setNeedsUnlock(false)} /> : null}
      {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
      {pending.length === 0 ? <p>{t('No pending requests.', 'No hay solicitudes pendientes.')}</p> : null}
      {pending.map((request) => (
        <div className="workspace-actions" key={request.id}>
          <span>
            {request.operator_name} · {recordLabel(request)}
            {request.reason ? ` · ${request.reason}` : ''} · {t('fingerprint', 'huella')} <strong>{request.fingerprint}</strong>
          </span>
          <input
            aria-label={`${t('Confirm fingerprint', 'Confirmar huella')} ${request.id}`}
            className="login-field__input"
            placeholder={t('Fingerprint they read to you', 'Huella que te leyó')}
            value={fingerprints[request.id] ?? ''}
            onChange={(event) => setFingerprints((current) => ({ ...current, [request.id]: event.target.value }))}
          />
          <select aria-label={t('Duration', 'Duración')} className="login-field__input" value={minutes} onChange={(event) => setMinutes(Number(event.target.value))}>
            {[30, 60, 120, 240].map((value) => (
              <option key={value} value={value}>
                {value} min
              </option>
            ))}
          </select>
          <button className="session-action" onClick={() => void act(() => grantAccess(request.id, fingerprints[request.id] ?? '', minutes))} type="button">
            {t('Grant', 'Conceder')}
          </button>
          <button className="session-action session-action--quiet" onClick={() => void act(() => denyAccess(request.id))} type="button">
            {t('Deny', 'Denegar')}
          </button>
        </div>
      ))}
      {active.length > 0 ? <h3>{t('Active accesses', 'Accesos activos')}</h3> : null}
      {active.map((request) => (
        <div className="workspace-actions" key={request.id}>
          <span>
            {request.operator_name} · {recordLabel(request)} · {t('until', 'hasta')} {request.expires_at}
          </span>
          <button className="session-action session-action--quiet" onClick={() => void act(() => revokeAccess(request.id))} type="button">
            {t('Revoke', 'Revocar')}
          </button>
        </div>
      ))}
    </section>
  )
}
