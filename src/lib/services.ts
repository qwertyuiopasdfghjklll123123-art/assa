import { getBackend } from './backend'
import { STATUS_LABEL, type Branch, type NotifType, type Order, type OrderItem, type OrderStatus, type Settings, type UserProfile } from './models'

const strip = <T extends { id?: string }>(o: T) => {
  const { id: _id, ...rest } = o
  return rest as Record<string, unknown>
}

/* ---------------------------------------------------------------- profiles */

export async function ensureProfile(uid: string, data: { name?: string; email: string; phone?: string }) {
  const b = await getBackend()
  const existing = await b.get<UserProfile>('users', uid)
  if (existing) return existing
  const profile: Omit<UserProfile, 'id'> = {
    name: data.name || data.email.split('@')[0],
    email: data.email,
    phone: data.phone ?? '',
    role: 'user',
    points: 0,
    totalOrders: 0,
    totalSpent: 0,
    createdAt: Date.now(),
  }
  await b.set('users', uid, profile)
  return { ...profile, id: uid }
}

export async function updateMyProfile(uid: string, data: Partial<Pick<UserProfile, 'name' | 'phone' | 'address' | 'notifSeenAt' | 'location'>>) {
  await (await getBackend()).set('users', uid, data)
}

export async function deleteMyAccount(uid: string) {
  const b = await getBackend()
  // Orders and loyalty history are kept; the profile is only deactivated.
  if (!b.serverSide) await b.set('users', uid, { deleted: true, deletedAt: Date.now() })
  await b.deleteAuthUser()
  await b.signOut()
}

/* ---------------------------------------------------------------- loyalty */

export function pointsForTotal(total: number, s: Settings) {
  if (!s.pointValue) return 0
  return Math.floor((total * s.loyaltyPercent) / 100 / s.pointValue)
}

/* ---------------------------------------------------------------- orders */

export interface PlaceOrderInput {
  user: UserProfile
  branch: Branch
  items: OrderItem[]
  settings: Settings
  type: 'delivery' | 'pickup'
  address: string
  phone: string
  notes: string
  usePoints: boolean
  location: { lat: number; lng: number } | null
}

export function quote(items: OrderItem[], s: Settings, type: 'delivery' | 'pickup', availablePoints: number, usePoints: boolean) {
  const subtotal = items.reduce((t, i) => t + i.price * i.qty, 0)
  const deliveryFee = type === 'delivery' && items.length ? s.deliveryFee : 0
  const maxPoints = s.pointValue ? Math.floor((subtotal + deliveryFee) / s.pointValue) : 0
  const pointsUsed = usePoints ? Math.min(availablePoints, maxPoints) : 0
  const discount = pointsUsed * s.pointValue
  const total = Math.max(0, subtotal + deliveryFee - discount)
  return { subtotal, deliveryFee, pointsUsed, discount, total }
}

export async function placeOrder(input: PlaceOrderInput) {
  const b = await getBackend()
  const { user, branch, items, settings } = input
  const q = quote(items, settings, input.type, user.points, input.usePoints)
  if (q.subtotal < settings.minOrder) throw new Error('min-order')
  const now = Date.now()
  const order: Omit<Order, 'id'> = {
    userId: user.id,
    userName: user.name,
    userPhone: input.phone,
    branchId: branch.id,
    branchName: branch.name,
    items,
    ...q,
    type: input.type,
    address: input.type === 'delivery' ? input.address : '',
    notes: input.notes,
    location: input.type === 'delivery' ? input.location : null,
    status: 'pending',
    history: [{ status: 'pending', at: now }],
    pointsEarned: pointsForTotal(q.total, settings),
    createdAt: now,
    updatedAt: now,
  }
  const id = await b.add('orders', order)
  // The PHP server recalculates the order and updates the profile itself.
  if (!b.serverSide) {
    const profilePatch: Record<string, unknown> = { phone: input.phone }
    if (input.type === 'delivery') {
      profilePatch.address = input.address
      if (input.location) profilePatch.location = input.location
    }
    if (q.pointsUsed) profilePatch.points = user.points - q.pointsUsed
    await b.set('users', user.id, profilePatch)
  }
  return id
}

export async function cancelMyOrder(order: Order) {
  // Orders paid partly with points are cancelled by the restaurant, which refunds the points.
  if (order.status !== 'pending' || order.pointsUsed) throw new Error('not-cancellable')
  await setOrderStatus(order, 'cancelled', { byCustomer: true })
}

const STATUS_MESSAGE: Record<OrderStatus, string> = {
  pending: 'استلمنا طلبك وهو بانتظار التأكيد.',
  confirmed: 'تم تأكيد طلبك وسيبدأ تحضيره قريباً.',
  preparing: 'طلبك قيد التحضير الآن في المطبخ 👨‍🍳',
  on_the_way: 'طلبك في الطريق إليك 🛵',
  delivered: 'تم تسليم طلبك. بالعافية!',
  cancelled: 'تم إلغاء طلبك.',
}

/**
 * Changes an order's status. When delivered, loyalty points are credited once;
 * when cancelled, any points the customer redeemed are refunded once.
 */
