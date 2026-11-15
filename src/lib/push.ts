import { getBackend } from './backend'

export type PushState = 'unsupported' | 'default' | 'granted' | 'denied'

export function pushState(): PushState {
  if (typeof window === 'undefined' || !('Notification' in window) || !('serviceWorker' in navigator)) return 'unsupported'
  return Notification.permission
}

function keyToBytes(base64url: string) {
  const pad = '='.repeat((4 - (base64url.length % 4)) % 4)
  const raw = atob((base64url + pad).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(raw, (c) => c.charCodeAt(0))
}

/** Registers this device for push notifications (asks permission if needed). */
export async function enablePush(ask = true): Promise<PushState> {
  let state = pushState()
  if (state === 'unsupported' || state === 'denied') return state
  if (state === 'default') {
    if (!ask) return state
    state = await Notification.requestPermission()
    if (state !== 'granted') return state
  }
  const b = await getBackend()
  const key = await b.pushKey()
  if (!key || !('PushManager' in window)) return state // demo mode: permission only
  const reg = await navigator.serviceWorker.ready
  let sub = await reg.pushManager.getSubscription()
  if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(key) })
  await b.pushSubscribe(sub.toJSON())
  return state
}

export async function disablePush() {
  if (pushState() === 'unsupported') return
  const reg = await navigator.serviceWorker.getRegistration()
  const sub = await reg?.pushManager.getSubscription()
  if (!sub) return
  await (await getBackend()).pushUnsubscribe(sub.endpoint).catch(() => {})
  await sub.unsubscribe()
}
