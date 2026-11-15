'use client'

import { ArrowRight } from 'lucide-react'
import Link from 'next/link'
import { Suspense, useState } from 'react'
import { AuthLayout, OtpInput, PasswordInput, PhoneInput, WhatsAppBadge, useAfterAuth } from '@/components/auth-layout'
import { DebugCode, ResendLine, useOtp } from '@/components/otp-flow'
import { useToast } from '@/components/providers'
import { Button, Field } from '@/components/ui'
import { getBackend } from '@/lib/backend'
import { errorMessage } from '@/lib/services'

export default function ForgotPage() {
  return (
    <Suspense>
      <Forgot />
    </Suspense>
  )
}

function Forgot() {
  useAfterAuth()
  const toast = useToast()
  const otp = useOtp('reset')
  const [step, setStep] = useState(0)
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)

  return (
    <AuthLayout
      title={['نسيت كلمة المرور؟', 'أدخل رمز التحقق', 'كلمة مرور جديدة'][step]}
      subtitle={
        [
          'أدخل رقم هاتفك المسجّل وسنرسل لك رمزاً عبر واتساب',
          `أرسلنا رمزاً من 6 أرقام إلى واتساب على الرقم ${phone}`,
          'اختر كلمة مرور جديدة لحسابك',
        ][step]
      }
      steps={['رقم الهاتف', 'الرمز', 'كلمة المرور']}
      step={step}
      footer={
        <Link href="/login/" className="font-bold text-sky-600">
          العودة لتسجيل الدخول
        </Link>
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
          <Field label="رقم الهاتف">
            <PhoneInput value={phone} onChange={setPhone} autoFocus />
          </Field>
          <Button type="submit" className="w-full !bg-[#25D366] hover:!bg-[#1ebe5b]" size="lg" loading={otp.sending}>
            <WhatsAppBadge /> إرسال الرمز عبر واتساب
          </Button>
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
        <form
          className="space-y-4"
          onSubmit={async (e) => {
            e.preventDefault()
            setBusy(true)
            try {
              await (await getBackend()).resetPassword({ phone, code, password })
              toast('تم تغيير كلمة المرور وتسجيل دخولك')
            } catch (err) {
              const m = errorMessage(err)
              toast(m, 'err')
              if (m.includes('رمز')) setStep(1)
              setBusy(false)
            }
          }}
        >
          <Field label="كلمة المرور الجديدة" hint="6 أحرف على الأقل">
            <PasswordInput value={password} onChange={setPassword} autoComplete="new-password" />
          </Field>
          <Button type="submit" className="w-full" size="lg" loading={busy}>
            حفظ وتسجيل الدخول
          </Button>
        </form>
      )}
    </AuthLayout>
  )
}
