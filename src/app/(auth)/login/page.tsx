'use client'

import { Eye, EyeOff, MessageCircle } from 'lucide-react'
import Link from 'next/link'
import { Suspense, useState } from 'react'
import { AuthLayout, useAfterAuth } from '@/components/auth-layout'
import { useToast } from '@/components/providers'
import { Button, Field } from '@/components/ui'
import { getBackend } from '@/lib/backend'
import { useSettings } from '@/lib/hooks'
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
  const s = useSettings()
  const toast = useToast()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      await (await getBackend()).signIn(email, password)
    } catch (err) {
      toast(errorMessage(err), 'err')
      setBusy(false)
    }
  }

  return (
    <AuthLayout title="أهلاً بعودتك" subtitle="سجّل الدخول لمتابعة طلباتك ونقاطك">
      <div className="grid gap-2">
        <a
          href={`https://wa.me/${s.whatsapp.replace(/\D/g, '')}`}
          target="_blank"
          rel="noreferrer"
          className="flex h-11 items-center justify-center gap-2 rounded-xl bg-ok-50 font-semibold text-emerald-700 transition hover:bg-ok hover:text-white"
        >
          <MessageCircle className="size-5" /> اطلب عبر واتساب
        </a>
      </div>

      <div className="my-5 flex items-center gap-3 text-xs text-ink-3">
        <span className="h-px flex-1 bg-line" /> أو بالبريد الإلكتروني <span className="h-px flex-1 bg-line" />
      </div>

      <form onSubmit={submit} className="space-y-4">
        <Field label="البريد الإلكتروني">
          <input className="field" type="email" required autoComplete="email" dir="ltr" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="name@example.com" />
        </Field>
        <Field label="كلمة المرور">
          <div className="relative">
            <input
              className="field pl-11"
              type={show ? 'text' : 'password'}
              required
              autoComplete="current-password"
              dir="ltr"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
            <button type="button" onClick={() => setShow(!show)} className="absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" aria-label="إظهار كلمة المرور">
              {show ? <EyeOff className="size-5" /> : <Eye className="size-5" />}
            </button>
          </div>
        </Field>
        <div className="flex justify-end">
          <Link href="/forgot/" className="text-sm font-semibold text-sky-600">
            نسيت كلمة المرور؟
          </Link>
        </div>
        <Button type="submit" className="w-full" size="lg" loading={busy}>
          تسجيل الدخول
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-ink-2">
        ليس لديك حساب؟{' '}
        <Link href="/register/" className="font-bold text-brand-600">
          أنشئ حساباً جديداً
        </Link>
      </p>
      <p className="mt-2 text-center">
        <Link href="/menu/" className="text-sm text-ink-3 underline-offset-4 hover:underline">
          تصفح المنيو بدون تسجيل
        </Link>
      </p>
    </AuthLayout>
  )
}
