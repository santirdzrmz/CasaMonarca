import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { AuthenticatedUser } from '../../lib/auth'
import * as statistics from '../../lib/registryStatistics'
import type { RegistryStatistics, StatisticsDimension } from '../../lib/registryStatistics'
import { MigrantStatisticsPage } from './MigrantStatisticsPage'

vi.mock('../../lib/registryStatistics', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/registryStatistics')>()
  return { ...actual, getRegistryStatistics: vi.fn() }
})

const user: AuthenticatedUser = {
  capabilities: {
    modules: { admin: false, dashboard: true, documents: true, history: true, invites: true, logging: false, upload: true },
    security: {
      enrolled: { passkey: true, totp: true }, enforced: true, isFullyEnrolled: true,
      missing: { passkey: false, totp: false }, requires: { passkey: true, totp: true },
    },
  },
  email: 'coordinator@casamonarca.local', id: 2, name: 'Coordinator', role: 'coordinator',
}

const dimension = (label: string, categories: [string, number][], byMonth: Record<string, number> = {}): StatisticsDimension => ({
  byMonth: [{ counts: byMonth, month: '2026-03' }],
  categories: categories.map(([value, count]) => ({ count, value })),
  label,
  ordered: false,
})

const sample: RegistryStatistics = {
  arrivalsByMonth: [{ count: 4, month: '2026-02' }, { count: 6, month: '2026-03' }],
  dimensions: {
    civilStatus: dimension('Estado civil', [['Soltera / Soltero', 7], ['Sin respuesta', 3]]),
    countryOfOrigin: dimension('Nacionalidad (país de origen)', [
      ['Honduras', 5], ['Guatemala', 1], ['El Salvador', 1], ['Venezuela (República Bolivariana de)', 1],
      ['Cuba', 1], ['Haití', 1], ['Colombia', 1], ['Nicaragua', 1], ['Ecuador', 1], ['Perú', 1], ['Chile', 1],
    ]),
    education: dimension('Nivel educativo', [['Primaria', 6], ['Secundaria', 4]]),
    finalDestination: dimension('Destino final', [['Estados Unidos', 7], ['México', 3]]),
    gender: dimension('Género', [['Femenino', 6], ['Masculino', 4]], { Femenino: 4, Masculino: 2 }),
    literacy: dimension('Sabe leer y escribir', [['Sí', 8], ['No', 2]]),
  },
  mexicoToMonterrey: {
    bins: [{ count: 3, label: '0–7 días', max: 7, min: 0 }, { count: 5, label: '8–14 días', max: 14, min: 8 }],
    count: 8,
    inconsistentDates: 1,
    median: 9,
    missingDates: 1,
    p25: 5,
    p75: 12,
  },
  range: { dateBasis: 'attentionDate', from: null, to: null },
  requestedServices: {
    areas: [
      { count: 4, key: 'humanitarian', label: 'Humanitaria', services: ['Ropa y Calzado'] },
      { count: 3, key: 'legal', label: 'Legal', services: ['Orientación / Asesoría legal'] },
      { count: 2, key: 'psychosocial', label: 'Psicosocial', services: ['Atención psicológica'] },
    ],
    areasByMonth: [{ counts: { humanitarian: 4, legal: 3 }, month: '2026-03' }],
    label: 'Servicios solicitados',
    noAnswer: 2,
    respondents: 8,
    services: [
      { count: 4, value: 'Ropa y Calzado' },
      { count: 3, value: 'Orientación / Asesoría legal' },
      { count: 2, value: 'Atención psicológica' },
    ],
  },
  total: 10,
}

describe('MigrantStatisticsPage', () => {
  beforeEach(() => {
    vi.mocked(statistics.getRegistryStatistics).mockReset()
    vi.mocked(statistics.getRegistryStatistics).mockResolvedValue(sample)
  })

  it('shows the headline figures and every requested breakdown', async () => {
    render(<MigrantStatisticsPage user={user} />)

    expect(await screen.findByRole('heading', { name: 'Llegadas por mes' })).toBeInTheDocument()
    const summary = within(screen.getByRole('region', { name: 'Resumen' }))
    expect(summary.getByText('10')).toBeInTheDocument()
    expect(summary.getByText('9 días')).toBeInTheDocument()
    expect(summary.getByText('80%')).toBeInTheDocument()
    expect(summary.getByText('Estados Unidos')).toBeInTheDocument()

    for (const title of ['Nacionalidad (país de origen)', 'Nivel educativo', 'Género', 'Estado civil', 'Destino final', 'Sabe leer y escribir']) {
      expect(screen.getByRole('heading', { name: title })).toBeInTheDocument()
    }
  })

  it('shows requested services per attention area over the people who answered', async () => {
    render(<MigrantStatisticsPage user={user} />)

    expect(await screen.findByRole('heading', { name: 'Servicios solicitados' })).toBeInTheDocument()
    const areas = within(screen.getByRole('group', { name: 'Personas por área de atención' }))
    expect(areas.getByText('Área Legal')).toBeInTheDocument()
    expect(areas.getByText('3')).toBeInTheDocument()
    expect(areas.getByText('25% de quienes respondieron · 1 servicios')).toBeInTheDocument()
    expect(screen.getByRole('list', { name: 'Servicios solicitados' })).toHaveTextContent('Atención psicológica')
  })

  it('folds countries past the top ten into one row', async () => {
    render(<MigrantStatisticsPage user={user} />)

    expect(await screen.findByText('Otros países (1)')).toBeInTheDocument()
  })

  it('refetches with the selected date preset', async () => {
    render(<MigrantStatisticsPage user={user} />)
    await screen.findByRole('heading', { name: 'Llegadas por mes' })

    await userEvent.click(screen.getByRole('button', { name: 'Todo' }))

    await waitFor(() => {
      expect(statistics.getRegistryStatistics).toHaveBeenLastCalledWith({ from: null, to: null })
    })
  })
})
