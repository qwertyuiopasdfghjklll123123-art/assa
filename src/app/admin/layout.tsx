'use client'

import { Bell, Home, LayoutDashboard, LogOut, MapPin, ReceiptText, Settings, ShieldAlert, Users, UtensilsCrossed } from 'lucide-react'
import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useState, type ReactNode } from 'react'
import { useAuth, useToast } from '@/components/providers'
import { Button, Field, Spinner } from '@/components/ui'
import { getBackend } from '@/lib/backend'
import { cx } from '@/lib/format'
import { useCollection, useSettings } from '@/lib/hooks'
import type { Order } from '@/lib/models'
import { errorMessage } from '@/lib/services'

const NAV = [
  { href: '/admin/', label: 'نظرة عامة', icon: LayoutDashboard },
  { href: '/admin/orders/', label: 'الطلبات', icon: ReceiptText, badge: true },
  { href: '/admin/menu/', label: 'المنيو', icon: UtensilsCrossed },
  { href: '/admin/branches/', label: 'الفروع', icon: MapPin },
  { href: '/admin/users/', label: 'المستخدمون', icon: Users },
  { href: '/admin/notifications/', label: 'الإشعارات', icon: Bell },
  { href: '/admin/settings/', label: 'الإعدادات', icon: Settings },
]

export default function AdminLayout({ children }: { children: ReactNode }) {
  const { user, isAdmin, loading } = useAuth()
  const path = usePathname()
  const settings = useSettings()
  const { items: pending } = useCollection<Order>(isAdmin ? 'orders' : null, { field: 'status', op: '==', value: 'pending' })

  if (loading) return <Spinner className="min-h-dvh" />
  if (!user) return <AdminLogin />
  if (!isAdmin)
    return (
      <div className="grid min-h-dvh place-items-center p-6 text-center">
        <div>
          <ShieldAlert className="mx-auto size-12 text-danger" />
          <h1 className="mt-3 font-display text-xl font-extrabold">لا تملك صلاحية الوصول</h1>
          <p className="mt-1 text-sm text-ink-2">هذه الصفحة مخصصة لحساب الإدارة فقط.</p>
          <Link href="/home/">
            <Button className="mt-4">العودة للرئيسية</Button>
          </Link>
        </div>
      </div>
    )

  const active = (href: string) => (href === '/admin/' ? path === '/admin' || path === '/admin/' : path.startsWith(href.replace(/\/$/, '')))

  return (
    <div className="min-h-dvh lg:grid lg:grid-cols-[250px_1fr]">
      <aside className="sticky top-0 hidden h-dvh flex-col bg-ink p-4 text-white lg:flex">
        <div className="flex items-center gap-2.5 px-2 py-3">
          <span className="hero-grad grid size-10 place-items-center rounded-xl font-display text-lg font-black">{settings.restaurantName.slice(0, 1)}</span>
          <div>
            <div className="font-display font-extrabold">{settings.restaurantName}</div>
            <div className="text-xs text-sky-300">لوحة الإدارة</div>
          </div>
        </div>
        <nav className="mt-6 flex-1 space-y-1">
          {NAV.map(({ href, label, icon: Icon, badge }) => (
            <Link
              key={href}
              href={href}
              className={cx(
                'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition',
                active(href) ? 'bg-brand text-white shadow-[0_6px_16px_rgba(249,115,22,0.35)]' : 'text-white/70 hover:bg-white/10 hover:text-white',
              )}
            >
              <Icon className="size-5" />
              <span className="flex-1">{label}</span>
              {badge && pending.length > 0 && <span className="num rounded-full bg-sky px-2 py-0.5 text-xs font-bold">{pending.length}</span>}
            </Link>
          ))}
        </nav>
        <div className="space-y-1 border-t border-white/10 pt-3">
          <Link href="/home/" className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-white/70 hover:bg-white/10 hover:text-white">
            <Home className="size-5" /> عرض التطبيق
          </Link>
          <button
            onClick={async () => (await getBackend()).signOut()}
            className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-white/70 hover:bg-white/10 hover:text-white"
          >
            <LogOut className="size-5" /> تسجيل الخروج
          </button>
          <div className="truncate px-3 pt-2 text-xs text-white/50">{user.email}</div>
        </div>
      </aside>

      <div className="min-w-0">
        <header className="sticky top-0 z-30 border-b border-line bg-white/90 backdrop-blur lg:hidden">
          <div className="flex h-14 items-center justify-between px-4">
            <span className="font-display font-extrabold">
              {settings.restaurantName} <span className="text-sm font-semibold text-sky-600">· الإدارة</span>
            </span>
            <Link href="/home/" className="grid size-9 place-items-center rounded-lg border border-line text-ink-2" aria-label="عرض التطبيق">
              <Home className="size-4" />
            </Link>
          </div>
          <nav className="no-scrollbar flex gap-1 overflow-x-auto px-3 pb-2">
            {NAV.map(({ href, label, icon: Icon, badge }) => (
              <Link
                key={href}
                href={href}
                className={cx(
                  'flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold',
                  active(href) ? 'bg-brand text-white' : 'bg-bg text-ink-2',
                )}
              >
                <Icon className="size-4" /> {label}
                {badge && pending.length > 0 && <span className="num rounded-full bg-sky px-1.5 text-xs text-white">{pending.length}</span>}
              </Link>
            ))}
          </nav>
        </header>
        <main className="mx-auto max-w-6xl p-4 lg:p-8">{children}</main>
      </div>
    </div>
  )
}

function AdminLogin() {
  const settings = useSettings()
  const toast = useToast()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  return (
    <div className="navy-grad grid min-h-dvh place-items-center p-4">
      <form
        className="card w-full max-w-sm space-y-4 p-6"
        onSubmit={async (e) => {
          e.preventDefault()
          setBusy(true)
          try {
            await (await getBackend()).signIn(email, password)
          } catch (err) {
            toast(errorMessage(err), 'err')
            setBusy(false)
          }
        }}
      >
        <div className="flex items-center gap-3">
          <span className="hero-grad grid size-12 place-items-center rounded-xl font-display text-xl font-black text-white">{settings.restaurantName.slice(0, 1)}</span>
          <div>
            <h1 className="font-display text-xl font-extrabold">لوحة الإدارة</h1>
            <p className="text-sm text-ink-2">{settings.restaurantName}</p>
          </div>
        </div>
        <Field label="البريد الإلكتروني أو رقم الهاتف">
          <input className="field" dir="ltr" required autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} />
        </Field>
        <Field label="كلمة المرور">
          <input className="field" type="password" dir="ltr" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
        <Button type="submit" size="lg" className="w-full" loading={busy}>
          دخول
        </Button>
      </form>
    </div>
  )
}
