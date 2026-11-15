import { SEED, SEED_ADMIN } from './seed'
import { fileToDataUrl, matches, type AuthInfo, type Backend, type Filter } from './types'

/*
 * Local demo backend: everything lives in this browser's localStorage.
 * It is meant for trying the app without any server setup. Data is NOT shared
 * between devices — configure Firebase (see README) for production.
 */

const DB_KEY = 'sufra.db.v1'
const CRED_KEY = 'sufra.creds.v1'
const SESSION_KEY = 'sufra.session.v1'
const EVT = 'sufra-db-change'

type DB = Record<string, Record<string, Record<string, unknown>>>
type Creds = Record<string, { uid: string; hash: string }>

function read<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as T) : fallback
  } catch {
    return fallback
  }
}
function write(key: string, value: unknown) {
  localStorage.setItem(key, JSON.stringify(value))
  window.dispatchEvent(new Event(EVT))
}

async function hash(text: string) {
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('sufra:' + text))
  return Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, '0')).join('')
}

function uid() {
  return Date.now().toString(36) + Math.random().toString(36).slice(2, 8)
}

async function ensureSeed() {
  if (localStorage.getItem(DB_KEY)) return
  localStorage.setItem(DB_KEY, JSON.stringify(SEED))
  const creds: Creds = { [SEED_ADMIN.email]: { uid: SEED_ADMIN.uid, hash: await hash(SEED_ADMIN.password) } }
  localStorage.setItem(CRED_KEY, JSON.stringify(creds))
}

const db = () => read<DB>(DB_KEY, {})
const withId = (col: Record<string, Record<string, unknown>> | undefined) =>
  Object.entries(col ?? {}).map(([id, d]) => ({ ...d, id }))

function onChange(fn: () => void) {
  const storage = (e: StorageEvent) => {
    if (!e.key || e.key.startsWith('sufra.')) fn()
  }
  window.addEventListener(EVT, fn)
  window.addEventListener('storage', storage) // sync across tabs (e.g. admin + customer)
  return () => {
    window.removeEventListener(EVT, fn)
    window.removeEventListener('storage', storage)
  }
}

function session(): AuthInfo | null {
  return read<AuthInfo | null>(SESSION_KEY, null)
}

export async function createLocalBackend(): Promise<Backend> {
  await ensureSeed()

  const backend: Backend = {
    mode: 'local',
    async list<T>(col: string, filter?: Filter) {
      return withId(db()[col]).filter((d) => matches(d, filter)) as T[]
    },
    async get<T>(col: string, id: string) {
      const d = db()[col]?.[id]
      return d ? ({ ...d, id } as T) : null
    },
    async set(col, id, data) {
      const all = db()
      all[col] = all[col] ?? {}
      all[col][id] = { ...(all[col][id] ?? {}), ...data }
      write(DB_KEY, all)
    },
    async add(col, data) {
      const id = uid()
      await backend.set(col, id, data)
      return id
    },
    async remove(col, id) {
      const all = db()
      if (all[col]) delete all[col][id]
      write(DB_KEY, all)
    },
    subscribe<T>(col: string, filter: Filter | undefined, cb: (items: T[]) => void) {
      const emit = () => cb(withId(db()[col]).filter((d) => matches(d, filter)) as T[])
      emit()
      return onChange(emit)
    },
    subscribeDoc<T>(col: string, id: string, cb: (item: T | null) => void) {
      const emit = () => {
        const d = db()[col]?.[id]
        cb(d ? ({ ...d, id } as T) : null)
      }
      emit()
      return onChange(emit)
    },

    onAuth(cb) {
      let last = JSON.stringify(session())
      cb(session())
      return onChange(() => {
        const s = JSON.stringify(session())
        if (s !== last) {
          last = s
          cb(session())
        }
      })
    },
    async signIn(email, password) {
      const creds = read<Creds>(CRED_KEY, {})
      const c = creds[email.trim().toLowerCase()]
      if (!c || c.hash !== (await hash(password))) throw new Error('auth/invalid-credential')
      const info = { uid: c.uid, email: email.trim().toLowerCase() }
      write(SESSION_KEY, info)
      return info
    },
    async signUp(email, password) {
      const key = email.trim().toLowerCase()
      const creds = read<Creds>(CRED_KEY, {})
      if (creds[key]) throw new Error('auth/email-already-in-use')
      if (password.length < 6) throw new Error('auth/weak-password')
      const info = { uid: uid(), email: key }
      creds[key] = { uid: info.uid, hash: await hash(password) }
      localStorage.setItem(CRED_KEY, JSON.stringify(creds))
      write(SESSION_KEY, info)
      return info
    },
    async signInGoogle() {
      throw new Error('auth/google-needs-firebase')
    },
    async signOut() {
      localStorage.removeItem(SESSION_KEY)
      window.dispatchEvent(new Event(EVT))
    },
    async resetPassword() {
      throw new Error('auth/reset-needs-firebase')
    },
    async deleteAuthUser() {
      const s = session()
      if (!s) return
      const creds = read<Creds>(CRED_KEY, {})
      delete creds[s.email]
      localStorage.setItem(CRED_KEY, JSON.stringify(creds))
      await backend.signOut()
    },
    async uploadImage(file) {
      return fileToDataUrl(file, 600)
    },
  }
  return backend
}
