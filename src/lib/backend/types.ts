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
 *  - firebase: Firestore + Firebase Auth + Storage (production)
 */
export interface Backend {
  mode: 'local' | 'firebase'
  list<T>(col: string, filter?: Filter): Promise<T[]>
  get<T>(col: string, id: string): Promise<T | null>
  /** Merge-writes `data` into the document (creates it if missing). */
  set(col: string, id: string, data: Record<string, unknown>): Promise<void>
  add(col: string, data: Record<string, unknown>): Promise<string>
  remove(col: string, id: string): Promise<void>
  subscribe<T>(col: string, filter: Filter | undefined, cb: (items: T[]) => void, onError?: (e: Error) => void): () => void
  subscribeDoc<T>(col: string, id: string, cb: (item: T | null) => void): () => void

  onAuth(cb: (info: AuthInfo | null) => void): () => void
  signIn(email: string, password: string): Promise<AuthInfo>
  signUp(email: string, password: string): Promise<AuthInfo>
  signInGoogle(): Promise<AuthInfo>
  signOut(): Promise<void>
  resetPassword(email: string): Promise<void>
  deleteAuthUser(): Promise<void>
  uploadImage(file: File, folder: string): Promise<string>
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
