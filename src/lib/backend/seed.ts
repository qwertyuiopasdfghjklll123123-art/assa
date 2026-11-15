import { DEFAULT_SETTINGS } from '../models'

const now = Date.now()

export const SEED: Record<string, Record<string, Record<string, unknown>>> = {
  config: { settings: { ...DEFAULT_SETTINGS } },
  branches: {
    erbil: { name: 'فرع أربيل', address: 'أربيل - شارع 100، قرب دوار الإسكان', phone: '+964 750 000 0001', open: true, order: 1 },
    baghdad: { name: 'فرع بغداد', address: 'بغداد - المنصور، شارع 14 رمضان', phone: '+964 770 000 0002', open: true, order: 2 },
  },
  categories: {
    offers: { name: 'عروض التوصيل', icon: '🔥', order: 1 },
    grills: { name: 'مشاوي', icon: '🍢', order: 2 },
    burgers: { name: 'برغر', icon: '🍔', order: 3 },
    salads: { name: 'سلطات', icon: '🥗', order: 4 },
    soups: { name: 'شوربة', icon: '🍲', order: 5 },
    drinks: { name: 'مشروبات', icon: '🥤', order: 6 },
  },
  products: {
    p1: { name: 'عرض الصفيحة', description: '8 قطع صفيحة مع سلطة ولبن ومشروب غازي', price: 13000, emoji: '🫓', categoryId: 'offers', available: true, featured: true, order: 1 },
    p2: { name: 'عرض البرغر', description: 'قطعتا برغر دجاج مع بطاطا وكول سلو ومشروب', price: 10000, emoji: '🍔', categoryId: 'offers', available: true, featured: true, order: 2 },
    p3: { name: 'عرض الريزو', description: 'أرز بالخضار مع قطع الدجاج المقرمشة والبطاطا', price: 10000, emoji: '🍛', categoryId: 'offers', available: true, order: 3 },
    p4: { name: 'عرض المندي', description: 'نصف دجاجة مندي مع أرز وشوربة وسلطة', price: 12000, emoji: '🍗', categoryId: 'offers', available: false, order: 4 },
    p5: { name: 'كباب لحم', description: 'سيخان كباب لحم مع خبز وخضار مشوية', price: 9000, emoji: '🍢', categoryId: 'grills', available: true, featured: true, order: 1 },
    p6: { name: 'تكة دجاج', description: 'قطع دجاج متبلة مشوية على الفحم', price: 8000, emoji: '🍗', categoryId: 'grills', available: true, order: 2 },
    p7: { name: 'مشكل مشاوي', description: 'كباب وتكة وأوصال لشخصين', price: 22000, emoji: '🥩', categoryId: 'grills', available: true, order: 3 },
    p8: { name: 'برغر لحم كلاسيك', description: 'لحم بقري، جبنة شيدر، خس وطماطم', price: 7000, emoji: '🍔', categoryId: 'burgers', available: true, order: 1 },
    p9: { name: 'برغر دجاج كرسبي', description: 'صدر دجاج مقرمش مع صوص خاص', price: 6500, emoji: '🍔', categoryId: 'burgers', available: true, order: 2 },
    p10: { name: 'فتوش', description: 'خضار طازجة مع خبز محمص ودبس رمان', price: 4000, emoji: '🥗', categoryId: 'salads', available: true, order: 1 },
    p11: { name: 'تبولة', description: 'بقدونس، برغل، طماطم وليمون', price: 4000, emoji: '🥗', categoryId: 'salads', available: true, order: 2 },
    p12: { name: 'شوربة عدس', description: 'شوربة عدس دافئة مع الليمون والخبز المحمص', price: 3000, emoji: '🍲', categoryId: 'soups', available: true, order: 1 },
    p13: { name: 'عصير برتقال طازج', description: 'برتقال معصور يومياً', price: 3000, emoji: '🍊', categoryId: 'drinks', available: true, order: 1 },
    p14: { name: 'لبن عيران', description: 'لبن بارد منعش', price: 1000, emoji: '🥛', categoryId: 'drinks', available: true, order: 2 },
  },
  users: {
    admin: { name: 'مدير النظام', email: 'admin@sufra.app', phone: '', role: 'admin', points: 0, totalOrders: 0, totalSpent: 0, createdAt: now },
  },
  notifications: {
    welcome: { userId: 'all', title: 'أهلاً بك في سُفرة 👋', body: 'اطلب الآن واجمع نقاط الولاء مع كل طلب.', createdAt: now },
  },
  orders: {},
}

/** Demo credentials for local mode (password: admin123). */
export const SEED_ADMIN = { uid: 'admin', email: 'admin@sufra.app', password: 'admin123' }
