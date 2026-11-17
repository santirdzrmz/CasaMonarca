import { useState, type FormEvent } from 'react'

import { translate as t } from '../../lib/i18n'
import { ApiRequestError, recordRegistryExit, undoRegistryExit, type RegistryEntry } from '../../lib/registry'
import { formatRegistryDate } from '../../lib/registryDisplay'
import { AppIcon } from '../ui/AppIcon'

export const MIN_EXIT_REASON_LENGTH = 5

const todayIso = () => {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

type ShelterExitDialogProps = {
  entry: RegistryEntry
  entryName: string
  /** "record" asks for the exit date and why; "undo" confirms removing a recorded exit. */
  mode: 'record' | 'undo'
  onClose: () => void
  onSaved: (entry: RegistryEntry) => void
  onSessionExpired?: () => void
}

/**
 * Pop-up to record a person's exit from the shelter (egreso), or to undo one recorded by mistake.
 * The reason is mandatory: staff must explain why the person left.
 */
export function ShelterExitDialog({ entry, entryName, mode, onClose, onSaved, onSessionExpired }: ShelterExitDialogProps) {
  const [exitedAt, setExitedAt] = useState(todayIso)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  const titleId = `shelter-exit-title-${entry.id}`
  const reasonTooShort = reason.trim().length < MIN_EXIT_REASON_LENGTH

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    if (mode === 'record' && (reasonTooShort || exitedAt === '')) {
      setError(t('Enter the exit date and explain why the person left.', 'Indica la fecha de egreso y explica por qué salió la persona.'))
      return
    }

    setIsSaving(true)
    setError(null)

    try {
      const response = mode === 'record'
        ? await recordRegistryExit(entry.id, exitedAt, reason.trim())
        : await undoRegistryExit(entry.id)
      onSaved(response.data)
    } catch (caught: unknown) {
      if (caught instanceof ApiRequestError && caught.status === 401) {
        onSessionExpired?.()
        return
      }

      setError(
        caught instanceof ApiRequestError && caught.status === 409
          ? t('The registration changed. Refresh the list and try again.', 'El registro cambió. Actualiza la lista e inténtalo de nuevo.')
          : caught instanceof ApiRequestError && caught.status === 422
            ? t(caught.message, 'Revisa la fecha: no puede ser futura ni anterior a la fecha de atención.')
            : caught instanceof Error
              ? caught.message
              : t('Unable to save the shelter exit.', 'No se pudo guardar el egreso.'),
      )
      setIsSaving(false)
    }
  }

  return (
    <div
      aria-labelledby={titleId}
      aria-modal="true"
      className="confirmation-modal"
      onClick={(event) => {
        if (event.target === event.currentTarget && !isSaving) {
          onClose()
        }
      }}
      onKeyDown={(event) => {
        if (event.key === 'Escape' && !isSaving) {
          onClose()
        }
      }}
      role="dialog"
    >
      <form className="confirmation-modal__surface shelter-exit" onSubmit={(event) => void submit(event)}>
        <div>
          <h3 id={titleId}>
            {mode === 'record' ? t('Record shelter exit', 'Registrar egreso del albergue') : t('Undo shelter exit', 'Deshacer egreso')}
          </h3>
          <p>
            <strong>{entryName}</strong>{' '}
            {mode === 'record'
              ? t('will be marked as having left the shelter. The reason is only visible to admins and coordinators.', 'quedará marcado como egresado del albergue. El motivo solo lo ven administradores y coordinadores.')
              : t(`will be shown as in the shelter again. The exit recorded on ${formatRegistryDate(entry.exited_at)} and its reason will be removed.`, `volverá a aparecer en el albergue. Se borrará el egreso del ${formatRegistryDate(entry.exited_at)} y su motivo.`)}
          </p>
        </div>

        {mode === 'record' ? (
          <div className="shelter-exit__fields">
            <label className="shelter-exit__field">
              <span>{t('Exit date', 'Fecha de egreso')}</span>
              <input
                max={todayIso()}
                onChange={(event) => setExitedAt(event.target.value)}
                required
                type="date"
                value={exitedAt}
              />
            </label>
            <label className="shelter-exit__field">
              <span>{t('Why did the person leave?', '¿Por qué salió la persona?')}</span>
              <textarea
                autoFocus
                maxLength={1000}
                onChange={(event) => setReason(event.target.value)}
                placeholder={t('E.g. continued the journey to their final destination.', 'Ej. continuó su viaje hacia su destino final.')}
                required
                rows={4}
                value={reason}
              />
              <small>{t(`Required, at least ${MIN_EXIT_REASON_LENGTH} characters.`, `Obligatorio, mínimo ${MIN_EXIT_REASON_LENGTH} caracteres.`)}</small>
            </label>
          </div>
        ) : null}

        {error ? <div className="login-feedback login-feedback--error" role="alert">{error}</div> : null}

        <div className="confirmation-modal__actions">
          <button
            autoFocus={mode === 'undo'}
            className="session-action session-action--quiet"
            disabled={isSaving}
            onClick={onClose}
            type="button"
          >
            {t('Cancel', 'Cancelar')}
          </button>
          <button
            className="session-action"
            disabled={isSaving || (mode === 'record' && (reasonTooShort || exitedAt === ''))}
            type="submit"
          >
            <AppIcon name={mode === 'record' ? 'document' : 'refresh'} />
            {isSaving
              ? t('Saving...', 'Guardando...')
              : mode === 'record' ? t('Record exit', 'Registrar egreso') : t('Undo exit', 'Deshacer egreso')}
          </button>
        </div>
      </form>
    </div>
  )
}
