'use client'

import { AlertTriangle, Bell, ChevronLeft, Gift, Info, LayoutDashboard, LogOut, MessageCircle, Pencil, ReceiptText } from 'lucide-react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useState, type ReactNode } from 'react'
import { useAuth, useToast } from '@/components/providers'
import { useUnreadCount } from '@/components/shop-shell'
import { Button, Field, RequireUser, Sheet } from '@/components/ui'
import { getBackend } from '@/lib/backend'
import { num } from '@/lib/format'
import { deleteMyAccount, errorMessage, updateMyProfile } from '@/lib/services'

export default function AccountPage() {
  return (
    <RequireUser>
      <Account />
    </RequireUser>
  )
}

function Account() {
  const { user, isAdmin } = useAuth()
  const unread = useUnreadCount()
  const toast = useToast()
  const router = useRouter()
  const [edit, setEdit] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({ name: user!.name, phone: user!.phone ?? '', address: user!.address ?? '' })

  const initials = user!.name.trim().slice(0, 1)

  async function save() {
    setBusy(true)
    try {
      await updateMyProfile(user!.id, form)
      toast('تم حفظ بياناتك')
      setEdit(false)
    } catch (e) {
      toast(errorMessage(e), 'err')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <section className="card flex items-center gap-4 p-4">
        <div className="hero-grad grid size-16 place-items-center rounded-2xl font-display text-2xl font-black text-white">{initials}</div>
        <div className="min-w-0 flex-1">
          <h1 className="truncate font-display text-xl font-extrabold">{user!.name}</h1>
          <p className="truncate text-sm text-ink-2">{user!.email}</p>
          {user!.phone && <p className="num text-right text-sm text-ink-3">{user!.phone}</p>}
        </div>
        <button onClick={() => setEdit(true)} className="grid size-10 place-items-center rounded-xl bg-sky-50 text-sky-700" aria-label="تعديل">
          <Pencil className="size-4" />
        </button>
      </section>

      <section className="card divide-y divide-line overflow-hidden">
        {isAdmin && (
          <Row href="/admin/" icon={<LayoutDashboard className="size-5" />} tone="bg-ink text-white" title="لوحة تحكم الإدارة" sub="إدارة الطلبات والمنيو والمستخدمين" />
        )}
        <Row href="/loyalty/" icon={<Gift className="size-5" />} tone="bg-brand-50 text-brand" title="برنامج الولاء" sub={`${num(user!.points)} نقطة`} />
        <Row href="/orders/" icon={<ReceiptText className="size-5" />} tone="bg-sky-50 text-sky" title="الطلبات السابقة" />
        <Row
          href="/notifications/"
          icon={<Bell className="size-5" />}
          tone="bg-sky-50 text-sky"
          title="الإشعارات"
          end={unread ? <span className="num rounded-full bg-brand px-2 py-0.5 text-xs font-bold text-white">{unread}</span> : null}
        />
        <Row href="/contact/" icon={<MessageCircle className="size-5" />} tone="bg-ok-50 text-ok" title="اتصل بنا" />
        <Row href="/about/" icon={<Info className="size-5" />} tone="bg-brand-50 text-brand" title="حول التطبيق" />
        <button
          onClick={async () => {
            await (await getBackend()).signOut()
            router.replace('/')
          }}
          className="flex w-full items-center gap-3 p-4 text-right transition hover:bg-bg"
        >
          <span className="grid size-10 place-items-center rounded-xl bg-bg text-ink-2">
            <LogOut className="size-5" />
          </span>
          <span className="flex-1 font-semibold">تسجيل الخروج</span>
        </button>
      </section>

      <section className="rounded-2xl border border-dashed border-danger/30 p-4">
        <div className="flex items-start gap-3">
          <AlertTriangle className="mt-0.5 size-5 shrink-0 text-danger" />
          <div className="flex-1">
            <h2 className="font-bold">حذف الحساب</h2>
            <p className="mt-1 text-sm text-ink-2">سيتم إلغاء تنشيط حسابك نهائياً. يُحتفظ بسجل الطلبات لأغراض تجارية وقانونية.</p>
          </div>
          <Button variant="danger" size="sm" onClick={() => setConfirmDelete(true)}>
            حذف
          </Button>
        </div>
      </section>

      <Sheet open={edit} onClose={() => setEdit(false)} title="تعديل البيانات">
        <div className="space-y-3">
          <Field label="الاسم">
            <input className="field" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </Field>
          <Field label="رقم الهاتف">
            <input className="field num text-right" inputMode="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          </Field>
          <Field label="العنوان">
            <textarea className="field min-h-20" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          </Field>
          <Button className="w-full" loading={busy} onClick={save} disabled={!form.name.trim()}>
            حفظ
          </Button>
        </div>
      </Sheet>

      <Sheet open={confirmDelete} onClose={() => setConfirmDelete(false)} title="تأكيد حذف الحساب">
        <p className="text-sm text-ink-2">لن تتمكن من تسجيل الدخول مرة أخرى وستفقد رصيد نقاطك. لا يمكن التراجع عن هذا الإجراء.</p>
        <div className="mt-4 grid grid-cols-2 gap-2">
          <Button variant="outline" onClick={() => setConfirmDelete(false)}>
            تراجع
          </Button>
          <Button
            variant="danger"
            loading={busy}
            onClick={async () => {
              setBusy(true)
              try {
                await deleteMyAccount(user!.id)
                router.replace('/')
              } catch (e) {
                toast(errorMessage(e), 'err')
                setBusy(false)
              }
            }}
          >
            حذف نهائياً
          </Button>
        </div>
      </Sheet>
    </div>
  )
}

function Row({ href, icon, tone, title, sub, end }: { href: string; icon: ReactNode; tone: string; title: string; sub?: string; end?: ReactNode }) {
  return (
    <Link href={href} className="flex items-center gap-3 p-4 transition hover:bg-bg">
      <span className={`grid size-10 place-items-center rounded-xl ${tone}`}>{icon}</span>
      <span className="flex-1">
        <span className="block font-semibold">{title}</span>
        {sub && <span className="block text-xs text-ink-3">{sub}</span>}
      </span>
      {end}
      <ChevronLeft className="size-5 text-ink-3" />
    </Link>
  )
}
