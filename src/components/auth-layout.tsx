'use client'

import { Gift, ShieldCheck, Truck } from 'lucide-react'
import { useRouter, useSearchParams } from 'next/navigation'
import { useEffect, type ReactNode } from 'react'
import { isDemoMode } from '@/lib/backend'
import { useSettings } from '@/lib/hooks'
import { useAuth } from './providers'
import { Logo } from './ui'

/** Where to go after signing in: ?next=… or the admin panel / home page. */
export function useAfterAuth() {
  const { user, isAdmin } = useAuth()
  const router = useRouter()
  const params = useSearchParams()
  useEffect(() => {
    if (!user) return
    const next = params.get('next')
    router.replace(next && next.startsWith('/') && !next.startsWith('//') ? next : isAdmin ? '/admin/' : '/')
  }, [user, isAdmin, params, router])
}

export function AuthLayout({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
  const s = useSettings()
  return (
    <div className="grid min-h-dvh lg:grid-cols-2">
      <aside className="hero-grad relative hidden overflow-hidden p-10 text-white lg:flex lg:flex-col lg:justify-between">
        <div className="dots absolute inset-0 opacity-50" />
        <div className="absolute -bottom-24 -left-24 size-96 rounded-full bg-sky/50 blur-3xl" />
        <div className="relative font-display text-2xl font-black">{s.restaurantName}</div>
        <div className="relative max-w-md">
          <h2 className="font-display text-4xl leading-tight font-black">{s.tagline}</h2>
          <ul className="mt-8 space-y-4 text-white/95">
            {[
              [Truck, 'توصيل سريع أو استلام من الفرع'],
              [Gift, `استرجع ${s.loyaltyPercent}% من كل طلب كنقاط ولاء`],
              [ShieldCheck, 'تتبّع طلبك خطوة بخطوة'],
            ].map(([Icon, t]) => {
              const I = Icon as typeof Truck
              return (
                <li key={t as string} className="flex items-center gap-3">
                  <span className="grid size-10 place-items-center rounded-xl bg-white/20">
                    <I className="size-5" />
                  </span>
                  {t as string}
                </li>
              )
            })}
          </ul>
        </div>
        <div className="relative text-sm text-white/80">© {new Date().getFullYear()} {s.restaurantName}</div>
      </aside>

      <main className="flex flex-col items-center justify-center px-4 py-10">
        <div className="w-full max-w-sm">
          <div className="mb-8">
            <Logo name={s.restaurantName} />
          </div>
          <h1 className="font-display text-3xl font-black">{title}</h1>
          <p className="mt-1 mb-6 text-ink-2">{subtitle}</p>
          {children}
          {isDemoMode && (
            <p className="mt-6 rounded-xl border border-sky-100 bg-sky-50 p-3 text-xs leading-relaxed text-sky-800">
              وضع تجريبي: البيانات محفوظة في هذا المتصفح فقط. حساب الأدمن التجريبي:{' '}
              <b className="num">admin@sufra.app</b> / <b className="num">admin123</b>
            </p>
          )}
        </div>
      </main>
    </div>
  )
}
