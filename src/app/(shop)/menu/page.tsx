'use client'

import { Search, ShoppingBag, UtensilsCrossed, X } from 'lucide-react'
import Link from 'next/link'
import { useMemo, useState } from 'react'
import { ProductCard } from '@/components/product-card'
import { useCart } from '@/components/providers'
import { Empty, Spinner } from '@/components/ui'
import { cx, money, num } from '@/lib/format'
import { byOrder, useCollection, useSettings } from '@/lib/hooks'
import type { Category, Product } from '@/lib/models'

export default function MenuPage() {
  const settings = useSettings()
  const { items: categories } = useCollection<Category>('categories', undefined, byOrder)
  const { items: products, loading } = useCollection<Product>('products', undefined, byOrder)
  const { items: cart, count } = useCart()
  const [active, setActive] = useState<string>('all')
  const [q, setQ] = useState('')

  const term = q.trim()
  const groups = useMemo(() => {
    const match = (p: Product) => !term || p.name.includes(term) || p.description?.includes(term)
    const cats = active === 'all' ? categories : categories.filter((c) => c.id === active)
    return cats
      .map((c) => ({ cat: c, items: products.filter((p) => p.categoryId === c.id && match(p)).sort((a, b) => Number(b.available) - Number(a.available) || a.order - b.order) }))
      .filter((g) => g.items.length)
  }, [products, categories, active, term])

  const total = cart.reduce((t, i) => t + i.price * i.qty, 0)

  return (
    <div className="-mx-4 -mt-5">
      {/* Sticky search + categories */}
      <div className="sticky top-16 z-20 border-b border-line bg-white px-4 pt-4 pb-3">
        <div className="relative">
          <Search className="pointer-events-none absolute top-1/2 right-3.5 size-5 -translate-y-1/2 text-ink-3" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="ابحث عن طبقك المفضّل…" className="field rounded-2xl bg-bg pr-11" />
          {q && (
            <button onClick={() => setQ('')} className="absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" aria-label="مسح">
              <X className="size-5" />
            </button>
          )}
        </div>
        <div className="no-scrollbar -mx-4 mt-3 flex gap-2 overflow-x-auto px-4">
          {[{ id: 'all', name: 'الكل', icon: '🍽️', order: 0 } as Category, ...categories].map((c) => (
            <button
              key={c.id}
              onClick={() => setActive(c.id)}
              className={cx(
                'flex h-10 shrink-0 items-center gap-1.5 rounded-full border px-4 text-sm font-bold whitespace-nowrap transition',
                active === c.id ? 'border-brand bg-brand text-white shadow-[0_4px_14px_rgba(249,115,22,0.3)]' : 'border-line bg-white text-ink-2 hover:border-brand/40',
              )}
            >
              <span aria-hidden>{c.icon}</span>
              {c.name}
            </button>
          ))}
        </div>
      </div>

      <div className="px-4 pt-5">
        {loading ? (
          <Spinner />
        ) : groups.length === 0 ? (
          <Empty icon={<UtensilsCrossed className="size-8" />} title="لا توجد أطباق" text="جرّب قسماً آخر أو كلمة بحث مختلفة" />
        ) : (
          <div className="space-y-7">
            {groups.map(({ cat, items }) => (
              <section key={cat.id}>
                <div className="mb-3 flex items-center gap-2">
                  <span className="grid size-9 place-items-center rounded-xl bg-brand-50 text-lg">{cat.icon}</span>
                  <h2 className="font-display text-lg font-extrabold">{cat.name}</h2>
                  <span className="num rounded-full bg-bg px-2 py-0.5 text-xs font-bold text-ink-3">{num(items.length)}</span>
                </div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                  {items.map((p) => (
                    <ProductCard key={p.id} product={p} currency={settings.currency} />
                  ))}
                </div>
              </section>
            ))}
          </div>
        )}
      </div>

      {count > 0 && (
        <Link
          href="/cart/"
          className="rise fixed inset-x-3 bottom-[5.5rem] z-30 mx-auto flex max-w-md items-center justify-between rounded-2xl bg-brand px-4 py-3 text-white shadow-[0_12px_30px_rgba(249,115,22,0.4)] md:bottom-6"
        >
          <span className="flex items-center gap-2 font-bold">
            <span className="num relative grid size-9 place-items-center rounded-xl bg-white/20">
              <ShoppingBag className="size-5" />
              <span className="absolute -top-1.5 -left-1.5 grid size-5 place-items-center rounded-full bg-sky text-[0.65rem] ring-2 ring-brand">{count}</span>
            </span>
            عرض السلة
          </span>
          <span className="num font-display font-black">{money(total, settings.currency)}</span>
        </Link>
      )}
    </div>
  )
}
