'use client'

import { Clock, MapPin, Navigation, Pencil, Phone, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { directionsLink } from '@/components/branch-card'
import { ImageInput } from '@/components/image-input'
import { LocationPicker } from '@/components/location-map'
import { useToast } from '@/components/providers'
import { Button, Empty, Field, PageTitle, Sheet, Thumb, Toggle } from '@/components/ui'
import { byOrder, useCollection } from '@/lib/hooks'
import type { Branch } from '@/lib/models'
import { errorMessage, removeDoc, saveDoc } from '@/lib/services'

type Draft = Omit<Branch, 'id'> & { id?: string }

export default function AdminBranchesPage() {
  const toast = useToast()
  const { items: branches } = useCollection<Branch>('branches', undefined, byOrder)
  const [edit, setEdit] = useState<Draft | null>(null)
  const [busy, setBusy] = useState(false)

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

  return (
    <div>
      <PageTitle
        title="الفروع"
        subtitle="الفروع التي يمكن للعملاء الطلب منها"
        action={
          <Button onClick={() => setEdit({ name: '', address: '', phone: '', image: '', hours: '', location: null, open: true, order: branches.length + 1 })}>
            <Plus className="size-4" /> فرع جديد
          </Button>
        }
      />
      {branches.length === 0 ? (
        <Empty icon={<MapPin className="size-8" />} title="لا توجد فروع" text="أضف فرعاً ليتمكن العملاء من الطلب" />
      ) : (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {branches.map((b) => (
            <div key={b.id} className="card overflow-hidden">
              <Thumb image={b.image} emoji="🏪" alt={b.name} className="h-28" />
              <div className="space-y-1.5 p-4">
                <div className="flex items-center justify-between">
                  <h3 className="font-bold">{b.name}</h3>
                  <div className="flex items-center gap-2 text-xs font-semibold text-ink-2">
                    {b.open ? 'مفتوح' : 'مغلق'}
                    <Toggle checked={b.open} label="حالة الفرع" onChange={(v) => run(() => saveDoc('branches', { ...b, open: v }), v ? 'تم فتح الفرع' : 'تم إغلاق الفرع')} />
                  </div>
                </div>
                <p className="flex items-center gap-1.5 text-sm text-ink-2">
                  <MapPin className="size-4 text-sky" /> {b.address}
                </p>
                <p className="flex items-center gap-1.5 text-sm text-ink-2">
                  <Phone className="size-4 text-sky" /> <span className="num">{b.phone}</span>
                </p>
                {b.hours && (
                  <p className="flex items-center gap-1.5 text-sm text-ink-2">
                    <Clock className="size-4 text-sky" /> {b.hours}
                  </p>
                )}
                <p className={b.location ? 'text-xs font-semibold text-emerald-600' : 'text-xs font-semibold text-amber-600'}>
                  {b.location ? '✓ الموقع محدد على الخريطة' : '⚠ لم يُحدد الموقع على الخريطة بعد'}
                </p>
                <div className="mt-2 grid grid-cols-2 gap-2">
                  <Button size="sm" variant="outline" onClick={() => setEdit(b)}>
                    <Pencil className="size-4" /> تعديل
                  </Button>
                  <a href={directionsLink(b)} target="_blank" rel="noreferrer">
                    <Button size="sm" variant="sky" className="w-full">
                      <Navigation className="size-4" /> خرائط جوجل
                    </Button>
                  </a>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      <Sheet open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? 'تعديل الفرع' : 'فرع جديد'}>
        {edit && (
          <form
            className="space-y-3"
            onSubmit={async (e) => {
              e.preventDefault()
              if (await run(() => saveDoc('branches', { ...edit, order: Number(edit.order) || 0 }), 'تم حفظ الفرع')) setEdit(null)
            }}
          >
            <Field label="صورة الفرع">
              <ImageInput folder="branches" value={edit.image} emoji="🏪" onChange={(image) => setEdit({ ...edit, image })} />
            </Field>
            <Field label="اسم الفرع">
              <input className="field" required value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} />
            </Field>
            <Field label="موقع الفرع على الخريطة" hint="يستخدمه العملاء لفتح الاتجاهات في خرائط جوجل">
              <LocationPicker
                value={edit.location ?? null}
                onChange={(location) => setEdit((cur) => (cur ? { ...cur, location } : cur))}
                onAddress={(address) => setEdit((cur) => (cur && !cur.address ? { ...cur, address } : cur))}
              />
            </Field>
            <Field label="العنوان">
              <input className="field" required value={edit.address} onChange={(e) => setEdit({ ...edit, address: e.target.value })} />
            </Field>
            <Field label="أوقات العمل (اختياري)">
              <input className="field" placeholder="مثال: يومياً 11 صباحاً – 12 ليلاً" value={edit.hours ?? ''} onChange={(e) => setEdit({ ...edit, hours: e.target.value })} />
            </Field>
            <div className="grid grid-cols-[1fr_90px] gap-2">
              <Field label="الهاتف">
                <input className="field num text-right" value={edit.phone} onChange={(e) => setEdit({ ...edit, phone: e.target.value })} />
              </Field>
              <Field label="الترتيب">
                <input className="field num" type="number" value={edit.order} onChange={(e) => setEdit({ ...edit, order: Number(e.target.value) })} />
              </Field>
            </div>
            <label className="flex items-center justify-between rounded-xl border border-line p-3 text-sm font-semibold">
              الفرع يستقبل الطلبات <Toggle checked={edit.open} onChange={(v) => setEdit({ ...edit, open: v })} />
            </label>
            <div className="flex gap-2">
              <Button type="submit" className="flex-1" loading={busy}>
                حفظ
              </Button>
              {edit.id && (
                <Button
                  type="button"
                  variant="danger"
                  onClick={async () => {
                    if (!confirm('حذف هذا الفرع؟')) return
                    if (await run(() => removeDoc('branches', edit.id!), 'تم حذف الفرع')) setEdit(null)
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
