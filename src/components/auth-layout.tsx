'use client'

import { Check, Eye, EyeOff } from 'lucide-react'
import { useRouter, useSearchParams } from 'next/navigation'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { isDemoMode } from '@/lib/backend'
import { cx } from '@/lib/format'
import { useSettings } from '@/lib/hooks'
import { useAuth } from './providers'

/** Where to go after signing in: ?next=… or the admin panel / home page. */
export function useAfterAuth() {
  const { user, isAdmin } = useAuth()
  const router = useRouter()
  const params = useSearchParams()
  useEffect(() => {
    if (!user) return
    const next = params.get('next')
    router.replace(next && next.startsWith('/') && !next.startsWith('//') ? next : isAdmin ? '/admin/' : '/home/')
  }, [user, isAdmin, params, router])
}

export function AuthLayout({ title, subtitle, children, footer, steps, step }: {
  title: string
  subtitle: string
  children: ReactNode
  footer?: ReactNode
  steps?: string[]
  step?: number
}) {
  const s = useSettings()
  return (
    <div className="min-h-dvh bg-bg">
      <header className="hero-grad relative overflow-hidden rounded-b-[2.5rem] px-6 pt-12 pb-24 text-center text-white">
        <div className="dots absolute inset-0 opacity-50" />
        <div className="absolute -bottom-24 -left-16 size-72 rounded-full bg-sky/50 blur-3xl" />
        <div className="absolute -top-16 -right-10 size-48 rounded-full bg-white/15 blur-2xl" />
        <div className="relative">
          <img
            src={`${(process.env.NEXT_PUBLIC_BASE_PATH ?? '').replace(/\/$/, '')}/icons/icon-192.png`}
            alt=""
            className="mx-auto size-20 rounded-[1.4rem] shadow-[0_14px_30px_rgba(0,0,0,0.2)] ring-4 ring-white/40"
          />
          <div className="mt-3 font-display text-2xl font-black">{s.restaurantName}</div>
          <div className="text-sm text-white/85">{s.tagline}</div>
        </div>
      </header>

      <main className="relative mx-auto -mt-16 w-full max-w-md px-4 pb-10">
        <div className="card p-6 shadow-pop">
          {steps && step !== undefined && <StepBar steps={steps} step={step} />}
          <h1 className="font-display text-2xl font-black">{title}</h1>
          <p className="mt-1 mb-6 text-sm leading-relaxed text-ink-2">{subtitle}</p>
          {children}
        </div>
        {footer && <div className="mt-5 text-center text-sm text-ink-2">{footer}</div>}
        {isDemoMode && (
          <p className="mt-5 rounded-xl border border-sky-100 bg-sky-50 p-3 text-xs leading-relaxed text-sky-800">
            وضع تجريبي: البيانات في هذا المتصفح فقط، ورمز واتساب يظهر على الشاشة.
          </p>
        )}
      </main>
    </div>
  )
}

function StepBar({ steps, step }: { steps: string[]; step: number }) {
  return (
    <ol className="mb-6 flex items-center">
      {steps.map((label, i) => (
        <li key={label} className={cx('flex items-center', i < steps.length - 1 && 'flex-1')}>
          <div className="flex flex-col items-center gap-1">
            <span
              className={cx(
                'grid size-8 place-items-center rounded-full text-sm font-bold transition',
                i < step && 'bg-ok text-white',
                i === step && 'bg-brand text-white ring-4 ring-brand-100',
                i > step && 'bg-bg text-ink-3',
              )}
            >
              {i < step ? <Check className="size-4" /> : i + 1}
            </span>
            <span className={cx('text-[0.68rem] font-semibold whitespace-nowrap', i <= step ? 'text-ink' : 'text-ink-3')}>{label}</span>
          </div>
          {i < steps.length - 1 && <span className={cx('mx-2 mb-5 h-0.5 flex-1 rounded-full', i < step ? 'bg-ok' : 'bg-line')} />}
        </li>
      ))}
    </ol>
  )
}

export function PhoneInput({ value, onChange, autoFocus }: { value: string; onChange: (v: string) => void; autoFocus?: boolean }) {
  return (
    <div className="flex overflow-hidden rounded-xl border border-line bg-[#fbfcfe] transition focus-within:border-sky focus-within:bg-white focus-within:shadow-[0_0_0_4px_rgba(14,165,233,0.12)]" dir="ltr">
      <span className="flex items-center gap-1.5 border-r border-line bg-bg px-3 text-sm font-bold text-ink-2">
        <span aria-hidden>🇮🇶</span> +964
      </span>
      <input
        className="num min-w-0 flex-1 bg-transparent px-3 py-3 text-base tracking-wide outline-none"
        type="tel"
        inputMode="tel"
        autoComplete="tel-national"
        placeholder="7XX XXX XXXX"
        autoFocus={autoFocus}
        value={value}
        onChange={(e) => onChange(e.target.value.replace(/[^\d\s+]/g, ''))}
        required
      />
    </div>
  )
}

export function PasswordInput({ value, onChange, autoComplete = 'current-password', placeholder }: {
  value: string
  onChange: (v: string) => void
  autoComplete?: string
  placeholder?: string
}) {
  const [show, setShow] = useState(false)
  return (
    <div className="relative">
      <input
        className="field pl-11"
        type={show ? 'text' : 'password'}
        required
        minLength={6}
        dir="ltr"
        autoComplete={autoComplete}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
      <button type="button" onClick={() => setShow(!show)} className="absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" aria-label="إظهار كلمة المرور">
        {show ? <EyeOff className="size-5" /> : <Eye className="size-5" />}
      </button>
    </div>
  )
}

/** Six separate boxes for the WhatsApp code; supports paste and SMS/OS autofill. */
export function OtpInput({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const refs = useRef<(HTMLInputElement | null)[]>([])
  const digits = Array.from({ length: 6 }, (_, i) => value[i] ?? '')
  const set = (v: string) => onChange(v.replace(/\D/g, '').slice(0, 6))
  return (
    <div className="flex justify-between gap-2" dir="ltr">
      {digits.map((d, i) => (
        <input
          key={i}
          ref={(el) => {
            refs.current[i] = el
          }}
          className={cx(
            'num size-12 rounded-xl border-2 bg-white text-center text-xl font-black outline-none transition focus:border-brand focus:ring-4 focus:ring-brand-100 sm:size-13',
            d ? 'border-sky' : 'border-line',
          )}
          inputMode="numeric"
          autoComplete={i === 0 ? 'one-time-code' : 'off'}
          aria-label={`الرقم ${i + 1}`}
          value={d}
          autoFocus={i === 0}
          onChange={(e) => {
            const v = e.target.value.replace(/\D/g, '')
            if (v.length > 1) {
              set(v) // paste / autofill
              refs.current[Math.min(5, v.length - 1)]?.focus()
              return
            }
            const next = [...digits]
            next[i] = v
            set(next.join(''))
            if (v && i < 5) refs.current[i + 1]?.focus()
          }}
          onKeyDown={(e) => {
            if (e.key === 'Backspace' && !d && i > 0) refs.current[i - 1]?.focus()
          }}
        />
      ))}
    </div>
  )
}

export function WhatsAppBadge() {
  return (
    <svg viewBox="0 0 24 24" className="size-5" fill="currentColor" aria-hidden>
      <path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.4.1-.2 0-.3 0-.5l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z" />
    </svg>
  )
}
