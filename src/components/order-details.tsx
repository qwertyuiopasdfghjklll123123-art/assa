'use client'

import { Check, MapPin, Phone, Store } from 'lucide-react'
import { cx, dateTime, money, num } from '@/lib/format'
import { STATUS_LABEL, type Order, type OrderStatus } from '@/lib/models'
import { Thumb } from './ui'

const FLOW: OrderStatus[] = ['pending', 'confirmed', 'preparing', 'on_the_way', 'delivered']

export function OrderTimeline({ order }: { order: Order }) {
  if (order.status === 'cancelled')
    return <div className="rounded-xl bg-danger-50 p-3 text-center text-sm font-bold text-red-700">تم إلغاء هذا الطلب</div>
  const flow = order.type === 'pickup' ? FLOW.filter((s) => s !== 'on_the_way') : FLOW
  const idx = flow.indexOf(order.status)
  return (
    <ol className="flex items-start">
      {flow.map((s, i) => (
        <li key={s} className="relative flex flex-1 flex-col items-center text-center">
          {i > 0 && <span className={cx('absolute top-3.5 left-1/2 h-0.5 w-full', i <= idx ? 'bg-brand' : 'bg-line')} />}
          <span
            className={cx(
              'relative z-10 grid size-7 place-items-center rounded-full border-2 text-xs font-bold',
              i < idx && 'border-brand bg-brand text-white',
              i === idx && 'border-brand bg-white text-brand ring-4 ring-brand-100',
              i > idx && 'border-line bg-white text-ink-3',
            )}
          >
            {i < idx ? <Check className="size-4" /> : i + 1}
          </span>
          <span className={cx('mt-1.5 text-[0.68rem] font-semibold', i <= idx ? 'text-ink' : 'text-ink-3')}>{STATUS_LABEL[s]}</span>
        </li>
      ))}
    </ol>
  )
}

export function OrderDetails({ order, currency }: { order: Order; currency: string }) {
  return (
    <div className="space-y-4">
      <OrderTimeline order={order} />
      <div className="divide-y divide-line rounded-2xl border border-line">
        {order.items.map((i) => (
          <div key={i.productId} className="flex items-center gap-3 p-3">
            <Thumb image={i.image} emoji={i.emoji} alt={i.name} className="size-12 shrink-0 rounded-lg" />
            <div className="flex-1">
              <div className="font-semibold">{i.name}</div>
              <div className="num text-xs text-ink-3">
                {num(i.qty)} × {money(i.price, currency)}
              </div>
            </div>
            <div className="num text-sm font-bold">{money(i.qty * i.price, currency)}</div>
          </div>
        ))}
      </div>
      <dl className="space-y-1.5 rounded-2xl bg-bg p-3 text-sm">
        <div className="flex justify-between">
          <dt className="text-ink-2">المجموع</dt>
          <dd className="num">{money(order.subtotal, currency)}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-ink-2">التوصيل</dt>
          <dd className="num">{money(order.deliveryFee, currency)}</dd>
        </div>
        {order.discount > 0 && (
          <div className="flex justify-between text-sky-700">
            <dt>خصم النقاط ({num(order.pointsUsed)})</dt>
            <dd className="num">- {money(order.discount, currency)}</dd>
          </div>
        )}
        <div className="flex justify-between border-t border-line pt-1.5 font-bold">
          <dt>الإجمالي</dt>
          <dd className="num text-brand-600">{money(order.total, currency)}</dd>
        </div>
        {!!order.pointsEarned && (
          <div className="flex justify-between text-xs text-ink-3">
            <dt>{order.pointsAwarded ? 'نقاط مكتسبة' : 'نقاط ستُضاف عند التسليم'}</dt>
            <dd className="num">+{num(order.pointsEarned)}</dd>
          </div>
        )}
      </dl>
      <div className="space-y-1.5 text-sm text-ink-2">
        <p className="flex items-center gap-2">
          <Store className="size-4 text-sky" /> {order.branchName} — {order.type === 'delivery' ? 'توصيل' : 'استلام من الفرع'}
        </p>
        {order.address && (
          <p className="flex items-center gap-2">
            <MapPin className="size-4 text-sky" /> {order.address}
          </p>
        )}
        <p className="flex items-center gap-2">
          <Phone className="size-4 text-sky" /> <span className="num">{order.userPhone}</span>
        </p>
        {order.notes && <p className="rounded-lg bg-warn-50 p-2 text-amber-800">ملاحظة: {order.notes}</p>}
        <p className="text-xs text-ink-3">{dateTime(order.createdAt)}</p>
      </div>
    </div>
  )
}
