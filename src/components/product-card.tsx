'use client'

import { Minus, Plus } from 'lucide-react'
import { money } from '@/lib/format'
import type { Product } from '@/lib/models'
import { useCart, useToast } from './providers'
import { Thumb } from './ui'

export function QtyStepper({ qty, onChange }: { qty: number; onChange: (q: number) => void }) {
  return (
    <div className="flex h-9 items-center rounded-xl border border-line bg-white">
      <button onClick={() => onChange(qty + 1)} className="grid size-9 place-items-center text-brand" aria-label="زيادة">
        <Plus className="size-4" />
      </button>
      <span className="num w-6 text-center text-sm font-bold">{qty}</span>
      <button onClick={() => onChange(qty - 1)} className="grid size-9 place-items-center text-ink-2" aria-label="إنقاص">
        <Minus className="size-4" />
      </button>
    </div>
  )
}

export function ProductCard({ product, currency }: { product: Product; currency: string }) {
  const { items, add, setQty } = useCart()
  const toast = useToast()
  const inCart = items.find((i) => i.productId === product.id)

  return (
    <article className="card rise flex gap-3 p-3">
      <Thumb image={product.image} emoji={product.emoji} alt={product.name} className="size-24 shrink-0 rounded-xl sm:size-28" />
      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex items-start justify-between gap-2">
          <h3 className="font-bold leading-snug">{product.name}</h3>
          {product.featured && <span className="shrink-0 rounded-md bg-sky-50 px-1.5 py-0.5 text-[0.65rem] font-bold text-sky-700">مميز</span>}
        </div>
        <p className="mt-0.5 line-clamp-2 text-xs leading-relaxed text-ink-2">{product.description}</p>
        <div className="mt-auto flex items-center justify-between gap-2 pt-2">
          <span className="num font-display text-[1.05rem] font-extrabold text-brand-600">{money(product.price, currency)}</span>
          {!product.available ? (
            <span className="rounded-lg bg-bg px-2.5 py-1.5 text-xs font-semibold text-ink-3">غير متوفر حالياً</span>
          ) : inCart ? (
            <QtyStepper qty={inCart.qty} onChange={(q) => setQty(product.id, q)} />
          ) : (
            <button
              onClick={() => {
                add(product)
                toast(`أُضيف «${product.name}» إلى السلة`)
              }}
              className="flex h-9 items-center gap-1 rounded-xl bg-sky px-3 text-sm font-bold text-white transition hover:bg-sky-600 active:scale-95"
            >
              <Plus className="size-4" />
              أضف
            </button>
          )}
        </div>
      </div>
    </article>
  )
}
