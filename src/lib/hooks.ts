'use client'

import { useEffect, useMemo, useState } from 'react'
import { getBackend, type Filter } from './backend'
import { DEFAULT_SETTINGS, type Settings } from './models'

export function useCollection<T extends { id: string }>(
  col: string | null,
  filter?: Filter,
  sort?: (a: T, b: T) => number,
) {
  const [items, setItems] = useState<T[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)
  const key = col ? col + JSON.stringify(filter ?? null) : null

  useEffect(() => {
    if (!key || !col) return
    let unsub: (() => void) | undefined
    let alive = true
    getBackend().then((b) => {
      if (!alive) return
      unsub = b.subscribe<T>(
        col,
        filter,
        (list) => {
          setItems(list)
          setLoading(false)
        },
        (e) => {
          setError(e)
          setLoading(false)
        },
      )
    })
    return () => {
      alive = false
      unsub?.()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  const sorted = useMemo(() => (sort ? [...items].sort(sort) : items), [items, sort])
  return { items: sorted, loading: key ? loading : false, error }
}

export function useDoc<T>(col: string, id: string | null) {
  const [item, setItem] = useState<T | null>(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    if (!id) return
    let unsub: (() => void) | undefined
    let alive = true
    getBackend().then((b) => {
      if (!alive) return
      unsub = b.subscribeDoc<T>(col, id, (d) => {
        setItem(d)
        setLoading(false)
      })
    })
    return () => {
      alive = false
      unsub?.()
    }
  }, [col, id])
  return { item: id ? item : null, loading: id ? loading : false }
}

export function useSettings(): Settings {
  const { item } = useDoc<Settings>('config', 'settings')
  return { ...DEFAULT_SETTINGS, ...(item ?? {}) }
}

export const byOrder = <T extends { order: number }>(a: T, b: T) => (a.order ?? 0) - (b.order ?? 0)
export const byNewest = <T extends { createdAt: number }>(a: T, b: T) => b.createdAt - a.createdAt
