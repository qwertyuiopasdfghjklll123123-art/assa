'use client'

import { ImagePlus, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { errorMessage, uploadImage } from '@/lib/services'
import { useToast } from './providers'
import { Thumb } from './ui'

export function ImageInput({ value, onChange, folder, emoji }: { value?: string; onChange: (url: string) => void; folder: string; emoji?: string }) {
  const ref = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const toast = useToast()

  return (
    <div className="flex items-center gap-3">
      <Thumb image={value} emoji={emoji} alt="" className="size-20 shrink-0 rounded-xl border border-line" />
      <div className="flex-1 space-y-2">
        <div className="flex gap-2">
          <button
            type="button"
            disabled={busy}
            onClick={() => ref.current?.click()}
            className="flex h-9 items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-semibold text-ink-2 hover:border-sky hover:text-sky-700 disabled:opacity-60"
          >
            <ImagePlus className="size-4" /> {busy ? 'جارٍ الرفع…' : 'رفع صورة'}
          </button>
          {value && (
            <button type="button" onClick={() => onChange('')} className="grid size-9 place-items-center rounded-lg border border-line text-danger" aria-label="حذف الصورة">
              <Trash2 className="size-4" />
            </button>
          )}
        </div>
        <input
          className="field h-9 py-1 text-xs"
          dir="ltr"
          placeholder="أو الصق رابط صورة https://…"
          value={value?.startsWith('data:') ? '' : (value ?? '')}
          onChange={(e) => onChange(e.target.value)}
        />
      </div>
      <input
        ref={ref}
        type="file"
        accept="image/png,image/jpeg,image/webp"
        hidden
        onChange={async (e) => {
          const file = e.target.files?.[0]
          e.target.value = ''
          if (!file) return
          setBusy(true)
          try {
            onChange(await uploadImage(file, folder))
          } catch (err) {
            toast(errorMessage(err), 'err')
          } finally {
            setBusy(false)
          }
        }}
      />
    </div>
  )
}
