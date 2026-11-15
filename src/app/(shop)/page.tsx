'use client'

import { ArrowLeft, ChevronLeft, ReceiptText, Sparkles, Zap } from 'lucide-react'
import Link from 'next/link'
import { BranchCard } from '@/components/branch-card'
import { ProductCard } from '@/components/product-card'
import { useAuth, useBranch } from '@/components/providers'
import { BranchPicker } from '@/components/shop-shell'
import { StatusBadge } from '@/components/ui'
import { num } from '@/lib/format'
import { byNewest, byOrder, useCollection, useSettings } from '@/lib/hooks'
import type { Category, Order, Product } from '@/lib/models'
import { orderCode } from '@/lib/services'

function greeting() {
  const h = new Date().getHours()
  if (h < 12) return 'صباح الخير'
  if (h < 18) return 'مساء الخير'
  return 'مساء النور'
}

export default function HomePage() {
  const { user } = useAuth()
  const settings = useSettings()
  const { branches, branch, setBranchId } = useBranch()
  const { items: products } = useCollection<Product>('products', undefined, byOrder)
  const { items: categories } = useCollection<Category>('categories', undefined, byOrder)
  const { items: orders } = useCollection<Order>(user ? 'orders' : null, user ? { field: 'userId', op: '==', value: user.id } : undefined, byNewest)
  const featured = products.filter((p) => p.featured).slice(0, 4)
  const live = orders.find((o) => ['pending', 'confirmed', 'preparing', 'on_the_way'].includes(o.status))

  return (
    <div className="space-y-7">
      <section className="flex items-center justify-between gap-3">
        <div>
          <p className="text-sm font-medium text-ink-2">{greeting()} 👋</p>
          <h1 className="font-display text-2xl font-extrabold">{user ? user.name : 'أهلاً بك'}</h1>
        </div>
        <BranchPicker />
      </section>

      {live && (
        <Link href="/orders/" className="flex items-center gap-3 rounded-2xl border border-sky-100 bg-sky-50 p-3.5 transition hover:border-sky">
          <span className="relative grid size-11 place-items-center rounded-xl bg-white text-sky">
            <ReceiptText className="size-5" />
            <span className="absolute -top-0.5 -left-0.5 size-3 animate-ping rounded-full bg-sky" />
          </span>
          <div className="flex-1">
            <div className="flex items-center gap-2 text-sm font-bold">
              طلبك الحالي <span className="num">#{orderCode(live.id)}</span>
            </div>
            <div className="mt-1">
              <StatusBadge status={live.status} />
            </div>
          </div>
          <ChevronLeft className="size-5 text-sky-700" />
        </Link>
      )}

      {/* Promo */}
      <section className="hero-grad relative overflow-hidden rounded-3xl p-5 text-white shadow-[0_14px_40px_rgba(249,115,22,0.3)] sm:p-7">
        <div className="dots absolute inset-0 opacity-60" />
        <div className="absolute -bottom-16 -left-10 size-48 rounded-full bg-sky/40 blur-2xl" />
        <div className="relative max-w-md">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-white/20 px-3 py-1 text-xs font-bold backdrop-blur">
            <Sparkles className="size-3.5" /> عرض الولاء
          </span>
          <h2 className="mt-3 font-display text-2xl font-black leading-tight sm:text-3xl">{settings.promoTitle}</h2>
          <p className="mt-2 text-sm leading-relaxed text-white/90">{settings.promoText}</p>
          <Link href="/menu/" className="mt-5 inline-flex h-11 items-center gap-2 rounded-xl bg-white px-5 text-sm font-bold text-brand-600 shadow-lg transition hover:gap-3">
            اطلب الآن <ArrowLeft className="size-4" />
          </Link>
        </div>
      </section>

      {/* Points */}
      <Link href={user ? '/loyalty/' : '/login/'} className="card flex items-center gap-4 p-4 transition hover:border-sky">
        <span className="sky-grad grid size-12 place-items-center rounded-2xl text-white shadow-[0_6px_16px_rgba(14,165,233,0.3)]">
          <Zap className="size-6" />
        </span>
        <div className="flex-1">
          <h3 className="font-bold">رصيد نقاطك</h3>
          <p className="text-xs text-ink-2">{user ? 'استخدمها كخصم على طلبك القادم' : 'سجّل الدخول لتبدأ بجمع النقاط'}</p>
        </div>
        <div className="text-left">
          <div className="num font-display text-2xl font-black text-sky-600">{num(user?.points ?? 0)}</div>
          <div className="text-[0.7rem] font-semibold text-ink-3">نقطة</div>
        </div>
      </Link>

      {/* Categories */}
      {categories.length > 0 && (
        <section>
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-display text-lg font-extrabold">الأقسام</h2>
            <Link href="/menu/" className="text-sm font-semibold text-sky-600">
              عرض الكل
            </Link>
          </div>
          <div className="no-scrollbar -mx-4 flex gap-3 overflow-x-auto px-4 pb-1">
            {categories.map((c) => (
              <Link key={c.id} href="/menu/" className="flex w-20 shrink-0 flex-col items-center gap-1.5">
                <span className="grid size-16 place-items-center rounded-2xl border border-line bg-white text-3xl shadow-card">{c.icon}</span>
                <span className="line-clamp-1 text-center text-xs font-semibold text-ink-2">{c.name}</span>
              </Link>
            ))}
          </div>
        </section>
      )}

      {featured.length > 0 && (
        <section>
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-display text-lg font-extrabold">الأكثر طلباً 🔥</h2>
            <Link href="/menu/" className="text-sm font-semibold text-sky-600">
              المنيو كاملاً
            </Link>
          </div>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {featured.map((p) => (
              <ProductCard key={p.id} product={p} currency={settings.currency} />
            ))}
          </div>
        </section>
      )}

      <section>
        <h2 className="mb-3 font-display text-lg font-extrabold">فروعنا</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          {branches.map((b) => (
            <BranchCard key={b.id} branch={b} selected={b.id === branch?.id} onSelect={() => setBranchId(b.id)} />
          ))}
        </div>
      </section>
    </div>
  )
}
