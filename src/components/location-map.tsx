'use client'

import 'leaflet/dist/leaflet.css'
import type { Map as LMap, Marker } from 'leaflet'
import { Crosshair, ExternalLink, Loader2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import type { GeoPoint } from '@/lib/models'
import { useToast } from './providers'

const DEFAULT_CENTER: GeoPoint = { lat: 36.1911, lng: 44.0092 } // Erbil

const PIN_HTML = `<svg width="34" height="44" viewBox="0 0 34 44" xmlns="http://www.w3.org/2000/svg">
  <path d="M17 43s15-14.6 15-26A15 15 0 0 0 2 17c0 11.4 15 26 15 26Z" fill="#f97316" stroke="#fff" stroke-width="2.5"/>
  <circle cx="17" cy="17" r="6" fill="#fff"/><circle cx="17" cy="17" r="3" fill="#0ea5e9"/></svg>`

export const mapsLink = (p: GeoPoint) => `https://www.google.com/maps?q=${p.lat},${p.lng}`

function useLeafletMap(
  ref: React.RefObject<HTMLDivElement | null>,
  value: GeoPoint | null | undefined,
  opts: { editable: boolean; onPick?: (p: GeoPoint) => void },
) {
  const mapRef = useRef<LMap | null>(null)
  const markerRef = useRef<Marker | null>(null)
  const pickRef = useRef(opts.onPick)
  useEffect(() => {
    pickRef.current = opts.onPick
  })
  const [ready, setReady] = useState(false)

  useEffect(() => {
    let alive = true
    import('leaflet').then((L) => {
      if (!alive || !ref.current || mapRef.current) return
      const start = value ?? DEFAULT_CENTER
      const map = L.map(ref.current, {
        center: [start.lat, start.lng],
        zoom: value ? 16 : 12,
        zoomControl: opts.editable,
        dragging: true,
        scrollWheelZoom: opts.editable,
        attributionControl: true,
      })
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap',
      }).addTo(map)
      const icon = L.divIcon({ html: PIN_HTML, className: '', iconSize: [34, 44], iconAnchor: [17, 43] })
      const place = (p: GeoPoint) => {
        if (markerRef.current) markerRef.current.setLatLng([p.lat, p.lng])
        else {
          markerRef.current = L.marker([p.lat, p.lng], { icon, draggable: opts.editable }).addTo(map)
          markerRef.current.on('dragend', () => {
            const ll = markerRef.current!.getLatLng()
            pickRef.current?.({ lat: ll.lat, lng: ll.lng })
          })
        }
      }
      if (value) place(value)
      if (opts.editable)
        map.on('click', (e) => {
          const p = { lat: e.latlng.lat, lng: e.latlng.lng }
          place(p)
          pickRef.current?.(p)
        })
      mapRef.current = map
      ;(map as LMap & { _place?: typeof place })._place = place
      setReady(true)
      setTimeout(() => map.invalidateSize(), 150)
    })
    return () => {
      alive = false
      mapRef.current?.remove()
      mapRef.current = null
      markerRef.current = null
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Keep the pin in sync when the value changes from outside (e.g. "my location").
  useEffect(() => {
    const map = mapRef.current as (LMap & { _place?: (p: GeoPoint) => void }) | null
    if (!map || !value) return
    map._place?.(value)
    map.setView([value.lat, value.lng], Math.max(map.getZoom(), 16))
  }, [value, ready])
}

export function LocationPicker({ value, onChange }: { value: GeoPoint | null; onChange: (p: GeoPoint) => void }) {
  const ref = useRef<HTMLDivElement>(null)
  const toast = useToast()
  const [locating, setLocating] = useState(false)
  useLeafletMap(ref, value, { editable: true, onPick: onChange })

  function locate() {
    if (!navigator.geolocation) return toast('المتصفح لا يدعم تحديد الموقع', 'err')
    setLocating(true)
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        onChange({ lat: pos.coords.latitude, lng: pos.coords.longitude })
        setLocating(false)
      },
      () => {
        toast('تعذّر تحديد موقعك، اسمح بالوصول للموقع أو حدده على الخريطة', 'err')
        setLocating(false)
      },
      { enableHighAccuracy: true, timeout: 12000 },
    )
  }

  return (
    <div className="relative isolate overflow-hidden rounded-2xl border border-line">
      <div className="relative">
        <div ref={ref} className="z-0 h-64 w-full bg-sky-50" />
        <button
          type="button"
          onClick={locate}
          className="absolute bottom-3 left-3 z-[400] flex h-10 items-center gap-2 rounded-xl bg-white px-3 text-sm font-bold text-sky-700 shadow-pop"
        >
          {locating ? <Loader2 className="size-4 animate-spin" /> : <Crosshair className="size-4" />}
          موقعي الحالي
        </button>
      </div>
      <p className="bg-bg px-3 py-2 text-xs text-ink-2">
        {value ? '✓ تم تحديد موقع البيت — يمكنك سحب الدبوس لتعديله' : 'اضغط على الخريطة لوضع دبوس على موقع بيتك'}
      </p>
    </div>
  )
}

export function LocationPreview({ value }: { value: GeoPoint }) {
  const ref = useRef<HTMLDivElement>(null)
  useLeafletMap(ref, value, { editable: false })
  return (
    <div className="relative isolate overflow-hidden rounded-2xl border border-line">
      <div ref={ref} className="z-0 h-44 w-full bg-sky-50" />
      <a
        href={mapsLink(value)}
        target="_blank"
        rel="noreferrer"
        className="flex items-center justify-center gap-2 bg-sky-50 py-2.5 text-sm font-bold text-sky-700 hover:bg-sky-100"
      >
        <ExternalLink className="size-4" /> فتح الموقع في خرائط جوجل
      </a>
    </div>
  )
}
