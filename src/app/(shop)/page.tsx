'use client'

import { ArrowLeft, Clock, Gift, MapPin, Phone, Sparkles, Zap } from 'lucide-react'
import Link from 'next/link'
import { ProductCard } from '@/components/product-card'
import { useAuth, useBranch } from '@/components/providers'
import { BranchPicker } from '@/components/shop-shell'
import { Thumb } from '@/components/ui'
import { cx, num } from '@/lib/format'
import { byOrder, useCollection, useSettings } from '@/lib/hooks'
import type { Product } from '@/lib/models'

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
  const featured = products.filter((p) => p.featured).slice(0, 4)

  return (
    <div className="space-y-6">
      <section className="flex items-center justify-between gap-3">
        <div>
          <p className="text-sm font-medium text-ink-2">{greeting()} 👋</p>
          <h1 className="font-display text-2xl font-extrabold">{user ? user.name : 'أهلاً بك'}</h1>
        </div>
        <BranchPicker />
      </section>

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
          <Link
            href="/menu/"
            className="mt-5 inline-flex h-11 items-center gap-2 rounded-xl bg-white px-5 text-sm font-bold text-brand-600 shadow-lg transition hover:gap-3"
          >
            اطلب الآن <ArrowLeft className="size-4" />
          </Link>
        </div>
      </section>

      {/* Points + quick links */}
      <section className="grid gap-3 sm:grid-cols-2">
        <Link href={user ? '/loyalty/' : '/login/'} className="navy-grad flex items-center gap-4 rounded-2xl p-4 text-white shadow-card transition hover:opacity-95">
          <span className="grid size-12 place-items-center rounded-2xl bg-white/15 text-sky-300">
            <Zap className="size-6" />
          </span>
          <div className="flex-1">
            <h3 className="font-bold">نقاطك</h3>
            <p className="text-xs text-sky-100/80">{user ? 'استخدم نقاطك كخصم على طلبك القادم' : 'سجّل الدخول لتبدأ بجمع النقاط'}</p>
          </div>
          <div className="text-left">
            <div className="num font-display text-2xl font-black text-brand">{num(user?.points ?? 0)}</div>
            <div className="text-[0.7rem] font-semibold text-sky-100/80">نقطة</div>
          </div>
        </Link>
        <Link href="/orders/" className="card flex items-center gap-4 p-4 transition hover:border-brand">
          <span className="grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand">
            <Gift className="size-6" />
          </span>
          <div className="flex-1">
            <h3 className="font-bold">تتبّع طلباتك</h3>
            <p className="text-xs text-ink-2">تابع حالة طلبك لحظة بلحظة</p>
          </div>
          <ArrowLeft className="size-5 text-ink-3" />
        </Link>
      </section>

      {/* Featured */}
      {featured.length > 0 && (
        <section>
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-display text-lg font-extrabold">الأكثر طلباً</h2>
            <Link href="/menu/" className="text-sm font-semibold text-sky-600">
              عرض المنيو
            </Link>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            {featured.map((p) => (
              <ProductCard key={p.id} product={p} currency={settings.currency} />
            ))}
          </div>
        </section>
      )}

      {/* Branches */}
      <section>
        <h2 className="mb-3 font-display text-lg font-extrabold">فروعنا</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          {branches.map((b) => (
            <button
              key={b.id}
              onClick={() => setBranchId(b.id)}
              className={cx('card overflow-hidden text-right transition', b.id === branch?.id ? 'ring-2 ring-brand' : 'hover:border-sky')}
            >
              <Thumb image={b.image} emoji="🏪" alt={b.name} className="h-32" />
              <div className="p-4">
                <div className="flex items-center justify-between">
                  <h3 className="font-bold">{b.name}</h3>
                  <span className={cx('inline-flex items-center gap-1 text-xs font-semibold', b.open ? 'text-emerald-600' : 'text-red-600')}>
                    <Clock className="size-3.5" /> {b.open ? 'مفتوح الآن' : 'مغلق'}
                  </span>
                </div>
                <p className="mt-1 flex items-center gap-1.5 text-sm text-ink-2">
                  <MapPin className="size-4 shrink-0 text-sky" /> {b.address}
                </p>
                <p className="mt-1 flex items-center gap-1.5 text-sm text-ink-2">
                  <Phone className="size-4 shrink-0 text-sky" /> <span className="num">{b.phone}</span>
                </p>
              </div>
            </button>
          ))}
        </div>
      </section>
    </div>
  )
}
