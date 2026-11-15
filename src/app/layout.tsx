import type { Metadata, Viewport } from 'next'
import { IBM_Plex_Sans_Arabic, Tajawal } from 'next/font/google'
import { Providers } from '@/components/providers'
import { PwaGate } from '@/components/pwa-gate'
import './globals.css'

const plex = IBM_Plex_Sans_Arabic({ subsets: ['arabic', 'latin'], weight: ['400', '500', '600', '700'], variable: '--font-plex' })
const tajawal = Tajawal({ subsets: ['arabic', 'latin'], weight: ['700', '800', '900'], variable: '--font-tajawal' })

const BASE = (process.env.NEXT_PUBLIC_BASE_PATH ?? '').replace(/\/$/, '')

export const metadata: Metadata = {
  title: 'سُفرة — اطلب أشهى الأطباق',
  description: 'اطلب من المطعم مباشرة واجمع نقاط الولاء مع كل طلب.',
  manifest: `${BASE}/manifest.webmanifest`,
  applicationName: 'سُفرة',
  appleWebApp: { capable: true, title: 'سُفرة', statusBarStyle: 'default' },
  icons: {
    icon: `${BASE}/icons/icon-192.png`,
    apple: `${BASE}/icons/apple-touch-icon.png`,
  },
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
  themeColor: '#f97316',
}

// Chrome fires `beforeinstallprompt` very early; keep it until the install screen mounts.
const CAPTURE_INSTALL = `window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();window.__installPrompt=e;});`

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ar" dir="rtl" className={`${plex.variable} ${tajawal.variable}`}>
      <head>
        <script dangerouslySetInnerHTML={{ __html: CAPTURE_INSTALL }} />
      </head>
      <body>
        <Providers>
          <PwaGate>{children}</PwaGate>
        </Providers>
      </body>
    </html>
  )
}
