'use client'

import { Search, ShoppingBag, UtensilsCrossed } from 'lucide-react'
import Link from 'next/link'
import { useMemo, useState } from 'react'
import { ProductCard } from '@/components/product-card'
import { useCart } from '@/components/providers'
import { Empty, Spinner } from '@/components/ui'
import { cx, money } from '@/lib/format'
import { byOrder, useCollection, useSettings } from '@/lib/hooks'
import type { Category, Product } from '@/lib/models'

export default function MenuPage() {
  const settings = useSettings()
  const { items: categories } = useCollection<Category>('categories', undefined, byOrder)
  const { items: products, loading } = useCollection<Product>('products', undefined, byOrder)
  const { items: cart, count } = useCart()
  const [active, setActive] = useState<string>('all')
  const [q, setQ] = useState('')

  const shown = useMemo(() => {
    const term = q.trim()
    const catOrder = new Map(categories.map((c) => [c.id, c.order]))
    return products
      .filter(
        (p) => (active === 'all' || p.categoryId === active) && (!term || p.name.includes(term) || p.description?.includes(term)),
      )
      .sort((a, b) => (catOrder.get(a.categoryId) ?? 99) - (catOrder.get(b.categoryId) ?? 99) || a.order - b.order)
  }, [products, categories, active, q])

  const total = cart.reduce((t, i) => t + i.price * i.qty, 0)

  return (
    <div>
      <div className="mb-4 flex items-end justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl font-extrabold">المنيو</h1>
          <p className="text-sm text-ink-2">اختر ما تشتهيه وأضفه إلى السلة</p>
        </div>
      </div>

      <div className="relative mb-4">
        <Search className="pointer-events-none absolute top-1/2 right-3.5 size-5 -translate-y-1/2 text-ink-3" />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="ابحث عن طبق…" className="field pr-11" />
      </div>

      {/* Categories: underline tabs */}
      <div className="no-scrollbar -mx-4 mb-5 overflow-x-auto border-b border-line px-4">
        <div className="flex gap-1">
          {[{ id: 'all', name: 'الكل', icon: '🍽️' } as Category, ...categories].map((c) => (
            <button
              key={c.id}
              onClick={() => setActive(c.id)}
              className={cx(
                'relative flex shrink-0 items-center gap-1.5 px-3 pt-1 pb-3 text-sm font-bold whitespace-nowrap transition',
                active === c.id ? 'text-brand-600' : 'text-ink-3 hover:text-ink',
              )}
            >
              <span aria-hidden>{c.icon}</span>
              {c.name}
              {active === c.id && <span className="absolute inset-x-2 -bottom-px h-[3px] rounded-full bg-brand" />}
            </button>
          ))}
        </div>
      </div>

      {loading ? (
        <Spinner />
      ) : shown.length === 0 ? (
        <Empty icon={<UtensilsCrossed className="size-8" />} title="لا توجد أطباق" text="جرّب قسماً آخر أو كلمة بحث مختلفة" />
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {shown.map((p) => (
            <ProductCard key={p.id} product={p} currency={settings.currency} />
          ))}
        </div>
      )}

      {count > 0 && (
        <Link
          href="/cart/"
          className="rise fixed inset-x-3 bottom-24 z-30 mx-auto flex max-w-md items-center justify-between rounded-2xl sky-grad px-4 py-3 text-white shadow-pop md:bottom-6"
        >
          <span className="flex items-center gap-2 font-bold">
            <span className="grid size-8 place-items-center rounded-lg bg-white/20">
              <ShoppingBag className="size-4" />
            </span>
            عرض السلة ({count})
          </span>
          <span className="num font-display font-extrabold">{money(total, settings.currency)}</span>
        </Link>
      )}
    </div>
  )
}
