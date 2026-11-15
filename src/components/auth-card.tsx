'use client'

import { ArrowRight, Loader2, LogIn, ShieldCheck, Sparkles, User, UserPlus } from 'lucide-react'
import Link from 'next/link'
import { useEffect, useState } from 'react'
import { getBackend } from '@/lib/backend'
import { cx, phoneDisplay } from '@/lib/format'
import { useSettings } from '@/lib/hooks'
import { errorMessage } from '@/lib/services'
import { AuthLayout, OtpInput, PhoneInput, WhatsAppBadge, useAfterAuth } from './auth-layout'
import { useToast } from './providers'
import { Button, Field } from './ui'

type Mode = 'login' | 'signup'
type Step = 'choose' | 'phone' | 'code'

interface GoogleTokenClient {
  requestAccessToken: () => void
}
declare global {
  interface Window {
    google?: {
      accounts: {
        oauth2: {
          initTokenClient: (cfg: {
            client_id: string
            scope: string
            callback: (r: { access_token?: string; error?: string }) => void
            error_callback?: (e: { type: string }) => void
          }) => GoogleTokenClient
        }
      }
    }
  }
}

function loadGoogle(): Promise<void> {
  if (window.google?.accounts) return Promise.resolve()
  return new Promise((resolve, reject) => {
    const s = document.createElement('script')
    s.src = 'https://accounts.google.com/gsi/client'
    s.async = true
    s.onload = () => resolve()
    s.onerror = reject
    document.head.appendChild(s)
  })
}

export function GoogleLogo() {
  return (
    <svg viewBox="0 0 24 24" className="size-5" aria-hidden>
      <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.4h6.5a5.6 5.6 0 0 1-2.4 3.7v3h3.9c2.3-2.1 3.5-5.2 3.5-8.8Z" />
      <path fill="#34A853" d="M12 24c3.2 0 6-1.1 8-2.9l-3.9-3c-1.1.7-2.5 1.2-4.1 1.2-3.1 0-5.8-2.1-6.7-5H1.3v3.1A12 12 0 0 0 12 24Z" />
      <path fill="#FBBC05" d="M5.3 14.3a7.2 7.2 0 0 1 0-4.6V6.6h-4a12 12 0 0 0 0 10.8l4-3.1Z" />
      <path fill="#EA4335" d="M12 4.8c1.8 0 3.3.6 4.6 1.8l3.4-3.4A12 12 0 0 0 1.3 6.6l4 3.1c.9-2.9 3.6-4.9 6.7-4.9Z" />
    </svg>
  )
}

