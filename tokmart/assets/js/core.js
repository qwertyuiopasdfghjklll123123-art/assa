/* ============================================================
   Tokmart - Core helpers: DOM shortcuts, toast, callAPI, global state, i18n
   ============================================================ */
const $ = function(id) { return document.getElementById(id); };
const $$ = function(sel) { return document.querySelectorAll(sel); };

let toastTimer = null;

function showToast(msg, duration) {
    duration = duration || 2200;
    const el = $('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function() {
        el.classList.remove('show');
    }, duration);
}

function callAPI(action, data, method) {
    method = method || 'POST';
    return new Promise(function(resolve) {
        let url = window.APP_CONFIG.apiUrl + '?action=' + action;
        const options = {
            method: method,
            headers: { 
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        if (data && data instanceof FormData) {
            options.body = data;
        } else if (data && method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        } else if (data && method === 'GET') {
            url += '&' + new URLSearchParams(data).toString();
        }

        const controller = new AbortController();
        const timeoutId = setTimeout(function() {
            controller.abort();
        }, 30000);
        options.signal = controller.signal;

        fetch(url, options)
            .then(function(response) {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(function(result) { 
                resolve(result); 
            })
            .catch(function(err) {
                clearTimeout(timeoutId);
                console.error('API Error:', err);
                if (err.name === 'AbortError') {
                    resolve({ success: false, message: 'انتهت مهلة الاتصال، يرجى المحاولة مرة أخرى' });
                } else {
                    resolve({ success: false, message: 'خطأ في الاتصال بالخادم' });
                }
            });
    });
}

// ===== State =====
let state = {
    user: null,
    isLoggedIn: window.APP_CONFIG.isLoggedIn,
    isAdmin: window.APP_CONFIG.isAdmin,
    isVerified: window.APP_CONFIG.isVerified,
    balance: 0,
    cart: [],
    favorites: [],
    products: [],
    categories: [],
    orders: [],
    payments: window.APP_CONFIG.payments,
    rechargeMethods: window.APP_CONFIG.rechargeMethods,
    notifications: [],
    chats: [],
    users: [],
    recharges: [],
    flashDeals: [],
    bestSellers: [],
    newArrivals: [],
    featured: [],
    currentPage: 'page-home',
    selectedPayment: 'cash',
    selectedRechargeMethod: null,
    detailQty: 1,
    userMessages: [],
    lastMessageCount: 0,
    adminCurrentUserId: null,
    isUserChatOpen: false,
    isAdminChatOpen: false,
    chatIntervals: {
        user: null,
        admin: null
    },
    otpTimer: null,
    resendTimer: 60,
    rechargeAmount: 0,
    deleteImages: []
};

// ============================================================
// نظام الترجمة
// ============================================================

const translations = {
    ar: {
        'app_name': 'Tokmart',
        'home': 'الرئيسية',
        'categories': 'التصنيفات',
        'cart': 'السلة',
        'account': 'الحساب',
        'search': 'بحث',
        'save': 'حفظ',
        'cancel': 'إلغاء',
        'delete': 'حذف',
        'edit': 'تعديل',
        'add': 'إضافة',
        'close': 'إغلاق',
        'back': 'رجوع',
        'loading': 'جاري التحميل...',
        'no_data': 'لا توجد بيانات',
        'error': 'حدث خطأ',
        'success': 'تم بنجاح',
        'products': 'المنتجات',
        'product_name': 'اسم المنتج',
        'product_name_en': 'اسم المنتج (بالإنجليزية)',
        'product_desc': 'وصف المنتج',
        'product_desc_en': 'وصف المنتج (بالإنجليزية)',
        'price': 'السعر',
        'old_price': 'السعر القديم',
        'category': 'التصنيف',
        'brand': 'العلامة التجارية',
        'delivery_time': 'وقت التوصيل',
        'rating': 'التقييم',
        'rating_count': 'عدد التقييمات',
        'add_to_cart': 'أضف للسلة',
        'favorite': 'المفضلة',
        'discount': 'خصم',
        'new': 'جديد',
        'bestseller': 'الأكثر مبيعاً',
        'featured': 'مميز',
        'flash_deal': 'عرض خاص',
        'balance': 'الرصيد',
        'recharge': 'شحن الرصيد',
        'orders': 'طلباتي',
        'favorites': 'المفضلة',
        'settings': 'الإعدادات',
        'language': 'اللغة',
        'arabic': 'العربية',
        'english': 'الإنجليزية',
        'logout': 'تسجيل الخروج',
        'login': 'تسجيل الدخول',
        'register': 'إنشاء حساب',
        'profile': 'الملف الشخصي',
        'phone': 'رقم الهاتف',
        'email': 'البريد الإلكتروني',
        'password': 'كلمة المرور',
        'current_password': 'كلمة المرور الحالية',
        'new_password': 'كلمة المرور الجديدة',
        'avatar': 'الصورة الشخصية',
        'recharge_balance': 'شحن الرصيد',
        'amount': 'المبلغ',
        'payment_method': 'طريقة الدفع',
        'receipt': 'صورة الإيصال',
        'submit_request': 'تقديم الطلب',
        'payment_details': 'تفاصيل الدفع',
        'account_number': 'رقم الحساب',
        'beneficiary': 'المستفيد',
        'copy': 'نسخ',
        'copied': 'تم النسخ',
        'recharge_history': 'سجل الشحن',
        'pending': 'قيد المعالجة',
        'approved': 'تمت الموافقة',
        'rejected': 'مرفوض',
        'pay_now': 'إدفع الآن',
        'order_details': 'تفاصيل الطلب',
        'order_id': 'رقم الطلب',
        'order_date': 'تاريخ الطلب',
        'order_status': 'حالة الطلب',
        'total': 'المجموع',
        'shipping_address': 'عنوان التوصيل',
        'payment_type': 'طريقة الدفع',
        'transfer_image': 'صورة التحويل',
        'support': 'الدعم الفني',
        'contact_support': 'التواصل مع الدعم',
        'notifications': 'الإشعارات',
        'mark_all_read': 'كمقروء',
        'delete_all': 'حذف',
        'no_notifications': 'لا توجد إشعارات',
        'read': 'مقروء',
        'unread': 'غير مقروء',
        'admin_panel': 'لوحة التحكم',
        'dashboard': 'لوحة المعلومات',
        'manage_products': 'إدارة المنتجات',
        'manage_categories': 'إدارة التصنيفات',
        'manage_orders': 'إدارة الطلبات',
        'manage_users': 'إدارة المستخدمين',
        'manage_payments': 'إدارة الدفع',
        'manage_chats': 'إدارة الدردشة',
        'manage_recharges': 'إدارة الشحن',
        'settings': 'الإعدادات',
        'stats': 'الإحصائيات',
        'add_product': 'إضافة منتج',
        'add_category': 'إضافة تصنيف',
        'add_payment': 'إضافة طريقة دفع',
        'export_data': 'تصدير البيانات',
        'approve': 'موافقة',
        'reject': 'رفض',
        'view': 'عرض',
        'welcome': 'مرحباً بك',
        'login_success': 'تم تسجيل الدخول بنجاح',
        'logout_success': 'تم تسجيل الخروج بنجاح',
        'register_success': 'تم إنشاء الحساب بنجاح',
        'update_success': 'تم التحديث بنجاح',
        'delete_success': 'تم الحذف بنجاح',
        'copy_success': 'تم النسخ بنجاح',
        'recharge_success': 'تم إرسال طلب الشحن بنجاح',
        'order_success': 'تم إنشاء الطلب بنجاح',
        'cart_empty': 'السلة فارغة',
        'required_field': 'هذا الحقل مطلوب',
        'invalid_email': 'البريد الإلكتروني غير صحيح',
        'invalid_phone': 'رقم الهاتف غير صحيح',
        'password_mismatch': 'كلمة المرور غير متطابقة',
        'balance_insufficient': 'الرصيد غير كافي',
        'recharge_details': 'تفاصيل طلب الشحن',
        'notes': 'ملاحظات',
        'no_recharges': 'لا توجد طلبات شحن سابقة',
        'recharge_now': 'قم بتقديم طلب شحن لزيادة رصيدك',
        'pending_review': 'بانتظار المراجعة',
        'receipt': 'صورة الإيصال',
        'no_orders': 'لا توجد طلبات',
        'start_shopping': 'ابدأ التسوق',
        'login_to_view': 'سجل دخولك لمشاهدة',
        'explore_products': 'استكشاف المنتجات',
        'add_to_favorites': 'أضف إلى المفضلة',
        'remove_from_favorites': 'إزالة من المفضلة',
        'no_favorites': 'لا توجد منتجات في المفضلة',
        'favorites_empty': 'أضف منتجاتك المفضلة لتظهر هنا',
        'back_to_list': 'العودة للقائمة',
        'total_amount': 'المجموع الكلي',
        'order_number': 'رقم الطلب',
    },
    en: {
        'app_name': 'Tokmart',
        'home': 'Home',
        'categories': 'Categories',
        'cart': 'Cart',
        'account': 'Account',
        'search': 'Search',
        'save': 'Save',
        'cancel': 'Cancel',
        'delete': 'Delete',
        'edit': 'Edit',
        'add': 'Add',
        'close': 'Close',
        'back': 'Back',
        'loading': 'Loading...',
        'no_data': 'No data available',
        'error': 'An error occurred',
        'success': 'Success',
        'products': 'Products',
        'product_name': 'Product Name',
        'product_name_en': 'Product Name (English)',
        'product_desc': 'Product Description',
        'product_desc_en': 'Product Description (English)',
        'price': 'Price',
        'old_price': 'Old Price',
        'category': 'Category',
        'brand': 'Brand',
        'delivery_time': 'Delivery Time',
        'rating': 'Rating',
        'rating_count': 'Ratings',
        'add_to_cart': 'Add to Cart',
        'favorite': 'Favorite',
        'discount': 'Discount',
        'new': 'New',
        'bestseller': 'Bestseller',
        'featured': 'Featured',
        'flash_deal': 'Flash Deal',
        'balance': 'Balance',
        'recharge': 'Recharge',
        'orders': 'My Orders',
        'favorites': 'Favorites',
        'settings': 'Settings',
        'language': 'Language',
        'arabic': 'Arabic',
        'english': 'English',
        'logout': 'Logout',
        'login': 'Login',
        'register': 'Register',
        'profile': 'Profile',
        'phone': 'Phone Number',
        'email': 'Email',
        'password': 'Password',
        'current_password': 'Current Password',
        'new_password': 'New Password',
        'avatar': 'Profile Picture',
        'recharge_balance': 'Recharge Balance',
        'amount': 'Amount',
        'payment_method': 'Payment Method',
        'receipt': 'Receipt Image',
        'submit_request': 'Submit Request',
        'payment_details': 'Payment Details',
        'account_number': 'Account Number',
        'beneficiary': 'Beneficiary',
        'copy': 'Copy',
        'copied': 'Copied',
        'recharge_history': 'Recharge History',
        'pending': 'Pending',
        'approved': 'Approved',
        'rejected': 'Rejected',
        'pay_now': 'Pay Now',
        'order_details': 'Order Details',
        'order_id': 'Order ID',
        'order_date': 'Order Date',
        'order_status': 'Order Status',
        'total': 'Total',
        'shipping_address': 'Shipping Address',
        'payment_type': 'Payment Method',
        'transfer_image': 'Transfer Image',
        'support': 'Support',
        'contact_support': 'Contact Support',
        'notifications': 'Notifications',
        'mark_all_read': 'Mark All as Read',
        'delete_all': 'Delete All',
        'no_notifications': 'No notifications',
        'read': 'Read',
        'unread': 'Unread',
        'admin_panel': 'Admin Panel',
        'dashboard': 'Dashboard',
        'manage_products': 'Manage Products',
        'manage_categories': 'Manage Categories',
        'manage_orders': 'Manage Orders',
        'manage_users': 'Manage Users',
        'manage_payments': 'Manage Payments',
        'manage_chats': 'Manage Chats',
        'manage_recharges': 'Manage Recharges',
        'settings': 'Settings',
        'stats': 'Statistics',
        'add_product': 'Add Product',
        'add_category': 'Add Category',
        'add_payment': 'Add Payment Method',
        'export_data': 'Export Data',
        'approve': 'Approve',
        'reject': 'Reject',
        'view': 'View',
        'welcome': 'Welcome',
        'login_success': 'Login successful',
        'logout_success': 'Logout successful',
        'register_success': 'Account created successfully',
        'update_success': 'Updated successfully',
        'delete_success': 'Deleted successfully',
        'copy_success': 'Copied successfully',
        'recharge_success': 'Recharge request submitted',
        'order_success': 'Order created successfully',
        'cart_empty': 'Cart is empty',
        'required_field': 'This field is required',
        'invalid_email': 'Invalid email address',
        'invalid_phone': 'Invalid phone number',
        'password_mismatch': 'Password mismatch',
        'balance_insufficient': 'Insufficient balance',
        'recharge_details': 'Recharge Request Details',
        'notes': 'Notes',
        'no_recharges': 'No previous recharge requests',
        'recharge_now': 'Submit a recharge request to increase your balance',
        'pending_review': 'Pending Review',
        'receipt': 'Receipt Image',
        'no_orders': 'No orders found',
        'start_shopping': 'Start Shopping',
        'login_to_view': 'Login to view',
        'explore_products': 'Explore Products',
        'add_to_favorites': 'Add to Favorites',
        'remove_from_favorites': 'Remove from Favorites',
        'no_favorites': 'No favorites found',
        'favorites_empty': 'Add your favorite products here',
        'back_to_list': 'Back to List',
        'total_amount': 'Total Amount',
        'order_number': 'Order Number',
    }
};

let currentLang = localStorage.getItem('Tokmart_lang') || 'ar';

function t(key) {
    const lang = currentLang || 'ar';
    return translations[lang]?.[key] || translations['ar'][key] || key;
}

function setLanguage(lang) {
    if (lang !== 'ar' && lang !== 'en') return;
    currentLang = lang;
    localStorage.setItem('Tokmart_lang', lang);
    document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.lang = lang;
    updateAllTexts();
    showToast(t('update_success'));
}

function updateAllTexts() {
    document.querySelectorAll('[data-i18n]').forEach(function(el) {
        const key = el.getAttribute('data-i18n');
        el.textContent = t(key);
    });
    
    const titleEl = document.querySelector('title');
    if (titleEl) {
        titleEl.textContent = t('app_name') + ' - ' + (state.currentPage === 'page-home' ? t('home') : '');
    }
    
    const navItems = {
        'navHome': 'home',
        'navCategories': 'categories',
        'navCart': 'cart',
        'navAccount': 'account'
    };
    Object.keys(navItems).forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            const span = el.querySelector('span:last-child');
            if (span) span.textContent = t(navItems[id]);
        }
    });
    
    updateDynamicTexts();
}

function updateDynamicTexts() {
    if (state.isLoggedIn && state.user) {
        const loginBtn = document.getElementById('loginBtn');
        if (loginBtn) loginBtn.textContent = '👤 ' + state.user.name;
    }
    
    const pageTitles = {
        'page-home': t('home'),
        'page-categories': t('categories'),
        'page-cart': t('cart'),
        'page-account': t('account')
    };
    Object.keys(pageTitles).forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            const h2 = el.querySelector('h2');
            if (h2) {
                const icon = h2.querySelector('i');
                if (icon) {
                    h2.innerHTML = icon.outerHTML + ' ' + pageTitles[id];
                } else {
                    h2.textContent = pageTitles[id];
                }
            }
        }
    });
}

// ============================================================
// GOOGLE LOGIN
// ============================================================

let googleLoginInitialized = false;
let googleRegisterInitialized = false;
let googleInitAttempts = 0;
const MAX_GOOGLE_INIT_ATTEMPTS = 10;

