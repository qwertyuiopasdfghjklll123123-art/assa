'use client'

import { Bike, ShoppingBag, Store, Trash2, Zap } from 'lucide-react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useState } from 'react'
import { QtyStepper } from '@/components/product-card'
import { useAuth, useBranch, useCart, useToast } from '@/components/providers'
import { BranchPicker } from '@/components/shop-shell'
import { Button, Empty, Field, PageTitle, Thumb, Toggle } from '@/components/ui'
import { cx, money, num } from '@/lib/format'
import { useSettings } from '@/lib/hooks'
import { errorMessage, placeOrder, quote } from '@/lib/services'

export default function CartPage() {
  const { items, setQty, clear } = useCart()
  const { user } = useAuth()
  const { branch } = useBranch()
  const settings = useSettings()
  const toast = useToast()
  const router = useRouter()

  const [type, setType] = useState<'delivery' | 'pickup'>('delivery')
  const [address, setAddress] = useState<string | null>(null)
  const [phone, setPhone] = useState<string | null>(null)
  const [notes, setNotes] = useState('')
  const [usePoints, setUsePoints] = useState(false)
  const [busy, setBusy] = useState(false)

  const addr = address ?? user?.address ?? ''
  const tel = phone ?? user?.phone ?? ''
  const q = quote(items, settings, type, user?.points ?? 0, usePoints)
  const belowMin = q.subtotal < settings.minOrder
  const cur = settings.currency

  if (!items.length)
    return (
      <Empty
        icon={<ShoppingBag className="size-8" />}
        title="سلتك فارغة"
        text="أضف بعض الأطباق من المنيو لتبدأ طلبك"
        action={
          <Link href="/menu/">
            <Button>تصفح المنيو</Button>
          </Link>
        }
      />
    )

  async function submit() {
    if (!user) {
      router.push('/login/?next=/cart/')
      return
    }
    if (!branch) return toast('اختر الفرع أولاً', 'err')
    if (!branch.open) return toast('هذا الفرع مغلق حالياً', 'err')
    if (!tel.trim()) return toast('أدخل رقم الهاتف', 'err')
    if (type === 'delivery' && !addr.trim()) return toast('أدخل عنوان التوصيل', 'err')
    setBusy(true)
    try {
      await placeOrder({ user, branch, items, settings, type, address: addr.trim(), phone: tel.trim(), notes: notes.trim(), usePoints })
      clear()
      toast('تم إرسال طلبك بنجاح 🎉')
      router.push('/orders/')
    } catch (e) {
      toast(errorMessage(e), 'err')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <PageTitle
        title="السلة"
        subtitle={`${num(items.length)} صنف`}
        action={
          <button onClick={clear} className="flex items-center gap-1 text-sm font-semibold text-danger">
            <Trash2 className="size-4" /> إفراغ
          </button>
        }
      />

      <div className="grid gap-5 lg:grid-cols-[1fr_380px]">
        <div className="space-y-4">
          <div className="card divide-y divide-line">
            {items.map((i) => (
              <div key={i.productId} className="flex items-center gap-3 p-3">
                <Thumb image={i.image} emoji={i.emoji} alt={i.name} className="size-16 shrink-0 rounded-xl" />
                <div className="min-w-0 flex-1">
                  <h3 className="truncate font-bold">{i.name}</h3>
                  <p className="num text-sm font-semibold text-brand-600">{money(i.price * i.qty, cur)}</p>
                </div>
                <QtyStepper qty={i.qty} onChange={(n) => setQty(i.productId, n)} />
              </div>
            ))}
          </div>

          <div className="card space-y-4 p-4">
            <div className="flex items-center justify-between">
              <h2 className="font-bold">طريقة الاستلام</h2>
              <BranchPicker />
            </div>
            <div className="grid grid-cols-2 gap-2 rounded-2xl bg-bg p-1">
              {(
                [
                  ['delivery', 'توصيل', Bike],
                  ['pickup', 'استلام من الفرع', Store],
                ] as const
              ).map(([k, label, Icon]) => (
                <button
                  key={k}
                  onClick={() => setType(k)}
                  className={cx(
                    'flex h-11 items-center justify-center gap-2 rounded-xl text-sm font-bold transition',
                    type === k ? 'bg-white text-brand-600 shadow-card' : 'text-ink-2',
                  )}
                >
                  <Icon className="size-4" /> {label}
                </button>
              ))}
            </div>
            <Field label="رقم الهاتف">
              <input className="field num text-right" inputMode="tel" value={tel} onChange={(e) => setPhone(e.target.value)} placeholder="07xx xxx xxxx" />
            </Field>
            {type === 'delivery' && (
              <Field label="عنوان التوصيل">
                <textarea className="field min-h-20" value={addr} onChange={(e) => setAddress(e.target.value)} placeholder="المنطقة، الشارع، أقرب نقطة دالة" />
              </Field>
            )}
            <Field label="ملاحظات (اختياري)">
              <input className="field" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="مثلاً: بدون بصل" />
            </Field>
          </div>
        </div>

        <aside className="card h-fit space-y-3 p-4 lg:sticky lg:top-20">
          <h2 className="font-bold">ملخص الطلب</h2>
          {user && user.points > 0 && (
            <div className="flex items-center justify-between rounded-xl border border-sky-100 bg-sky-50 p-3">
              <span className="flex items-center gap-2 text-sm font-semibold text-sky-700">
                <Zap className="size-4" /> استخدم {num(user.points)} نقطة
              </span>
              <Toggle checked={usePoints} onChange={setUsePoints} label="استخدام النقاط" />
            </div>
          )}
          <dl className="space-y-2 text-sm">
            <Row label="المجموع" value={money(q.subtotal, cur)} />
            <Row label="التوصيل" value={q.deliveryFee ? money(q.deliveryFee, cur) : 'مجاني'} />
            {q.discount > 0 && <Row label={`خصم النقاط (${num(q.pointsUsed)})`} value={`- ${money(q.discount, cur)}`} accent />}
          </dl>
          <div className="flex items-center justify-between border-t border-dashed border-line pt-3">
            <span className="font-bold">الإجمالي</span>
            <span className="num font-display text-xl font-black text-brand-600">{money(q.total, cur)}</span>
          </div>
          {belowMin && (
            <p className="rounded-lg bg-warn-50 p-2 text-xs font-semibold text-amber-700">
              الحد الأدنى للطلب <span className="num">{money(settings.minOrder, cur)}</span>
            </p>
          )}
          <Button className="w-full" size="lg" loading={busy} disabled={belowMin} onClick={submit}>
            {user ? 'تأكيد الطلب' : 'سجّل الدخول لإتمام الطلب'}
          </Button>
        </aside>
      </div>
    </div>
  )
}

function Row({ label, value, accent }: { label: string; value: string; accent?: boolean }) {
  return (
    <div className="flex items-center justify-between">
      <dt className="text-ink-2">{label}</dt>
      <dd className={cx('num font-semibold', accent && 'text-sky-600')}>{value}</dd>
    </div>
  )
}
