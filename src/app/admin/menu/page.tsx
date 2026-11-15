'use client'

import { Pencil, Plus, Star, Trash2, UtensilsCrossed } from 'lucide-react'
import { useState } from 'react'
import { ImageInput } from '@/components/image-input'
import { useToast } from '@/components/providers'
import { Button, Empty, Field, PageTitle, Sheet, Thumb, Toggle } from '@/components/ui'
import { cx, money } from '@/lib/format'
import { byOrder, useCollection, useSettings } from '@/lib/hooks'
import type { Category, Product } from '@/lib/models'
import { errorMessage, removeDoc, saveDoc } from '@/lib/services'

type ProductDraft = Omit<Product, 'id'> & { id?: string }
type CategoryDraft = Omit<Category, 'id'> & { id?: string }

export default function AdminMenuPage() {
  const s = useSettings()
  const toast = useToast()
  const { items: categories } = useCollection<Category>('categories', undefined, byOrder)
  const { items: products } = useCollection<Product>('products', undefined, byOrder)
  const [cat, setCat] = useState<string>('all')
  const [product, setProduct] = useState<ProductDraft | null>(null)
  const [category, setCategory] = useState<CategoryDraft | null>(null)
  const [busy, setBusy] = useState(false)

  const shown = cat === 'all' ? products : products.filter((p) => p.categoryId === cat)

  async function run(fn: () => Promise<unknown>, ok: string) {
    setBusy(true)
    try {
      await fn()
      toast(ok)
      return true
    } catch (e) {
      toast(errorMessage(e), 'err')
      return false
    } finally {
      setBusy(false)
    }
  }

  const newProduct = (): ProductDraft => ({
    name: '',
    description: '',
    price: 0,
    image: '',
    emoji: '🍽️',
    categoryId: cat !== 'all' ? cat : (categories[0]?.id ?? ''),
    available: true,
    featured: false,
    order: products.length + 1,
  })

  return (
    <div>
      <PageTitle
        title="إدارة المنيو"
        subtitle={`${products.length} صنف في ${categories.length} قسم`}
        action={
          <Button onClick={() => setProduct(newProduct())} disabled={!categories.length}>
            <Plus className="size-4" /> صنف جديد
          </Button>
        }
      />

      {/* Categories */}
      <section className="card mb-5 p-4">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="font-bold">الأقسام</h2>
          <Button size="sm" variant="outline" onClick={() => setCategory({ name: '', icon: '🍽️', order: categories.length + 1 })}>
            <Plus className="size-4" /> قسم
          </Button>
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            onClick={() => setCat('all')}
            className={cx('rounded-lg border px-3 py-1.5 text-sm font-semibold', cat === 'all' ? 'border-brand bg-brand-50 text-brand-600' : 'border-line')}
          >
            الكل
          </button>
          {categories.map((c) => (
            <span
              key={c.id}
              className={cx(
                'inline-flex items-center gap-1 rounded-lg border ps-3 text-sm font-semibold',
                cat === c.id ? 'border-brand bg-brand-50 text-brand-600' : 'border-line',
              )}
            >
              <button onClick={() => setCat(c.id)} className="py-1.5">
                {c.icon} {c.name}
              </button>
              <button onClick={() => setCategory(c)} className="grid size-7 place-items-center text-ink-3 hover:text-sky-700" aria-label="تعديل القسم">
                <Pencil className="size-3.5" />
              </button>
            </span>
          ))}
        </div>
      </section>

      {shown.length === 0 ? (
        <Empty icon={<UtensilsCrossed className="size-8" />} title="لا توجد أصناف" text="أضف أول صنف لهذا القسم" />
      ) : (
        <div className="card divide-y divide-line overflow-hidden">
          {shown.map((p) => (
            <div key={p.id} className="flex items-center gap-3 p-3">
              <Thumb image={p.image} emoji={p.emoji} alt={p.name} className="size-14 shrink-0 rounded-xl" />
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5 font-bold">
                  <span className="truncate">{p.name}</span>
                  {p.featured && <Star className="size-4 shrink-0 fill-amber-400 text-amber-400" />}
                </div>
                <div className="text-xs text-ink-3">
                  {categories.find((c) => c.id === p.categoryId)?.name ?? '—'} · <span className="num">{money(p.price, s.currency)}</span>
                </div>
              </div>
              <div className="flex items-center gap-2 text-xs font-semibold text-ink-2">
                <span className="hidden sm:inline">{p.available ? 'متوفر' : 'غير متوفر'}</span>
                <Toggle
                  checked={p.available}
                  label="التوفر"
                  onChange={(v) => run(() => saveDoc('products', { ...p, available: v }), v ? 'الصنف متوفر الآن' : 'تم إخفاء التوفر')}
                />
              </div>
              <button onClick={() => setProduct(p)} className="grid size-9 place-items-center rounded-lg border border-line text-ink-2 hover:text-sky-700" aria-label="تعديل">
                <Pencil className="size-4" />
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Product editor */}
      <Sheet open={!!product} onClose={() => setProduct(null)} title={product?.id ? 'تعديل الصنف' : 'صنف جديد'}>
        {product && (
          <form
            className="space-y-3"
            onSubmit={async (e) => {
              e.preventDefault()
              if (await run(() => saveDoc('products', { ...product, price: Number(product.price) || 0, order: Number(product.order) || 0 }), 'تم حفظ الصنف'))
                setProduct(null)
            }}
          >
            <Field label="الصورة">
              <ImageInput folder="products" value={product.image} emoji={product.emoji} onChange={(image) => setProduct({ ...product, image })} />
            </Field>
            <div className="grid grid-cols-[1fr_80px] gap-2">
              <Field label="اسم الصنف">
                <input className="field" required value={product.name} onChange={(e) => setProduct({ ...product, name: e.target.value })} />
              </Field>
              <Field label="رمز">
                <input className="field text-center" value={product.emoji ?? ''} onChange={(e) => setProduct({ ...product, emoji: e.target.value })} />
              </Field>
            </div>
            <Field label="الوصف">
              <textarea className="field min-h-20" value={product.description} onChange={(e) => setProduct({ ...product, description: e.target.value })} />
            </Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label={`السعر (${s.currency})`}>
                <input className="field num" type="number" min={0} step={250} required value={product.price} onChange={(e) => setProduct({ ...product, price: Number(e.target.value) })} />
              </Field>
              <Field label="القسم">
                <select className="field" value={product.categoryId} onChange={(e) => setProduct({ ...product, categoryId: e.target.value })}>
                  {categories.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <div className="grid grid-cols-3 gap-2">
              <label className="flex items-center justify-between gap-2 rounded-xl border border-line p-3 text-sm font-semibold">
                متوفر <Toggle checked={product.available} onChange={(v) => setProduct({ ...product, available: v })} />
              </label>
              <label className="flex items-center justify-between gap-2 rounded-xl border border-line p-3 text-sm font-semibold">
                مميز <Toggle checked={!!product.featured} onChange={(v) => setProduct({ ...product, featured: v })} />
              </label>
              <Field label="الترتيب">
                <input className="field num" type="number" value={product.order} onChange={(e) => setProduct({ ...product, order: Number(e.target.value) })} />
              </Field>
            </div>
            <div className="flex gap-2 pt-2">
              <Button type="submit" className="flex-1" loading={busy}>
                حفظ
              </Button>
              {product.id && (
                <Button
                  type="button"
                  variant="danger"
                  onClick={async () => {
                    if (!confirm('حذف هذا الصنف نهائياً؟')) return
                    if (await run(() => removeDoc('products', product.id!), 'تم حذف الصنف')) setProduct(null)
                  }}
                >
                  <Trash2 className="size-4" />
                </Button>
              )}
            </div>
          </form>
        )}
      </Sheet>

      {/* Category editor */}
      <Sheet open={!!category} onClose={() => setCategory(null)} title={category?.id ? 'تعديل القسم' : 'قسم جديد'}>
        {category && (
          <form
            className="space-y-3"
            onSubmit={async (e) => {
              e.preventDefault()
              if (await run(() => saveDoc('categories', { ...category, order: Number(category.order) || 0 }), 'تم حفظ القسم')) setCategory(null)
            }}
          >
            <div className="grid grid-cols-[80px_1fr_90px] gap-2">
              <Field label="رمز">
                <input className="field text-center" value={category.icon ?? ''} onChange={(e) => setCategory({ ...category, icon: e.target.value })} />
              </Field>
              <Field label="اسم القسم">
                <input className="field" required value={category.name} onChange={(e) => setCategory({ ...category, name: e.target.value })} />
              </Field>
              <Field label="الترتيب">
                <input className="field num" type="number" value={category.order} onChange={(e) => setCategory({ ...category, order: Number(e.target.value) })} />
              </Field>
            </div>
            <div className="flex gap-2">
              <Button type="submit" className="flex-1" loading={busy}>
                حفظ
              </Button>
              {category.id && (
                <Button
                  type="button"
                  variant="danger"
                  onClick={async () => {
                    if (products.some((p) => p.categoryId === category.id)) return toast('انقل أو احذف أصناف هذا القسم أولاً', 'err')
                    if (!confirm('حذف هذا القسم؟')) return
                    if (await run(() => removeDoc('categories', category.id!), 'تم حذف القسم')) {
                      setCategory(null)
                      setCat('all')
                    }
                  }}
                >
                  <Trash2 className="size-4" />
                </Button>
              )}
            </div>
          </form>
        )}
      </Sheet>
    </div>
  )
}
