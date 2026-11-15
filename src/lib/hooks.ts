'use client'

import { useCallback, useMemo, useSyncExternalStore } from 'react'
import type { Filter } from './backend'
import { docKey, listKey, readKey, subscribeKey } from './store'
import { DEFAULT_SETTINGS, type Settings } from './models'

const EMPTY: never[] = []
const noopSub = () => () => {}

export function useCollection<T extends { id: string }>(
  col: string | null,
  filter?: Filter,
  sort?: (a: T, b: T) => number,
) {
  const key = col ? listKey(col, filter) : null
  const subscribe = useCallback((cb: () => void) => (key ? subscribeKey(key, cb) : () => {}), [key])
  const value = useSyncExternalStore(
    key ? subscribe : noopSub,
    () => (key ? (readKey(key) as T[] | undefined) : EMPTY),
    () => undefined,
  )
  const items = (value ?? EMPTY) as T[]
  const sorted = useMemo(() => (sort ? [...items].sort(sort) : items), [items, sort])
  return { items: sorted, loading: key ? value === undefined : false, error: null as Error | null }
}

export function useDoc<T>(col: string, id: string | null) {
  const key = id ? docKey(col, id) : null
  const subscribe = useCallback((cb: () => void) => (key ? subscribeKey(key, cb) : () => {}), [key])
  const value = useSyncExternalStore(
    key ? subscribe : noopSub,
    () => (key ? (readKey(key) as T | null | undefined) : null),
    () => undefined,
  )
  return { item: (value ?? null) as T | null, loading: key ? value === undefined : false }
}

export function useSettings(): Settings {
  const { item } = useDoc<Settings>('config', 'settings')
  return { ...DEFAULT_SETTINGS, ...(item ?? {}) }
}

export const byOrder = <T extends { order: number }>(a: T, b: T) => (a.order ?? 0) - (b.order ?? 0)
export const byNewest = <T extends { createdAt: number }>(a: T, b: T) => b.createdAt - a.createdAt
