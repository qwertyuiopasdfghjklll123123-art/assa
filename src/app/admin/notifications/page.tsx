'use client'

import { Megaphone, Send, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useToast } from '@/components/providers'
import { Button, Field, PageTitle } from '@/components/ui'
import { timeAgo } from '@/lib/format'
import { byNewest, useCollection } from '@/lib/hooks'
import type { AppNotification } from '@/lib/models'
import { errorMessage, removeDoc, sendNotification } from '@/lib/services'

export default function AdminNotificationsPage() {
  const toast = useToast()
  const { items } = useCollection<AppNotification>('notifications', { field: 'userId', op: '==', value: 'all' }, byNewest)
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_1.2fr]">
      <div>
        <PageTitle title="الإشعارات" subtitle="أرسل عرضاً أو خبراً لجميع العملاء" />
        <form
          className="card space-y-3 p-4"
          onSubmit={async (e) => {
            e.preventDefault()
            setBusy(true)
            try {
              await sendNotification('all', title.trim(), body.trim())
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
          <Field label="العنوان">
            <input className="field" required value={title} onChange={(e) => setTitle(e.target.value)} placeholder="مثال: خصم 20% اليوم فقط" />
          </Field>
          <Field label="النص">
            <textarea className="field min-h-28" value={body} onChange={(e) => setBody(e.target.value)} />
          </Field>
          <Button type="submit" className="w-full" loading={busy}>
            <Send className="size-4" /> إرسال للجميع
          </Button>
        </form>
      </div>

      <section>
        <h2 className="mb-3 font-bold lg:mt-16">الإشعارات العامة المرسلة</h2>
        <ul className="space-y-2">
          {items.map((n) => (
            <li key={n.id} className="card flex gap-3 p-3">
              <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky">
                <Megaphone className="size-5" />
              </span>
              <div className="flex-1">
                <div className="font-bold">{n.title}</div>
                <p className="text-sm text-ink-2">{n.body}</p>
                <span className="text-xs text-ink-3">{timeAgo(n.createdAt)}</span>
              </div>
              <button
                onClick={async () => {
                  if (!confirm('حذف هذا الإشعار؟')) return
                  await removeDoc('notifications', n.id).catch((e) => toast(errorMessage(e), 'err'))
                }}
                className="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-danger-50 hover:text-danger"
                aria-label="حذف"
              >
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
          {items.length === 0 && <p className="text-sm text-ink-3">لم تُرسل إشعارات بعد</p>}
        </ul>
      </section>
    </div>
  )
}
