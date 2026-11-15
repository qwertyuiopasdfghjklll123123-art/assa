'use client'

import { Minus, Plus, ShoppingBag, Star } from 'lucide-react'
import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import { cx, money } from '@/lib/format'
import { useSettings } from '@/lib/hooks'
import type { Product } from '@/lib/models'
import { useCart, useToast } from './providers'
import { Sheet, Thumb } from './ui'

export function QtyStepper({ qty, onChange, size = 'sm', min = 0 }: { qty: number; onChange: (q: number) => void; size?: 'sm' | 'lg'; min?: number }) {
  const big = size === 'lg'
  return (
    <div className={cx('flex items-center rounded-full border border-line bg-white', big ? 'h-12 gap-1 p-1' : 'h-9')}>
      <button
        type="button"
        onClick={() => onChange(Math.min(50, qty + 1))}
        className={cx('grid place-items-center rounded-full text-white transition active:scale-90', big ? 'size-10 bg-brand' : 'size-9 text-brand')}
        aria-label="زيادة"
      >
        <Plus className={cx(big ? 'size-5' : 'size-4', !big && 'text-brand')} />
      </button>
      <span className={cx('num text-center font-black', big ? 'w-10 text-lg' : 'w-6 text-sm')}>{qty}</span>
      <button
        type="button"
        disabled={qty <= min}
        onClick={() => onChange(qty - 1)}
        className={cx('grid place-items-center rounded-full transition active:scale-90 disabled:opacity-40', big ? 'size-10 bg-bg text-ink' : 'size-9 text-ink-2')}
        aria-label="إنقاص"
      >
        <Minus className={big ? 'size-5' : 'size-4'} />
      </button>
    </div>
  )
}

/* ------------------------------------------------------------------ product sheet */

const SheetCtx = createContext<(p: Product) => void>(() => {})
export const useOpenProduct = () => useContext(SheetCtx)

export function ProductSheetProvider({ children }: { children: ReactNode }) {
  const [product, setProduct] = useState<Product | null>(null)
  const open = useCallback((p: Product) => setProduct(p), [])
  return (
    <SheetCtx.Provider value={open}>
      {children}
      <ProductSheet key={product?.id} product={product} onClose={() => setProduct(null)} />
    </SheetCtx.Provider>
  )
}

function ProductSheet({ product, onClose }: { product: Product | null; onClose: () => void }) {
  const { add, items } = useCart()
  const toast = useToast()
  const s = useSettings()
  const [qty, setQty] = useState(1)
  const inCart = product ? (items.find((i) => i.productId === product.id)?.qty ?? 0) : 0

  return (
    <Sheet open={!!product} onClose={onClose} title={product?.name ?? ''} hideTitle>
      {product && (
        <div className="-mx-5 -mt-5">
          <div className="relative">
            <Thumb image={product.image} emoji={product.emoji} alt={product.name} className="aspect-[4/3] w-full rounded-t-3xl [&>span]:text-8xl" />
            {product.featured && (
              <span className="absolute top-4 right-4 flex items-center gap-1 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-brand-600 shadow">
                <Star className="size-3.5 fill-current" /> الأكثر طلباً
              </span>
            )}
          </div>
          <div className="space-y-4 p-5">
            <div className="flex items-start justify-between gap-3">
              <h2 className="font-display text-2xl font-black leading-tight">{product.name}</h2>
              <span className="num shrink-0 font-display text-xl font-black text-brand-600">{money(product.price, s.currency)}</span>
            </div>
            {product.description && <p className="leading-relaxed text-ink-2">{product.description}</p>}
            {inCart > 0 && (
              <p className="rounded-xl bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700">
                لديك <span className="num">{inCart}</span> من هذا الصنف في السلة
              </p>
            )}

            {product.available ? (
              <>
                <div className="flex items-center justify-between rounded-2xl bg-bg p-3">
                  <span className="font-bold">الكمية</span>
                  <QtyStepper qty={qty} onChange={setQty} size="lg" min={1} />
                </div>
                <button
                  onClick={() => {
                    add(product, qty)
                    toast(`أُضيف ${qty} × «${product.name}» إلى السلة`)
                    onClose()
                  }}
                  className="flex h-14 w-full items-center justify-between rounded-2xl bg-brand px-5 font-bold text-white shadow-[0_8px_22px_rgba(249,115,22,0.35)] transition hover:bg-brand-600 active:scale-[0.99]"
                >
                  <span className="flex items-center gap-2">
                    <ShoppingBag className="size-5" /> إضافة إلى السلة
                  </span>
                  <span className="num rounded-xl bg-white/20 px-3 py-1">{money(product.price * qty, s.currency)}</span>
                </button>
              </>
            ) : (
              <div className="rounded-2xl bg-bg p-4 text-center font-semibold text-ink-3">هذا الصنف غير متوفر حالياً</div>
            )}
          </div>
        </div>
      )}
    </Sheet>
  )
}

/* ------------------------------------------------------------------ card */

export function ProductCard({ product, currency }: { product: Product; currency: string }) {
  const open = useOpenProduct()
  const { items } = useCart()
  const inCart = items.find((i) => i.productId === product.id)?.qty ?? 0

  return (
    <article
      role="button"
      tabIndex={0}
      onClick={() => open(product)}
      onKeyDown={(e) => e.key === 'Enter' && open(product)}
      className={cx(
        'rise group flex cursor-pointer flex-col overflow-hidden rounded-2xl border border-line bg-white shadow-card transition hover:-translate-y-0.5 hover:shadow-pop',
        !product.available && 'opacity-70',
      )}
    >
      <div className="relative">
        <Thumb image={product.image} emoji={product.emoji} alt={product.name} className="aspect-square w-full [&>span]:text-5xl" />
        {product.featured && (
          <span className="absolute top-2 right-2 rounded-full bg-white/95 px-2 py-0.5 text-[0.65rem] font-bold text-brand-600 shadow-sm">⭐ مميز</span>
        )}
        {!product.available && (
          <span className="absolute inset-x-2 bottom-2 rounded-lg bg-ink/80 py-1 text-center text-xs font-bold text-white">غير متوفر</span>
        )}
        {inCart > 0 && (
          <span className="num absolute top-2 left-2 grid size-6 place-items-center rounded-full bg-sky text-xs font-bold text-white shadow">{inCart}</span>
        )}
      </div>
      <div className="flex flex-1 flex-col p-3">
        <h3 className="line-clamp-1 font-bold">{product.name}</h3>
        <p className="mt-0.5 line-clamp-2 min-h-[2.2em] text-[0.72rem] leading-snug text-ink-3">{product.description}</p>
        <div className="mt-auto flex items-center justify-between pt-2">
          <span className="num font-display font-black text-ink">{money(product.price, currency)}</span>
          {product.available && (
            <span className="grid size-8 place-items-center rounded-full bg-brand text-white shadow-[0_4px_12px_rgba(249,115,22,0.35)] transition group-hover:scale-110">
              <Plus className="size-4" strokeWidth={2.6} />
            </span>
          )}
        </div>
      </div>
    </article>
  )
}
