'use client'

import { MessageCircle } from 'lucide-react'
import Link from 'next/link'
import { AuthLayout } from '@/components/auth-layout'
import { useSettings } from '@/lib/hooks'

export default function ForgotPage() {
  const s = useSettings()
  const text = encodeURIComponent('مرحباً، نسيت كلمة المرور لحسابي في التطبيق. بريدي الإلكتروني هو: ')
  return (
    <AuthLayout title="نسيت كلمة المرور؟" subtitle="تواصل معنا عبر واتساب وسنعيد تعيين كلمة المرور لحسابك فوراً">
      <a
        href={`https://wa.me/${s.whatsapp.replace(/\D/g, '')}?text=${text}`}
        target="_blank"
        rel="noreferrer"
        className="flex h-12 items-center justify-center gap-2 rounded-xl bg-ok font-bold text-white shadow-[0_6px_18px_rgba(16,185,129,0.3)] transition hover:opacity-90"
      >
        <MessageCircle className="size-5" /> تواصل عبر واتساب
      </a>
      <p className="mt-4 rounded-xl bg-sky-50 p-3 text-sm leading-relaxed text-sky-800">
        أرسل لنا البريد الإلكتروني المسجّل في حسابك، وسيرسل لك فريق المطعم كلمة مرور جديدة يمكنك تغييرها لاحقاً من صفحة «حسابي».
      </p>
      <p className="mt-6 text-center text-sm">
        <Link href="/login/" className="font-bold text-sky-600">
          العودة لتسجيل الدخول
        </Link>
      </p>
    </AuthLayout>
  )
}
