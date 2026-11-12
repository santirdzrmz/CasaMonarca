import { useState, type FormEvent } from 'react'

import { ApiRequestError } from '../../lib/api'
import { translate as t } from '../../lib/i18n'
import { unlockRegistryKeys } from '../../lib/registryKeys'

const readFileText = (file: File): Promise<string> =>
  typeof file.text === 'function'
    ? file.text()
    : new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => resolve(String(reader.result ?? ''))
        reader.onerror = () => reject(reader.error ?? new Error('Could not read the key file.'))
        reader.readAsText(file)
      })

type KeyUnlockPanelProps = {
  onSessionExpired?: () => void
  onUnlocked: () => void
}

/**
 * Pide el archivo de llave personal y su contraseña para abrir las llaves del registro en esta sesión.
 * El archivo se lee en el navegador y solo se envía a /registry/keys/unlock; no se guarda.
 */
export function KeyUnlockPanel({ onSessionExpired, onUnlocked }: KeyUnlockPanelProps) {
  const [keyFile, setKeyFile] = useState<string | null>(null)
  const [fileName, setFileName] = useState('')
  const [passphrase, setPassphrase] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const handleFile = async (file: File | undefined) => {
    setError(null)
    if (!file) {
      setKeyFile(null)
      setFileName('')
      return
    }
    setFileName(file.name)
    setKeyFile(await readFileText(file))
  }

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!keyFile || !passphrase) {
      setError(t('Choose your key file and enter its passphrase.', 'Elige tu archivo de llave y escribe su contraseña.'))
      return
    }

    setBusy(true)
    setError(null)
    try {
      await unlockRegistryKeys(keyFile, passphrase)
      setPassphrase('')
      setKeyFile(null)
      setFileName('')
      onUnlocked()
    } catch (unlockError) {
      if (unlockError instanceof ApiRequestError && unlockError.status === 401) {
        onSessionExpired?.()
        return
      }
      setError(unlockError instanceof Error ? unlockError.message : t('Keys could not be unlocked.', 'No se pudieron desbloquear las llaves.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="workspace-stack" onSubmit={(event) => void handleSubmit(event)}>
      <p>
        {t(
          'This view needs your registry keys. Unlock them with your personal key file; they stay available only in this session.',
          'Esta vista necesita tus llaves del registro. Desbloquéalas con tu archivo de llave personal; solo quedan disponibles en esta sesión.',
        )}
      </p>
      <label className="login-field">
        <span className="login-field__label">{t('Key file', 'Archivo de llave')}</span>
        <input className="login-field__input" type="file" onChange={(event) => void handleFile(event.target.files?.[0])} />
        {fileName ? <small>{fileName}</small> : null}
      </label>
      <label className="login-field">
        <span className="login-field__label">{t('Key file passphrase', 'Contraseña del archivo de llave')}</span>
        <input
          autoComplete="off"
          className="login-field__input"
          type="password"
          value={passphrase}
          onChange={(event) => setPassphrase(event.target.value)}
        />
      </label>
      {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
      <button className="session-action" disabled={busy} type="submit">
        {busy ? t('Unlocking…', 'Desbloqueando…') : t('Unlock keys', 'Desbloquear llaves')}
      </button>
    </form>
  )
}
