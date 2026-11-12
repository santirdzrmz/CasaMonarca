import { useEffect, useState } from 'react'

import { translate as t } from '../../lib/i18n'
import { getRegistryKeyState, lockRegistryKeys, type RegistryKeyState } from '../../lib/registryKeys'

type KeyStatusBarProps = {
  onLocked: () => void
}

const DOMAIN_LABELS: Record<string, [string, string]> = {
  cand: ['Candidates', 'Candidatos'],
  proc: ['Processed', 'Procesados'],
}

/** Shows which key domains are unlocked in this session and for how long; lets the user lock them at once. */
export function KeyStatusBar({ onLocked }: KeyStatusBarProps) {
  const [state, setState] = useState<RegistryKeyState | null>(null)

  useEffect(() => {
    let active = true
    getRegistryKeyState()
      .then((next) => {
        if (active) setState(next)
      })
      .catch(() => {
        if (active) setState(null)
      })
    return () => {
      active = false
    }
  }, [])

  if (!state || state.mode !== 'publickey' || !state.unlocked) {
    return null
  }

  const minutes = Math.max(1, Math.ceil(state.expires_in_seconds / 60))
  const describe = (kekId: string) => {
    // "pk:cand-1" -> "Candidatos v1": the domain and the key version in use.
    const match = /^pk:([a-z]+)-(\d+)$/.exec(kekId)
    if (!match) return kekId
    const label = DOMAIN_LABELS[match[1]]
    return `${label ? t(label[0], label[1]) : match[1]} v${match[2]}`
  }
  const domains = state.unlocked_kek_ids.map(describe)

  const handleLock = async () => {
    try {
      await lockRegistryKeys()
    } finally {
      onLocked()
    }
  }

  return (
    <div className="login-feedback login-feedback--warning" role="status">
      <span>
        {t('Keys unlocked for this session: ', 'Llaves desbloqueadas en esta sesión: ')}
        {domains.join(', ')}
        {' · '}
        {t(`expire in ${minutes} min`, `vencen en ${minutes} min`)}
      </span>{' '}
      <button className="session-action session-action--quiet" onClick={() => void handleLock()} type="button">
        {t('Lock keys', 'Bloquear llaves')}
      </button>
    </div>
  )
}
