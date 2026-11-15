'use client'

import { useRouter } from 'next/navigation'
import { useEffect } from 'react'
import { useAuth } from '@/components/providers'

/** The bare domain sends signed-in customers to /home and everyone else to /login. */
export default function Root() {
  const { user, isAdmin, loading } = useAuth()
  const router = useRouter()
  useEffect(() => {
    if (loading) return
    router.replace(user ? (isAdmin ? '/admin/' : '/home/') : '/login/')
  }, [loading, user, isAdmin, router])
  return (
    <div className="grid min-h-dvh place-items-center">
      <span className="hero-grad size-14 animate-pulse rounded-2xl" />
    </div>
  )
}
