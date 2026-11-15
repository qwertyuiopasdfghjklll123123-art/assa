import seedJson from '../../../api/seed.json'
import { fileToDataUrl, matches, normalizePhone, type AuthInfo, type Backend, type Filter } from './types'

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

/** Demo admin for local mode (same defaults as api/config.php). */
const SEED_ADMIN = { uid: 'admin', email: 'admin@sufra.app', password: 'admin123' }

async function ensureSeed() {
  if (localStorage.getItem(DB_KEY)) return
  const now = Date.now()
  const seed = JSON.parse(JSON.stringify(seedJson)) as DB
  for (const rows of Object.values(seed)) for (const r of Object.values(rows)) if ('createdAt' in r) r.createdAt = now
  seed.users = { admin: { name: 'مدير النظام', email: SEED_ADMIN.email, login: SEED_ADMIN.email, phone: '', role: 'admin', points: 0, totalOrders: 0, totalSpent: 0, createdAt: now } }
  seed.orders = {}
  localStorage.setItem(DB_KEY, JSON.stringify(seed))
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

const otps = new Map<string, { code: string; purpose: string }>()
function checkOtp(phone: string, purpose: string, code: string) {
  const o = otps.get(phone)
  if (!o || o.purpose !== purpose) throw new Error('otp/expired')
  if (o.code !== code.trim()) throw new Error('otp/invalid')
  otps.delete(phone)
}

function session(): AuthInfo | null {
  return read<AuthInfo | null>(SESSION_KEY, null)
}

export async function createLocalBackend(): Promise<Backend> {
  await ensureSeed()

  const backend: Backend = {
    mode: 'local',
    serverSide: false,
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
    async signIn(login, password) {
      const key = login.includes('@') ? login.trim().toLowerCase() : normalizePhone(login)
      const creds = read<Creds>(CRED_KEY, {})
      const c = creds[key]
      if (!c || !c.hash || c.hash !== (await hash(password))) throw new Error('auth/invalid-credential')
      const info = { uid: c.uid, email: key }
      write(SESSION_KEY, info)
      return info
    },
    async authConfig() {
      return { googleClientId: '', whatsapp: true }
    },
    async sendOtp(raw) {
      const phone = normalizePhone(raw)
      if (phone.length < 10 || phone.length > 15) throw new Error('auth/invalid-phone')
      const c = read<Creds>(CRED_KEY, {})[phone]
      const exists = !!c && !!(await backend.get<{ deleted?: boolean }>('users', c.uid)) && !(await backend.get<{ deleted?: boolean }>('users', c.uid))?.deleted
      // Demo mode cannot send WhatsApp messages: the code is shown on screen instead.
      const code = String(Math.floor(100000 + Math.random() * 900000))
      otps.set(phone, { code, purpose: 'auth' })
      return { phone, exists, debugCode: code }
    },
    async signInWhatsApp({ phone: raw, code, name }) {
      const phone = normalizePhone(raw)
      const creds = read<Creds>(CRED_KEY, {})
      const existing = creds[phone] ? await backend.get<{ deleted?: boolean }>('users', creds[phone].uid) : null
      const isNew = !existing || existing.deleted
      if (isNew && !name?.trim()) throw new Error('auth/name-required')
      checkOtp(phone, 'auth', code)
      if (!isNew) {
        const info = { uid: creds[phone].uid, email: phone }
        write(SESSION_KEY, info)
        return info
      }
      const info = { uid: uid(), email: phone }
      creds[phone] = { uid: info.uid, hash: '' }
      localStorage.setItem(CRED_KEY, JSON.stringify(creds))
      await backend.set('users', info.uid, {
        name: name!.trim(), email: '', phone: '+' + phone, login: phone, loginMethod: 'whatsapp', role: 'user',
        points: 0, totalOrders: 0, totalSpent: 0, createdAt: Date.now(),
      })
      write(SESSION_KEY, info)
      return info
    },
    async signUpEmail({ name, email, password }) {
      const key = email.trim().toLowerCase()
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(key)) throw new Error('auth/invalid-email')
      if (!name.trim()) throw new Error('auth/name-required')
      if (password.length < 6) throw new Error('auth/weak-password')
      const creds = read<Creds>(CRED_KEY, {})
      if (creds[key]) throw new Error('auth/email-already-in-use')
      const info = { uid: uid(), email: key }
      creds[key] = { uid: info.uid, hash: await hash(password) }
      localStorage.setItem(CRED_KEY, JSON.stringify(creds))
      await backend.set('users', info.uid, {
        name: name.trim(), email: key, phone: '', login: key, loginMethod: 'email', role: 'user',
        points: 0, totalOrders: 0, totalSpent: 0, createdAt: Date.now(),
      })
      write(SESSION_KEY, info)
      return info
    },
    async signInGoogle() {
      throw new Error('auth/google-not-configured')
    },
    async signOut() {
      localStorage.removeItem(SESSION_KEY)
      window.dispatchEvent(new Event(EVT))
    },
    async deleteAuthUser() {
      const s = session()
      if (!s) return
      const creds = read<Creds>(CRED_KEY, {})
      delete creds[s.email]
      localStorage.setItem(CRED_KEY, JSON.stringify(creds))
      await backend.signOut()
    },
    async changePassword(current, next) {
      const s = session()
      if (!s) throw new Error('unauthenticated')
      const creds = read<Creds>(CRED_KEY, {})
      if (creds[s.email]?.hash !== (await hash(current))) throw new Error('auth/wrong-password')
      if (next.length < 6) throw new Error('auth/weak-password')
      creds[s.email].hash = await hash(next)
      localStorage.setItem(CRED_KEY, JSON.stringify(creds))
    },
    async setUserPassword(uid, password) {
      if (password.length < 6) throw new Error('auth/weak-password')
      const creds = read<Creds>(CRED_KEY, {})
      const email = Object.keys(creds).find((e) => creds[e].uid === uid)
      if (!email) throw new Error('not-found')
      creds[email].hash = await hash(password)
      localStorage.setItem(CRED_KEY, JSON.stringify(creds))
    },
    async uploadImage(file) {
      return fileToDataUrl(file, 600)
    },
    pushKey: async () => null,
    pushSubscribe: async () => {},
    pushUnsubscribe: async () => {},
  }
  return backend
}
