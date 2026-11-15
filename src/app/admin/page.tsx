'use client'

import { Clock, Coins, ReceiptText, TrendingUp, Users } from 'lucide-react'
import Link from 'next/link'
import { useMemo } from 'react'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { PageTitle, StatusBadge } from '@/components/ui'
import { money, num, timeAgo } from '@/lib/format'
import { byNewest, useCollection, useSettings } from '@/lib/hooks'
import type { Order, UserProfile } from '@/lib/models'
import { orderCode } from '@/lib/services'

export default function AdminDashboard() {
  const s = useSettings()
  const { items: orders } = useCollection<Order>('orders', undefined, byNewest)
  const { items: users } = useCollection<UserProfile>('users')

  const stats = useMemo(() => {
    const start = new Date()
    start.setHours(0, 0, 0, 0)
    const today = orders.filter((o) => o.createdAt >= start.getTime() && o.status !== 'cancelled')
    const delivered = orders.filter((o) => o.status === 'delivered')
    const active = orders.filter((o) => ['pending', 'confirmed', 'preparing', 'on_the_way'].includes(o.status))
    const days = Array.from({ length: 7 }, (_, i) => {
      const d = new Date(start)
      d.setDate(d.getDate() - (6 - i))
      const from = d.getTime()
      const to = from + 864e5
      const list = orders.filter((o) => o.createdAt >= from && o.createdAt < to && o.status !== 'cancelled')
      return {
        day: new Intl.DateTimeFormat('ar', { weekday: 'short' }).format(d),
        orders: list.length,
        revenue: list.reduce((t, o) => t + o.total, 0),
      }
    })
    const top = new Map<string, number>()
    for (const o of orders) if (o.status !== 'cancelled') for (const i of o.items) top.set(i.name, (top.get(i.name) ?? 0) + i.qty)
    return {
      todayCount: today.length,
      todayRevenue: today.reduce((t, o) => t + o.total, 0),
      revenue: delivered.reduce((t, o) => t + o.total, 0),
      active: active.length,
      customers: users.filter((u) => u.role === 'user' && !u.deleted).length,
      points: users.reduce((t, u) => t + (u.points ?? 0), 0),
      days,
      top: [...top.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5),
    }
  }, [orders, users])

  const cards = [
    { label: 'طلبات اليوم', value: num(stats.todayCount), sub: money(stats.todayRevenue, s.currency), icon: ReceiptText, tone: 'bg-brand-50 text-brand' },
    { label: 'طلبات نشطة', value: num(stats.active), sub: 'بانتظار الإنجاز', icon: Clock, tone: 'bg-sky-50 text-sky' },
    { label: 'إجمالي المبيعات', value: num(stats.revenue), sub: `${s.currency} · طلبات مسلّمة`, icon: TrendingUp, tone: 'bg-ok-50 text-ok' },
    { label: 'العملاء', value: num(stats.customers), sub: `${num(stats.points)} نقطة لديهم`, icon: Users, tone: 'bg-sky-50 text-sky' },
  ]

  return (
    <div className="space-y-6">
      <PageTitle title="نظرة عامة" subtitle="ملخص أداء المطعم" />

      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        {cards.map(({ label, value, sub, icon: Icon, tone }) => (
          <div key={label} className="card p-4">
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold text-ink-2">{label}</span>
              <span className={`grid size-9 place-items-center rounded-xl ${tone}`}>
                <Icon className="size-5" />
              </span>
            </div>
            <div className="num mt-2 font-display text-3xl font-black">{value}</div>
            <div className="num text-xs text-ink-3">{sub}</div>
          </div>
        ))}
      </div>

      <div className="grid gap-4 xl:grid-cols-[1.6fr_1fr]">
        <section className="card p-4">
          <h2 className="mb-4 font-bold">الطلبات خلال آخر 7 أيام</h2>
          <div className="h-64" dir="ltr">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={stats.days} margin={{ top: 4, right: -20, left: 4, bottom: 0 }}>
                <CartesianGrid vertical={false} stroke="#e7ecf3" />
                <XAxis reversed dataKey="day" tickLine={false} axisLine={false} tick={{ fill: '#8b97ab', fontSize: 12 }} />
                <YAxis orientation="right" allowDecimals={false} tickLine={false} axisLine={false} tick={{ fill: '#8b97ab', fontSize: 12 }} />
                <Tooltip
                  cursor={{ fill: 'rgba(14,165,233,0.06)' }}
                  contentStyle={{ borderRadius: 12, border: '1px solid #e7ecf3', fontFamily: 'inherit', direction: 'rtl' }}
                  formatter={(v, name) => [num(Number(v)), name === 'orders' ? 'الطلبات' : 'الإيراد']}
                />
                <Bar dataKey="orders" fill="#f97316" radius={[6, 6, 0, 0]} maxBarSize={36} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </section>

        <section className="card p-4">
          <h2 className="mb-3 font-bold">الأكثر مبيعاً</h2>
          {stats.top.length === 0 ? (
            <p className="py-8 text-center text-sm text-ink-3">لا توجد بيانات بعد</p>
          ) : (
            <ol className="space-y-3">
              {stats.top.map(([name, qty], i) => (
                <li key={name} className="flex items-center gap-3">
                  <span className="num grid size-7 place-items-center rounded-lg bg-sky-50 text-xs font-bold text-sky-700">{i + 1}</span>
                  <span className="flex-1 truncate text-sm font-semibold">{name}</span>
                  <span className="num text-sm text-ink-2">{num(qty)}</span>
                </li>
              ))}
            </ol>
          )}
          <div className="mt-4 flex items-center gap-2 rounded-xl bg-brand-50 p-3 text-xs font-semibold text-brand-600">
            <Coins className="size-4" /> نسبة الولاء الحالية <span className="num">{s.loyaltyPercent}%</span>
          </div>
        </section>
      </div>

      <section className="card overflow-hidden">
        <div className="flex items-center justify-between p-4">
          <h2 className="font-bold">أحدث الطلبات</h2>
          <Link href="/admin/orders/" className="text-sm font-semibold text-sky-600">
            عرض الكل
          </Link>
        </div>
        <div className="divide-y divide-line border-t border-line">
          {orders.slice(0, 6).map((o) => (
            <Link key={o.id} href={`/admin/orders/?id=${o.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-bg">
              <span className="num w-16 font-bold">#{orderCode(o.id)}</span>
              <span className="flex-1 truncate text-sm">
                {o.userName} <span className="text-ink-3">· {o.branchName}</span>
              </span>
              <StatusBadge status={o.status} />
              <span className="num hidden w-28 text-left text-sm font-semibold sm:block">{money(o.total, s.currency)}</span>
              <span className="hidden w-20 text-left text-xs text-ink-3 md:block">{timeAgo(o.createdAt)}</span>
            </Link>
          ))}
          {orders.length === 0 && <p className="p-8 text-center text-sm text-ink-3">لا توجد طلبات بعد</p>}
        </div>
      </section>
    </div>
  )
}
