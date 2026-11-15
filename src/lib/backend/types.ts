export type Filter = { field: string; op: '==' | 'in'; value: unknown }

export type Doc = Record<string, unknown> & { id: string }

export interface AuthInfo {
  uid: string
  email: string
  name?: string
}

/**
 * Minimal storage + auth contract. Two implementations exist:
 *  - local:    browser-only demo database (localStorage), zero configuration
 *  - php:      the PHP + JSON-files API in /api (production)
 */
export interface Backend {
  mode: 'local' | 'php'
  /** True when the server recomputes orders and deducts redeemed points itself. */
  serverSide: boolean
  list<T>(col: string, filter?: Filter): Promise<T[]>
  get<T>(col: string, id: string): Promise<T | null>
  /** Merge-writes `data` into the document (creates it if missing). */
  set(col: string, id: string, data: Record<string, unknown>): Promise<void>
  add(col: string, data: Record<string, unknown>): Promise<string>
  remove(col: string, id: string): Promise<void>
  subscribe<T>(col: string, filter: Filter | undefined, cb: (items: T[]) => void, onError?: (e: Error) => void): () => void
  subscribeDoc<T>(col: string, id: string, cb: (item: T | null) => void): () => void

  onAuth(cb: (info: AuthInfo | null) => void): () => void
  /** `login` is a phone number (customers) or an e-mail (admin). */
  signIn(login: string, password: string): Promise<AuthInfo>
  /** Which customer sign-in methods are switched on. */
  authConfig(): Promise<{ googleClientId: string; whatsapp: boolean }>
  /** Sends a 6-digit code over WhatsApp. `exists` tells whether the number already has an account. */
  sendOtp(phone: string): Promise<{ phone: string; exists: boolean; debugCode?: string }>
  /** Signs in with the WhatsApp code, creating the account (with `name`) if the number is new. */
  signInWhatsApp(input: { phone: string; code: string; name?: string }): Promise<AuthInfo>
  /** Signs in with a Google OAuth access token, creating the account on first use. */
  signInGoogle(accessToken: string): Promise<AuthInfo>
  signOut(): Promise<void>
  deleteAuthUser(): Promise<void>
  changePassword(current: string, next: string): Promise<void>
  /** Admin only: sets a new password for a customer. */
  setUserPassword(uid: string, password: string): Promise<void>
  uploadImage(file: File, folder: string): Promise<string>
  /** Web Push: the server's public key, or null when push is unavailable (demo mode). */
  pushKey(): Promise<string | null>
  pushSubscribe(subscription: PushSubscriptionJSON): Promise<void>
  pushUnsubscribe(endpoint: string): Promise<void>
}

/** 07xx… / +964 7xx… / 00964… → 9647xx… */
export function normalizePhone(raw: string, country = '964') {
  let d = raw.replace(/\D+/g, '')
  if (d.startsWith('00')) d = d.slice(2)
  if (d.startsWith('0')) d = country + d.slice(1)
  else if (d.length <= 10 && !d.startsWith(country)) d = country + d
  return d
}

export function matches(doc: Record<string, unknown>, filter?: Filter) {
  if (!filter) return true
  const v = doc[filter.field]
  if (filter.op === '==') return v === filter.value
  return Array.isArray(filter.value) && filter.value.includes(v)
}

/** Downscale an image file to a JPEG data URL (used for local mode, and as a size guard). */
export function fileToDataUrl(file: File, max = 800): Promise<string> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    const url = URL.createObjectURL(file)
    img.onload = () => {
      const scale = Math.min(1, max / Math.max(img.width, img.height))
      const c = document.createElement('canvas')
      c.width = Math.round(img.width * scale)
      c.height = Math.round(img.height * scale)
      c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height)
      URL.revokeObjectURL(url)
      resolve(c.toDataURL('image/jpeg', 0.82))
    }
    img.onerror = reject
    img.src = url
  })
}
