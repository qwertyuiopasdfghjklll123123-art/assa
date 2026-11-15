import type { Backend } from './types'

export type { Backend, Filter, AuthInfo } from './types'

export const isFirebaseConfigured = Boolean(
  process.env.NEXT_PUBLIC_FIREBASE_API_KEY && process.env.NEXT_PUBLIC_FIREBASE_PROJECT_ID,
)

let pending: Promise<Backend> | null = null

/** Returns the active backend: Firebase when configured, otherwise the local demo database. */
export function getBackend(): Promise<Backend> {
  if (!pending) {
    pending = isFirebaseConfigured
      ? import('./firebase').then((m) => m.createFirebaseBackend())
      : import('./local').then((m) => m.createLocalBackend())
  }
  return pending
}
