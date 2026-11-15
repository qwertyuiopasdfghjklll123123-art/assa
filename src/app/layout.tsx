import type { Metadata, Viewport } from 'next'
import { IBM_Plex_Sans_Arabic, Tajawal } from 'next/font/google'
import { Providers } from '@/components/providers'
import './globals.css'

const plex = IBM_Plex_Sans_Arabic({ subsets: ['arabic', 'latin'], weight: ['400', '500', '600', '700'], variable: '--font-plex' })
const tajawal = Tajawal({ subsets: ['arabic', 'latin'], weight: ['700', '800', '900'], variable: '--font-tajawal' })

export const metadata: Metadata = {
  title: 'سُفرة — اطلب أشهى الأطباق',
  description: 'اطلب من المطعم مباشرة واجمع نقاط الولاء مع كل طلب.',
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
  themeColor: '#f97316',
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ar" dir="rtl" className={`${plex.variable} ${tajawal.variable}`}>
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  )
}
