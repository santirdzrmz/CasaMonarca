import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { AuthenticatedUser } from '../../lib/auth'
import * as migrantDocuments from '../../lib/migrantDocuments'
import * as registry from '../../lib/registry'
import * as securityChallenges from '../../lib/securityChallenges'
import * as webauthn from '../../lib/webauthn'
import { MigrantRegistrationsPage } from './MigrantRegistrationsPage'

vi.mock('../../lib/migrantDocuments', async () => {
  const actual = await vi.importActual<typeof import('../../lib/migrantDocuments')>(
    '../../lib/migrantDocuments',
  )

  return {
    ...actual,
    listMigrantDocuments: vi.fn(),
    startMigrantDocumentDownload: vi.fn(),
    verifyMigrantDocumentDownload: vi.fn(),
  }
})

vi.mock('../../lib/registry', async () => {
  const actual = await vi.importActual<typeof import('../../lib/registry')>(
    '../../lib/registry',
  )

  return {
    ...actual,
    getRegistryEntries: vi.fn(),
    recordRegistryExit: vi.fn(),
    startRegistryPdfDownload: vi.fn(),
    undoRegistryExit: vi.fn(),
    verifyRegistryPdfDownload: vi.fn(),
  }
})

vi.mock('../../lib/webauthn', () => ({ getWebauthnAssertion: vi.fn() }))
vi.mock('../../lib/securityChallenges', () => ({ cancelSecurityChallenge: vi.fn() }))

const getRegistryEntriesMock = vi.mocked(registry.getRegistryEntries)
const recordRegistryExitMock = vi.mocked(registry.recordRegistryExit)
const undoRegistryExitMock = vi.mocked(registry.undoRegistryExit)
const listMigrantDocumentsMock = vi.mocked(migrantDocuments.listMigrantDocuments)
const startMigrantDocumentDownloadMock = vi.mocked(migrantDocuments.startMigrantDocumentDownload)
const verifyMigrantDocumentDownloadMock = vi.mocked(migrantDocuments.verifyMigrantDocumentDownload)
const startRegistryPdfDownloadMock = vi.mocked(registry.startRegistryPdfDownload)
const verifyRegistryPdfDownloadMock = vi.mocked(registry.verifyRegistryPdfDownload)
const getWebauthnAssertionMock = vi.mocked(webauthn.getWebauthnAssertion)
const cancelSecurityChallengeMock = vi.mocked(securityChallenges.cancelSecurityChallenge)

const user: AuthenticatedUser = {
  capabilities: {
    modules: {
      admin: false,
      dashboard: true,
      documents: true,
      history: true,
      invites: false,
      logging: true,
      upload: true,
    },
    security: {
      enrolled: { passkey: false, totp: true },
      enforced: false,
      isFullyEnrolled: true,
      missing: { passkey: false, totp: false },
      requires: { passkey: false, totp: true },
    },
  },
  email: 'reviewer@casamonarca.local',
  id: 7,
  name: 'Registry Reviewer',
  role: 'non_coordinator',
}

