'use client'

import { ArrowRight, Gift, ShieldCheck, Sparkles, User } from 'lucide-react'
import Link from 'next/link'
import { Suspense, useState } from 'react'
import { AuthLayout, OtpInput, PasswordInput, PhoneInput, WhatsAppBadge, useAfterAuth } from '@/components/auth-layout'
import { DebugCode, ResendLine, useOtp } from '@/components/otp-flow'
import { useToast } from '@/components/providers'
import { Button, Field } from '@/components/ui'
import { getBackend } from '@/lib/backend'
import { useSettings } from '@/lib/hooks'
import { errorMessage } from '@/lib/services'

export default function RegisterPage() {
  return (
    <Suspense>
      <Register />
    </Suspense>
  )
}

const STEPS = ['بياناتك', 'رمز واتساب', 'كلمة المرور']

function Register() {
  useAfterAuth()
  const toast = useToast()
  const s = useSettings()
  const otp = useOtp('signup')
  const [step, setStep] = useState(0)
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)

  async function create(e: React.FormEvent) {
    e.preventDefault()
    if (password !== confirm) return toast('كلمتا المرور غير متطابقتين', 'err')
    setBusy(true)
    try {
      await (await getBackend()).signUp({ phone, code, name: name.trim(), password })
      toast('أهلاً بك! تم إنشاء حسابك 🎉')
    } catch (err) {
      toast(errorMessage(err), 'err')
      const m = errorMessage(err)
      if (m.includes('رمز')) setStep(1)
      setBusy(false)
    }
  }

  const titles = [
    ['إنشاء حساب جديد', 'سجّل برقم هاتفك وسنرسل لك رمز تحقق عبر واتساب'],
    ['تأكيد رقم الهاتف', `أدخل الرمز المكوّن من 6 أرقام المرسل إلى واتساب على الرقم ${phone}`],
    ['اختر كلمة المرور', 'ستستخدمها مع رقم هاتفك لتسجيل الدخول لاحقاً'],
  ][step]

  return (
    <AuthLayout
      title={titles[0]}
      subtitle={titles[1]}
      steps={STEPS}
      step={step}
      footer={
        <>
          لديك حساب؟{' '}
          <Link href="/login/" className="font-bold text-brand-600">
            سجّل الدخول
          </Link>
        </>
      }
    >
      {step === 0 && (
        <form
          className="space-y-4"
          onSubmit={async (e) => {
            e.preventDefault()
            if (await otp.send(phone)) setStep(1)
          }}
        >
          <Field label="الاسم الكامل">
            <div className="relative">
              <User className="pointer-events-none absolute top-1/2 right-3.5 size-5 -translate-y-1/2 text-ink-3" />
              <input className="field pr-11" required autoComplete="name" placeholder="مثال: علي حسن" value={name} onChange={(e) => setName(e.target.value)} />
            </div>
          </Field>
          <Field label="رقم الواتساب" hint="سيكون هذا الرقم هو رقم التواصل لطلباتك">
            <PhoneInput value={phone} onChange={setPhone} />
          </Field>
          <Button type="submit" variant="sky" className="w-full !bg-[#25D366] hover:!bg-[#1ebe5b]" size="lg" loading={otp.sending}>
            <WhatsAppBadge /> إرسال الرمز عبر واتساب
          </Button>
          <ul className="grid grid-cols-3 gap-2 pt-2 text-center text-[0.7rem] font-semibold text-ink-2">
            <li className="rounded-xl bg-bg p-2.5">
              <Gift className="mx-auto mb-1 size-5 text-brand" /> {s.loyaltyPercent}% كاش باك
            </li>
            <li className="rounded-xl bg-bg p-2.5">
              <Sparkles className="mx-auto mb-1 size-5 text-sky" /> عروض حصرية
            </li>
            <li className="rounded-xl bg-bg p-2.5">
              <ShieldCheck className="mx-auto mb-1 size-5 text-ok" /> حساب آمن
            </li>
          </ul>
        </form>
      )}

      {step === 1 && (
        <form
          onSubmit={(e) => {
            e.preventDefault()
            if (code.length === 6) setStep(2)
          }}
        >
          <OtpInput value={code} onChange={setCode} />
          <DebugCode code={otp.debugCode} />
          <Button type="submit" className="mt-5 w-full" size="lg" disabled={code.length !== 6}>
            متابعة
          </Button>
          <ResendLine cooldown={otp.cooldown} sending={otp.sending} onResend={() => otp.send(phone)} />
          <button type="button" onClick={() => setStep(0)} className="mx-auto mt-2 flex items-center gap-1 text-sm font-semibold text-ink-3">
            <ArrowRight className="size-4" /> تعديل الرقم
          </button>
        </form>
      )}

      {step === 2 && (
        <form onSubmit={create} className="space-y-4">
          <Field label="كلمة المرور" hint="6 أحرف على الأقل">
            <PasswordInput value={password} onChange={setPassword} autoComplete="new-password" />
          </Field>
          <Field label="تأكيد كلمة المرور">
            <PasswordInput value={confirm} onChange={setConfirm} autoComplete="new-password" />
          </Field>
          <Button type="submit" className="w-full" size="lg" loading={busy}>
            إنشاء الحساب
          </Button>
          <p className="text-center text-xs leading-relaxed text-ink-3">بإنشاء الحساب أنت توافق على شروط الاستخدام وسياسة الخصوصية.</p>
        </form>
      )}
    </AuthLayout>
  )
}
