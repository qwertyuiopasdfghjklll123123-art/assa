'use client'

import { BellRing, Bike, CheckCircle2, Tag } from 'lucide-react'
import { useEffect, useState } from 'react'
import { enablePush, pushState } from '@/lib/push'
import { useAuth, useToast } from './providers'
import { Button, Sheet } from './ui'

const SNOOZE_KEY = 'sufra.notifyAskedAt'

/** Asks the signed-in customer to turn on notifications (once every 3 days until answered). */
export function NotifyPrompt() {
  const { user } = useAuth()
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!user) return
    const state = pushState()
    // Already allowed: make sure this device is registered with the server.
    if (state === 'granted') {
      enablePush(false).catch(() => {})
      return
    }
    if (state !== 'default') return
    let last = 0
    try {
      last = Number(localStorage.getItem(SNOOZE_KEY) || 0)
    } catch {
      /* ignore */
    }
    if (Date.now() - last < 3 * 864e5) return
    const t = setTimeout(() => setOpen(true), 1200)
    return () => clearTimeout(t)
  }, [user])

  const snooze = () => {
    try {
      localStorage.setItem(SNOOZE_KEY, String(Date.now()))
    } catch {
      /* ignore */
    }
    setOpen(false)
  }

  return (
    <Sheet open={open} onClose={snooze} title="تفعيل الإشعارات" hideTitle>
      <div className="pt-4 text-center">
        <div className="hero-grad mx-auto grid size-20 place-items-center rounded-3xl text-white shadow-[0_12px_30px_rgba(249,115,22,0.35)]">
          <BellRing className="size-10" />
        </div>
        <h2 className="mt-4 font-display text-2xl font-black">فعّل الإشعارات</h2>
        <p className="mx-auto mt-1 max-w-xs text-sm text-ink-2">لا تفوّت أي تحديث — سنخبرك فوراً حتى لو كان التطبيق مغلقاً</p>
        <ul className="mt-5 space-y-2 text-right">
          {[
            [CheckCircle2, 'عند قبول طلبك وتأكيده', 'bg-ok-50 text-ok'],
            [Bike, 'عندما يصبح طلبك في الطريق إليك', 'bg-sky-50 text-sky'],
            [Tag, 'العروض والخصومات الحصرية', 'bg-brand-50 text-brand'],
          ].map(([Icon, text, tone]) => {
            const I = Icon as typeof Bike
            return (
              <li key={text as string} className="flex items-center gap-3 rounded-xl bg-bg p-3">
                <span className={`grid size-9 place-items-center rounded-lg ${tone}`}>
                  <I className="size-5" />
                </span>
                <span className="text-sm font-semibold">{text as string}</span>
              </li>
            )
          })}
        </ul>
        <Button
          size="lg"
          className="mt-5 w-full"
          loading={busy}
          onClick={async () => {
            setBusy(true)
            try {
              const s = await enablePush(true)
              if (s === 'granted') toast('تم تفعيل الإشعارات 🔔')
              else if (s === 'denied') toast('تم رفض الإشعارات، يمكنك تفعيلها من إعدادات الهاتف', 'err')
            } catch {
              toast('تعذّر تفعيل الإشعارات على هذا الجهاز', 'err')
            } finally {
              setBusy(false)
              snooze()
            }
          }}
        >
          <BellRing className="size-5" /> تفعيل الإشعارات
        </Button>
        <button onClick={snooze} className="mt-3 text-sm font-semibold text-ink-3">
          ليس الآن
        </button>
      </div>
    </Sheet>
  )
}
