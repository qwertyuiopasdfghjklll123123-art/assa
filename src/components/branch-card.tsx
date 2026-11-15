'use client'

import { Clock, MapPin, Navigation, Phone } from 'lucide-react'
import { cx } from '@/lib/format'
import type { Branch } from '@/lib/models'
import { Thumb } from './ui'

/** Google Maps directions to the branch (pin if set, otherwise its written address). */
export function directionsLink(b: Pick<Branch, 'location' | 'address' | 'name'>) {
  return b.location
    ? `https://www.google.com/maps/dir/?api=1&destination=${b.location.lat},${b.location.lng}`
    : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${b.name} ${b.address}`)}`
}

export function BranchCard({ branch, selected, onSelect }: { branch: Branch; selected?: boolean; onSelect?: () => void }) {
  return (
    <div className={cx('card overflow-hidden transition', selected ? 'ring-2 ring-brand' : '')}>
      <button type="button" onClick={onSelect} className="block w-full text-right" disabled={!onSelect}>
        <div className="relative">
          <Thumb image={branch.image} emoji="🏪" alt={branch.name} className="h-32" />
          <span
            className={cx(
              'absolute top-3 right-3 flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold shadow',
              branch.open ? 'bg-white text-emerald-700' : 'bg-white text-red-600',
            )}
          >
            <span className={cx('size-2 rounded-full', branch.open ? 'bg-ok' : 'bg-danger')} />
            {branch.open ? 'مفتوح الآن' : 'مغلق'}
          </span>
          {selected && <span className="absolute top-3 left-3 rounded-full bg-brand px-2.5 py-1 text-xs font-bold text-white shadow">فرعك الحالي</span>}
        </div>
        <div className="space-y-1.5 p-4 pb-3">
          <h3 className="font-display text-lg font-extrabold">{branch.name}</h3>
          <p className="flex items-start gap-1.5 text-sm text-ink-2">
            <MapPin className="mt-0.5 size-4 shrink-0 text-sky" /> {branch.address}
          </p>
          {branch.hours && (
            <p className="flex items-center gap-1.5 text-sm text-ink-2">
              <Clock className="size-4 shrink-0 text-sky" /> {branch.hours}
            </p>
          )}
        </div>
      </button>
      <div className="grid grid-cols-2 gap-2 px-4 pb-4">
        <a
          href={directionsLink(branch)}
          target="_blank"
          rel="noreferrer"
          className="flex h-10 items-center justify-center gap-1.5 rounded-xl bg-sky text-sm font-bold text-white transition hover:bg-sky-600"
        >
          <Navigation className="size-4" /> الاتجاهات
        </a>
        <a
          href={`tel:${branch.phone.replace(/\s/g, '')}`}
          className="flex h-10 items-center justify-center gap-1.5 rounded-xl border border-line text-sm font-bold text-ink transition hover:border-sky hover:text-sky-700"
        >
          <Phone className="size-4" /> اتصال
        </a>
      </div>
    </div>
  )
}
