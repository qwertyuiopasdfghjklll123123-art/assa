'use client'

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { getBackend, type AuthInfo } from '@/lib/backend'
import { byOrder, useCollection } from '@/lib/hooks'
import type { Branch, OrderItem, Product, UserProfile } from '@/lib/models'
import { ensureProfile } from '@/lib/services'
import { clearPrivate, docKey, readKey, subscribeKey } from '@/lib/store'

const PROFILE_KEY = 'sufra.profile'

/* ------------------------------------------------------------------ auth */

interface AuthCtx {
  auth: AuthInfo | null
  user: UserProfile | null
  loading: boolean
  isAdmin: boolean
}
const AuthContext = createContext<AuthCtx>({ auth: null, user: null, loading: true, isAdmin: false })

export function AuthProvider({ children }: { children: ReactNode }) {
  const [auth, setAuth] = useState<AuthInfo | null>(null)
  const [authLoading, setAuthLoading] = useState(true)
  // The profile is tagged with the uid it belongs to, so a stale profile is never shown.
  const [profile, setProfile] = useState<{ uid: string; data: UserProfile | null } | null>(null)

  useEffect(() => {
    let unsub: (() => void) | undefined
    getBackend().then((b) => {
      unsub = b.onAuth((info) => {
        setAuth(info)
        setAuthLoading(false)
      })
    })
    return () => unsub?.()
  }, [])

  useEffect(() => {
    if (!auth) return
    let unsub: (() => void) | undefined
    let alive = true
    // Show the last known profile at once (instant app start), then keep it live.
    try {
      const last = JSON.parse(localStorage.getItem(PROFILE_KEY) || 'null') as UserProfile | null
      // eslint-disable-next-line react-hooks/set-state-in-effect
      if (last?.id === auth.uid) setProfile({ uid: auth.uid, data: last })
    } catch {
      /* ignore */
    }
    getBackend().then(async (b) => {
      // The PHP server always creates the profile; the local demo may need it written.
      if (!b.serverSide) await ensureProfile(auth.uid, { email: auth.email, name: auth.name }).catch(() => null)
      if (!alive) return
      const key = docKey('users', auth.uid)
      unsub = subscribeKey(key, () => {
        const p = readKey(key) as UserProfile | null | undefined
        if (p === undefined) return
        if (p?.deleted || p?.blocked) {
          b.signOut()
          return
        }
        try {
          localStorage.setItem(PROFILE_KEY, JSON.stringify(p))
        } catch {
          /* ignore */
        }
        setProfile({ uid: auth.uid, data: p })
      })
    })
    return () => {
      alive = false
      unsub?.()
    }
  }, [auth])

  // Signing out: forget cached private data.
  useEffect(() => {
    if (authLoading || auth) return
    clearPrivate()
    try {
      localStorage.removeItem(PROFILE_KEY)
    } catch {
      /* ignore */
    }
  }, [auth, authLoading])

  const user = auth && profile?.uid === auth.uid ? profile.data : null
  const value = useMemo(
    () => ({
      auth,
      user,
      loading: authLoading || (!!auth && profile?.uid !== auth.uid),
      isAdmin: user?.role === 'admin',
    }),
    [auth, user, authLoading, profile],
  )
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export const useAuth = () => useContext(AuthContext)

/* ------------------------------------------------------------------ branch */

interface BranchCtx {
  branches: Branch[]
  branch: Branch | null
  setBranchId: (id: string) => void
}
const BranchContext = createContext<BranchCtx>({ branches: [], branch: null, setBranchId: () => {} })

export function BranchProvider({ children }: { children: ReactNode }) {
  const { items: branches } = useCollection<Branch>('branches', undefined, byOrder)
  const [branchId, setId] = useState<string | null>(null)

  useEffect(() => {
    try {
      // Read after mount so the static HTML and the first client render match.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setId(localStorage.getItem('sufra.branch'))
    } catch {
      /* storage unavailable */
    }
  }, [])

  const setBranchId = useCallback((id: string) => {
    setId(id)
    try {
      localStorage.setItem('sufra.branch', id)
    } catch {
      /* storage unavailable */
    }
  }, [])

  const branch = branches.find((b) => b.id === branchId) ?? branches[0] ?? null
  return <BranchContext.Provider value={{ branches, branch, setBranchId }}>{children}</BranchContext.Provider>
}

export const useBranch = () => useContext(BranchContext)

/* ------------------------------------------------------------------ cart */

interface CartCtx {
  items: OrderItem[]
  count: number
  add: (p: Product, qty?: number, note?: string) => void
  setQty: (productId: string, qty: number) => void
  clear: () => void
}
const CartContext = createContext<CartCtx>({ items: [], count: 0, add: () => {}, setQty: () => {}, clear: () => {} })

export function CartProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<OrderItem[]>([])
  const [ready, setReady] = useState(false)

  useEffect(() => {
    try {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setItems(JSON.parse(localStorage.getItem('sufra.cart') || '[]'))
    } catch {
      /* ignore */
    }
    setReady(true)
  }, [])

  useEffect(() => {
    if (!ready) return
    try {
      localStorage.setItem('sufra.cart', JSON.stringify(items))
    } catch {
      /* ignore */
    }
  }, [items, ready])

  const add = useCallback((p: Product, qty = 1) => {
    setItems((cur) => {
      const found = cur.find((i) => i.productId === p.id)
      if (found) return cur.map((i) => (i.productId === p.id ? { ...i, qty: Math.min(50, i.qty + qty) } : i))
      return [...cur, { productId: p.id, name: p.name, price: p.price, qty, image: p.image, emoji: p.emoji }]
    })
  }, [])
  const setQty = useCallback((productId: string, qty: number) => {
    setItems((cur) => (qty <= 0 ? cur.filter((i) => i.productId !== productId) : cur.map((i) => (i.productId === productId ? { ...i, qty } : i))))
  }, [])
  const clear = useCallback(() => setItems([]), [])
  const count = items.reduce((t, i) => t + i.qty, 0)

  return <CartContext.Provider value={{ items, count, add, setQty, clear }}>{children}</CartContext.Provider>
}

export const useCart = () => useContext(CartContext)

/* ------------------------------------------------------------------ toast */

const ToastContext = createContext<(msg: string, tone?: 'ok' | 'err') => void>(() => {})

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toast, setToast] = useState<{ msg: string; tone: 'ok' | 'err'; id: number } | null>(null)
  const show = useCallback((msg: string, tone: 'ok' | 'err' = 'ok') => {
    const id = Date.now()
    setToast({ msg, tone, id })
    setTimeout(() => setToast((t) => (t?.id === id ? null : t)), 2600)
  }, [])
  return (
    <ToastContext.Provider value={show}>
      {children}
      {toast && (
        <div className="pointer-events-none fixed inset-x-0 top-4 z-[100] flex justify-center px-4">
          <div
            role="status"
            className={`toast-in rounded-xl px-4 py-3 text-sm font-semibold shadow-lg ${
              toast.tone === 'ok' ? 'bg-ink text-white' : 'bg-danger text-white'
            }`}
          >
            {toast.msg}
          </div>
        </div>
      )}
    </ToastContext.Provider>
  )
}

export const useToast = () => useContext(ToastContext)

export function Providers({ children }: { children: ReactNode }) {
  return (
    <ToastProvider>
      <AuthProvider>
        <BranchProvider>
          <CartProvider>{children}</CartProvider>
        </BranchProvider>
      </AuthProvider>
    </ToastProvider>
  )
}
