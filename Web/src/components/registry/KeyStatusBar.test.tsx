import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as keys from '../../lib/registryKeys'
import { KeyStatusBar } from './KeyStatusBar'

vi.mock('../../lib/registryKeys', () => ({
  getRegistryKeyState: vi.fn(),
  lockRegistryKeys: vi.fn(),
  unlockRegistryKeys: vi.fn(),
}))

const unlocked = { enrolled: true, expires_in_seconds: 600, mode: 'publickey', ttl_seconds: 1800, unlocked: true, unlocked_domains: ['cand', 'proc'], unlocked_kek_ids: ['pk:cand-1', 'pk:proc-1'] }

describe('KeyStatusBar', () => {
  beforeEach(() => {
    vi.mocked(keys.getRegistryKeyState).mockReset()
    vi.mocked(keys.lockRegistryKeys).mockReset()
  })

  it('shows the unlocked domains and the time left, and locks on demand', async () => {
    vi.mocked(keys.getRegistryKeyState).mockResolvedValue(unlocked)
    vi.mocked(keys.lockRegistryKeys).mockResolvedValue({ ...unlocked, unlocked: false, unlocked_domains: [], unlocked_kek_ids: [] })
    const onLocked = vi.fn()

    render(<KeyStatusBar onLocked={onLocked} />)

    expect(await screen.findByText(/Candidatos v1, Procesados v1/)).toBeInTheDocument()
    expect(screen.getByText(/vencen en 10 min/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Bloquear llaves' }))
    expect(keys.lockRegistryKeys).toHaveBeenCalledTimes(1)
    expect(onLocked).toHaveBeenCalledTimes(1)
  })

  it('renders nothing when keys are locked, encryption is off or the request fails', async () => {
    vi.mocked(keys.getRegistryKeyState).mockResolvedValueOnce({ ...unlocked, unlocked: false, unlocked_domains: [], unlocked_kek_ids: [] })
    const { container, rerender } = render(<KeyStatusBar onLocked={vi.fn()} />)
    await vi.waitFor(() => expect(keys.getRegistryKeyState).toHaveBeenCalledTimes(1))
    expect(container).toBeEmptyDOMElement()

    vi.mocked(keys.getRegistryKeyState).mockResolvedValueOnce({ ...unlocked, mode: 'off' })
    rerender(<KeyStatusBar key="file" onLocked={vi.fn()} />)
    await vi.waitFor(() => expect(keys.getRegistryKeyState).toHaveBeenCalledTimes(2))
    expect(container).toBeEmptyDOMElement()

    vi.mocked(keys.getRegistryKeyState).mockRejectedValueOnce(new Error('offline'))
    rerender(<KeyStatusBar key="error" onLocked={vi.fn()} />)
    await vi.waitFor(() => expect(keys.getRegistryKeyState).toHaveBeenCalledTimes(3))
    expect(container).toBeEmptyDOMElement()
  })
})
