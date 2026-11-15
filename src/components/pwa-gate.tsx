'use client'

import { CheckCircle2, Download, EllipsisVertical, MonitorSmartphone, Plus, Share, Smartphone } from 'lucide-react'
import { usePathname } from 'next/navigation'
import { useEffect, useState, useSyncExternalStore, type ReactNode } from 'react'
import { useSettings } from '@/lib/hooks'
import { Button } from './ui'

const BASE = (process.env.NEXT_PUBLIC_BASE_PATH ?? '').replace(/\/$/, '')

/** The app must be installed to be used, unless explicitly disabled (and never during `next dev`). */
export const PWA_REQUIRED = process.env.NEXT_PUBLIC_REQUIRE_PWA !== 'false' && process.env.NODE_ENV !== 'development'

type InstallEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> }
declare global {
  interface Window {
    __installPrompt?: InstallEvent | null
  }
}

function isStandalone() {
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    window.matchMedia('(display-mode: fullscreen)').matches ||
    window.matchMedia('(display-mode: minimal-ui)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true ||
    document.referrer.startsWith('android-app://')
  )
}

function subscribeStandalone(cb: () => void) {
  const mq = window.matchMedia('(display-mode: standalone)')
  mq.addEventListener('change', cb)
  return () => mq.removeEventListener('change', cb)
}

/** null while rendering on the server / before hydration. */
export function useStandalone(): boolean | null {
  return useSyncExternalStore(subscribeStandalone, isStandalone, () => null)
}

function platform() {
  const ua = navigator.userAgent
  const ios = /iPhone|iPad|iPod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  if (ios) return /CriOS|FxiOS|EdgiOS|OPiOS/i.test(ua) ? 'ios-other' : 'ios'
  if (/Android/i.test(ua)) return 'android'
  return 'desktop'
}

export function PwaGate({ children }: { children: ReactNode }) {
  const path = usePathname()
  const standalone = useStandalone()

  useEffect(() => {
    if ('serviceWorker' in navigator && process.env.NODE_ENV === 'production') {
      navigator.serviceWorker.register(`${BASE}/sw.js`).catch(() => {})
    }
  }, [])

  // The admin panel is used from the restaurant's computer, so it stays reachable in the browser.
  const exempt = !PWA_REQUIRED || path.startsWith('/admin')
  if (exempt || standalone) return <>{children}</>
  if (standalone === null) return <Splash />
  return <InstallScreen />
}

function Splash() {
  return (
    <div className="grid min-h-dvh place-items-center">
      <span className="hero-grad size-16 animate-pulse rounded-2xl" />
    </div>
  )
}