describe('MigrantRegistrationsPage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    window.sessionStorage.clear()
    getRegistryEntriesMock.mockReset()
    recordRegistryExitMock.mockReset()
    undoRegistryExitMock.mockReset()
    listMigrantDocumentsMock.mockReset()
    startMigrantDocumentDownloadMock.mockReset()
    verifyMigrantDocumentDownloadMock.mockReset()
    startRegistryPdfDownloadMock.mockReset()
    verifyRegistryPdfDownloadMock.mockReset()
    getWebauthnAssertionMock.mockReset()
    cancelSecurityChallengeMock.mockReset()
    getRegistryEntriesMock.mockResolvedValue({
      data: [{
        created_at: '2026-07-17T12:00:00Z',
        created_by: 3,
        created_by_role: 'volunteer',
        current_status: 'approved',
        id: 42,
        payload_json: {
          attentionDate: '2026-07-17',
          countryOfOrigin: 'Honduras',
          fullName: 'Maria Doe',
          populationGroup: 'adult',
        },
        updated_at: '2026-07-17T13:00:00Z',
      }],
    })
    listMigrantDocumentsMock.mockResolvedValue({
      data: [{
        arco_access_completed: false,
        created_at: '2026-07-17T12:00:00Z',
        id: 9,
        label: 'Identification',
        mime_type: 'application/pdf',
        original_file_name: 'passport.pdf',
        registry_entry_id: 42,
        sha256: 'abc123',
        size_bytes: 2048,
        updated_at: '2026-07-17T12:00:00Z',
        uploaded_by_role: 'volunteer',
      }],
    })
  })

  it('loads document metadata without exposing downloads to a non-coordinator', async () => {
    const browserUser = userEvent.setup()

    render(<MigrantRegistrationsPage user={user} />)

    expect(await screen.findByText('Maria Doe')).toBeInTheDocument()
    expect(screen.getAllByText('Persona adulta (18-59 años)')).toHaveLength(2)
    expect(screen.getAllByText('Aprobado')).toHaveLength(2)
    expect(screen.queryByText('adult')).not.toBeInTheDocument()
    expect(listMigrantDocumentsMock).not.toHaveBeenCalled()

    await browserUser.click(screen.getByText('Ver detalles del registro'))

    await waitFor(() => expect(listMigrantDocumentsMock).toHaveBeenCalledWith(42))
    expect(await screen.findByText('Identification — passport.pdf')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Descargar' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Descargar PDF del registro' })).not.toBeInTheDocument()
  })

  it('searches registration text without requiring accents', async () => {
    const browserUser = userEvent.setup()
    getRegistryEntriesMock.mockResolvedValueOnce({
      data: [{
        created_at: '2026-07-17T12:00:00Z', created_by: 3, created_by_role: 'volunteer',
        current_status: 'approved', id: 42,
        payload_json: { countryOfOrigin: 'México', fullName: 'María López', populationGroup: 'adult' },
        updated_at: '2026-07-17T13:00:00Z',
      }],
    })
    render(<MigrantRegistrationsPage user={user} />)

    await browserUser.type(await screen.findByLabelText('Buscar'), 'maria lopez mexico')
    await waitFor(() => expect(JSON.parse(window.sessionStorage.getItem('casa-monarca.migrant-registrations.filters') ?? '{}').search).toBe('maria lopez mexico'))

    expect(screen.getByText('María López')).toBeInTheDocument()
  })

  it('passkey-authenticates coordinator document downloads', async () => {
    const browserUser = userEvent.setup()
    const coordinator = { ...user, role: 'coordinator' as const }
    const assertion = {
      id: 'credential-coordinator',
      rawId: 'credential-coordinator',
      type: 'public-key' as const,
      response: {
        authenticatorData: 'authenticator-data',
        clientDataJSON: 'client-data',
        signature: 'signature',
      },
    }
    startMigrantDocumentDownloadMock.mockResolvedValue({
      challengeIntent: {
        expiresAt: '2026-07-17T13:01:00Z',
        id: 'challenge-id',
        purpose: 'migrant.registry.document.download',
        status: 'pending',
      },
      message: 'Challenge created.',
      options: {
        challenge: 'challenge',
        rpId: 'localhost',
      },
    })
    getWebauthnAssertionMock.mockResolvedValue(assertion)
    verifyMigrantDocumentDownloadMock.mockResolvedValue(new Blob(['private document']))
    const createObjectUrl = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:download')
    const revokeObjectUrl = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const anchorClick = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    render(<MigrantRegistrationsPage user={coordinator} />)
    await browserUser.click(await screen.findByText('Ver detalles del registro'))
    await browserUser.click(await screen.findByRole('button', { name: 'Descargar' }))

    expect(screen.getByRole('dialog', { name: 'Documento fuera de Acceso ARCO completado' })).toBeInTheDocument()
    expect(startMigrantDocumentDownloadMock).not.toHaveBeenCalled()

    await browserUser.click(screen.getByRole('button', { name: 'Continuar con llave de acceso' }))

    await waitFor(() => expect(verifyMigrantDocumentDownloadMock).toHaveBeenCalledWith(42, 9, assertion))
    expect(startMigrantDocumentDownloadMock).toHaveBeenCalledWith(42, 9)
    expect(getWebauthnAssertionMock).toHaveBeenCalledTimes(1)
    expect(anchorClick).toHaveBeenCalledTimes(1)
    expect(createObjectUrl).toHaveBeenCalledTimes(1)
    expect(revokeObjectUrl).toHaveBeenCalledWith('blob:download')
  })

  it('shows admin-only registry PDF download and completes its passkey flow', async () => {
    const browserUser = userEvent.setup()
    const admin = { ...user, role: 'admin' as const }
    const assertion = {
      id: 'credential-admin',
      rawId: 'credential-admin',
      type: 'public-key' as const,
      response: {
        authenticatorData: 'authenticator-data',
        clientDataJSON: 'client-data',
        signature: 'signature',
      },
    }
    startRegistryPdfDownloadMock.mockResolvedValue({
      challengeIntent: {
        expiresAt: '2026-07-17T13:01:00Z',
        id: 'registry-pdf-challenge',
        purpose: 'migrant.registry.pdf.download',
        status: 'pending',
      },
      message: 'Challenge created.',
      options: { challenge: 'challenge', rpId: 'localhost' },
    })
    getWebauthnAssertionMock.mockResolvedValue(assertion)
    verifyRegistryPdfDownloadMock.mockResolvedValue(new Blob(['%PDF registry']))
    const createObjectUrl = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:registry-pdf')
    const revokeObjectUrl = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const anchorClick = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    render(<MigrantRegistrationsPage user={admin} />)
    await browserUser.click(await screen.findByText('Ver detalles del registro'))
    await browserUser.click(screen.getByRole('button', { name: 'Descargar PDF del registro' }))

    await waitFor(() => expect(verifyRegistryPdfDownloadMock).toHaveBeenCalledWith(42, assertion))
    expect(startRegistryPdfDownloadMock).toHaveBeenCalledWith(42)
    expect(getWebauthnAssertionMock).toHaveBeenCalledTimes(1)
    expect(anchorClick).toHaveBeenCalledTimes(1)
    expect(createObjectUrl).toHaveBeenCalledTimes(1)
    expect(revokeObjectUrl).toHaveBeenCalledWith('blob:registry-pdf')
    expect(screen.getByText('Se descargó el PDF del registro.')).toBeInTheDocument()
  })

  it('cancels the registry PDF challenge when the passkey prompt is dismissed', async () => {
    const browserUser = userEvent.setup()
    const admin = { ...user, role: 'admin' as const }
    startRegistryPdfDownloadMock.mockResolvedValue({
      challengeIntent: {
        id: 'cancelled-registry-pdf-challenge',
        purpose: 'migrant.registry.pdf.download',
        status: 'pending',
      },
      message: 'Challenge created.',
      options: { challenge: 'challenge', rpId: 'localhost' },
    })
    getWebauthnAssertionMock.mockRejectedValue(new DOMException('Dismissed', 'NotAllowedError'))
    cancelSecurityChallengeMock.mockResolvedValue({
      challengeIntent: { id: 'cancelled-registry-pdf-challenge', status: 'cancelled' },
      message: 'Challenge cancelled.',
    })

    render(<MigrantRegistrationsPage user={admin} />)
    await browserUser.click(await screen.findByText('Ver detalles del registro'))
    await browserUser.click(screen.getByRole('button', { name: 'Descargar PDF del registro' }))

    await waitFor(() => expect(cancelSecurityChallengeMock).toHaveBeenCalledWith('cancelled-registry-pdf-challenge'))
    expect(verifyRegistryPdfDownloadMock).not.toHaveBeenCalled()
    expect(screen.getByText('Se canceló la descarga con llave de acceso.')).toBeInTheDocument()
  })

  it('filters by shelter stay: in the shelter or already left', async () => {
    const browserUser = userEvent.setup()
    getRegistryEntriesMock.mockResolvedValueOnce({
      data: [
        {
          created_at: '2026-07-17T12:00:00Z', created_by: 3, created_by_role: 'volunteer', current_status: 'approved', id: 42,
          payload_json: { fullName: 'Maria Doe', populationGroup: 'adult' }, updated_at: '2026-07-17T13:00:00Z',
        },
        {
          created_at: '2026-07-10T12:00:00Z', created_by: 3, created_by_role: 'volunteer', current_status: 'approved', exited_at: '2026-07-20', id: 43,
          payload_json: { fullName: 'Juan Perez', populationGroup: 'adult' }, updated_at: '2026-07-20T13:00:00Z',
        },
        {
          created_at: '2026-07-18T12:00:00Z', created_by: 3, created_by_role: 'volunteer', current_status: 'pending_review', id: 44,
          payload_json: { fullName: 'Ana Ruiz', populationGroup: 'adult' }, updated_at: '2026-07-18T13:00:00Z',
        },
      ],
    })
    render(<MigrantRegistrationsPage user={user} />)
    expect(await screen.findByText('Maria Doe')).toBeInTheDocument()

    await browserUser.click(screen.getByRole('button', { name: 'Egresados' }))
    expect(screen.getByText('Juan Perez')).toBeInTheDocument()
    expect(screen.queryByText('Maria Doe')).not.toBeInTheDocument()
    expect(screen.queryByText('Ana Ruiz')).not.toBeInTheDocument()

    await browserUser.click(screen.getByRole('button', { name: 'En albergue' }))
    expect(screen.getByText('Maria Doe')).toBeInTheDocument()
    expect(screen.queryByText('Juan Perez')).not.toBeInTheDocument()
    expect(screen.queryByText('Ana Ruiz')).not.toBeInTheDocument()
    // Non-coordinators can filter but not record exits.
    expect(screen.queryByRole('button', { name: 'Registrar egreso' })).not.toBeInTheDocument()
  })

  it('lets a coordinator record an exit only after explaining why', async () => {
    const browserUser = userEvent.setup()
    const coordinator = { ...user, role: 'coordinator' as const }
    recordRegistryExitMock.mockResolvedValue({
      data: {
        created_at: '2026-07-17T12:00:00Z', created_by: 3, created_by_role: 'volunteer', current_status: 'approved',
        exit_reason: 'Continuó su viaje.', exited_at: '2026-07-25', exited_by: { id: 7, name: 'Registry Reviewer' }, id: 42,
        payload_json: { fullName: 'Maria Doe', populationGroup: 'adult' }, updated_at: '2026-07-25T13:00:00Z',
      },
      message: 'Shelter exit recorded.',
    })
    render(<MigrantRegistrationsPage user={coordinator} />)

    await browserUser.click(await screen.findByRole('button', { name: 'Registrar egreso' }))
    const dialog = screen.getByRole('dialog', { name: 'Registrar egreso del albergue' })
    const submit = within(dialog).getByRole('button', { name: 'Registrar egreso' })
    expect(submit).toBeDisabled()

    await browserUser.clear(within(dialog).getByLabelText('Fecha de egreso'))
    await browserUser.type(within(dialog).getByLabelText('Fecha de egreso'), '2026-07-25')
    await browserUser.type(within(dialog).getByLabelText(/¿Por qué salió la persona/), 'Continuó su viaje.')
    await browserUser.click(submit)

    await waitFor(() => expect(recordRegistryExitMock).toHaveBeenCalledWith(42, '2026-07-25', 'Continuó su viaje.'))
    expect(await screen.findByText('Egreso registrado para Maria Doe.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByText('Continuó su viaje.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Deshacer egreso' })).toBeInTheDocument()
  })
})
