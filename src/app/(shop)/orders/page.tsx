'use client'

import { ChevronLeft, PackageOpen } from 'lucide-react'
import Link from 'next/link'
import { useMemo, useState } from 'react'
import { OrderDetails } from '@/components/order-details'
import { useAuth, useToast } from '@/components/providers'
import { Button, Empty, PageTitle, RequireUser, Sheet, Spinner, StatusBadge } from '@/components/ui'
import { cx, money, num, timeAgo } from '@/lib/format'
import { byNewest, useCollection, useSettings } from '@/lib/hooks'
import { ORDER_STATUSES, STATUS_LABEL, type Order, type OrderStatus } from '@/lib/models'
import { cancelMyOrder, errorMessage, orderCode } from '@/lib/services'

export default function OrdersPage() {
  return (
    <RequireUser>
      <Orders />
    </RequireUser>
  )
}

function Orders() {
  const { user } = useAuth()
  const settings = useSettings()
  const toast = useToast()
  const { items: orders, loading } = useCollection<Order>('orders', { field: 'userId', op: '==', value: user!.id }, byNewest)
  const [filter, setFilter] = useState<'all' | OrderStatus>('all')
  const [openId, setOpenId] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const shown = useMemo(() => (filter === 'all' ? orders : orders.filter((o) => o.status === filter)), [orders, filter])
  const open = orders.find((o) => o.id === openId) ?? null
  const counts = useMemo(() => {
    const c: Record<string, number> = { all: orders.length }
    for (const o of orders) c[o.status] = (c[o.status] ?? 0) + 1
    return c
  }, [orders])

  return (
    <div>
      <PageTitle title="طلباتي" subtitle="تابع حالة طلباتك وسجلّها" />

      <div className="no-scrollbar -mx-4 mb-4 overflow-x-auto px-4">
        <div className="inline-flex gap-1 rounded-2xl border border-line bg-card p-1">
          {(['all', ...ORDER_STATUSES] as const).map((s) => (
            <button
              key={s}
              onClick={() => setFilter(s)}
              className={cx(
                'flex h-9 shrink-0 items-center gap-1.5 rounded-xl px-3 text-sm font-semibold whitespace-nowrap transition',
                filter === s ? 'bg-sky text-white' : 'text-ink-2 hover:bg-bg',
              )}
            >
              {s === 'all' ? 'الكل' : STATUS_LABEL[s]}
              {!!counts[s] && (
                <span className={cx('num rounded-md px-1.5 text-[0.7rem]', filter === s ? 'bg-white/25' : 'bg-bg')}>{counts[s]}</span>
              )}
            </button>
          ))}
        </div>
      </div>

      {loading ? (
        <Spinner />
      ) : shown.length === 0 ? (
        <Empty
          icon={<PackageOpen className="size-8" />}
          title="لا توجد طلبات بعد"
          text="اطلب أول وجبة وستظهر هنا لتتابع حالتها"
          action={
            <Link href="/menu/">
              <Button>تصفح المنيو</Button>
            </Link>
          }
        />
      ) : (
        <div className="space-y-3">
          {shown.map((o) => (
            <button key={o.id} onClick={() => setOpenId(o.id)} className="card rise flex w-full items-center gap-3 p-4 text-right transition hover:border-sky">
              <div className="grid size-12 shrink-0 place-items-center rounded-xl bg-brand-50 text-xl">{o.items[0]?.emoji ?? '🧾'}</div>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="num font-bold">#{orderCode(o.id)}</span>
                  <StatusBadge status={o.status} />
                </div>
                <p className="mt-1 truncate text-sm text-ink-2">
                  {o.items.map((i) => i.name).join('، ')} · {o.branchName}
                </p>
                <p className="mt-0.5 text-xs text-ink-3">
                  {timeAgo(o.createdAt)} · <span className="num">{num(o.items.reduce((t, i) => t + i.qty, 0))}</span> قطعة
                </p>
              </div>
              <div className="text-left">
                <div className="num font-display font-extrabold text-brand-600">{money(o.total, settings.currency)}</div>
                <ChevronLeft className="mr-auto size-5 text-ink-3" />
              </div>
            </button>
          ))}
        </div>
      )}

      <Sheet open={!!open} onClose={() => setOpenId(null)} title={open ? `طلب #${orderCode(open.id)}` : ''}>
        {open && (
          <>
            <OrderDetails order={open} currency={settings.currency} />
            {open.status === 'pending' && !open.pointsUsed && (
              <Button
                variant="danger"
                className="mt-4 w-full"
                loading={busy}
                onClick={async () => {
                  if (!confirm('هل تريد إلغاء الطلب؟')) return
                  setBusy(true)
                  try {
                    await cancelMyOrder(open)
                    toast('تم إلغاء الطلب')
                  } catch (e) {
                    toast(errorMessage(e), 'err')
                  } finally {
                    setBusy(false)
                  }
                }}
              >
                إلغاء الطلب
              </Button>
            )}
          </>
        )}
      </Sheet>
    </div>
  )
}
