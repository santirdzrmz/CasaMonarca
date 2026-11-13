import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiRequestError } from '../../lib/api'
import * as access from '../../lib/registryAccess'
import * as keys from '../../lib/registryKeys'
import { CoordinatorAccessPanel } from './CoordinatorAccessPanel'
import { OperatorAccessPanel } from './OperatorAccessPanel'

vi.mock('../../lib/registryAccess', () => ({
  denyAccess: vi.fn(),
  getOperatorAccess: vi.fn(),
  grantAccess: vi.fn(),
  listAccessRequests: vi.fn(),
  requestAccess: vi.fn(),
  revokeAccess: vi.fn(),
}))

vi.mock('../../lib/registryKeys', () => ({
  getRegistryKeyState: vi.fn(),
  lockRegistryKeys: vi.fn(),
  unlockRegistryKeys: vi.fn(),
}))

const keyState = { enrolled: true, expires_in_seconds: 600, mode: 'publickey', ttl_seconds: 1800, unlocked: true, unlocked_domains: ['cand'], unlocked_kek_ids: ['pk:cand-1'] }
const pending = {
  created_at: '2026-11-10 10:00:00', expires_at: null, fingerprint: 'ab12-cd34-ef56', id: 7, operator_name: 'Luis Operador',
  reason: 'Corregir documento', resource_id: 5, resource_type: 'registry' as const, status: 'requested' as const,
}

describe('OperatorAccessPanel', () => {
  beforeEach(() => {
    vi.mocked(access.getOperatorAccess).mockReset()
    vi.mocked(access.requestAccess).mockReset()
  })

  it('shows the session fingerprint and sends a request for one record', async () => {
    vi.mocked(access.getOperatorAccess).mockResolvedValue({ fingerprint: 'ab12-cd34-ef56', mode: 'publickey', requests: [] })
    vi.mocked(access.requestAccess).mockResolvedValue({ data: { id: 7 } })

    render(<OperatorAccessPanel />)

    expect(await screen.findByText('ab12-cd34-ef56')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Número de registro'), '5')
    await userEvent.click(screen.getByRole('button', { name: 'Solicitar acceso' }))

    expect(access.requestAccess).toHaveBeenCalledWith('registry', 5, '')
  })

  it('renders nothing when user keys are not enabled', async () => {
    vi.mocked(access.getOperatorAccess).mockResolvedValue({ mode: 'file' })
    const { container } = render(<OperatorAccessPanel />)
    await vi.waitFor(() => expect(access.getOperatorAccess).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })
})

describe('CoordinatorAccessPanel', () => {
  beforeEach(() => {
    vi.mocked(keys.getRegistryKeyState).mockReset().mockResolvedValue(keyState)
    vi.mocked(access.listAccessRequests).mockReset().mockImplementation(async (status) => (status === 'requested' ? [pending] : []))
    vi.mocked(access.grantAccess).mockReset()
    vi.mocked(access.denyAccess).mockReset()
  })

  it('grants access with the fingerprint the operator read out', async () => {
    vi.mocked(access.grantAccess).mockResolvedValue({})
    render(<CoordinatorAccessPanel />)

    expect(await screen.findByText(/Luis Operador/)).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Confirmar huella 7'), 'ab12-cd34-ef56')
    await userEvent.click(screen.getByRole('button', { name: 'Conceder' }))

    expect(access.grantAccess).toHaveBeenCalledWith(7, 'ab12-cd34-ef56', 60)
  })

  it('asks the coordinator to unlock keys when the API answers 423', async () => {
    vi.mocked(access.grantAccess).mockRejectedValue(new ApiRequestError('Registry keys are locked.', 423))
    render(<CoordinatorAccessPanel />)

    await screen.findByText(/Luis Operador/)
    await userEvent.click(screen.getByRole('button', { name: 'Conceder' }))

    expect(await screen.findByText(/necesita tus llaves del registro/i)).toBeInTheDocument()
  })

  it('denies a request', async () => {
    vi.mocked(access.denyAccess).mockResolvedValue({})
    render(<CoordinatorAccessPanel />)

    await screen.findByText(/Luis Operador/)
    await userEvent.click(screen.getByRole('button', { name: 'Denegar' }))

    expect(access.denyAccess).toHaveBeenCalledWith(7)
  })
})