export async function setOrderStatus(order: Order, status: OrderStatus, opts: { byCustomer?: boolean } = {}) {
  const b = await getBackend()
  const now = Date.now()
  const patch: Record<string, unknown> = {
    status,
    updatedAt: now,
    history: [...(order.history ?? []), { status, at: now }],
  }

  if (!opts.byCustomer) {
    const user = await b.get<UserProfile>('users', order.userId)
    if (user) {
      if (status === 'delivered' && !order.pointsAwarded) {
        await b.set('users', user.id, {
          points: (user.points ?? 0) + (order.pointsEarned ?? 0),
          totalOrders: (user.totalOrders ?? 0) + 1,
          totalSpent: (user.totalSpent ?? 0) + order.total,
        })
        patch.pointsAwarded = true
      }
      if (status === 'cancelled' && order.pointsUsed && !order.pointsRefunded) {
        await b.set('users', user.id, { points: (user.points ?? 0) + order.pointsUsed })
        patch.pointsRefunded = true
      }
    }
  }

  await b.set('orders', order.id, patch)

  if (!opts.byCustomer) {
    let body = STATUS_MESSAGE[status]
    if (status === 'delivered' && order.pointsEarned) body += ` أُضيفت ${order.pointsEarned} نقطة إلى رصيدك.`
    await b.add('notifications', {
      userId: order.userId,
      type: 'order',
      orderId: order.id,
      title: `طلب #${orderCode(order.id)} — ${STATUS_LABEL[status]}`,
      body,
      createdAt: now,
    })
  }
}

export const orderCode = (id: string) => id.slice(-5).toUpperCase()

/* ---------------------------------------------------------------- admin CRUD */

export async function saveDoc<T extends { id?: string }>(col: string, item: T) {
  const b = await getBackend()
  if (item.id) {
    await b.set(col, item.id, strip(item))
    return item.id
  }
  return b.add(col, strip(item))
}

export async function removeDoc(col: string, id: string) {
  await (await getBackend()).remove(col, id)
}

export async function saveSettings(s: Settings) {
  await (await getBackend()).set('config', 'settings', { ...s })
}

export async function sendNotification(userId: string, title: string, body: string, type: NotifType = 'news', link = '') {
  await (await getBackend()).add('notifications', { userId, type, title, body, link, createdAt: Date.now() })
}

export async function updateUserAdmin(id: string, patch: Partial<Pick<UserProfile, 'points' | 'role' | 'blocked' | 'name' | 'phone'>>) {
  await (await getBackend()).set('users', id, patch)
}

export async function uploadImage(file: File, folder: string) {
  return (await getBackend()).uploadImage(file, folder)
}

/* ---------------------------------------------------------------- errors */

export function errorMessage(e: unknown) {
  const code = e instanceof Error ? (e as Error & { code?: string }).code || e.message : String(e)
  const map: [string, string][] = [
    ['invalid-credential', 'رقم الهاتف أو كلمة المرور غير صحيحة'],
    ['invalid-phone', 'رقم الهاتف غير صحيح'],
    ['phone-already-in-use', 'هذا الرقم مسجّل مسبقاً، سجّل الدخول بدلاً من ذلك'],
    ['phone-not-found', 'لا يوجد حساب بهذا الرقم'],
    ['name-required', 'أدخل اسمك'],
    ['otp/invalid', 'رمز التحقق غير صحيح'],
    ['otp/expired', 'انتهت صلاحية الرمز، اطلب رمزاً جديداً'],
    ['otp/too-many', 'محاولات كثيرة، اطلب رمزاً جديداً'],
    ['otp/too-soon', 'انتظر دقيقة قبل طلب رمز جديد'],
    ['otp/not-configured', 'إرسال رموز واتساب غير مفعّل بعد، تواصل مع المطعم'],
    ['otp/send-failed', 'تعذّر إرسال الرمز عبر واتساب، حاول لاحقاً'],
    ['wrong-password', 'كلمة المرور الحالية غير صحيحة'],
    ['email-already-in-use', 'هذا البريد مسجّل مسبقاً'],
    ['weak-password', 'كلمة المرور يجب أن تكون 6 أحرف على الأقل'],
    ['invalid-email', 'البريد الإلكتروني غير صالح'],
    ['user-blocked', 'تم إيقاف هذا الحساب، تواصل مع المطعم'],
    ['permission', 'ليست لديك صلاحية لتنفيذ هذا الإجراء'],
    ['unauthenticated', 'انتهت الجلسة، سجّل الدخول من جديد'],
    ['not-cancellable', 'لا يمكن إلغاء هذا الطلب، تواصل مع المطعم'],
    ['min-order', 'لم يصل الطلب إلى الحد الأدنى'],
    ['branch-closed', 'هذا الفرع مغلق حالياً'],
    ['product-unavailable', 'أحد الأصناف في السلة لم يعد متوفراً'],
    ['empty-order', 'السلة فارغة'],
    ['phone-required', 'أدخل رقم الهاتف'],
    ['address-required', 'أدخل عنوان التوصيل'],
    ['bad-image', 'الصورة يجب أن تكون JPG أو PNG أو WEBP'],
    ['file-too-large', 'حجم الصورة كبير جداً (الحد 5MB)'],
    ['Failed to fetch', 'تعذّر الاتصال بالخادم، تحقق من الإنترنت'],
  ]
  for (const [k, msg] of map) if (code.includes(k)) return msg
  return 'حدث خطأ غير متوقع، حاول مرة أخرى'
}
