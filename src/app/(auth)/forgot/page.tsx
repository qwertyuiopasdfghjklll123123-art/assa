'use client'

import Link from 'next/link'
import { useState } from 'react'
import { AuthLayout } from '@/components/auth-layout'
import { useToast } from '@/components/providers'
import { Button, Field } from '@/components/ui'
import { getBackend } from '@/lib/backend'
import { errorMessage } from '@/lib/services'

export default function ForgotPage() {
  const toast = useToast()
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [sent, setSent] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      await (await getBackend()).resetPassword(email)
      setSent(true)
    } catch (err) {
      toast(errorMessage(err), 'err')
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout title="استعادة كلمة المرور" subtitle="أدخل بريدك وسنرسل لك رابطاً لتعيين كلمة مرور جديدة">
      {sent ? (
        <div className="rounded-2xl border border-emerald-200 bg-ok-50 p-4 text-sm font-semibold text-emerald-800">
          تم إرسال رابط الاستعادة إلى بريدك الإلكتروني. تحقق من صندوق الوارد.
        </div>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          <Field label="البريد الإلكتروني">
            <input className="field" type="email" required dir="ltr" value={email} onChange={(e) => setEmail(e.target.value)} />
          </Field>
          <Button type="submit" className="w-full" size="lg" loading={busy}>
            إرسال الرابط
          </Button>
        </form>
      )}
      <p className="mt-6 text-center text-sm">
        <Link href="/login/" className="font-bold text-sky-600">
          العودة لتسجيل الدخول
        </Link>
      </p>
    </AuthLayout>
  )
}
