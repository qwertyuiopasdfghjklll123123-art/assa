'use client'

import { Instagram, Mail, MapPin, MessageCircle, Phone } from 'lucide-react'
import { BranchCard } from '@/components/branch-card'
import { useBranch } from '@/components/providers'
import { PageTitle } from '@/components/ui'
import { useSettings } from '@/lib/hooks'

export default function ContactPage() {
  const s = useSettings()
  const { branches } = useBranch()
  const items = [
    { icon: MessageCircle, label: 'واتساب', value: s.whatsapp, href: `https://wa.me/${s.whatsapp.replace(/\D/g, '')}`, tone: 'bg-ok-50 text-ok' },
    { icon: Phone, label: 'اتصال هاتفي', value: s.phone, href: `tel:${s.phone.replace(/\s/g, '')}`, tone: 'bg-sky-50 text-sky' },
    { icon: Mail, label: 'البريد الإلكتروني', value: s.email, href: `mailto:${s.email}`, tone: 'bg-brand-50 text-brand' },
    { icon: Instagram, label: 'إنستغرام', value: '@' + s.instagram, href: `https://instagram.com/${s.instagram}`, tone: 'bg-brand-50 text-brand' },
  ].filter((i) => i.value && i.value !== '@')

  return (
    <div className="mx-auto max-w-2xl">
      <PageTitle title="اتصل بنا" subtitle="يسعدنا سماع رأيك أو مساعدتك في طلبك" />
      <div className="grid gap-3 sm:grid-cols-2">
        {items.map(({ icon: Icon, label, value, href, tone }) => (
          <a key={label} href={href} target="_blank" rel="noreferrer" className="card flex items-center gap-3 p-4 transition hover:border-sky">
            <span className={`grid size-11 place-items-center rounded-xl ${tone}`}>
              <Icon className="size-5" />
            </span>
            <span>
              <span className="block text-xs text-ink-3">{label}</span>
              <span className="num block font-semibold">{value}</span>
            </span>
          </a>
        ))}
      </div>
      <div className="card mt-3 flex items-center gap-3 p-4">
        <span className="grid size-11 place-items-center rounded-xl bg-sky-50 text-sky">
          <MapPin className="size-5" />
        </span>
        <span>
          <span className="block text-xs text-ink-3">العنوان</span>
          <span className="block font-semibold">{s.address}</span>
        </span>
      </div>
      <h2 className="mt-6 mb-3 font-display text-lg font-extrabold">فروعنا على الخريطة</h2>
      <div className="grid gap-3 sm:grid-cols-2">
        {branches.map((b) => (
          <BranchCard key={b.id} branch={b} />
        ))}
      </div>
    </div>
  )
}
