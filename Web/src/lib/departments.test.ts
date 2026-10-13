import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  ACTING_DEPARTMENT_HEADER,
  getActingDepartment,
  installActingDepartmentHeader,
  setActingDepartment,
  syncActingDepartment,
} from './departments'

const fetchMock = vi.fn<typeof window.fetch>(async () => new Response('{}', { status: 200 }))

const lastHeaders = () => {
  const init = fetchMock.mock.calls.at(-1)?.[1] as RequestInit | undefined

  return new Headers(init?.headers)
}

describe('acting department', () => {
  beforeAll(() => {
    window.fetch = fetchMock
    installActingDepartmentHeader()
  })

  beforeEach(() => {
    window.sessionStorage.clear()
    fetchMock.mockClear()
  })

  afterEach(() => {
    syncActingDepartment(null, [])
  })

  it('auto-selects the only assigned department', () => {
    syncActingDepartment(7, ['legal'])

    expect(getActingDepartment()).toBe('legal')
  })

  it('requires a choice when several departments are assigned', () => {
    syncActingDepartment(7, ['legal', 'warehouse'])

    expect(getActingDepartment()).toBeNull()
  })

  it('keeps a stored choice only while it is still assigned', () => {
    syncActingDepartment(7, ['legal', 'warehouse'])
    setActingDepartment('warehouse')

    syncActingDepartment(7, ['legal', 'warehouse'])
    expect(getActingDepartment()).toBe('warehouse')

    syncActingDepartment(7, ['legal', 'humanitarian'])
    expect(getActingDepartment()).toBeNull()
  })

  it('adds the header to modifying API requests only', async () => {
    syncActingDepartment(7, ['psychosocial'])

    await window.fetch('/api/registry/migrants/drafts', { method: 'POST', headers: { 'X-CSRF-TOKEN': 'abc' } })
    expect(lastHeaders().get(ACTING_DEPARTMENT_HEADER)).toBe('psychosocial')
    expect(lastHeaders().get('X-CSRF-TOKEN')).toBe('abc')

    await window.fetch('/api/registry/migrants', { method: 'GET' })
    expect(lastHeaders().get(ACTING_DEPARTMENT_HEADER)).toBeNull()

    await window.fetch('https://example.com/api/anything', { method: 'POST' })
    expect(lastHeaders().get(ACTING_DEPARTMENT_HEADER)).toBeNull()
  })

  it('sends no header when no department is selected', async () => {
    syncActingDepartment(7, ['legal', 'warehouse'])

    await window.fetch('/api/admin/invites', { method: 'POST' })
    expect(lastHeaders().get(ACTING_DEPARTMENT_HEADER)).toBeNull()
  })
})
