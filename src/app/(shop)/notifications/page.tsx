'use client'

import { Bell, Megaphone, ReceiptText } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useAuth } from '@/components/providers'
import { Empty, PageTitle, RequireUser, Spinner } from '@/components/ui'
import { cx, timeAgo } from '@/lib/format'
import { byNewest, useCollection } from '@/lib/hooks'
import type { AppNotification } from '@/lib/models'
import { updateMyProfile } from '@/lib/services'

export default function NotificationsPage() {
  return (
    <RequireUser>
      <Notifications />
    </RequireUser>
  )
}

function Notifications() {
  const { user } = useAuth()
  const { items, loading } = useCollection<AppNotification>('notifications', { field: 'userId', op: 'in', value: [user!.id, 'all'] }, byNewest)
  // Remember what was unread when the page opened, then mark everything as seen.
  const [seenAt] = useState(user!.notifSeenAt ?? 0)
  const uid = user!.id
  useEffect(() => {
    updateMyProfile(uid, { notifSeenAt: Date.now() }).catch(() => {})
  }, [uid])

  return (
    <div className="mx-auto max-w-2xl">
      <PageTitle title="الإشعارات" subtitle="تحديثات طلباتك وعروض المطعم" />
      {loading ? (
        <Spinner />
      ) : items.length === 0 ? (
        <Empty icon={<Bell className="size-8" />} title="لا توجد إشعارات" text="سنخبرك هنا بكل جديد عن طلباتك" />
      ) : (
        <ul className="space-y-2">
          {items.map((n) => {
            const fresh = n.createdAt > seenAt
            return (
              <li key={n.id} className={cx('card rise flex gap-3 p-4', fresh && 'border-sky/40 bg-sky-50/60')}>
                <span className={cx('grid size-10 shrink-0 place-items-center rounded-xl', n.orderId ? 'bg-brand-50 text-brand' : 'bg-sky-50 text-sky')}>
                  {n.orderId ? <ReceiptText className="size-5" /> : <Megaphone className="size-5" />}
                </span>
                <div className="flex-1">
                  <div className="flex items-start justify-between gap-2">
                    <h3 className="font-bold">{n.title}</h3>
                    <span className="shrink-0 text-xs text-ink-3">{timeAgo(n.createdAt)}</span>
                  </div>
                  <p className="mt-0.5 text-sm text-ink-2">{n.body}</p>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
