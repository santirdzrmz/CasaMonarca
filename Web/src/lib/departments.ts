import { useSyncExternalStore } from 'react'

import { apiBaseUrl } from '../config/env'
import { translate as t } from './i18n'

export type Department =
  | 'humanitarian'
  | 'psychosocial'
  | 'legal'
  | 'communication'
  | 'warehouse'
  | 'information_technology'

export const ACTING_DEPARTMENT_HEADER = 'X-Acting-Department'

export const DEPARTMENTS: readonly Department[] = [
  'humanitarian',
  'psychosocial',
  'legal',
  'communication',
  'warehouse',
  'information_technology',
]

export const departmentLabel = (department: string): string => {
  switch (department) {
    case 'humanitarian':
      return t('Humanitarian', 'Humanitaria')
    case 'psychosocial':
      return t('Psychosocial', 'Psicosocial')
    case 'legal':
      return t('Legal', 'Legal')
    case 'communication':
      return t('Communication', 'Comunicación')
    case 'warehouse':
      return t('Warehouse', 'Almacén')
    case 'information_technology':
      return t('Information technology', 'Tecnologías de información')
    default:
      return department
  }
}

const isDepartment = (value: unknown): value is Department =>
  typeof value === 'string' && (DEPARTMENTS as readonly string[]).includes(value)

const storageKey = (userId: number) => `casa-monarca.acting-department:${userId}`

let activeUserId: number | null = null
let actingDepartment: Department | null = null
const listeners = new Set<() => void>()

const notify = () => listeners.forEach((listener) => listener())

const readStored = (userId: number): string | null => {
  try {
    return window.sessionStorage.getItem(storageKey(userId))
  } catch {
    return null
  }
}

const writeStored = (userId: number, value: Department | null) => {
  try {
    if (value) {
      window.sessionStorage.setItem(storageKey(userId), value)
    } else {
      window.sessionStorage.removeItem(storageKey(userId))
    }
  } catch {
    // Storage unavailable: the selection still lives in memory for this tab.
  }
}

/**
 * Align the acting department with the signed-in user's assignments: keep the
 * stored choice while it is still assigned, auto-select a single assignment,
 * otherwise require an explicit choice.
 */
export function syncActingDepartment(userId: number | null, assigned: readonly string[]) {
  activeUserId = userId

  if (userId === null) {
    actingDepartment = null
    notify()
    return
  }

  const stored = readStored(userId)
  const next = isDepartment(stored) && assigned.includes(stored)
    ? stored
    : assigned.length === 1 && isDepartment(assigned[0])
      ? assigned[0]
      : null

  actingDepartment = next
  writeStored(userId, next)
  notify()
}

export function setActingDepartment(value: Department | null) {
  actingDepartment = value

  if (activeUserId !== null) {
    writeStored(activeUserId, value)
  }

  notify()
}

export const getActingDepartment = () => actingDepartment

export function useActingDepartment() {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    getActingDepartment,
    getActingDepartment,
  )
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

const isApiRequest = (url: string) => {
  try {
    const target = new URL(url, window.location.origin)
    const base = new URL(apiBaseUrl, window.location.origin)
    const basePath = base.pathname.replace(/\/+$/, '')

    return target.origin === base.origin
      && (target.pathname === basePath || target.pathname.startsWith(`${basePath}/`))
  } catch {
    return false
  }
}

let installed = false

/**
 * The web client builds its API requests in many modules. Rather than thread the
 * department through each one, attach it to every modifying API request here.
 */
export function installActingDepartmentHeader() {
  if (installed || typeof window === 'undefined') {
    return
  }

  installed = true
  const originalFetch = window.fetch.bind(window)

  window.fetch = (input: RequestInfo | URL, init?: RequestInit) => {
    const department = actingDepartment
    const request = input instanceof Request ? input : null
    const method = (init?.method ?? request?.method ?? 'GET').toUpperCase()
    const url = request ? request.url : input.toString()

    if (!department || SAFE_METHODS.has(method) || !isApiRequest(url)) {
      return originalFetch(input, init)
    }

    const headers = new Headers(init?.headers ?? request?.headers)

    if (!headers.has(ACTING_DEPARTMENT_HEADER)) {
      headers.set(ACTING_DEPARTMENT_HEADER, department)
    }

    return originalFetch(input, { ...init, headers })
  }
}
