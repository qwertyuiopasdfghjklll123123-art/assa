'use client'

import { Bell, ChevronLeft } from 'lucide-react'
import Link from 'next/link'
import { useEffect, useMemo, useState } from 'react'
import { useAuth } from '@/components/providers'
import { Empty, PageTitle, RequireUser, Spinner } from '@/components/ui'
import { cx, timeAgo } from '@/lib/format'
import { byNewest, useCollection } from '@/lib/hooks'
import { NOTIF_TYPES, type AppNotification, type NotifType } from '@/lib/models'
import { updateMyProfile } from '@/lib/services'

export default function NotificationsPage() {
  return (
    <RequireUser>
      <Notifications />
    </RequireUser>
  )
}

const typeOf = (n: AppNotification): NotifType => n.type ?? (n.orderId ? 'order' : 'news')

function Notifications() {
  const { user } = useAuth()
  const { items, loading } = useCollection<AppNotification>('notifications', { field: 'userId', op: 'in', value: [user!.id, 'all'] }, byNewest)
  const [filter, setFilter] = useState<'all' | NotifType>('all')
  // Remember what was unread when the page opened, then mark everything as seen.
  const [seenAt] = useState(user!.notifSeenAt ?? 0)
  const uid = user!.id
  useEffect(() => {
    updateMyProfile(uid, { notifSeenAt: Date.now() }).catch(() => {})
  }, [uid])

  const visible = items.filter((n) => n.userId !== 'all' || n.createdAt >= (user!.createdAt ?? 0) - 30 * 864e5)
  const types = useMemo(() => Array.from(new Set(visible.map(typeOf))), [visible])
  const shown = filter === 'all' ? visible : visible.filter((n) => typeOf(n) === filter)

  return (
    <div className="mx-auto max-w-2xl">
      <PageTitle title="الإشعارات" subtitle="تحديثات طلباتك وعروض المطعم" />

      {types.length > 1 && (
        <div className="no-scrollbar -mx-4 mb-4 flex gap-2 overflow-x-auto px-4">
          {(['all', ...types] as const).map((t) => (
            <button
              key={t}
              onClick={() => setFilter(t)}
              className={cx(
                'flex h-9 shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-sm font-bold whitespace-nowrap transition',
                filter === t ? 'border-sky bg-sky text-white' : 'border-line bg-white text-ink-2',
              )}
            >
              {t === 'all' ? 'الكل' : `${NOTIF_TYPES[t].emoji} ${NOTIF_TYPES[t].label}`}
            </button>
          ))}
        </div>
      )}

      {loading ? (
        <Spinner />
      ) : shown.length === 0 ? (
        <Empty icon={<Bell className="size-8" />} title="لا توجد إشعارات" text="سنخبرك هنا بكل جديد عن طلباتك وعروضنا" />
      ) : (
        <ul className="space-y-2.5">
          {shown.map((n) => {
            const t = NOTIF_TYPES[typeOf(n)]
            const fresh = n.createdAt > seenAt
            const href = n.orderId ? '/orders/' : n.link || ''
            const body = (
              <>
                <span className={cx('grid size-11 shrink-0 place-items-center rounded-2xl text-xl', t.tone)}>{t.emoji}</span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className={cx('rounded-md px-1.5 py-0.5 text-[0.65rem] font-bold', t.tone)}>{t.label}</span>
                    {fresh && <span className="size-2 rounded-full bg-brand" aria-label="جديد" />}
                    <span className="mr-auto shrink-0 text-xs text-ink-3">{timeAgo(n.createdAt)}</span>
                  </div>
                  <h3 className="mt-1 font-bold">{n.title}</h3>
                  {n.body && <p className="mt-0.5 text-sm leading-relaxed text-ink-2">{n.body}</p>}
                </div>
                {href && <ChevronLeft className="mt-3 size-5 shrink-0 text-ink-3" />}
              </>
            )
            const cls = cx('card rise flex gap-3 p-4 text-right', fresh && 'border-brand/30 bg-brand-50/40')
            return (
              <li key={n.id}>
                {href ? (
                  <Link href={href} className={cls}>
                    {body}
                  </Link>
                ) : (
                  <div className={cls}>{body}</div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
