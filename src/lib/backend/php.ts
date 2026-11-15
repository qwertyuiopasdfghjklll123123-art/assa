import { fileToDataUrl, type AuthInfo, type Backend, type Filter } from './types'

/*
 * Production backend: the PHP API in /api stores everything in JSON files on the server.
 * There is no push channel on shared PHP hosting, so live views poll every few seconds
 * (and refresh immediately after this browser writes something).
 */

const BASE = (process.env.NEXT_PUBLIC_BASE_PATH ?? '').replace(/\/$/, '')
const API = process.env.NEXT_PUBLIC_API_URL || `${BASE}/api/index.php`
const TOKEN_KEY = 'sufra.token'
const CHANGE = 'sufra-php-change'
const POLL_MS = 5000

class ApiError extends Error {
  constructor(
    public code: string,
    public status: number,
  ) {
    super(code)
  }
}

function getToken() {
  try {
    return localStorage.getItem(TOKEN_KEY) ?? ''
  } catch {
    return ''
  }
}
function setToken(t: string | null) {
  try {
    if (t) localStorage.setItem(TOKEN_KEY, t)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* storage unavailable */
  }
}

const authListeners = new Set<(info: AuthInfo | null) => void>()
let currentAuth: AuthInfo | null = null
const emitAuth = (info: AuthInfo | null) => {
  currentAuth = info
  authListeners.forEach((l) => l(info))
}

async function call<T>(action: string, payload: Record<string, unknown> = {}, body?: FormData): Promise<T> {
  const headers: Record<string, string> = {}
  const token = getToken()
  if (token) headers['X-Auth-Token'] = token
  let init: RequestInit
  if (body) {
    body.append('action', action)
    init = { method: 'POST', headers, body }
  } else {
    headers['Content-Type'] = 'application/json'
    init = { method: 'POST', headers, body: JSON.stringify({ action, ...payload }) }
  }
  const res = await fetch(API, init)
  let json: { ok: boolean; data?: T; error?: string }
  try {
    json = await res.json()
  } catch {
    throw new ApiError('server-error', res.status)
  }
  if (!json.ok) {
    if (json.error === 'unauthenticated' && token) {
      setToken(null)
      emitAuth(null)
    }
    throw new ApiError(json.error || 'server-error', res.status)
  }
  return json.data as T
}

const changed = () => window.dispatchEvent(new Event(CHANGE))

/** Calls `load` now, every POLL_MS while the page is visible, and right after local writes. */
function poll<T>(load: () => Promise<T>, cb: (v: T) => void, onError?: (e: Error) => void) {
  let last = ''
  let alive = true
  const run = async () => {
    if (!alive || document.visibilityState === 'hidden') return
    try {
      const v = await load()
      const s = JSON.stringify(v)
      if (alive && s !== last) {
        last = s
        cb(v)
      }
    } catch (e) {
      if (alive) onError?.(e as Error)
    }
  }
  run()
  const timer = setInterval(run, POLL_MS)
  window.addEventListener(CHANGE, run)
  document.addEventListener('visibilitychange', run)
  return () => {
    alive = false
    clearInterval(timer)
    window.removeEventListener(CHANGE, run)
    document.removeEventListener('visibilitychange', run)
  }
}

function session(r: { token: string; uid: string; email: string }): AuthInfo {
  setToken(r.token)
  const info = { uid: r.uid, email: r.email }
  emitAuth(info)
  return info
}

export async function createPhpBackend(): Promise<Backend> {
  const authReady = (async () => {
    if (!getToken()) return null
    try {
      return await call<AuthInfo | null>('auth.me')
    } catch {
      return null
    }
  })()
  authReady.then((info) => {
    if (!info) setToken(null)
    emitAuth(info)
  })

  const backend: Backend = {
    mode: 'php',
    serverSide: true,
    list: <T,>(col: string, filter?: Filter) => call<T[]>('db.list', { col, filter }),
    get: <T,>(col: string, id: string) => call<T | null>('db.get', { col, id }),
    async set(col, id, data) {
      await call('db.set', { col, id, data })
      changed()
    },
    async add(col, data) {
      const id = await call<string>('db.add', { col, data })
      changed()
      return id
    },
    async remove(col, id) {
      await call('db.remove', { col, id })
      changed()
    },
    subscribe<T>(col: string, filter: Filter | undefined, cb: (items: T[]) => void, onError?: (e: Error) => void) {
      return poll(() => call<T[]>('db.list', { col, filter }), cb, onError)
    },
    subscribeDoc<T>(col: string, id: string, cb: (item: T | null) => void) {
      return poll(() => call<T | null>('db.get', { col, id }), cb, () => {})
    },

    onAuth(cb) {
      authListeners.add(cb)
      authReady.then(() => cb(currentAuth))
      return () => authListeners.delete(cb)
    },
    async signIn(login, password) {
      return session(await call('auth.signin', { login, password }))
    },
    authConfig: () => call('auth.config'),
    sendOtp: (phone) => call('otp.send', { phone }),
    async signInWhatsApp(input) {
      return session(await call('auth.whatsapp', input))
    },
    async signInGoogle(accessToken) {
      return session(await call('auth.google', { accessToken }))
    },
    async signOut() {
      await call('auth.signout').catch(() => null)
      setToken(null)
      emitAuth(null)
    },
    async deleteAuthUser() {
      await call('auth.delete')
    },
    async changePassword(current, next) {
      await call('auth.changePassword', { current, password: next })
    },
    async setUserPassword(uid, password) {
      await call('auth.setPassword', { uid, password })
    },
    async uploadImage(file, folder) {
      const fd = new FormData()
      // Downscale large photos before upload to keep the menu fast.
      const blob = await fetch(await fileToDataUrl(file, 1200)).then((r) => r.blob())
      fd.append('file', blob, 'image.jpg')
      fd.append('folder', folder)
      return call<string>('upload', {}, fd)
    },
    pushKey: () => call<string>('push.key').catch(() => null),
    async pushSubscribe(subscription) {
      await call('push.subscribe', { subscription })
    },
    async pushUnsubscribe(endpoint) {
      await call('push.unsubscribe', { endpoint })
    },
  }
  return backend
}
