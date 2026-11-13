import { useCallback, useEffect, useState, type FormEvent } from 'react'

import { ApiRequestError } from '../../lib/api'
import { translate as t } from '../../lib/i18n'
import {
  getOperatorAccess,
  requestAccess,
  type AccessRequest,
  type AccessResource,
  type OperatorAccessState,
} from '../../lib/registryAccess'

type OperatorAccessPanelProps = {
  onSessionExpired?: () => void
}

const statusLabel = (request: AccessRequest) => {
  switch (request.status) {
    case 'granted':
      return t(`Granted until ${request.expires_at ?? ''}`, `Concedido hasta ${request.expires_at ?? ''}`)
    case 'denied':
      return t('Denied', 'Denegado')
    case 'revoked':
      return t('Revoked', 'Revocado')
    default:
      return t('Waiting for a coordinator', 'Esperando a un coordinador')
  }
}

/** Operator: shows the session fingerprint and requests temporary access to a single record. */
export function OperatorAccessPanel({ onSessionExpired }: OperatorAccessPanelProps) {
  const [state, setState] = useState<OperatorAccessState | null>(null)
  const [resource, setResource] = useState<AccessResource>('registry')
  const [recordId, setRecordId] = useState('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setState(await getOperatorAccess())
    } catch (loadError) {
      if (loadError instanceof ApiRequestError && loadError.status === 401) {
        onSessionExpired?.()
        return
      }
      setState(null)
    }
  }, [onSessionExpired])

  useEffect(() => {
    void load()
  }, [load])

  if (!state || state.mode !== 'publickey') {
    return null
  }

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const id = Number.parseInt(recordId, 10)
    if (!Number.isInteger(id) || id < 1) {
      setError(t('Enter a valid record number.', 'Escribe un número de registro válido.'))
      return
    }
    setBusy(true)
    setError(null)
    try {
      await requestAccess(resource, id, reason.trim())
      setRecordId('')
      setReason('')
      await load()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : t('The request could not be sent.', 'No se pudo enviar la solicitud.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="workspace-panel">
      <h2 className="workspace-panel__title">{t('Temporary access to a record', 'Acceso temporal a un registro')}</h2>
      <p className="workspace-panel__copy">
        {t(
          'The content of registrations is encrypted. To review one, ask a coordinator for temporary access and read them your session fingerprint so they can confirm it is you: ',
          'El contenido de los registros está cifrado. Para revisar uno, pide un acceso temporal a un coordinador y dile tu huella de sesión para que confirme que eres tú: ',
        )}
        <strong>{state.fingerprint}</strong>
      </p>
      <form className="workspace-actions" onSubmit={(event) => void handleSubmit(event)}>
        <label className="login-field">
          <span className="login-field__label">{t('Type', 'Tipo')}</span>
          <select className="login-field__input" value={resource} onChange={(event) => setResource(event.target.value as AccessResource)}>
            <option value="registry">{t('Registration', 'Registro')}</option>
            <option value="arco">ARCO</option>
          </select>
        </label>
        <label className="login-field">
          <span className="login-field__label">{t('Record number', 'Número de registro')}</span>
          <input className="login-field__input" inputMode="numeric" value={recordId} onChange={(event) => setRecordId(event.target.value)} />
        </label>
        <label className="login-field">
          <span className="login-field__label">{t('Reason (optional)', 'Motivo (opcional)')}</span>
          <input className="login-field__input" maxLength={255} value={reason} onChange={(event) => setReason(event.target.value)} />
        </label>
        <button className="session-action" disabled={busy} type="submit">
          {busy ? t('Sending…', 'Enviando…') : t('Request access', 'Solicitar acceso')}
        </button>
      </form>
      {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
      <ul>
        {(state.requests ?? []).map((request) => (
          <li key={request.id}>
            {request.resource_type === 'arco' ? 'ARCO' : t('Registration', 'Registro')} #{request.resource_id}: {statusLabel(request)}
          </li>
        ))}
      </ul>
    </section>
  )
}
