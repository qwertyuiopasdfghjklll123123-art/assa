'use client'

import { Logo, PageTitle } from '@/components/ui'
import { useSettings } from '@/lib/hooks'

export default function AboutPage() {
  const s = useSettings()
  return (
    <div className="mx-auto max-w-2xl">
      <PageTitle title="حول التطبيق" />
      <div className="card p-6 text-center">
        <div className="flex justify-center">
          <Logo name={s.restaurantName} />
        </div>
        <p className="mt-2 text-sm font-semibold text-sky-600">{s.tagline}</p>
        <p className="mt-4 leading-loose whitespace-pre-line text-ink-2">{s.about}</p>
        <p className="num mt-6 text-xs text-ink-3">v1.0.0</p>
      </div>
    </div>
  )
}
