'use client'

import { Bell, Gift, Home, LayoutDashboard, MapPin, ReceiptText, ShoppingBag, User, UtensilsCrossed } from 'lucide-react'
import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useState, type ReactNode } from 'react'
import { cx } from '@/lib/format'
import { useCollection, useSettings } from '@/lib/hooks'
import type { AppNotification, Order } from '@/lib/models'
import { NotifyPrompt } from './notify-prompt'
import { ProductSheetProvider } from './product-card'
import { useAuth, useBranch, useCart } from './providers'
import { Logo, Sheet } from './ui'

const NAV = [
  { href: '/', label: 'الرئيسية', icon: Home },
  { href: '/menu/', label: 'المنيو', icon: UtensilsCrossed },
  { href: '/orders/', label: 'طلباتي', icon: ReceiptText },
  { href: '/loyalty/', label: 'الولاء', icon: Gift },
  { href: '/account/', label: 'حسابي', icon: User },
]

const isActive = (path: string, href: string) => (href === '/' ? path === '/' : path.startsWith(href.replace(/\/$/, '')))

export function useUnreadCount() {
  const { user } = useAuth()
  const { items } = useCollection<AppNotification>(
    user ? 'notifications' : null,
    user ? { field: 'userId', op: 'in', value: [user.id, 'all'] } : undefined,
  )
  const seen = user?.notifSeenAt ?? 0
  return items.filter((n) => n.createdAt > seen && n.createdAt >= (n.userId === 'all' ? (user?.createdAt ?? 0) - 7 * 864e5 : 0)).length
}

export function BranchPicker() {
  const { branches, branch, setBranchId } = useBranch()
  const [open, setOpen] = useState(false)
  if (!branch) return null
  return (
    <>
      <button
        onClick={() => setOpen(true)}
        className="flex h-10 items-center gap-1.5 rounded-xl border border-sky-100 bg-sky-50 px-3 text-sm font-semibold text-sky-700 transition hover:border-sky"
      >
        <MapPin className="size-4" />
        <span className="max-w-[7rem] truncate">{branch.name}</span>
      </button>
      <Sheet open={open} onClose={() => setOpen(false)} title="اختر الفرع">
        <div className="space-y-2">
          {branches.map((b) => (
            <button
              key={b.id}
              onClick={() => {
                setBranchId(b.id)
                setOpen(false)
              }}
              className={cx(
                'flex w-full items-start gap-3 rounded-2xl border p-3 text-right transition',
                b.id === branch.id ? 'border-brand bg-brand-50' : 'border-line hover:border-sky',
              )}
            >
              <span className="mt-0.5 grid size-10 shrink-0 place-items-center rounded-xl bg-white text-brand shadow-card">
                <MapPin className="size-5" />
              </span>
              <span className="flex-1">
                <span className="flex items-center gap-2 font-bold">
                  {b.name}
                  <span className={cx('rounded-full px-2 py-0.5 text-[0.7rem]', b.open ? 'bg-ok-50 text-emerald-700' : 'bg-danger-50 text-red-700')}>
                    {b.open ? 'مفتوح' : 'مغلق'}
                  </span>
                </span>
                <span className="mt-0.5 block text-sm text-ink-2">{b.address}</span>
              </span>
            </button>
          ))}
        </div>
      </Sheet>
    </>
  )
}

export function IconLink({ href, label, children, badge }: { href: string; label: string; children: ReactNode; badge?: number }) {
  return (
    <Link
      href={href}
      aria-label={label}
      className="relative grid size-10 place-items-center rounded-xl border border-line bg-card text-ink-2 transition hover:border-brand hover:text-brand"
    >
      {children}
      {!!badge && (
        <span className="num absolute -top-1.5 -left-1.5 grid h-5 min-w-5 place-items-center rounded-full bg-brand px-1 text-[0.7rem] font-bold text-white ring-2 ring-bg">
          {badge}
        </span>
      )}
    </Link>
  )
}

export function ShopShell({ children }: { children: ReactNode }) {
  const path = usePathname()
  const settings = useSettings()
  const { isAdmin, user } = useAuth()
  const { count } = useCart()
  const unread = useUnreadCount()
  const { items: myOrders } = useCollection<Order>(user ? 'orders' : null, user ? { field: 'userId', op: '==', value: user.id } : undefined)
  const activeOrders = myOrders.filter((o) => ['pending', 'confirmed', 'preparing', 'on_the_way'].includes(o.status)).length

  return (
    <div className="min-h-dvh pb-24 md:pb-10">
      <header className="sticky top-0 z-30 border-b border-line bg-white/85 backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-5xl items-center justify-between gap-3 px-4">
          <Logo name={settings.restaurantName} />
          <nav className="hidden items-center gap-1 md:flex">
            {NAV.map(({ href, label }) => (
              <Link
                key={href}
                href={href}
                className={cx(
                  'rounded-lg px-3 py-2 text-sm font-semibold transition',
                  isActive(path, href) ? 'bg-brand-50 text-brand-600' : 'text-ink-2 hover:text-ink',
                )}
              >
                {label}
              </Link>
            ))}
          </nav>
          <div className="flex items-center gap-2">
            {isAdmin && (
              <IconLink href="/admin/" label="لوحة التحكم">
                <LayoutDashboard className="size-5" />
              </IconLink>
            )}
            <IconLink href="/notifications/" label="الإشعارات" badge={unread}>
              <Bell className="size-5" />
            </IconLink>
            <IconLink href="/cart/" label="السلة" badge={count}>
              <ShoppingBag className="size-5" />
            </IconLink>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-4 pt-5">
        <ProductSheetProvider>{children}</ProductSheetProvider>
        <NotifyPrompt />
      </main>

      <nav
        className="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-white/95 px-2 pt-1.5 pb-[max(0.5rem,env(safe-area-inset-bottom))] shadow-[0_-6px_24px_rgba(11,42,74,0.06)] backdrop-blur md:hidden"
      >
        <ul className="grid grid-cols-5">
          {NAV.map(({ href, label, icon: Icon }) => {
            const active = isActive(path, href)
            return (
              <li key={href}>
                <Link
                  href={href}
                  className={cx('relative flex flex-col items-center gap-1 py-1.5 text-[0.7rem] font-semibold transition', active ? 'text-brand-600' : 'text-ink-3 hover:text-ink')}
                >
                  <span className={cx('grid h-8 w-12 place-items-center rounded-full transition', active && 'bg-brand-50')}>
                    <Icon className="size-[1.35rem]" strokeWidth={active ? 2.4 : 1.9} />
                  </span>
                  {label}
                  {href === '/orders/' && activeOrders > 0 && (
                    <span className="num absolute top-0 right-[calc(50%-1.4rem)] grid size-4 place-items-center rounded-full bg-sky text-[0.6rem] font-bold text-white ring-2 ring-white">
                      {activeOrders}
                    </span>
                  )}
                </Link>
              </li>
            )
          })}
        </ul>
      </nav>
    </div>
  )
}
