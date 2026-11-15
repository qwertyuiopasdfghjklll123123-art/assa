'use client'

import { Send, Smartphone, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useToast } from '@/components/providers'
import { Button, Field, PageTitle } from '@/components/ui'
import { cx, timeAgo } from '@/lib/format'
import { byNewest, useCollection } from '@/lib/hooks'
import { NOTIF_TYPES, type AppNotification, type NotifType } from '@/lib/models'
import { errorMessage, removeDoc, sendNotification } from '@/lib/services'

const SENDABLE = (Object.keys(NOTIF_TYPES) as NotifType[]).filter((t) => t !== 'order')

const LINKS = [
  ['', 'بدون رابط (صفحة الإشعارات)'],
  ['/menu/', 'المنيو'],
  ['/loyalty/', 'برنامج الولاء'],
  ['/', 'الصفحة الرئيسية'],
  ['/contact/', 'اتصل بنا'],
]

const TEMPLATES: Record<string, { title: string; body: string }> = {
  offer: { title: 'عرض اليوم 🎁', body: 'اطلب وجبتين واحصل على مشروب مجاناً — لفترة محدودة!' },
  discount: { title: 'خصم 20% على كل الطلبات 🏷️', body: 'استخدم التطبيق اليوم واستمتع بخصم 20% على طلبك.' },
  new_item: { title: 'صنف جديد في المنيو 🍽️', body: 'جرّب طبقنا الجديد الآن واجمع نقاطك.' },
  news: { title: 'خبر من المطعم 📢', body: '' },
  alert: { title: 'تنبيه مهم ⚠️', body: '' },
}

export default function AdminNotificationsPage() {
  const toast = useToast()
  const { items } = useCollection<AppNotification>('notifications', { field: 'userId', op: '==', value: 'all' }, byNewest)
  const [type, setType] = useState<NotifType>('offer')
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [link, setLink] = useState('/menu/')
  const [busy, setBusy] = useState(false)
  const t = NOTIF_TYPES[type]

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_1.1fr]">
      <div>
        <PageTitle title="إرسال إشعار" subtitle="يصل لجميع العملاء داخل التطبيق وعلى هواتفهم" />
        <form
          className="card space-y-4 p-4"
          onSubmit={async (e) => {
            e.preventDefault()
            setBusy(true)
            try {
              await sendNotification('all', title.trim(), body.trim(), type, link)
              toast('تم إرسال الإشعار لجميع العملاء')
              setTitle('')
              setBody('')
            } catch (err) {
              toast(errorMessage(err), 'err')
            } finally {
              setBusy(false)
            }
          }}
        >
          <Field label="نوع الإشعار">
            <div className="grid grid-cols-3 gap-2">
              {SENDABLE.map((k) => (
                <button
                  key={k}
                  type="button"
                  onClick={() => setType(k)}
                  className={cx(
                    'flex flex-col items-center gap-1 rounded-xl border p-2.5 text-xs font-bold transition',
                    type === k ? 'border-brand bg-brand-50 text-brand-600' : 'border-line text-ink-2 hover:border-sky',
                  )}
                >
                  <span className="text-xl">{NOTIF_TYPES[k].emoji}</span>
                  {NOTIF_TYPES[k].label}
                </button>
              ))}
            </div>
          </Field>
          <div className="flex justify-end">
            <button
              type="button"
              onClick={() => {
                setTitle(TEMPLATES[type]?.title ?? '')
                setBody(TEMPLATES[type]?.body ?? '')
              }}
              className="text-xs font-bold text-sky-600"
            >
              استخدم نصاً جاهزاً
            </button>
          </div>
          <Field label="العنوان">
            <input className="field" required maxLength={80} value={title} onChange={(e) => setTitle(e.target.value)} />
          </Field>
          <Field label="النص">
            <textarea className="field min-h-24" maxLength={300} value={body} onChange={(e) => setBody(e.target.value)} />
          </Field>
          <Field label="عند الضغط على الإشعار يفتح">
            <select className="field" value={link} onChange={(e) => setLink(e.target.value)}>
              {LINKS.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </Field>

          {/* Phone preview */}
          <div className="rounded-2xl bg-ink p-3">
            <div className="mb-2 flex items-center gap-1.5 text-[0.7rem] font-semibold text-sky-100/70">
              <Smartphone className="size-3.5" /> معاينة على الهاتف
            </div>
            <div className="flex gap-3 rounded-xl bg-white/95 p-3">
              <span className={cx('grid size-10 shrink-0 place-items-center rounded-xl text-lg', t.tone)}>{t.emoji}</span>
              <div className="min-w-0">
                <div className="truncate text-sm font-bold">{title || 'عنوان الإشعار'}</div>
                <div className="line-clamp-2 text-xs text-ink-2">{body || 'نص الإشعار يظهر هنا'}</div>
              </div>
            </div>
          </div>

          <Button type="submit" className="w-full" size="lg" loading={busy}>
            <Send className="size-4" /> إرسال للجميع
          </Button>
        </form>
      </div>

      <section>
        <h2 className="mb-3 font-bold lg:mt-16">الإشعارات المرسلة</h2>
        <ul className="space-y-2">
          {items.map((n) => {
            const nt = NOTIF_TYPES[n.type ?? 'news']
            return (
              <li key={n.id} className="card flex gap-3 p-3">
                <span className={cx('grid size-10 shrink-0 place-items-center rounded-xl text-lg', nt.tone)}>{nt.emoji}</span>
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <span className={cx('rounded-md px-1.5 py-0.5 text-[0.65rem] font-bold', nt.tone)}>{nt.label}</span>
                    <span className="text-xs text-ink-3">{timeAgo(n.createdAt)}</span>
                  </div>
                  <div className="mt-0.5 font-bold">{n.title}</div>
                  <p className="text-sm text-ink-2">{n.body}</p>
                </div>
                <button
                  onClick={async () => {
                    if (!confirm('حذف هذا الإشعار من التطبيق؟')) return
                    await removeDoc('notifications', n.id).catch((e) => toast(errorMessage(e), 'err'))
                  }}
                  className="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-danger-50 hover:text-danger"
                  aria-label="حذف"
                >
                  <Trash2 className="size-4" />
                </button>
              </li>
            )
          })}
          {items.length === 0 && <p className="text-sm text-ink-3">لم تُرسل إشعارات بعد</p>}
        </ul>
      </section>
    </div>
  )
}
