'use client'

import { ArrowDownLeft, ArrowUpLeft, Gift, ReceiptText, ShoppingBag, Sparkles, Wallet } from 'lucide-react'
import Link from 'next/link'
import { useMemo } from 'react'
import { useAuth } from '@/components/providers'
import { Button, RequireUser } from '@/components/ui'
import { cx, dateTime, money, num, phoneDisplay } from '@/lib/format'
import { byNewest, useCollection, useSettings } from '@/lib/hooks'
import type { Order } from '@/lib/models'
import { orderCode } from '@/lib/services'

export default function LoyaltyPage() {
  return (
    <RequireUser>
      <Loyalty />
    </RequireUser>
  )
}

function Loyalty() {
  const { user } = useAuth()
  const s = useSettings()
  const { items: orders } = useCollection<Order>('orders', { field: 'userId', op: '==', value: user!.id }, byNewest)
  const points = user!.points ?? 0
  const goal = Math.max(1, s.rewardThreshold)
  const cycle = points % goal
  const pct = Math.min(100, (cycle / goal) * 100)

  const history = useMemo(() => {
    const rows: { id: string; label: string; at: number; delta: number }[] = []
    for (const o of orders) {
      if (o.pointsAwarded && o.pointsEarned) rows.push({ id: o.id + 'e', label: `مكافأة طلب #${orderCode(o.id)}`, at: o.updatedAt, delta: o.pointsEarned })
      if (o.pointsUsed) rows.push({ id: o.id + 'u', label: `خصم على طلب #${orderCode(o.id)}`, at: o.createdAt, delta: -o.pointsUsed })
      if (o.pointsRefunded && o.pointsUsed) rows.push({ id: o.id + 'r', label: `استرجاع نقاط طلب ملغي #${orderCode(o.id)}`, at: o.updatedAt, delta: o.pointsUsed })
    }
    return rows.sort((a, b) => b.at - a.at).slice(0, 15)
  }, [orders])

  const pending = orders.filter((o) => !o.pointsAwarded && o.status !== 'cancelled').reduce((t, o) => t + (o.pointsEarned ?? 0), 0)

  // progress ring geometry
  const R = 52
  const C = 2 * Math.PI * R

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      <div>
        <h1 className="font-display text-2xl font-extrabold">برنامج الولاء</h1>
        <p className="text-sm text-ink-2">
          استرجع <span className="num font-bold text-brand-600">{s.loyaltyPercent}%</span> من قيمة كل طلب كنقاط
        </p>
      </div>

      {/* Membership card */}
      <section className="navy-grad relative overflow-hidden rounded-3xl p-5 text-white shadow-[0_18px_40px_rgba(11,42,74,0.35)]">
        <div className="absolute -top-20 -left-20 size-56 rounded-full bg-brand/40 blur-2xl" />
        <div className="absolute -right-10 -bottom-24 size-56 rounded-full bg-sky/40 blur-2xl" />
        <div className="dots absolute inset-0 opacity-30" />
        <div className="relative">
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 font-display font-black">
              <Sparkles className="size-4 text-brand" /> {s.restaurantName}
            </span>
            <span className="rounded-full bg-white/15 px-3 py-1 text-xs font-bold backdrop-blur">بطاقة الولاء</span>
          </div>
          <div className="mt-6 text-xs text-sky-100/80">رصيد النقاط</div>
          <div className="flex items-end gap-2">
            <span className="num font-display text-5xl leading-none font-black">{num(points)}</span>
            <span className="mb-1 text-sm text-sky-100/80">نقطة</span>
          </div>
          <div className="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-2.5 py-1 text-sm font-bold">
            <Wallet className="size-4 text-brand" /> تعادل <span className="num">{money(points * s.pointValue, s.currency)}</span> خصماً
          </div>
          <div className="mt-6 flex items-end justify-between text-sm">
            <div>
              <div className="text-[0.7rem] text-sky-100/70">صاحب البطاقة</div>
              <div className="font-bold">{user!.name}</div>
            </div>
            <div className="num text-left text-xs text-sky-100/70" dir="ltr">
              {phoneDisplay(user!.phone)}
            </div>
          </div>
        </div>
      </section>

      {/* Progress */}
      <section className="card flex items-center gap-5 p-5">
        <div className="relative size-32 shrink-0">
          <svg viewBox="0 0 120 120" className="size-full -rotate-90">
            <circle cx="60" cy="60" r={R} fill="none" stroke="var(--color-brand-50)" strokeWidth="12" />
            <circle
              cx="60"
              cy="60"
              r={R}
              fill="none"
              stroke="var(--color-brand)"
              strokeWidth="12"
              strokeLinecap="round"
              strokeDasharray={C}
              strokeDashoffset={C - (C * pct) / 100}
              className="transition-all duration-700"
            />
          </svg>
          <div className="absolute inset-0 grid place-content-center text-center">
            <span className="num font-display text-2xl font-black">{num(cycle)}</span>
            <span className="num text-xs text-ink-3">من {num(goal)}</span>
          </div>
        </div>
        <div>
          <h2 className="font-bold">المكافأة التالية</h2>
          <p className="mt-1 text-sm leading-relaxed text-ink-2">
            {points === 0 ? 'ابدأ بالطلب لتجمع أول نقاطك.' : `باقي ${num(goal - cycle)} نقطة للوصول إلى المكافأة التالية.`}
          </p>
          {pending > 0 && (
            <p className="mt-2 inline-flex items-center gap-1 rounded-lg bg-sky-50 px-2 py-1 text-xs font-bold text-sky-700">
              +<span className="num">{num(pending)}</span> نقطة ستُضاف عند تسليم طلباتك
            </p>
          )}
        </div>
      </section>

      {/* Stats */}
      <section className="grid grid-cols-3 gap-3">
        {[
          [ReceiptText, num(user!.totalOrders ?? 0), 'طلب مكتمل', 'bg-brand-50 text-brand'],
          [ShoppingBag, num(user!.totalSpent ?? 0), `إنفاق (${s.currency})`, 'bg-sky-50 text-sky'],
          [Gift, num(Math.floor(points / goal)), 'مكافآت متاحة', 'bg-ok-50 text-ok'],
        ].map(([Icon, v, l, tone]) => {
          const I = Icon as typeof Gift
          return (
            <div key={l as string} className="card p-3 text-center">
              <span className={`mx-auto grid size-9 place-items-center rounded-xl ${tone}`}>
                <I className="size-5" />
              </span>
              <div className="num mt-2 font-display text-lg font-black">{v as string}</div>
              <div className="text-[0.68rem] font-semibold text-ink-3">{l as string}</div>
            </div>
          )
        })}
      </section>

      {/* How it works */}
      <section>
        <h2 className="mb-3 font-display text-lg font-extrabold">كيف أجمع النقاط؟</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          {[
            ['🛒', 'اطلب من التطبيق', 'كل طلب يضيف نقاطاً إلى رصيدك.'],
            ['🎁', 'تُضاف عند التسليم', `${s.loyaltyPercent}% من قيمة الطلب بعد استلامه.`],
            ['💸', 'استخدمها كخصم', 'فعّل «استخدام النقاط» في السلة.'],
          ].map(([e, t, d], i) => (
            <div key={t} className="card relative p-4">
              <span className="num absolute top-3 left-3 text-xs font-black text-ink-3/50">0{i + 1}</span>
              <div className="text-3xl">{e}</div>
              <div className="mt-2 font-bold">{t}</div>
              <div className="text-sm text-ink-2">{d}</div>
            </div>
          ))}
        </div>
      </section>

      {/* History */}
      <section>
        <h2 className="mb-3 font-display text-lg font-extrabold">سجل النقاط</h2>
        {history.length === 0 ? (
          <div className="card p-6 text-center">
            <p className="text-sm text-ink-2">لا توجد حركات بعد</p>
            <Link href="/menu/">
              <Button size="sm" className="mt-3">
                اطلب الآن
              </Button>
            </Link>
          </div>
        ) : (
          <ul className="card divide-y divide-line overflow-hidden">
            {history.map((h) => (
              <li key={h.id} className="flex items-center gap-3 p-3.5">
                <span className={cx('grid size-9 place-items-center rounded-xl', h.delta > 0 ? 'bg-ok-50 text-ok' : 'bg-brand-50 text-brand')}>
                  {h.delta > 0 ? <ArrowDownLeft className="size-5" /> : <ArrowUpLeft className="size-5" />}
                </span>
                <div className="flex-1">
                  <div className="text-sm font-semibold">{h.label}</div>
                  <div className="text-xs text-ink-3">{dateTime(h.at)}</div>
                </div>
                <span className={cx('num font-display font-black', h.delta > 0 ? 'text-emerald-600' : 'text-brand-600')}>
                  {h.delta > 0 ? '+' : '−'}
                  {num(Math.abs(h.delta))}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}
