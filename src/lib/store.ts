'use client'

import { getBackend, type Filter } from './backend'

/*
 * Shared live-data store.
 *  - One backend subscription per (collection, filter) no matter how many components use it.
 *  - The last value is kept in memory, so going back to a page shows it instantly
 *    while fresh data loads in the background.
 *  - Public catalogue data (menu, branches, settings) is also saved in localStorage,
 *    so even a cold start of the app paints the menu immediately.
 *  - When the last component stops listening, the subscription stays alive for a while
 *    (quick back-and-forth navigation never re-fetches).
 */

const PERSIST_KEY = 'sufra.cache.v1'
const PERSISTED = new Set(['config', 'branches', 'categories', 'products'])
const KEEP_ALIVE_MS = 60_000

type Entry = {
  value: unknown
  listeners: Set<() => void>
  stop?: () => void
  starting?: boolean
  idleTimer?: ReturnType<typeof setTimeout>
}

const entries = new Map<string, Entry>()
let persisted: Record<string, unknown> | null = null

function loadPersisted() {
  if (persisted) return persisted
  try {
    persisted = JSON.parse(localStorage.getItem(PERSIST_KEY) || '{}')
  } catch {
    persisted = {}
  }
  return persisted!
}

function savePersisted(key: string, value: unknown) {
  const all = loadPersisted()
  all[key] = value
  try {
    localStorage.setItem(PERSIST_KEY, JSON.stringify(all))
  } catch {
    /* quota / private mode */
  }
}

function entry(key: string): Entry {
  let e = entries.get(key)
  if (!e) {
    const col = key.split('|')[0]
    e = { value: PERSISTED.has(col) ? loadPersisted()[key] : undefined, listeners: new Set() }
    entries.set(key, e)
  }
  return e
}

function set(key: string, value: unknown) {
  const e = entry(key)
  e.value = value
  if (PERSISTED.has(key.split('|')[0])) savePersisted(key, value)
  e.listeners.forEach((l) => l())
}

export const listKey = (col: string, filter?: Filter) => `${col}|list|${filter ? JSON.stringify(filter) : ''}`
export const docKey = (col: string, id: string) => `${col}|doc|${id}`

function start(key: string) {
  const e = entry(key)
  if (e.stop || e.starting) return
  e.starting = true
  const [col, kind, rest] = key.split('|')
  getBackend().then((b) => {
    e.starting = false
    if (e.stop) return
    e.stop =
      kind === 'list'
        ? b.subscribe(col, rest ? (JSON.parse(rest) as Filter) : undefined, (v) => set(key, v), () => set(key, e.value ?? []))
        : b.subscribeDoc(col, rest, (v) => set(key, v))
  })
}

/** For useSyncExternalStore: starts / keeps the live subscription for `key`. */
export function subscribeKey(key: string, onChange: () => void) {
  const e = entry(key)
  e.listeners.add(onChange)
  if (e.idleTimer) {
    clearTimeout(e.idleTimer)
    e.idleTimer = undefined
  }
  start(key)
  return () => {
    e.listeners.delete(onChange)
    if (e.listeners.size === 0) {
      e.idleTimer = setTimeout(() => {
        if (e.listeners.size) return
        e.stop?.()
        e.stop = undefined
      }, KEEP_ALIVE_MS)
    }
  }
}

export function readKey(key: string) {
  return entry(key).value
}

/** Warm the cache before the user opens a page (e.g. the menu on app start). */
export function prefetch(key: string) {
  const off = subscribeKey(key, () => {})
  setTimeout(off, 0)
}

/** Forget private data (orders, profile, notifications) — used on sign-out. */
export function clearPrivate() {
  for (const [key, e] of entries) {
    if (PERSISTED.has(key.split('|')[0])) continue
    e.stop?.()
    entries.delete(key)
  }
}
