import { apiFetch } from './api'
import { getCsrfToken } from './csrf'

export type RegistryKeyState = {
  enrolled: boolean
  expires_in_seconds: number
  mode: string
  ttl_seconds: number
  unlocked: boolean
  unlocked_domains: string[]
  unlocked_kek_ids: string[]
}

type RegistryKeyStateResponse = { data: RegistryKeyState }

export async function getRegistryKeyState() {
  const response = await apiFetch<RegistryKeyStateResponse>('/registry/keys/status')
  return response.data
}

export async function unlockRegistryKeys(keyFile: string, passphrase: string) {
  const { csrfToken } = await getCsrfToken()
  const response = await apiFetch<RegistryKeyStateResponse>('/registry/keys/unlock', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    body: JSON.stringify({ key_file: keyFile, passphrase }),
  })
  return response.data
}

export async function lockRegistryKeys() {
  const { csrfToken } = await getCsrfToken()
  const response = await apiFetch<RegistryKeyStateResponse>('/registry/keys/unlock', {
    method: 'DELETE',
    headers: { 'X-CSRF-TOKEN': csrfToken },
  })
  return response.data
}
