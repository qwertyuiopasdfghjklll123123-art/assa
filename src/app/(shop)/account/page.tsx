'use client'

import {
  Bell,
  BellRing,
  ChevronLeft,
  Gift,
  Info,
  KeyRound,
  LayoutDashboard,
  LogOut,
  MapPin,
  MessageCircle,
  Pencil,
  ReceiptText,
  Trash2,
  UserRound,
} from 'lucide-react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useState, useSyncExternalStore, type ReactNode } from 'react'
import { GoogleLogo } from '@/components/auth-card'
import { PasswordInput, WhatsAppBadge } from '@/components/auth-layout'
import { useAuth, useToast } from '@/components/providers'
import { useUnreadCount } from '@/components/shop-shell'
import { Button, Field, RequireUser, Sheet, Toggle } from '@/components/ui'
import { getBackend } from '@/lib/backend'
import { cx, money, num, phoneDisplay } from '@/lib/format'
import { useSettings } from '@/lib/hooks'
import { disablePush, enablePush, pushState, type PushState } from '@/lib/push'
import { deleteMyAccount, errorMessage, updateMyProfile } from '@/lib/services'

export default function AccountPage() {
  return (
    <RequireUser>
      <Account />
    </RequireUser>
  )
}

const noop = () => () => {}

function Account() {
  const { user, isAdmin } = useAuth()
  const settings = useSettings()
  const unread = useUnreadCount()
  const toast = useToast()
  const router = useRouter()
  const [sheet, setSheet] = useState<null | 'edit' | 'password' | 'logout' | 'delete'>(null)
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({ name: user!.name, phone: user!.phone ?? '', address: user!.address ?? '' })
  const [pw, setPw] = useState({ current: '', next: '' })
  const initialPush = useSyncExternalStore(noop, pushState, () => 'unsupported' as PushState)
  const [push, setPush] = useState<PushState | null>(null)
  const pushNow = push ?? initialPush

  async function run(fn: () => Promise<unknown>, ok?: string) {
    setBusy(true)
    try {
      await fn()
      if (ok) toast(ok)
      setSheet(null)
    } catch (e) {
      toast(errorMessage(e), 'err')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      {/* Profile header */}
      <section className="card overflow-hidden">
        <div className="hero-grad relative h-20">
          <div className="dots absolute inset-0 opacity-50" />
        </div>
        <div className="relative -mt-10 flex items-end justify-between px-4">
          <div className="sky-grad grid size-20 shrink-0 place-items-center overflow-hidden rounded-3xl font-display text-3xl font-black text-white shadow-[0_8px_20px_rgba(14,165,233,0.3)] ring-4 ring-white">
            {user!.avatar ? <img src={user!.avatar} alt="" referrerPolicy="no-referrer" className="size-full object-cover" /> : user!.name.trim().slice(0, 1)}
          </div>
          <button
            onClick={() => setSheet('edit')}
            className="flex h-9 items-center gap-1.5 rounded-xl border border-line bg-white px-3 text-sm font-semibold text-ink-2 hover:text-sky-700"
          >
            <Pencil className="size-4" /> تعديل
          </button>
        </div>
        <div className="px-4 pt-3">
          <h1 className="font-display text-xl font-extrabold">{user!.name}</h1>
          <p className="num text-right text-sm text-ink-2">{phoneDisplay(user!.phone) || user!.email}</p>
        </div>
        <div className="mt-4 grid grid-cols-3 divide-x divide-x-reverse divide-line border-t border-line text-center">
          <Stat value={num(user!.points)} label="نقطة" tone="text-sky-600" />
          <Stat value={num(user!.totalOrders ?? 0)} label="طلب مكتمل" tone="text-brand-600" />
          <Stat value={money(user!.points * settings.pointValue, '')} label={`رصيد (${settings.currency})`} tone="text-emerald-600" />
        </div>
      </section>

      {isAdmin && (
        <Link href="/admin/" className="navy-grad flex items-center gap-3 rounded-2xl p-4 text-white shadow-card">
          <LayoutDashboard className="size-6 text-sky-300" />
          <span className="flex-1">
            <span className="block font-bold">لوحة تحكم الإدارة</span>
            <span className="block text-xs text-sky-100/80">الطلبات، المنيو، الفروع، المستخدمون</span>
          </span>
          <ChevronLeft className="size-5" />
        </Link>
      )}

      <Group title="طلباتي ومكافآتي">
        <Row href="/orders/" icon={<ReceiptText />} tone="bg-sky-50 text-sky" title="طلباتي" sub="تتبّع الطلبات الحالية والسابقة" />
        <Row href="/loyalty/" icon={<Gift />} tone="bg-brand-50 text-brand" title="برنامج الولاء" sub={`${num(user!.points)} نقطة متاحة`} />
        <Row
          href="/notifications/"
          icon={<Bell />}
          tone="bg-sky-50 text-sky"
          title="الإشعارات"
          end={unread ? <span className="num rounded-full bg-brand px-2 py-0.5 text-xs font-bold text-white">{unread}</span> : null}
        />
      </Group>

      <Group title="الحساب والإعدادات">
        <Row onClick={() => setSheet('edit')} icon={<UserRound />} tone="bg-bg text-ink-2" title="البيانات الشخصية" sub="الاسم، رقم التواصل، العنوان" />
        {user!.loginMethod && user!.loginMethod !== 'email' ? (
          <div className="flex items-center gap-3 p-4">
            <span className="grid size-10 place-items-center rounded-xl bg-bg text-ink-2 [&>svg]:size-5">
              {user!.loginMethod === 'google' ? <GoogleLogo /> : <span className="text-[#25D366]"><WhatsAppBadge /></span>}
            </span>
            <span className="flex-1">
              <span className="block font-semibold">طريقة الدخول</span>
              <span className="block text-xs text-ink-3">{user!.loginMethod === 'google' ? `حساب Google — ${user!.email}` : 'واتساب — برمز تحقق بدون كلمة مرور'}</span>
            </span>
          </div>
        ) : (
          <Row onClick={() => setSheet('password')} icon={<KeyRound />} tone="bg-bg text-ink-2" title="تغيير كلمة المرور" />
        )}
        <div className="flex items-center gap-3 p-4">
          <span className="grid size-10 place-items-center rounded-xl bg-brand-50 text-brand [&>svg]:size-5">
            <BellRing />
          </span>
          <span className="flex-1">
            <span className="block font-semibold">إشعارات الهاتف</span>
            <span className="block text-xs text-ink-3">
              {pushNow === 'granted' ? 'مفعّلة — ستصلك تحديثات طلباتك والعروض' : pushNow === 'denied' ? 'مرفوضة — فعّلها من إعدادات الهاتف' : pushNow === 'unsupported' ? 'غير مدعومة على هذا الجهاز' : 'غير مفعّلة'}
            </span>
          </span>
          <Toggle
            checked={pushNow === 'granted'}
            label="إشعارات الهاتف"
            onChange={async (on) => {
              if (pushNow === 'denied' || pushNow === 'unsupported') return toast('فعّل الإشعارات من إعدادات الهاتف لهذا التطبيق', 'err')
              if (on) {
                const s = await enablePush(true).catch(() => 'default' as PushState)
                setPush(s)
                if (s === 'granted') toast('تم تفعيل الإشعارات 🔔')
              } else {
                await disablePush()
                toast('لن تصلك إشعارات على هذا الجهاز')
                setPush('default')
              }
            }}
          />
        </div>
        {user!.address && (
          <div className="flex items-start gap-3 p-4">
            <span className="grid size-10 place-items-center rounded-xl bg-sky-50 text-sky [&>svg]:size-5">
              <MapPin />
            </span>
            <span className="flex-1">
              <span className="block font-semibold">عنوان التوصيل المحفوظ</span>
              <span className="block text-xs leading-relaxed text-ink-3">{user!.address}</span>
            </span>
          </div>
        )}
      </Group>

      <Group title="الدعم">
        <Row href="/contact/" icon={<MessageCircle />} tone="bg-ok-50 text-ok" title="اتصل بنا" sub="واتساب، هاتف، العنوان" />
        <Row href="/about/" icon={<Info />} tone="bg-bg text-ink-2" title="حول التطبيق" />
      </Group>

      <button
        onClick={() => setSheet('logout')}
        className="flex h-13 w-full items-center justify-center gap-2 rounded-2xl border border-line bg-white font-bold text-danger shadow-card transition hover:border-danger/40 hover:bg-danger-50"
      >
        <LogOut className="size-5" /> تسجيل الخروج
      </button>
      <button onClick={() => setSheet('delete')} className="mx-auto flex items-center gap-1.5 text-sm font-semibold text-ink-3 hover:text-danger">
        <Trash2 className="size-4" /> حذف الحساب نهائياً
      </button>

      {/* ---------- sheets ---------- */}
      <Sheet open={sheet === 'logout'} onClose={() => setSheet(null)} title="تسجيل الخروج" hideTitle>
        <div className="pt-4 text-center">
          <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-danger-50 text-danger">
            <LogOut className="size-8" />
          </div>
          <h2 className="mt-4 font-display text-xl font-black">تسجيل الخروج؟</h2>
          <p className="mt-1 text-sm text-ink-2">يمكنك العودة في أي وقت بالدخول عبر واتساب أو Google.</p>
          <div className="mt-6 grid grid-cols-2 gap-2">
            <Button variant="outline" size="lg" onClick={() => setSheet(null)}>
              إلغاء
            </Button>
            <Button
              size="lg"
              className="!bg-danger !shadow-none hover:!bg-red-600"
              loading={busy}
              onClick={() =>
                run(async () => {
                  await disablePush().catch(() => {})
                  await (await getBackend()).signOut()
                  router.replace('/')
                })
              }
            >
              نعم، خروج
            </Button>
          </div>
        </div>
      </Sheet>

      <Sheet open={sheet === 'edit'} onClose={() => setSheet(null)} title="البيانات الشخصية">
        <div className="space-y-3">
          <Field label="الاسم">
            <input className="field" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </Field>
          <Field label="رقم التواصل للطلبات" hint="يمكن أن يختلف عن رقم تسجيل الدخول">
            <input className="field num text-right" inputMode="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          </Field>
          <Field label="عنوان التوصيل">
            <textarea className="field min-h-20" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          </Field>
          <Button className="w-full" loading={busy} disabled={!form.name.trim()} onClick={() => run(() => updateMyProfile(user!.id, form), 'تم حفظ بياناتك')}>
            حفظ
          </Button>
        </div>
      </Sheet>

      <Sheet open={sheet === 'password'} onClose={() => setSheet(null)} title="تغيير كلمة المرور">
        <div className="space-y-3">
          <Field label="كلمة المرور الحالية">
            <PasswordInput value={pw.current} onChange={(v) => setPw({ ...pw, current: v })} />
          </Field>
          <Field label="كلمة المرور الجديدة" hint="6 أحرف على الأقل">
            <PasswordInput value={pw.next} onChange={(v) => setPw({ ...pw, next: v })} autoComplete="new-password" />
          </Field>
          <Button
            className="w-full"
            loading={busy}
            disabled={!pw.current || pw.next.length < 6}
            onClick={() =>
              run(async () => {
                await (await getBackend()).changePassword(pw.current, pw.next)
                setPw({ current: '', next: '' })
              }, 'تم تغيير كلمة المرور')
            }
          >
            حفظ
          </Button>
        </div>
      </Sheet>

      <Sheet open={sheet === 'delete'} onClose={() => setSheet(null)} title="حذف الحساب" hideTitle>
        <div className="pt-4 text-center">
          <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-danger-50 text-danger">
            <Trash2 className="size-8" />
          </div>
          <h2 className="mt-4 font-display text-xl font-black">حذف الحساب نهائياً؟</h2>
          <p className="mt-1 text-sm leading-relaxed text-ink-2">
            لن تتمكن من تسجيل الدخول مرة أخرى وستفقد رصيد نقاطك (<span className="num">{num(user!.points)}</span> نقطة). يُحتفظ بسجل الطلبات لأغراض قانونية.
          </p>
          <div className="mt-6 grid grid-cols-2 gap-2">
            <Button variant="outline" size="lg" onClick={() => setSheet(null)}>
              تراجع
            </Button>
            <Button
              variant="danger"
              size="lg"
              loading={busy}
              onClick={() =>
                run(async () => {
                  await deleteMyAccount(user!.id)
                  router.replace('/')
                })
              }
            >
              حذف نهائياً
            </Button>
          </div>
        </div>
      </Sheet>
    </div>
  )
}

function Stat({ value, label, tone }: { value: string; label: string; tone: string }) {
  return (
    <div className="py-3">
      <div className={cx('num font-display text-lg font-black', tone)}>{value}</div>
      <div className="text-[0.7rem] font-semibold text-ink-3">{label}</div>
    </div>
  )
}

function Group({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section>
      <h2 className="mb-2 px-1 text-xs font-bold tracking-wide text-ink-3">{title}</h2>
      <div className="card divide-y divide-line overflow-hidden">{children}</div>
    </section>
  )
}

function Row({ href, onClick, icon, tone, title, sub, end }: {
  href?: string
  onClick?: () => void
  icon: ReactNode
  tone: string
  title: string
  sub?: string
  end?: ReactNode
}) {
  const inner = (
    <>
      <span className={`grid size-10 place-items-center rounded-xl [&>svg]:size-5 ${tone}`}>{icon}</span>
      <span className="flex-1 text-right">
        <span className="block font-semibold">{title}</span>
        {sub && <span className="block text-xs text-ink-3">{sub}</span>}
      </span>
      {end}
      <ChevronLeft className="size-5 text-ink-3" />
    </>
  )
  const cls = 'flex w-full items-center gap-3 p-4 transition hover:bg-bg'
  return href ? (
    <Link href={href} className={cls}>
      {inner}
    </Link>
  ) : (
    <button onClick={onClick} className={cls}>
      {inner}
    </button>
  )
}
