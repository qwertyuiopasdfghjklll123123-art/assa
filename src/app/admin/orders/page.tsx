'use client'

import { Search } from 'lucide-react'
import { useRouter, useSearchParams } from 'next/navigation'
import { Suspense, useMemo, useState } from 'react'
import { OrderDetails } from '@/components/order-details'
import { useToast } from '@/components/providers'
import { Button, Empty, PageTitle, Sheet, Spinner, StatusBadge } from '@/components/ui'
import { cx, money, timeAgo } from '@/lib/format'
import { byNewest, useCollection, useSettings } from '@/lib/hooks'
import { ORDER_STATUSES, STATUS_LABEL, type Order, type OrderStatus } from '@/lib/models'
import { errorMessage, orderCode, setOrderStatus } from '@/lib/services'

const NEXT: Partial<Record<OrderStatus, OrderStatus>> = {
  pending: 'confirmed',
  confirmed: 'preparing',
  preparing: 'on_the_way',
  on_the_way: 'delivered',
}

export default function AdminOrdersPage() {
  return (
    <Suspense>
      <AdminOrders />
    </Suspense>
  )
}

function AdminOrders() {
  const s = useSettings()
  const toast = useToast()
  const router = useRouter()
  const params = useSearchParams()
  const openId = params.get('id')
  const { items: orders, loading } = useCollection<Order>('orders', undefined, byNewest)
  const [filter, setFilter] = useState<'active' | 'all' | OrderStatus>('active')
  const [q, setQ] = useState('')
  const [busy, setBusy] = useState<string | null>(null)

  const shown = useMemo(() => {
    const term = q.trim().toLowerCase()
    return orders.filter((o) => {
      if (filter === 'active' && ['delivered', 'cancelled'].includes(o.status)) return false
      if (filter !== 'active' && filter !== 'all' && o.status !== filter) return false
      if (!term) return true
      return orderCode(o.id).toLowerCase().includes(term) || o.userName.toLowerCase().includes(term) || o.userPhone.includes(term)
    })
  }, [orders, filter, q])

  const open = orders.find((o) => o.id === openId) ?? null
  const setOpen = (id: string | null) => router.replace(id ? `/admin/orders/?id=${id}` : '/admin/orders/', { scroll: false })

  async function change(o: Order, status: OrderStatus) {
    if (status === 'cancelled' && !confirm('تأكيد إلغاء الطلب؟')) return
    setBusy(o.id + status)
    try {
      await setOrderStatus(o, status)
      toast(`تم تحديث الطلب إلى «${STATUS_LABEL[status]}»`)
    } catch (e) {
      toast(errorMessage(e), 'err')
    } finally {
      setBusy(null)
    }
  }

  const nextFor = (o: Order) => {
    const n = NEXT[o.status]
    return o.type === 'pickup' && n === 'on_the_way' ? 'delivered' : n
  }

  return (
    <div>
      <PageTitle title="إدارة الطلبات" subtitle="تابع الطلبات وحدّث حالتها — يصل إشعار للعميل مع كل تحديث" />

      <div className="mb-4 flex flex-col gap-3 md:flex-row md:items-center">
        <div className="no-scrollbar flex gap-1 overflow-x-auto">
          {(['active', 'all', ...ORDER_STATUSES] as const).map((f) => (
            <button
              key={f}
              onClick={() => setFilter(f)}
              className={cx(
                'shrink-0 rounded-lg px-3 py-1.5 text-sm font-semibold whitespace-nowrap',
                filter === f ? 'bg-ink text-white' : 'border border-line bg-card text-ink-2',
              )}
            >
              {f === 'active' ? 'النشطة' : f === 'all' ? 'الكل' : STATUS_LABEL[f]}
            </button>
          ))}
        </div>
        <div className="relative md:mr-auto md:w-64">
          <Search className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-3" />
          <input className="field h-10 pr-9 text-sm" placeholder="رقم الطلب، الاسم، الهاتف" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
      </div>

      {loading ? (
        <Spinner />
      ) : shown.length === 0 ? (
        <Empty icon={<Search className="size-8" />} title="لا توجد طلبات" text="لا توجد طلبات مطابقة لهذا الفلتر" />
      ) : (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {shown.map((o) => {
            const next = nextFor(o)
            return (
              <article key={o.id} className={cx('card flex flex-col p-4', o.status === 'pending' && 'border-amber-300 ring-2 ring-amber-100')}>
                <button onClick={() => setOpen(o.id)} className="mb-3 text-right">
                  <div className="flex items-center justify-between">
                    <span className="num font-display text-lg font-extrabold">#{orderCode(o.id)}</span>
                    <StatusBadge status={o.status} />
                  </div>
                  <div className="mt-1 text-sm font-semibold">{o.userName}</div>
                  <div className="text-xs text-ink-3">
                    {o.branchName} · {o.type === 'delivery' ? 'توصيل' : 'استلام'} · {timeAgo(o.createdAt)}
                  </div>
                  <p className="mt-2 line-clamp-2 text-sm text-ink-2">{o.items.map((i) => `${i.qty}× ${i.name}`).join('، ')}</p>
                </button>
                <div className="mt-auto flex items-center gap-2 border-t border-line pt-3">
                  <span className="num flex-1 font-display font-extrabold text-brand-600">{money(o.total, s.currency)}</span>
                  {next && (
                    <Button size="sm" variant="sky" loading={busy === o.id + next} onClick={() => change(o, next)}>
                      {STATUS_LABEL[next]}
                    </Button>
                  )}
                  {o.status === 'pending' && (
                    <Button size="sm" variant="danger" loading={busy === o.id + 'cancelled'} onClick={() => change(o, 'cancelled')}>
                      رفض
                    </Button>
                  )}
                </div>
              </article>
            )
          })}
        </div>
      )}

      <Sheet open={!!open} onClose={() => setOpen(null)} title={open ? `طلب #${orderCode(open.id)} — ${open.userName}` : ''}>
        {open && (
          <div className="space-y-4">
            <OrderDetails order={open} currency={s.currency} />
            <div>
              <div className="mb-2 text-sm font-bold">تغيير الحالة</div>
              <div className="grid grid-cols-3 gap-2">
                {ORDER_STATUSES.map((st) => (
                  <button
                    key={st}
                    disabled={st === open.status || !!busy}
                    onClick={() => change(open, st)}
                    className={cx(
                      'rounded-xl border px-2 py-2 text-xs font-bold transition disabled:cursor-default',
                      st === open.status ? 'border-brand bg-brand text-white' : 'border-line hover:border-sky hover:text-sky-700',
                    )}
                  >
                    {STATUS_LABEL[st]}
                  </button>
                ))}
              </div>
            </div>
            <a href={`tel:${open.userPhone}`} className="block">
              <Button variant="outline" className="w-full">
                الاتصال بالعميل
              </Button>
            </a>
          </div>
        )}
      </Sheet>
    </div>
  )
}
