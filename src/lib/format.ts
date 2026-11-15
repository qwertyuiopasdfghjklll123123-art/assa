export function money(n: number, currency = 'IQD') {
  return `${new Intl.NumberFormat('en-US').format(Math.round(n))} ${currency}`
}

export function num(n: number) {
  return new Intl.NumberFormat('en-US').format(Math.round(n))
}

export function dateTime(ts: number) {
  return new Intl.DateTimeFormat('ar-IQ-u-nu-latn', { dateStyle: 'medium', timeStyle: 'short' }).format(ts)
}

export function timeAgo(ts: number) {
  const s = Math.floor((Date.now() - ts) / 1000)
  if (s < 60) return 'الآن'
  const m = Math.floor(s / 60)
  if (m < 60) return `منذ ${m} د`
  const h = Math.floor(m / 60)
  if (h < 24) return `منذ ${h} س`
  const d = Math.floor(h / 24)
  if (d < 30) return `منذ ${d} يوم`
  return dateTime(ts)
}

export const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(' ')
