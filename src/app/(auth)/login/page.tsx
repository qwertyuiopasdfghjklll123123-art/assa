'use client'

import { LogIn } from 'lucide-react'
import Link from 'next/link'
import { Suspense, useState } from 'react'
import { AuthLayout, PasswordInput, PhoneInput, useAfterAuth } from '@/components/auth-layout'
import { useToast } from '@/components/providers'
import { Button, Field } from '@/components/ui'
import { getBackend } from '@/lib/backend'
import { errorMessage } from '@/lib/services'

export default function LoginPage() {
  return (
    <Suspense>
      <Login />
    </Suspense>
  )
}

function Login() {
  useAfterAuth()
  const toast = useToast()
  const [phone, setPhone] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      await (await getBackend()).signIn(phone, password)
    } catch (err) {
      toast(errorMessage(err), 'err')
      setBusy(false)
    }
  }

  return (
    <AuthLayout
      title="أهلاً بعودتك 👋"
      subtitle="سجّل الدخول برقم هاتفك لمتابعة طلباتك ونقاطك"
      footer={
        <>
          ليس لديك حساب؟{' '}
          <Link href="/register/" className="font-bold text-brand-600">
            أنشئ حساباً جديداً
          </Link>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        <Field label="رقم الهاتف">
          <PhoneInput value={phone} onChange={setPhone} />
        </Field>
        <Field label="كلمة المرور">
          <PasswordInput value={password} onChange={setPassword} />
        </Field>
        <div className="flex justify-end">
          <Link href="/forgot/" className="text-sm font-semibold text-sky-600">
            نسيت كلمة المرور؟
          </Link>
        </div>
        <Button type="submit" className="w-full" size="lg" loading={busy}>
          <LogIn className="size-5" /> تسجيل الدخول
        </Button>
      </form>
      <div className="mt-5 border-t border-line pt-4 text-center">
        <Link href="/menu/" className="text-sm font-semibold text-ink-3 hover:text-ink">
          تصفح المنيو بدون تسجيل ←
        </Link>
      </div>
    </AuthLayout>
  )
}
