'use client'

import { useEffect, useState } from 'react'
import { getBackend } from '@/lib/backend'
import { errorMessage } from '@/lib/services'
import { useToast } from './providers'

/** Sends / re-sends a WhatsApp code with a 60-second cooldown. */
export function useOtp(purpose: 'signup' | 'reset') {
  const toast = useToast()
  const [sending, setSending] = useState(false)
  const [cooldown, setCooldown] = useState(0)
  const [debugCode, setDebugCode] = useState<string | null>(null)

  useEffect(() => {
    if (cooldown <= 0) return
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000)
    return () => clearTimeout(t)
  }, [cooldown])

  async function send(phone: string) {
    setSending(true)
    try {
      const r = await (await getBackend()).sendOtp(phone, purpose)
      setDebugCode(r.debugCode ?? null)
      setCooldown(60)
      toast('تم إرسال رمز التحقق إلى واتساب')
      return true
    } catch (e) {
      toast(errorMessage(e), 'err')
      return false
    } finally {
      setSending(false)
    }
  }

  return { send, sending, cooldown, debugCode }
}

export function ResendLine({ cooldown, sending, onResend }: { cooldown: number; sending: boolean; onResend: () => void }) {
  return (
    <p className="mt-4 text-center text-sm text-ink-2">
      لم يصلك الرمز؟{' '}
      {cooldown > 0 ? (
        <span className="num font-semibold text-ink-3">إعادة الإرسال بعد {cooldown} ث</span>
      ) : (
        <button type="button" disabled={sending} onClick={onResend} className="font-bold text-sky-600 disabled:opacity-50">
          إعادة الإرسال
        </button>
      )}
    </p>
  )
}

export function DebugCode({ code }: { code: string | null }) {
  if (!code) return null
  return (
    <p className="mt-3 rounded-xl border border-dashed border-amber-300 bg-warn-50 p-2.5 text-center text-xs font-semibold text-amber-800">
      وضع الاختبار (واتساب غير مربوط بعد) — الرمز: <b className="num text-base tracking-widest">{code}</b>
    </p>
  )
}
