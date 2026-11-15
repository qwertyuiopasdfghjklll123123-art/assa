'use client'

import { useState, type ReactNode } from 'react'
import { useToast } from '@/components/providers'
import { Button, Field, PageTitle } from '@/components/ui'
import { isDemoMode } from '@/lib/backend'
import { money } from '@/lib/format'
import { useSettings } from '@/lib/hooks'
import type { Settings } from '@/lib/models'
import { errorMessage, saveSettings } from '@/lib/services'

export default function AdminSettingsPage() {
  const current = useSettings()
  const toast = useToast()
  const [draft, setDraft] = useState<Settings | null>(null)
  const [busy, setBusy] = useState(false)
  const s = draft ?? current
  const set = <K extends keyof Settings>(k: K, v: Settings[K]) => setDraft({ ...s, [k]: v })
  const text = (k: keyof Settings, props: Record<string, unknown> = {}) => (
    <input className="field" value={String(s[k] ?? '')} onChange={(e) => set(k, e.target.value as never)} {...props} />
  )
  const number = (k: keyof Settings) => (
    <input className="field num" type="number" min={0} value={Number(s[k] ?? 0)} onChange={(e) => set(k, Number(e.target.value) as never)} />
  )

  return (
    <form
      onSubmit={async (e) => {
        e.preventDefault()
        setBusy(true)
        try {
          await saveSettings(s)
          setDraft(null)
          toast('تم حفظ الإعدادات')
        } catch (err) {
          toast(errorMessage(err), 'err')
        } finally {
          setBusy(false)
        }
      }}
    >
      <PageTitle
        title="الإعدادات"
        subtitle={isDemoMode ? 'وضع تجريبي — البيانات في هذا المتصفح فقط' : 'متصل بالخادم — البيانات محفوظة في ملفات JSON'}
        action={
          <Button type="submit" loading={busy} disabled={!draft}>
            حفظ التغييرات
          </Button>
        }
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Section title="هوية المطعم">
          <Field label="اسم المطعم">{text('restaurantName', { required: true })}</Field>
          <Field label="الشعار النصي">{text('tagline')}</Field>
          <Field label="نبذة (صفحة حول التطبيق)">
            <textarea className="field min-h-28" value={s.about} onChange={(e) => set('about', e.target.value)} />
          </Field>
        </Section>

        <Section title="الطلبات والتوصيل">
          <div className="grid grid-cols-2 gap-3">
            <Field label="العملة">{text('currency')}</Field>
            <Field label="رسوم التوصيل">{number('deliveryFee')}</Field>
          </div>
          <Field label="الحد الأدنى للطلب">{number('minOrder')}</Field>
        </Section>

        <Section title="برنامج الولاء">
          <div className="grid grid-cols-3 gap-3">
            <Field label="نسبة الاسترجاع %">{number('loyaltyPercent')}</Field>
            <Field label="قيمة النقطة">{number('pointValue')}</Field>
            <Field label="هدف المكافأة">{number('rewardThreshold')}</Field>
          </div>
          <p className="rounded-xl bg-sky-50 p-3 text-xs leading-relaxed text-sky-800">
            مثال: طلب بقيمة {money(20000, s.currency)} يمنح العميل{' '}
            <b className="num">{s.pointValue ? Math.floor((20000 * s.loyaltyPercent) / 100 / s.pointValue) : 0}</b> نقطة، وكل نقطة تساوي{' '}
            <b className="num">{money(s.pointValue, s.currency)}</b> خصماً.
          </p>
          <Field label="عنوان بطاقة العرض">{text('promoTitle')}</Field>
          <Field label="نص بطاقة العرض">
            <textarea className="field min-h-20" value={s.promoText} onChange={(e) => set('promoText', e.target.value)} />
          </Field>
        </Section>

        <Section title="معلومات التواصل">
          <Field label="الهاتف">{text('phone', { dir: 'ltr' })}</Field>
          <Field label="واتساب (أرقام فقط مع رمز الدولة)">{text('whatsapp', { dir: 'ltr' })}</Field>
          <Field label="البريد الإلكتروني">{text('email', { dir: 'ltr' })}</Field>
          <Field label="إنستغرام (اسم الحساب)">{text('instagram', { dir: 'ltr' })}</Field>
          <Field label="العنوان">{text('address')}</Field>
        </Section>
      </div>
    </form>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="card space-y-3 p-4">
      <h2 className="font-bold">{title}</h2>
      {children}
    </section>
  )
}
