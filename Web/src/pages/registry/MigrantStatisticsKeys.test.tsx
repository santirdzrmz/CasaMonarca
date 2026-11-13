import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiRequestError } from '../../lib/api'
import type { AuthenticatedUser } from '../../lib/auth'
import * as keys from '../../lib/registryKeys'
import * as statistics from '../../lib/registryStatistics'
import type { RegistryStatistics, StatisticsDimension } from '../../lib/registryStatistics'
import { MigrantStatisticsPage } from './MigrantStatisticsPage'

vi.mock('../../lib/registryStatistics', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/registryStatistics')>()
  return { ...actual, getRegistryStatistics: vi.fn() }
})

vi.mock('../../lib/registryKeys', () => ({
  getRegistryKeyState: vi.fn().mockResolvedValue({ enrolled: true, expires_in_seconds: 0, mode: 'publickey', ttl_seconds: 1800, unlocked: false, unlocked_domains: [], unlocked_kek_ids: [] }),
  lockRegistryKeys: vi.fn(),
  unlockRegistryKeys: vi.fn(),
}))

const user = {
  capabilities: {
    modules: { admin: false, dashboard: true, documents: true, history: true, invites: true, logging: false, upload: true },
    security: {
      enrolled: { passkey: true, totp: true }, enforced: true, isFullyEnrolled: true,
      missing: { passkey: false, totp: false }, requires: { passkey: true, totp: true },
    },
  },
  email: 'coordinator@casamonarca.local', id: 2, name: 'Coordinator', role: 'coordinator',
} as AuthenticatedUser

const dimension = (label: string): StatisticsDimension => ({ byMonth: [], categories: [], label, ordered: false })

const sample: RegistryStatistics = {
  arrivalsByMonth: [{ count: 4, month: '2026-02' }],
  dimensions: {
    civilStatus: dimension('Estado civil'),
    countryOfOrigin: dimension('Nacionalidad (país de origen)'),
    education: dimension('Nivel educativo'),
    finalDestination: dimension('Destino final'),
    gender: dimension('Género'),
    literacy: dimension('Sabe leer y escribir'),
  },
  mexicoToMonterrey: { bins: [], count: 0, inconsistentDates: 0, median: null, missingDates: 0, p25: null, p75: null },
  range: { dateBasis: 'attentionDate', from: null, to: null },
  requestedServices: { areas: [], areasByMonth: [], label: 'Servicios solicitados', noAnswer: 4, respondents: 0, services: [] },
  total: 4,
}

describe('MigrantStatisticsPage with locked registry keys', () => {
  beforeEach(() => {
    vi.mocked(statistics.getRegistryStatistics).mockReset()
    vi.mocked(keys.unlockRegistryKeys).mockReset()
  })

  it('asks for the key file when the API answers 423 and loads the figures after unlocking', async () => {
    vi.mocked(statistics.getRegistryStatistics)
      .mockRejectedValueOnce(new ApiRequestError('Registry keys are locked.', 423))
      .mockResolvedValue(sample)
    vi.mocked(keys.unlockRegistryKeys).mockResolvedValue({ enrolled: true, expires_in_seconds: 1800, mode: 'publickey', ttl_seconds: 1800, unlocked: true, unlocked_domains: ['cand', 'proc'], unlocked_kek_ids: ['pk:cand-1', 'pk:proc-1'] })

    render(<MigrantStatisticsPage user={user} />)

    expect(await screen.findByText(/necesita tus llaves del registro/i)).toBeInTheDocument()

    const file = new File(['{"v":1}'], 'coordinador.keyfile.json', { type: 'application/json' })
    await userEvent.upload(screen.getByLabelText('Archivo de llave'), file)
    await userEvent.type(screen.getByLabelText('Contraseña del archivo de llave'), 'una-contraseña-larga')
    await userEvent.click(screen.getByRole('button', { name: 'Desbloquear llaves' }))

    expect(keys.unlockRegistryKeys).toHaveBeenCalledWith('{"v":1}', 'una-contraseña-larga')
    expect(await screen.findByRole('heading', { name: 'Llegadas por mes' })).toBeInTheDocument()
    expect(statistics.getRegistryStatistics).toHaveBeenCalledTimes(2)
  })

  it('shows the server message and keeps the form when the key file is rejected', async () => {
    vi.mocked(statistics.getRegistryStatistics).mockRejectedValue(new ApiRequestError('Registry keys are locked.', 423))
    vi.mocked(keys.unlockRegistryKeys).mockRejectedValue(new ApiRequestError('El archivo de llave o la contraseña no son válidos.', 422))

    render(<MigrantStatisticsPage user={user} />)

    await screen.findByText(/necesita tus llaves del registro/i)
    await userEvent.upload(screen.getByLabelText('Archivo de llave'), new File(['x'], 'k.json'))
    await userEvent.type(screen.getByLabelText('Contraseña del archivo de llave'), 'mala')
    await userEvent.click(screen.getByRole('button', { name: 'Desbloquear llaves' }))

    expect(await screen.findByText('El archivo de llave o la contraseña no son válidos.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Desbloquear llaves' })).toBeEnabled()
  })
})
