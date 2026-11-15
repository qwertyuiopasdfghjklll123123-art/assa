'use client'

import { Award, Coins, Gift, ReceiptText, Sparkles, Star, Wallet } from 'lucide-react'
import { useAuth } from '@/components/providers'
import { RequireUser } from '@/components/ui'
import { money, num } from '@/lib/format'
import { useSettings } from '@/lib/hooks'

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
  const points = user!.points ?? 0
  const goal = Math.max(1, s.rewardThreshold)
  const cycle = points % goal
  const reached = Math.floor(points / goal)
  const pct = Math.min(100, Math.round((cycle / goal) * 100))
  const value = points * s.pointValue

  return (
    <div className="space-y-5">
      <section className="sky-grad relative overflow-hidden rounded-3xl p-5 text-white shadow-[0_14px_40px_rgba(14,165,233,0.3)] sm:p-7">
        <div className="dots absolute inset-0 opacity-50" />
        <div className="absolute -top-14 -left-14 size-48 rounded-full bg-brand/50 blur-2xl" />
        <div className="relative">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-white/20 px-3 py-1 text-xs font-bold">
            <Sparkles className="size-3.5" /> برنامج الولاء
          </span>
          <h1 className="mt-3 font-display text-2xl font-black sm:text-3xl">اجمع النقاط مع كل طلب</h1>
          <p className="mt-1 text-sm text-white/90">
            يعود إليك <span className="num font-bold">{s.loyaltyPercent}%</span> من قيمة كل طلب كنقاط، وكل نقطة تساوي{' '}
            <span className="num font-bold">{money(s.pointValue, s.currency)}</span> خصماً.
          </p>

          {/* Membership card */}
          <div className="mt-5 rounded-2xl border border-white/25 bg-white/15 p-4 backdrop-blur">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-xs text-white/80">رصيدك الحالي</div>
                <div className="num font-display text-4xl font-black">{num(points)}</div>
                <div className="text-xs text-white/80">
                  نقطة · تعادل <span className="num">{money(value, s.currency)}</span>
                </div>
              </div>
              <div className="grid size-16 place-items-center rounded-2xl bg-white text-brand shadow-lg">
                <Award className="size-9" />
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="card p-5">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="font-bold">التقدم نحو المكافأة التالية</h2>
          <span className="num text-sm font-bold text-ink-2">
            {num(cycle)}/{num(goal)}
          </span>
        </div>
        <div className="h-3 overflow-hidden rounded-full bg-brand-50">
          <div className="hero-grad h-full rounded-full transition-all" style={{ width: `${pct}%` }} />
        </div>
        <p className="mt-2 text-xs text-ink-2">
          {cycle === 0 && points === 0 ? 'ابدأ بالطلب لتجمع أول نقاطك.' : `باقي ${num(goal - cycle)} نقطة للوصول إلى المكافأة التالية.`}
        </p>
      </section>

      <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat icon={<Coins className="size-5" />} label="النقاط" value={num(points)} tone="sky" />
        <Stat icon={<ReceiptText className="size-5" />} label="الطلبات المكتملة" value={num(user!.totalOrders ?? 0)} tone="brand" />
        <Stat icon={<Wallet className="size-5" />} label="إجمالي الإنفاق" value={num(user!.totalSpent ?? 0)} tone="sky" />
        <Stat icon={<Star className="size-5" />} label="مكافآت محققة" value={num(reached)} tone="brand" />
      </section>

      <section className="card p-5">
        <h2 className="mb-4 font-bold">كيف يعمل البرنامج؟</h2>
        <ol className="space-y-4">
          {[
            ['اطلب من التطبيق', 'كل طلب مكتمل يضيف نقاطاً إلى رصيدك تلقائياً.'],
            ['تُضاف النقاط عند التسليم', `تحصل على ${s.loyaltyPercent}% من قيمة طلبك بعد تسليمه.`],
            ['استخدمها كخصم', 'فعّل خيار استخدام النقاط في السلة ليُخصم رصيدك من الإجمالي.'],
          ].map(([t, d], i) => (
            <li key={t} className="flex gap-3">
              <span className="num grid size-8 shrink-0 place-items-center rounded-lg bg-sky-50 font-bold text-sky-700">{i + 1}</span>
              <div>
                <div className="font-semibold">{t}</div>
                <div className="text-sm text-ink-2">{d}</div>
              </div>
            </li>
          ))}
        </ol>
        <div className="mt-4 flex items-center gap-2 rounded-xl bg-brand-50 p-3 text-sm font-semibold text-brand-600">
          <Gift className="size-4" /> النقاط لا تنتهي صلاحيتها ما دام حسابك نشطاً.
        </div>
      </section>
    </div>
  )
}

function Stat({ icon, label, value, tone }: { icon: React.ReactNode; label: string; value: string; tone: 'sky' | 'brand' }) {
  return (
    <div className="card p-4">
      <span className={`grid size-9 place-items-center rounded-xl ${tone === 'sky' ? 'bg-sky-50 text-sky' : 'bg-brand-50 text-brand'}`}>{icon}</span>
      <div className="num mt-3 font-display text-2xl font-black">{value}</div>
      <div className="text-xs font-semibold text-ink-3">{label}</div>
    </div>
  )
}