/** One card for both signing in and creating an account, with WhatsApp or Google. */
export function AuthCard({ initialMode = 'login' }: { initialMode?: Mode }) {
  useAfterAuth()
  const toast = useToast()
  const s = useSettings()
  const [mode, setMode] = useState<Mode>(initialMode)
  const [step, setStep] = useState<Step>('choose')
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [exists, setExists] = useState(true)
  const [debugCode, setDebugCode] = useState<string | null>(null)
  const [sentTo, setSentTo] = useState('')
  const [cooldown, setCooldown] = useState(0)
  const [busy, setBusy] = useState<null | 'send' | 'verify' | 'google'>(null)
  const [googleId, setGoogleId] = useState('')

  useEffect(() => {
    getBackend()
      .then((b) => b.authConfig())
      .then((c) => {
        setGoogleId(c.googleClientId)
        if (c.googleClientId) loadGoogle().catch(() => {})
      })
      .catch(() => {})
  }, [])

  useEffect(() => {
    if (cooldown <= 0) return
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000)
    return () => clearTimeout(t)
  }, [cooldown])

  async function sendCode() {
    setBusy('send')
    try {
      const r = await (await getBackend()).sendOtp(phone)
      setExists(r.exists)
      setSentTo('\u2066' + phoneDisplay(r.phone) + '\u2069') // keep the number left-to-right inside Arabic text
      setDebugCode(r.debugCode ?? null)
      setCooldown(60)
      setCode('')
      setStep('code')
      toast('أرسلنا رمز التحقق إلى واتساب')
    } catch (e) {
      toast(errorMessage(e), 'err')
    } finally {
      setBusy(null)
    }
  }

  async function verify(e: React.FormEvent) {
    e.preventDefault()
    setBusy('verify')
    try {
      await (await getBackend()).signInWhatsApp({ phone, code, name: exists ? undefined : name.trim() })
      toast(exists ? 'أهلاً بعودتك 👋' : 'تم إنشاء حسابك بنجاح 🎉')
    } catch (err) {
      toast(errorMessage(err), 'err')
      setBusy(null)
    }
  }

  async function google() {
    if (!googleId) return toast(errorMessage(new Error('google-not-configured')), 'err')
    setBusy('google')
    try {
      await loadGoogle()
      const client = window.google!.accounts.oauth2.initTokenClient({
        client_id: googleId,
        scope: 'openid email profile',
        callback: async (r) => {
          if (!r.access_token) {
            toast(errorMessage(new Error('google-cancelled')), 'err')
            setBusy(null)
            return
          }
          try {
            await (await getBackend()).signInGoogle(r.access_token)
            toast('تم تسجيل الدخول بحساب Google')
          } catch (err) {
            toast(errorMessage(err), 'err')
            setBusy(null)
          }
        },
        error_callback: () => {
          toast(errorMessage(new Error('google-cancelled')), 'err')
          setBusy(null)
        },
      })
      client.requestAccessToken()
    } catch {
      toast('تعذّر فتح نافذة Google، تحقق من الإنترنت', 'err')
      setBusy(null)
    }
  }

  const head =
    step === 'choose'
      ? mode === 'login'
        ? ['أهلاً بعودتك 👋', 'سجّل الدخول لمتابعة طلباتك ونقاطك']
        : ['أنشئ حسابك مجاناً ✨', `واحصل على ${s.loyaltyPercent}% كاش باك مع كل طلب`]
      : step === 'phone'
        ? ['المتابعة عبر واتساب', 'أدخل رقمك وسنرسل لك رمز تحقق على واتساب']
        : exists
          ? ['أدخل رمز التحقق', `أرسلنا رمزاً من 6 أرقام إلى واتساب على الرقم ${sentTo}`]
          : ['رقم جديد — لنُنشئ حسابك', `أدخل الرمز المرسل إلى واتساب على الرقم ${sentTo} واسمك`]

  return (
    <AuthLayout
      title={head[0]}
      subtitle={head[1]}
      footer={
        <Link href="/menu/" className="font-semibold text-ink-3 hover:text-ink">
          تصفح المنيو بدون تسجيل ←
        </Link>
      }
    >
      {step === 'choose' && (
        <>
          <div className="mb-5 grid grid-cols-2 gap-1 rounded-2xl bg-bg p-1">
            {(
              [
                ['login', 'تسجيل الدخول', LogIn],
                ['signup', 'حساب جديد', UserPlus],
              ] as const
            ).map(([k, label, Icon]) => (
              <button
                key={k}
                onClick={() => setMode(k)}
                className={cx(
                  'flex h-10 items-center justify-center gap-1.5 rounded-xl text-sm font-bold transition',
                  mode === k ? 'bg-white text-brand-600 shadow-card' : 'text-ink-3',
                )}
              >
                <Icon className="size-4" /> {label}
              </button>
            ))}
          </div>

          <div className="space-y-3">
            <button
              onClick={() => setStep('phone')}
              className="flex h-14 w-full items-center gap-3 rounded-2xl bg-[#25D366] px-4 font-bold text-white shadow-[0_8px_20px_rgba(37,211,102,0.3)] transition hover:bg-[#1ebe5b] active:scale-[0.99]"
            >
              <span className="grid size-9 place-items-center rounded-xl bg-white/20">
                <WhatsAppBadge />
              </span>
              <span className="flex-1 text-center">{mode === 'login' ? 'الدخول عبر واتساب' : 'إنشاء حساب عبر واتساب'}</span>
              <span className="w-9" />
            </button>
            <button
              onClick={google}
              disabled={busy === 'google'}
              className="flex h-14 w-full items-center gap-3 rounded-2xl border-2 border-line bg-white px-4 font-bold text-ink transition hover:border-sky active:scale-[0.99] disabled:opacity-60"
            >
              <span className="grid size-9 place-items-center rounded-xl bg-bg">{busy === 'google' ? <Loader2 className="size-5 animate-spin" /> : <GoogleLogo />}</span>
              <span className="flex-1 text-center">{mode === 'login' ? 'الدخول بحساب Google' : 'إنشاء حساب بـ Google'}</span>
              <span className="w-9" />
            </button>
          </div>

          <p className="mt-5 text-center text-xs leading-relaxed text-ink-3">
            {mode === 'login' ? 'ليس لديك حساب؟ لا مشكلة — سننشئه لك تلقائياً عند أول دخول.' : 'لديك حساب؟ اختر نفس الطريقة وسندخلك إليه مباشرة.'}
          </p>
          <ul className="mt-4 grid grid-cols-3 gap-2 border-t border-line pt-4 text-center text-[0.7rem] font-semibold text-ink-2">
            <li>
              <Sparkles className="mx-auto mb-1 size-5 text-brand" /> {s.loyaltyPercent}% كاش باك
            </li>
            <li>
              <ShieldCheck className="mx-auto mb-1 size-5 text-ok" /> بدون كلمة مرور
            </li>
            <li>
              <WhatsAppBadge /> <span className="mt-1 block">تحديثات طلبك</span>
            </li>
          </ul>
        </>
      )}

      {step === 'phone' && (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            sendCode()
          }}
        >
          <Field label="رقم الواتساب">
            <PhoneInput value={phone} onChange={setPhone} autoFocus />
          </Field>
          <Button type="submit" size="lg" className="w-full !bg-[#25D366] !shadow-none hover:!bg-[#1ebe5b]" loading={busy === 'send'}>
            <WhatsAppBadge /> إرسال الرمز
          </Button>
          <BackLink onClick={() => setStep('choose')} label="طرق أخرى للدخول" />
        </form>
      )}

      {step === 'code' && (
        <form className="space-y-4" onSubmit={verify}>
          {!exists && (
            <Field label="اسمك الكامل">
              <div className="relative">
                <User className="pointer-events-none absolute top-1/2 right-3.5 size-5 -translate-y-1/2 text-ink-3" />
                <input className="field pr-11" required autoComplete="name" placeholder="مثال: علي حسن" value={name} onChange={(e) => setName(e.target.value)} />
              </div>
            </Field>
          )}
          <Field label="رمز التحقق">
            <OtpInput value={code} onChange={setCode} />
          </Field>
          {debugCode && (
            <p className="rounded-xl border border-dashed border-amber-300 bg-warn-50 p-2.5 text-center text-xs font-semibold text-amber-800">
              وضع الاختبار (واتساب غير مربوط بعد) — الرمز: <b className="num text-base tracking-widest">{debugCode}</b>
            </p>
          )}
          <Button type="submit" size="lg" className="w-full" loading={busy === 'verify'} disabled={code.length !== 6 || (!exists && !name.trim())}>
            {exists ? 'تسجيل الدخول' : 'إنشاء الحساب'}
          </Button>
          <p className="text-center text-sm text-ink-2">
            لم يصلك الرمز؟{' '}
            {cooldown > 0 ? (
              <span className="num font-semibold text-ink-3">إعادة الإرسال بعد {cooldown} ث</span>
            ) : (
              <button type="button" disabled={busy === 'send'} onClick={sendCode} className="font-bold text-sky-600 disabled:opacity-50">
                إعادة الإرسال
              </button>
            )}
          </p>
          <BackLink onClick={() => setStep('phone')} label="تعديل الرقم" />
        </form>
      )}
    </AuthLayout>
  )
}

function BackLink({ onClick, label }: { onClick: () => void; label: string }) {
  return (
    <button type="button" onClick={onClick} className="mx-auto flex items-center gap-1 text-sm font-semibold text-ink-3 hover:text-ink">
      <ArrowRight className="size-4" /> {label}
    </button>
  )
}