function InstallScreen() {
  const s = useSettings()
  const [evt, setEvt] = useState<InstallEvent | null>(() => (typeof window !== 'undefined' ? (window.__installPrompt ?? null) : null))
  const [installed, setInstalled] = useState(false)
  const [os] = useState(() => (typeof window !== 'undefined' ? platform() : 'desktop'))

  useEffect(() => {
    const onPrompt = (e: Event) => {
      e.preventDefault()
      window.__installPrompt = e as InstallEvent
      setEvt(e as InstallEvent)
    }
    const onInstalled = () => setInstalled(true)
    window.addEventListener('beforeinstallprompt', onPrompt)
    window.addEventListener('appinstalled', onInstalled)
    return () => {
      window.removeEventListener('beforeinstallprompt', onPrompt)
      window.removeEventListener('appinstalled', onInstalled)
    }
  }, [])

  async function install() {
    if (!evt) return
    await evt.prompt()
    const { outcome } = await evt.userChoice
    if (outcome === 'accepted') setInstalled(true)
    window.__installPrompt = null
    setEvt(null)
  }

  return (
    <div className="relative min-h-dvh overflow-hidden">
      <div className="hero-grad absolute inset-x-0 top-0 h-[46dvh] rounded-b-[2.5rem]">
        <div className="dots absolute inset-0 opacity-50" />
        <div className="absolute -bottom-20 -left-16 size-72 rounded-full bg-sky/50 blur-3xl" />
      </div>

      <div className="relative mx-auto flex min-h-dvh max-w-md flex-col px-5 pt-14 pb-8">
        <div className="text-center text-white">
          <img src={`${BASE}/icons/icon-192.png`} alt="" className="mx-auto size-24 rounded-[1.6rem] shadow-[0_18px_40px_rgba(0,0,0,0.25)] ring-4 ring-white/40" />
          <h1 className="mt-5 font-display text-3xl font-black">{s.restaurantName}</h1>
          <p className="mt-1 text-white/90">{s.tagline}</p>
        </div>

        <div className="card mt-8 p-5 shadow-pop">
          {installed ? (
            <div className="text-center">
              <CheckCircle2 className="mx-auto size-14 text-ok" />
              <h2 className="mt-3 font-display text-xl font-extrabold">تم تثبيت التطبيق 🎉</h2>
              <p className="mt-1 text-sm text-ink-2">افتح «{s.restaurantName}» من الشاشة الرئيسية لهاتفك لتبدأ الطلب.</p>
            </div>
          ) : (
            <>
              <div className="flex items-center gap-3">
                <span className="grid size-11 place-items-center rounded-xl bg-sky-50 text-sky">
                  <MonitorSmartphone className="size-6" />
                </span>
                <div>
                  <h2 className="font-display text-lg font-extrabold">ثبّت التطبيق للمتابعة</h2>
                  <p className="text-sm text-ink-2">التطبيق يعمل بعد تثبيته على هاتفك فقط</p>
                </div>
              </div>

              {evt ? (
                <Button size="lg" className="mt-5 w-full" onClick={install}>
                  <Download className="size-5" /> تثبيت التطبيق
                </Button>
              ) : os === 'ios' ? (
                <Steps
                  steps={[
                    [<Share key="i" className="size-5" />, 'اضغط زر المشاركة في أسفل متصفح Safari'],
                    [<Plus key="i" className="size-5" />, 'اختر «إضافة إلى الشاشة الرئيسية»'],
                    [<CheckCircle2 key="i" className="size-5" />, 'اضغط «إضافة» ثم افتح التطبيق من الشاشة الرئيسية'],
                  ]}
                />
              ) : os === 'ios-other' ? (
                <p className="mt-5 rounded-xl bg-warn-50 p-3 text-sm font-semibold text-amber-800">
                  على الآيفون يتم التثبيت من متصفح Safari فقط. انسخ الرابط وافتحه في Safari ثم اتبع خطوات «إضافة إلى الشاشة الرئيسية».
                </p>
              ) : (
                <Steps
                  steps={[
                    [<EllipsisVertical key="i" className="size-5" />, 'افتح قائمة المتصفح (⋮) في الأعلى'],
                    [<Smartphone key="i" className="size-5" />, 'اختر «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية»'],
                    [<CheckCircle2 key="i" className="size-5" />, 'افتح التطبيق من الشاشة الرئيسية'],
                  ]}
                />
              )}
            </>
          )}
        </div>

        <ul className="mt-6 grid grid-cols-3 gap-2 text-center text-xs font-semibold text-ink-2">
          {['يفتح بسرعة', 'إشعارات طلبك', `${s.loyaltyPercent}% كاش باك`].map((t) => (
            <li key={t} className="rounded-xl border border-line bg-card px-2 py-3">
              {t}
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function Steps({ steps }: { steps: [ReactNode, string][] }) {
  return (
    <ol className="mt-5 space-y-3">
      {steps.map(([icon, text], i) => (
        <li key={i} className="flex items-center gap-3 rounded-xl bg-bg p-3">
          <span className="num grid size-8 shrink-0 place-items-center rounded-lg bg-brand font-bold text-white">{i + 1}</span>
          <span className="flex-1 text-sm font-semibold">{text}</span>
          <span className="text-sky">{icon}</span>
        </li>
      ))}
    </ol>
  )
}
