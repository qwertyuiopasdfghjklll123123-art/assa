'use client'

import Link from 'next/link'
import { Suspense, useState } from 'react'
import { AuthLayout, useAfterAuth } from '@/components/auth-layout'
import { useToast } from '@/components/providers'
import { Button, Field } from '@/components/ui'
import { getBackend } from '@/lib/backend'
import { errorMessage } from '@/lib/services'

export default function RegisterPage() {
  return (
    <Suspense>
      <Register />
    </Suspense>
  )
}

function Register() {
  useAfterAuth()
  const toast = useToast()
  const [f, setF] = useState({ name: '', phone: '', email: '', password: '' })
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      await (await getBackend()).signUp(f.email, f.password, { name: f.name.trim(), phone: f.phone.trim() })
      toast('تم إنشاء حسابك 🎉')
    } catch (err) {
      toast(errorMessage(err), 'err')
      setBusy(false)
    }
  }

  return (
    <AuthLayout title="إنشاء حساب" subtitle="انضم وابدأ بجمع نقاط الولاء من أول طلب">
      <form onSubmit={submit} className="space-y-4">
        <Field label="الاسم الكامل">
          <input className="field" required autoComplete="name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
        </Field>
        <Field label="رقم الهاتف">
          <input className="field num text-right" required inputMode="tel" autoComplete="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} placeholder="07xx xxx xxxx" />
        </Field>
        <Field label="البريد الإلكتروني">
          <input className="field" type="email" required dir="ltr" autoComplete="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
        </Field>
        <Field label="كلمة المرور" hint="6 أحرف على الأقل">
          <input className="field" type="password" required minLength={6} dir="ltr" autoComplete="new-password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} />
        </Field>
        <Button type="submit" className="w-full" size="lg" loading={busy}>
          إنشاء الحساب
        </Button>
      </form>
      <p className="mt-6 text-center text-sm text-ink-2">
        لديك حساب؟{' '}
        <Link href="/login/" className="font-bold text-brand-600">
          سجّل الدخول
        </Link>
      </p>
    </AuthLayout>
  )
}
