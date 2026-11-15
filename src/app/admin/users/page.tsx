'use client'

import { Search, ShieldCheck, Users } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useAuth, useToast } from '@/components/providers'
import { getBackend } from '@/lib/backend'
import { Button, Empty, PageTitle, Sheet, StatusBadge } from '@/components/ui'
import { cx, dateTime, money, num, phoneDisplay } from '@/lib/format'
import { byNewest, useCollection, useSettings } from '@/lib/hooks'
import { NOTIF_TYPES, type NotifType, type Order, type UserProfile } from '@/lib/models'
import { errorMessage, orderCode, sendNotification, updateUserAdmin } from '@/lib/services'

export default function AdminUsersPage() {
  const s = useSettings()
  const toast = useToast()
  const { user: me } = useAuth()
  const { items: users } = useCollection<UserProfile>('users', undefined, byNewest)
  const { items: orders } = useCollection<Order>('orders', undefined, byNewest)
  const [q, setQ] = useState('')
  const [openId, setOpenId] = useState<string | null>(null)
  const [delta, setDelta] = useState('')
  const [msg, setMsg] = useState({ title: '', body: '', type: 'news' as NotifType })
  const [newPass, setNewPass] = useState('')
  const [busy, setBusy] = useState(false)

  const shown = useMemo(() => {
    const t = q.trim().toLowerCase()
    return users.filter((u) => !t || u.name?.toLowerCase().includes(t) || u.email?.toLowerCase().includes(t) || u.phone?.includes(t))
  }, [users, q])

  const open = users.find((u) => u.id === openId) ?? null
  const userOrders = open ? orders.filter((o) => o.userId === open.id) : []

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
      <PageTitle title="المستخدمون" subtitle={`${num(users.length)} حساب مسجّل`} />
      <div className="relative mb-4 md:w-80">
        <Search className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-3" />
        <input className="field h-10 pr-9 text-sm" placeholder="بحث بالاسم، البريد، الهاتف" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>

      {shown.length === 0 ? (
        <Empty icon={<Users className="size-8" />} title="لا يوجد مستخدمون" />
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="bg-bg text-right text-xs text-ink-3">
              <tr>
                <th className="p-3 font-semibold">المستخدم</th>
                <th className="p-3 font-semibold">الهاتف</th>
                <th className="p-3 font-semibold">النقاط</th>
                <th className="p-3 font-semibold">الطلبات</th>
                <th className="p-3 font-semibold">الإنفاق</th>
                <th className="p-3 font-semibold">الحالة</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {shown.map((u) => (
                <tr key={u.id} onClick={() => setOpenId(u.id)} className="cursor-pointer hover:bg-bg">
                  <td className="p-3">
                    <div className="flex items-center gap-1.5 font-semibold">
                      {u.name}
                      {u.role === 'admin' && <ShieldCheck className="size-4 text-sky" />}
                    </div>
                    <div className="num text-right text-xs text-ink-3">{u.email || phoneDisplay(u.phone)}</div>
                  </td>
                  <td className="num p-3 text-right">{phoneDisplay(u.phone) || '—'}</td>
                  <td className="num p-3 font-bold text-sky-700">{num(u.points ?? 0)}</td>
                  <td className="num p-3">{num(u.totalOrders ?? 0)}</td>
                  <td className="num p-3">{num(u.totalSpent ?? 0)}</td>
                  <td className="p-3">
                    <span
                      className={cx(
                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                        u.deleted ? 'bg-bg text-ink-3' : u.blocked ? 'bg-danger-50 text-red-700' : 'bg-ok-50 text-emerald-700',
                      )}
                    >
                      {u.deleted ? 'محذوف' : u.blocked ? 'محظور' : 'نشط'}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Sheet open={!!open} onClose={() => setOpenId(null)} title={open?.name ?? ''}>
        {open && (
          <div className="space-y-4">
            <div className="grid grid-cols-3 gap-2 text-center">
              {[
                ['النقاط', num(open.points ?? 0)],
                ['الطلبات', num(open.totalOrders ?? 0)],
                ['الإنفاق', num(open.totalSpent ?? 0)],
              ].map(([l, v]) => (
                <div key={l} className="rounded-xl bg-bg p-3">
                  <div className="num font-display text-xl font-black">{v}</div>
                  <div className="text-xs text-ink-3">{l}</div>
                </div>
              ))}
            </div>
            <div className="text-xs text-ink-3">
              <span className="num">{phoneDisplay(open.phone) || open.email}</span> · {open.loginMethod === 'google' ? 'Google' : open.loginMethod === 'whatsapp' ? 'واتساب' : 'بريد وكلمة مرور'} · انضم {dateTime(open.createdAt)}
            </div>

            <div className="rounded-2xl border border-line p-3">
              <div className="mb-2 text-sm font-bold">تعديل النقاط</div>
              <div className="flex gap-2">
                <input className="field num h-10" type="number" placeholder="مثال: 50 أو -20" value={delta} onChange={(e) => setDelta(e.target.value)} />
                <Button
                  variant="sky"
                  loading={busy}
                  disabled={!Number(delta)}
                  onClick={async () => {
                    const d = Math.trunc(Number(delta))
                    const points = Math.max(0, (open.points ?? 0) + d)
                    if (
                      await run(async () => {
                        await updateUserAdmin(open.id, { points })
                        await sendNotification(open.id, d > 0 ? 'نقاط هدية 🎁' : 'تعديل رصيد النقاط', `${d > 0 ? 'أُضيفت' : 'خُصمت'} ${Math.abs(d)} نقطة. رصيدك الآن ${points}.`, 'offer', '/loyalty/')
                      }, 'تم تحديث النقاط')
                    )
                      setDelta('')
                  }}
                >
                  تطبيق
                </Button>
              </div>
            </div>

            <div className="rounded-2xl border border-line p-3">
              <div className="mb-2 text-sm font-bold">إرسال إشعار لهذا المستخدم</div>
              <div className="space-y-2">
                <select className="field h-10 py-1 text-sm" value={msg.type} onChange={(e) => setMsg({ ...msg, type: e.target.value as NotifType })}>
                  {(Object.keys(NOTIF_TYPES) as NotifType[])
                    .filter((t) => t !== 'order')
                    .map((t) => (
                      <option key={t} value={t}>
                        {NOTIF_TYPES[t].emoji} {NOTIF_TYPES[t].label}
                      </option>
                    ))}
                </select>
                <input className="field h-10" placeholder="العنوان" value={msg.title} onChange={(e) => setMsg({ ...msg, title: e.target.value })} />
                <textarea className="field min-h-16" placeholder="النص" value={msg.body} onChange={(e) => setMsg({ ...msg, body: e.target.value })} />
                <Button
                  size="sm"
                  loading={busy}
                  disabled={!msg.title.trim()}
                  onClick={async () => {
                    if (await run(() => sendNotification(open.id, msg.title.trim(), msg.body.trim(), msg.type), 'تم إرسال الإشعار')) setMsg({ title: '', body: '', type: 'news' })
                  }}
                >
                  إرسال
                </Button>
              </div>
            </div>

            {!open.deleted && (!open.loginMethod || open.loginMethod === 'email') && (
              <div className="rounded-2xl border border-line p-3">
                <div className="mb-2 text-sm font-bold">إعادة تعيين كلمة المرور</div>
                <div className="flex gap-2">
                  <input className="field h-10" dir="ltr" placeholder="كلمة مرور جديدة (6+ أحرف)" value={newPass} onChange={(e) => setNewPass(e.target.value)} />
                  <Button
                    variant="sky"
                    loading={busy}
                    disabled={newPass.length < 6}
                    onClick={async () => {
                      if (await run(async () => (await getBackend()).setUserPassword(open.id, newPass), 'تم تعيين كلمة المرور الجديدة')) setNewPass('')
                    }}
                  >
                    حفظ
                  </Button>
                </div>
                <p className="mt-1.5 text-xs text-ink-3">أرسلها للعميل عبر واتساب، ويمكنه تغييرها من صفحة «حسابي».</p>
              </div>
            )}

            {open.id !== me?.id && !open.deleted && (
              <div className="grid grid-cols-2 gap-2">
                <Button
                  variant="outline"
                  loading={busy}
                  onClick={() => {
                    const role = open.role === 'admin' ? 'user' : 'admin'
                    if (confirm(role === 'admin' ? 'منح صلاحيات الإدارة الكاملة لهذا المستخدم؟' : 'سحب صلاحيات الإدارة؟'))
                      run(() => updateUserAdmin(open.id, { role }), 'تم تحديث الصلاحية')
                  }}
                >
                  {open.role === 'admin' ? 'سحب صلاحية الأدمن' : 'جعله أدمن'}
                </Button>
                <Button variant="danger" loading={busy} onClick={() => run(() => updateUserAdmin(open.id, { blocked: !open.blocked }), open.blocked ? 'تم رفع الحظر' : 'تم حظر المستخدم')}>
                  {open.blocked ? 'رفع الحظر' : 'حظر المستخدم'}
                </Button>
              </div>
            )}

            <div>
              <div className="mb-2 text-sm font-bold">طلبات المستخدم</div>
              {userOrders.length === 0 ? (
                <p className="text-sm text-ink-3">لا توجد طلبات</p>
              ) : (
                <ul className="divide-y divide-line rounded-xl border border-line">
                  {userOrders.slice(0, 10).map((o) => (
                    <li key={o.id} className="flex items-center gap-2 p-2.5 text-sm">
                      <span className="num font-bold">#{orderCode(o.id)}</span>
                      <StatusBadge status={o.status} />
                      <span className="num mr-auto font-semibold">{money(o.total, s.currency)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        )}
      </Sheet>
    </div>
  )
}
