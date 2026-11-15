import type { Backend } from './types'

export type { Backend, Filter, AuthInfo } from './types'

/**
 * `php` (default for builds): talks to the PHP API in /api, data stored in JSON files on the server.
 * `local`: browser-only demo database, used by `npm run dev` unless NEXT_PUBLIC_BACKEND=php.
 */
export const backendMode: 'php' | 'local' =
  process.env.NEXT_PUBLIC_BACKEND === 'local' || (process.env.NODE_ENV === 'development' && process.env.NEXT_PUBLIC_BACKEND !== 'php')
    ? 'local'
    : 'php'

export const isDemoMode = backendMode === 'local'

let pending: Promise<Backend> | null = null

/** Returns the active backend (PHP API or the local demo database). */
export function getBackend(): Promise<Backend> {
  if (!pending) {
    pending = isDemoMode
      ? import('./local').then((m) => m.createLocalBackend())
      : import('./php').then((m) => m.createPhpBackend())
  }
  return pending
}
