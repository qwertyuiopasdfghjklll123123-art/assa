export type Role = 'user' | 'admin'

export interface GeoPoint {
  lat: number
  lng: number
}

export interface UserProfile {
  id: string
  name: string
  email: string
  phone?: string
  address?: string
  location?: GeoPoint
  avatar?: string
  /** How the customer signs in. Missing on older / admin accounts (password). */
  loginMethod?: 'whatsapp' | 'google' | 'email'
  role: Role
  points: number
  totalOrders: number
  totalSpent: number
  createdAt: number
  deleted?: boolean
  blocked?: boolean
  notifSeenAt?: number
}

export interface Branch {
  id: string
  name: string
  address: string
  phone: string
  location?: GeoPoint | null
  hours?: string
  image?: string
  open: boolean
  order: number
}

export interface Category {
  id: string
  name: string
  icon?: string
  order: number
}

export interface Product {
  id: string
  name: string
  description: string
  price: number
  image?: string
  emoji?: string
  categoryId: string
  available: boolean
  featured?: boolean
  order: number
}

export type OrderStatus = 'pending' | 'confirmed' | 'preparing' | 'on_the_way' | 'delivered' | 'cancelled'

export const ORDER_STATUSES: OrderStatus[] = ['pending', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'cancelled']

export const STATUS_LABEL: Record<OrderStatus, string> = {
  pending: 'في الانتظار',
  confirmed: 'مؤكد',
  preparing: 'قيد التحضير',
  on_the_way: 'في الطريق',
  delivered: 'تم التسليم',
  cancelled: 'ملغي',
}

export interface OrderItem {
  productId: string
  name: string
  price: number
  qty: number
  image?: string
  emoji?: string
}

export interface Order {
  id: string
  userId: string
  userName: string
  userPhone: string
  branchId: string
  branchName: string
  items: OrderItem[]
  subtotal: number
  deliveryFee: number
  discount: number
  pointsUsed: number
  total: number
  type: 'delivery' | 'pickup'
  address: string
  location?: GeoPoint | null
  notes: string
  status: OrderStatus
  history: { status: OrderStatus; at: number }[]
  pointsEarned?: number
  pointsAwarded?: boolean
  pointsRefunded?: boolean
  createdAt: number
  updatedAt: number
}

export type NotifType = 'order' | 'offer' | 'discount' | 'new_item' | 'news' | 'alert'

/** Kinds of notifications the admin can send (orders are sent automatically). */
export const NOTIF_TYPES: Record<NotifType, { label: string; emoji: string; tone: string }> = {
  order: { label: 'الطلبات', emoji: '🧾', tone: 'bg-brand-50 text-brand-600' },
  offer: { label: 'العروض', emoji: '🎁', tone: 'bg-brand-50 text-brand-600' },
  discount: { label: 'الخصومات', emoji: '🏷️', tone: 'bg-red-50 text-red-600' },
  new_item: { label: 'أصناف جديدة', emoji: '🍽️', tone: 'bg-ok-50 text-emerald-700' },
  news: { label: 'أخبار المطعم', emoji: '📢', tone: 'bg-sky-50 text-sky-700' },
  alert: { label: 'تنبيه مهم', emoji: '⚠️', tone: 'bg-warn-50 text-amber-700' },
}

export interface AppNotification {
  id: string
  userId: string // a user id, or 'all' for a broadcast
  type?: NotifType
  title: string
  body: string
  orderId?: string
  /** Optional in-app page to open, e.g. /menu/ */
  link?: string
  createdAt: number
}

export interface Settings {
  restaurantName: string
  tagline: string
  currency: string
  deliveryFee: number
  minOrder: number
  loyaltyPercent: number // % of the order total returned as points value
  pointValue: number // currency value of a single point
  rewardThreshold: number // points milestone shown as the next reward
  phone: string
  whatsapp: string
  email: string
  address: string
  instagram: string
  about: string
  promoTitle: string
  promoText: string
}

export const DEFAULT_SETTINGS: Settings = {
  restaurantName: 'سُفرة',
  tagline: 'أطباق شرقية تُحضّر بحب',
  currency: 'IQD',
  deliveryFee: 2000,
  minOrder: 5000,
  loyaltyPercent: 5,
  pointValue: 100,
  rewardThreshold: 200,
  phone: '+964 750 000 0000',
  whatsapp: '9647500000000',
  email: 'hello@sufra.app',
  address: 'أربيل، شارع 100',
  instagram: 'sufra',
  about: 'سُفرة مطعم عائلي يقدّم أشهى الأطباق الشرقية والغربية بمكونات طازجة يومياً. اطلب من التطبيق واجمع نقاط الولاء مع كل طلب.',
  promoTitle: 'استرجع 5% كنقاط ولاء',
  promoText: 'مع كل طلب يُضاف جزء من قيمته إلى رصيد نقاطك لتستخدمه كخصم في طلباتك القادمة.',
}
