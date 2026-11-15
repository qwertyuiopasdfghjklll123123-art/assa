'use client'

import { Loader2, X } from 'lucide-react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useEffect, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { cx } from '@/lib/format'
import { STATUS_LABEL, type OrderStatus } from '@/lib/models'
import { useAuth } from './providers'

type Variant = 'primary' | 'sky' | 'ghost' | 'outline' | 'danger' | 'dark'

const VARIANTS: Record<Variant, string> = {
  primary: 'bg-brand text-white hover:bg-brand-600 shadow-[0_6px_18px_rgba(249,115,22,0.28)]',
  sky: 'bg-sky text-white hover:bg-sky-600 shadow-[0_6px_18px_rgba(14,165,233,0.25)]',
  dark: 'bg-ink text-white hover:bg-ink/90',
  ghost: 'bg-transparent text-ink-2 hover:bg-line/60',
  outline: 'bg-card text-ink border border-line hover:border-sky hover:text-sky-600',
  danger: 'bg-danger-50 text-danger border border-danger/20 hover:bg-danger hover:text-white',
}

export function Button({
  variant = 'primary',
  loading,
  className,
  children,
  size = 'md',
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; loading?: boolean; size?: 'sm' | 'md' | 'lg' }) {
  return (
    <button
      {...rest}
      disabled={rest.disabled || loading}
      className={cx(
        'inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-55',
        size === 'sm' && 'h-9 px-3 text-sm',
        size === 'md' && 'h-11 px-4 text-[0.95rem]',
        size === 'lg' && 'h-13 px-6 text-base',
        VARIANTS[variant],
        className,
      )}
    >
      {loading && <Loader2 className="size-4 animate-spin" />}
      {children}
    </button>
  )
}

export function Logo({ name = 'سُفرة', compact }: { name?: string; compact?: boolean }) {
  return (
    <Link href="/home/" className="flex items-center gap-2.5" aria-label={name}>
      <span className="grid size-10 place-items-center rounded-xl hero-grad text-white shadow-[0_6px_16px_rgba(249,115,22,0.3)]">
        <svg viewBox="0 0 24 24" className="size-6" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
          <path d="M3 14h18" />
          <path d="M5 14a7 7 0 0 1 14 0" />
          <path d="M12 5V4" />
          <path d="M4 18h16" />
        </svg>
      </span>
      {!compact && (
        <span className="leading-tight">
          <span className="block font-display text-xl font-extrabold text-ink">{name}</span>
          <span className="block text-[0.7rem] font-medium text-sky-600">اطلب • استمتع • اجمع النقاط</span>
        </span>
      )}
    </Link>
  )
}

export function Spinner({ className }: { className?: string }) {
  return (
    <div className={cx('grid place-items-center py-16', className)}>
      <Loader2 className="size-7 animate-spin text-brand" />
    </div>
  )
}

export function Empty({ icon, title, text, action }: { icon: ReactNode; title: string; text?: string; action?: ReactNode }) {
  return (
    <div className="rise flex flex-col items-center px-6 py-14 text-center">
      <div className="mb-4 grid size-20 place-items-center rounded-2xl border border-dashed border-sky/40 bg-sky-50 text-sky">
        {icon}
      </div>
      <h3 className="font-display text-lg font-extrabold">{title}</h3>
      {text && <p className="mt-1 max-w-xs text-sm text-ink-2">{text}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}

const STATUS_STYLE: Record<OrderStatus, string> = {
  pending: 'bg-warn-50 text-amber-700 border-amber-200',
  confirmed: 'bg-sky-50 text-sky-700 border-sky-200',
  preparing: 'bg-brand-50 text-brand-600 border-brand-100',
  on_the_way: 'bg-indigo-50 text-indigo-700 border-indigo-200',
  delivered: 'bg-ok-50 text-emerald-700 border-emerald-200',
  cancelled: 'bg-danger-50 text-red-700 border-red-200',
}

export function StatusBadge({ status }: { status: OrderStatus }) {
  return (
    <span className={cx('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold', STATUS_STYLE[status])}>
      <span className="size-1.5 rounded-full bg-current" />
      {STATUS_LABEL[status]}
    </span>
  )
}

export function Thumb({ image, emoji, alt, className }: { image?: string; emoji?: string; alt: string; className?: string }) {
  return (
    <div className={cx('relative overflow-hidden bg-gradient-to-br from-brand-50 to-sky-50', className)}>
      {image ? (
        <img src={image} alt={alt} className="absolute inset-0 size-full object-cover" loading="lazy" />
      ) : (
        <span className="absolute inset-0 grid place-items-center text-4xl" aria-hidden>
          {emoji || '🍽️'}
        </span>
      )}
    </div>
  )
}

export function Sheet({ open, onClose, title, children, hideTitle }: { open: boolean; onClose: () => void; title: string; children: ReactNode; hideTitle?: boolean }) {
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
    }
  }, [open, onClose])
  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal aria-label={title}>
      <button className="absolute inset-0 bg-ink/40 backdrop-blur-[2px]" onClick={onClose} aria-label="إغلاق" />
      <div className="sheet-in relative max-h-[92dvh] w-full overflow-y-auto rounded-t-3xl bg-card p-5 shadow-pop sm:max-w-lg sm:rounded-3xl">
        {hideTitle ? (
          <button
            onClick={onClose}
            className="absolute top-4 left-4 z-10 grid size-10 place-items-center rounded-full bg-white/95 text-ink shadow-pop"
            aria-label="إغلاق"
          >
            <X className="size-5" />
          </button>
        ) : (
          <div className="mb-4 flex items-center justify-between">
            <h2 className="font-display text-lg font-extrabold">{title}</h2>
            <button onClick={onClose} className="grid size-9 place-items-center rounded-xl bg-bg text-ink-2 hover:text-ink" aria-label="إغلاق">
              <X className="size-5" />
            </button>
          </div>
        )}
        {children}
      </div>
    </div>
  )
}

export function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-semibold text-ink">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-ink-3">{hint}</span>}
    </label>
  )
}

export function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label?: string }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      onClick={() => onChange(!checked)}
      className={cx('relative h-6 w-11 shrink-0 rounded-full transition', checked ? 'bg-ok' : 'bg-line')}
    >
      <span className={cx('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', checked ? 'right-0.5' : 'right-[1.375rem]')} />
    </button>
  )
}

/** Redirects to the login page when there is no signed-in user. */
export function RequireUser({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  const router = useRouter()
  useEffect(() => {
    if (!loading && !user) router.replace(`/login/?next=${encodeURIComponent(window.location.pathname)}`)
  }, [loading, user, router])
  if (loading || !user) return <Spinner />
  return <>{children}</>
}

export function PageTitle({ title, subtitle, action }: { title: string; subtitle?: string; action?: ReactNode }) {
  return (
    <div className="mb-5 flex items-end justify-between gap-3">
      <div>
        <h1 className="font-display text-2xl font-extrabold">{title}</h1>
        {subtitle && <p className="mt-0.5 text-sm text-ink-2">{subtitle}</p>}
      </div>
      {action}
    </div>
  )
}
