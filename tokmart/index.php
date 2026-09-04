<?php
/**
 * Tokmart storefront - single entry point for both signed-out browsing
 * (catalog, categories, login/register) and the signed-in app (cart,
 * orders, account, chat). All data comes from api/index.php via callAPI();
 * this file only renders the shell and injects the handful of values the
 * frontend needs before it can call the API (login state, enabled payment
 * methods, Google client id) through window.APP_CONFIG. The admin panel
 * lives separately under admin/.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$site = getSiteSettings();
$paymentMethods = query("SELECT * FROM payments WHERE enabled = 1 ORDER BY id ASC");
$rechargeMethods = query("SELECT * FROM payments WHERE enabled = 1 AND isRechargeOnly = 1 ORDER BY id ASC");

$route = $_GET['route'] ?? '';
$page = $_GET['page'] ?? '';

$protectedPages = ['account', 'orders', 'settings', 'cart', 'chat', 'notifications', 'favorites', 'recharge'];
if (in_array($page, $protectedPages, true) && !isset($_SESSION['user_id'])) {
    $page = 'home';
}

$pages = [
    'home' => 'الرئيسية', 'app' => 'المتجر', 'categories' => 'التصنيفات',
    'cart' => 'سلة التسوق', 'account' => 'الحساب', 'orders' => 'طلباتي',
    'settings' => 'الإعدادات', 'admin' => 'لوحة التحكم', 'product' => 'تفاصيل المنتج',
    'order' => 'تفاصيل الطلب', 'chat' => 'الدردشة', 'notifications' => 'الإشعارات',
    'favorites' => 'المفضلة', 'recharge' => 'شحن الرصيد', 'forgot' => 'نسيان كلمة المرور',
];

$pageKey = $page ?: 'home';
if (!isset($pages[$pageKey])) {
    $pageKey = 'home';
}

$pageTitle = $site['name'] . ' - ' . $pages[$pageKey];

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = !empty($_SESSION['is_admin']);
$isVerified = !empty($_SESSION['is_verified']);

if ($isLoggedIn && isset($_SESSION['user_name'])) {
    $pageTitle .= ' - ' . $_SESSION['user_name'];
}

$privacyPolicy = $site['privacy_policy'];
$termsConditions = $site['terms_conditions'];
$appVersion = $site['app_version'];
$siteName = $site['name'];
$siteDescription = $site['description'];
$siteLogoUrl = $site['logo_url'];

// Relative (not APP_BASE_PATH-prefixed) on purpose: this document is always
// the reference point browsers resolve these against, however deep/shallow
// the app is installed, so it can't be thrown off by a wrong base-path guess.
$assetsBase = 'assets';
$cssFiles = ['base', 'layout', 'notifications', 'catalog', 'pages', 'chat', 'auth', 'misc', 'responsive'];
$jsFiles = ['widgets-extra', 'core', 'auth', 'cart', 'catalog', 'chat', 'checkout', 'ui-utils', 'init'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0f3d1c">
    <meta name="description" content="<?php echo htmlspecialchars($siteDescription); ?>">
    <title id="pageTitle"><?php echo htmlspecialchars($pageTitle); ?></title>

    <script src="https://accounts.google.com/gsi/client" async defer></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <?php foreach ($cssFiles as $cssFile): ?>
    <link rel="stylesheet" href="<?php echo $assetsBase; ?>/css/<?php echo $cssFile; ?>.css?v=<?php echo CACHE_VERSION; ?>">
    <?php endforeach; ?>
</head>
<body>


<div id="app">
    <!-- ===== MAIN CONTENT ===== -->
    <div id="mainContent" class="visible">
        
        <!-- ===== HEADER ===== -->
        <header>
            <div class="topbar">
                <div class="topbar-left">
                    <button class="icon-btn" id="notifBtn" aria-label="الإشعارات">
                        <i class="fa-solid fa-bell"></i>
                        <span class="badge" id="notifBadge">0</span>
                    </button>
                   
                </div>
                <div class="topbar-right">
                    <div class="logo" onclick="switchPage('page-home')" role="button" tabindex="0">
                        <img class="logo-icon" id="siteLogo" src="" alt="Logo">
                        <!-- تم إزالة النص -->
                    </div>
                </div>
            </div>
        </header>

        <!-- ===== SCROLL AREA ===== -->
        <div class="scroll-area" id="scrollArea">
            
         <!-- Home -->
    <div class="page active" id="page-home">
        <div class="countdown-section" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 14px;margin:10px 0 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;box-shadow:var(--shadow);">
            <div class="label" style="font-size:12px;font-weight:700;color:var(--text2);display:flex;align-items:center;gap:6px;">
                <i class="fa-solid fa-clock" style="color:var(--secondary);font-size:14px;"></i>
                <span>عروض الخصم ينتهى خلال</span>
            </div>
            <div class="discount-timer" style="display: flex; gap: 10px; direction: ltr;">
                <span class="t-hours">02</span>:<span class="t-minutes">00</span>:<span class="t-seconds">00</span>
            </div>
        </div>
        
        <div id="categoryBannersContainer"></div>
    </div> <!-- نهاية صفحة الرئيسية -->

    <div style="height: 20px;"></div>
            <!-- Categories -->
            <div class="page" id="page-categories">
                <div class="category-search-wrapper" style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:16px;box-shadow:var(--shadow);">
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="category-title" style="font-size:16px;font-weight:800;color:var(--text2);white-space:nowrap;">
                                <i class="fa-solid fa-grid-2" style="color:var(--primary);"></i> 
                            </span>
                            <div class="category-search" style="flex:1;display:flex;align-items:center;gap:8px;background:var(--bg2);border:2px solid var(--border);border-radius:10px;padding:6px 12px;transition:border-color 0.3s;">
                                <i class="fa-solid fa-search" style="color:var(--text3);"></i>
                                <input type="text" id="categorySearchInput" placeholder="ابحث عن تصنيف أو منتج..." style="flex:1;border:none;background:transparent;outline:none;color:var(--text);font-size:13px;padding:6px 0;">
                                <button id="clearSearchBtn" style="background:none;border:none;color:var(--text3);cursor:pointer;padding:4px 8px;display:none;font-size:14px;" onclick="document.getElementById('categorySearchInput').value='';performCategorySearch();">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span style="font-size:12px;font-weight:600;color:var(--text3);"> السعر:</span>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="font-size:11px;color:var(--text3);">من</span>
                                <input type="number" id="minPriceInput" placeholder="0" min="0" step="1" 
                                       style="width:70px;padding:4px 8px;border:2px solid var(--border);border-radius:6px;background:var(--bg2);font-size:12px;outline:none;">
                                <span style="font-size:11px;color:var(--text3);">إلى</span>
                                <input type="number" id="maxPriceInput" placeholder="1000" min="0" step="1" 
                                       style="width:70px;padding:4px 8px;border:2px solid var(--border);border-radius:6px;background:var(--bg2);font-size:12px;outline:none;">
                                <span style="font-size:11px;color:var(--text3);">د.ع</span>
                            </div>
                            <button id="searchBtn" onclick="performCategorySearch()" style="padding:4px 14px;border:none;border-radius:8px;background:var(--primary-light);color:#fff;font-size:12px;font-weight:700;cursor:pointer;">
                                <i class="fa-solid fa-magnifying-glass"></i> بحث
                            </button>
                        </div>
                    </div>
                </div>
                <div class="categories-grid" id="categoriesGrid"></div>
            </div>

            <!-- Cart -->
            <div class="page" id="page-cart">
                <h2 style="font-size:18px;font-weight:800;margin-bottom:14px;color:var(--text2);">
                    <i class="" style="color:var(--primary);"></i> 
                    <span id="cartTitle"> </span>
                </h2>
                <div id="cartContent"></div>
                <div id="checkoutSection" style="display:none;"></div>
            </div>

            <!-- Account -->
            <div class="page" id="page-account">
                <h2 style="font-size:var(--fs-2xl);font-weight:800;margin-bottom:14px;color:var(--text2);">
                    <span id="accountTitle" data-i18n="account"></span>
                </h2>

                <div class="balance-row" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                    <div class="balance-info" style="display:flex;align-items:center;gap:12px;">
                        <div class="bal-icon" style="width:40px;height:40px;border-radius:50%;background:var(--acSh);display:grid;place-items:center;font-size:var(--fs-2xl);color:var(--primary);">
                            <i class="fa-solid fa-wallet"></i>
                        </div>
                        <div class="bal-text">
                            <span class="bal-label" style="font-size:var(--fs-xs);color:var(--text3);" data-i18n="balance">رصيدك</span>
                            <span class="bal-amount" id="userBalance" style="font-size:var(--fs-3xl);font-weight:900;color:var(--primary);">0.00 د.ع</span>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-primary add-balance-btn" id="addBalanceBtn">
                        <i class="fa-solid fa-plus"></i> <span data-i18n="recharge_balance">شحن الرصيد</span>
                    </button>
                </div>

                <div class="auth-buttons" style="display:flex;gap:10px;margin:12px 0;flex-wrap:wrap;">
                    <button class="btn btn-md btn-outline active auth-btn" id="loginBtn" style="flex:1;min-width:120px;">
                        🔑 <span data-i18n="login">تسجيل الدخول</span>
                    </button>
                    <button class="btn btn-md btn-outline auth-btn" id="registerBtn" style="flex:1;min-width:120px;">
                        📝 <span data-i18n="register">إنشاء حساب</span>
                    </button>
                </div>

<!-- المفضلة -->
<div class="settings-item" id="favoritesMenuItem">
    <div class="left">
        <div class="icon-box" style="background:linear-gradient(135deg, #E91E63, #C2185B);box-shadow:0 4px 12px rgba(233,30,99,.25);">
            <i class="fa-solid fa-heart"></i>
        </div>
        <div class="text-block">
            <div class="title" data-i18n="favorites">المفضلة</div>
            <div class="subtitle">منتجاتك المفضلة</div>
        </div>
    </div>
    <div class="right">
        <span id="favCount" class="count-badge"></span>
        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
    </div>
</div>

<!-- طلباتي -->
<div class="settings-item" id="ordersMenuItem">
    <div class="left">
        <div class="icon-box" style="background:linear-gradient(135deg, #058693, #0AA6B5);box-shadow:0 4px 12px rgba(5,134,147,.25);">
            <i class="fa-solid fa-box"></i>
        </div>
        <div class="text-block">
            <div class="title" data-i18n="orders">طلباتي</div>
            <div class="subtitle">تتبع طلباتك</div>
        </div>
    </div>
    <div class="right">
        <span id="orderCount" class="count-badge"></span>
        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
    </div>
</div>

<!-- شحن الرصيد -->
<div class="settings-item" id="rechargeMenuItem">
    <div class="left">
        <div class="icon-box" style="background:linear-gradient(135deg, #D8B07A, #C49A5E);box-shadow:0 4px 12px rgba(216,176,122,.25);">
            <i class="fa-solid fa-wallet"></i>
        </div>
        <div class="text-block">
            <div class="title" data-i18n="recharge">شحن الرصيد</div>
            <div class="subtitle">إضافة رصيد لحسابك</div>
        </div>
    </div>
    <div class="right">
        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
    </div>
</div>

<!-- إعدادات الحساب -->
<div class="settings-item" id="accountSettingsMenuItem">
    <div class="left">
        <div class="icon-box" style="background:linear-gradient(135deg, #6C5CE7, #4834D4);box-shadow:0 4px 12px rgba(108,92,231,.25);">
            <i class="fa-solid fa-gear"></i>
        </div>
        <div class="text-block">
            <div class="title" data-i18n="settings">إعدادات الحساب</div>
            <div class="subtitle">تعديل الملف الشخصي</div>
        </div>
    </div>
    <div class="right">
        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
    </div>
</div>

<!-- الدردشة مع الدعم -->
<div class="settings-item" id="chatSupportMenuItem">
    <div class="left">
        <div class="icon-box" style="background:linear-gradient(135deg, #00B894, #00A381);box-shadow:0 4px 12px rgba(0,184,148,.25);">
            <i class="fa-solid fa-headset"></i>
        </div>
        <div class="text-block">
            <div class="title" data-i18n="contact_support">الدعم الفني</div>
            <div class="subtitle">تواصل مع فريق الدعم</div>
        </div>
    </div>
    <div class="right">
        <span class="status-pill" style="background:#2ECC71;">متصل</span>
        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
    </div>
</div>

<!-- لوحة الإدارة (للمدير فقط) -->
<div class="settings-item admin-item" id="adminPanelMenuItem" style="display:none;">
    <div class="left">
        <div class="icon-box" style="background:linear-gradient(135deg, #D8B07A, #C49A5E);box-shadow:0 4px 12px rgba(216,176,122,.25);">
            <i class="fa-solid fa-chart-simple"></i>
        </div>
        <div class="text-block">
            <div class="title" data-i18n="admin_panel">لوحة الإدارة</div>
            <div class="subtitle">📊 التحكم الكامل بالمتجر</div>
        </div>
    </div>
    <div class="right">
        <span class="status-pill" style="background:#D8B07A;color:#1a1a2e;">Admin</span>
        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
    </div>
</div>

<!-- ===== إعدادات اللغة ===== -->
<div class="settings-item" id="languageMenuItem" style="margin-top:4px;">
    <div class="left">
        <div class="icon-box" style="background:linear-gradient(135deg, #058693, #0AA6B5);box-shadow:0 4px 12px rgba(5,134,147,.25);">
            <i class="fa-solid fa-globe"></i>
        </div>
        <div class="text-block">
            <div class="title" data-i18n="language">اللغة</div>
            <div class="subtitle">اختر لغة التطبيق المفضلة</div>
        </div>
    </div>
    <div class="right">
        <span id="currentLangDisplay" class="account-lang-display">
            <i class="fa-solid fa-check" style="font-size:var(--fs-xs);color:var(--green);margin-left:4px;"></i>
            العربية
        </span>
        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
    </div>
</div>
                <!-- Policy & Terms -->
                <div style="margin-top:20px;border-top:1px solid var(--border);padding-top:16px;">
                    <div class="policy-section">
                        <div class="policy-title" onclick="togglePolicy('privacy')" style="font-size:var(--fs-md);font-weight:700;color:var(--text2);margin-bottom:6px;display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <i class="fa-solid fa-shield-halved" style="color:var(--primary);"></i>
                            سياسة الخصوصية
                            <i class="fa-solid fa-chevron-down" style="margin-right:auto;font-size:var(--fs-sm);color:var(--text3);"></i>
                        </div>
                        <div class="policy-content" id="privacyPolicyContent" style="font-size:var(--fs-base);color:var(--text3);line-height:1.8;max-height:0;overflow:hidden;transition:max-height 0.4s var(--ease-out);">
                            <?php echo nl2br(htmlspecialchars($privacyPolicy)); ?>
                        </div>
                    </div>

                    <div class="policy-section">
                        <div class="policy-title" onclick="togglePolicy('terms')" style="font-size:var(--fs-md);font-weight:700;color:var(--text2);margin-bottom:6px;display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <i class="fa-solid fa-file-contract" style="color:var(--secondary);"></i>
                            الشروط والأحكام
                            <i class="fa-solid fa-chevron-down" style="margin-right:auto;font-size:var(--fs-sm);color:var(--text3);"></i>
                        </div>
                        <div class="policy-content" id="termsPolicyContent" style="font-size:var(--fs-base);color:var(--text3);line-height:1.8;max-height:0;overflow:hidden;transition:max-height 0.4s var(--ease-out);">
                            <?php echo nl2br(htmlspecialchars($termsConditions)); ?>
                        </div>
                    </div>

                    <div class="app-version" style="text-align:center;padding:16px 0;color:var(--text3);font-size:var(--fs-sm);">
                        <i class="fa-solid fa-code"></i> الإصدار <span style="font-weight:700;color:var(--primary);"><?php echo htmlspecialchars($appVersion); ?></span>
                    </div>
                </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
           SLIDING PAGES
           ============================================================ -->
        
        <!-- Product Detail -->
        <div class="product-detail-page" id="productDetailPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeProductDetail()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2 id="detailTitle">تفاصيل المنتج</h2>
            </div>
            <div class="detail-body" id="detailBody"></div>
        </div>

        <!-- Category Products -->
        <div class="category-products-page" id="categoryProductsPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeCategoryProducts()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2 id="catProductsTitle">المنتجات</h2>
            </div>
            <div class="products-grid" id="categoryProductsGrid"></div>
        </div>

        <!-- All Products -->
        <div class="all-products-page" id="allProductsPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeAllProducts()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2 id="allProductsTitle">جميع المنتجات</h2>
            </div>
            <div class="products-grid" id="allProductsGrid"></div>
        </div>

  <!-- Notifications -->
<div class="settings-page" id="page-notifications">
    <div class="detail-header">
        <button class="back-btn" onclick="closeSettingsPage('page-notifications')"><i class="fa-solid fa-arrow-right"></i></button>
        <h2> الإشعارات</h2>
    </div>
    <div class="settings-page-body">
        <!-- شريط الإجراءات والزر الملون -->
        <div class="d-flex justify-content-between align-items-center mb-3 px-1" id="notifications-actions">
            
        </div>
        <div id="notificationsList">
            <!-- سيتم حقن الإشعارات ديناميكياً هنا عبر جافاسكريبت -->
        </div>
    </div>
</div>
        <!-- Favorites -->
        <div class="favorites-page" id="favoritesPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeFavorites()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>❤️ المفضلة</h2>
            </div>
            <div class="favorites-grid" id="favoritesGrid"></div>
        </div>

        <!-- Orders -->
        <div class="orders-page" id="ordersPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeOrders()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>📦 طلباتي</h2>
            </div>
            <div class="orders-list" id="ordersList"></div>
        </div>

        <!-- Order Detail -->
        <div class="order-detail-page" id="orderDetailPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeOrderDetail()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2 id="orderDetailTitle">تفاصيل الطلب</h2>
            </div>
            <div class="order-detail-body" id="orderDetailBody"></div>
        </div>

        <!-- Recharge -->
        <div class="recharge-page" id="rechargePage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeRecharge()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>💰 شحن الرصيد</h2>
            </div>
            <div class="recharge-body" id="rechargeBody">
                <div id="rechargeForm"></div>
            </div>
        </div>

        <!-- Chat Support -->
        <div class="settings-page" id="page-chat-support">
            <div class="detail-header">
                <button class="back-btn" onclick="closeChatPage()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>💬 الدردشة مع الدعم</h2>
            </div>
            <div class="settings-page-body" id="chatSupportBody"></div>
        </div>

        <!-- Account Settings -->
        <div class="settings-page" id="page-account-settings">
            <div class="detail-header">
                <button class="back-btn" onclick="closeSettingsPage('page-account-settings')"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>⚙️ إعدادات الحساب</h2>
            </div>

<?php
$user = null;
if (isset($_SESSION['user_id'])) {
    $user = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
}
?>

<form id="settingsForm" onsubmit="return false;">

    <!-- الصورة الشخصية -->
    <div style="text-align:center;margin-bottom:25px;">
        <div id="settingsAvatarPreview" style="position:relative;width:90px;height:90px;border-radius:50%;margin:0 auto;overflow:hidden;border:3px solid var(--primary);background:var(--bg2);box-shadow:0 4px 20px rgba(5,134,147,.15);">
            <?php if (!empty($user['avatar_path'])): ?>
                <img src="<?php echo htmlspecialchars(uploadUrl('avatars', $user['avatar_path'])); ?>" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:38px;color:var(--text3);">
                    <i class="fa-regular fa-user"></i>
                </div>
            <?php endif; ?>
        </div>
        <label for="settingsAvatar" style="display:inline-block;margin-top:8px;font-size:12px;color:var(--primary);cursor:pointer;font-weight:700;">
            <i class="fa-solid fa-camera"></i> تغيير الصورة
        </label>
        <input type="file" id="settingsAvatar" accept="image/*" style="display:none;" onchange="previewAvatar(this)">
    </div>

    <!-- اسم المستخدم -->
    <div class="form-group" style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--text2);margin-bottom:4px;">
            <i class="fa-regular fa-user" style="color:var(--primary);margin-left:5px;"></i> اسم المستخدم
        </label>
        <input type="text" id="settingsUsername" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required
               style="width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);outline:none;font-size:14px;color:var(--text);">
    </div>

    <!-- رقم الهاتف -->
    <div class="form-group" style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--text2);margin-bottom:4px;">
            <i class="fa-solid fa-phone" style="color:var(--primary);margin-left:5px;"></i> رقم الهاتف <span style="color:var(--red);">*</span>
        </label>
        <input type="text" id="settingsPhone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="مثال: 07700000000" required
               style="width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);outline:none;font-size:14px;color:var(--text);">
    </div>

    <!-- البريد الإلكتروني -->
    <div class="form-group" style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--text2);margin-bottom:4px;">
            <i class="fa-regular fa-envelope" style="color:var(--primary);margin-left:5px;"></i> البريد الإلكتروني
        </label>
        <input type="email" id="settingsEmail" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
               style="width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);outline:none;font-size:14px;color:var(--text);">
    </div>

    <!-- الفاصل -->
    <div style="display:flex;align-items:center;gap:12px;margin:22px 0 16px;">
        <div style="flex:1;height:1px;background:var(--border);"></div>
        <span style="font-size:12px;color:var(--text3);font-weight:600;white-space:nowrap;">
            <i class="fa-solid fa-lock" style="color:var(--primary);margin-left:4px;"></i> تغيير كلمة المرور (اختياري)
        </span>
        <div style="flex:1;height:1px;background:var(--border);"></div>
    </div>

    <!-- كلمة المرور الحالية -->
    <div class="form-group password-field" style="margin-bottom:14px;position:relative;">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--text2);margin-bottom:4px;">كلمة المرور الحالية</label>
        <input type="password" id="settingsCurrentPassword" placeholder="••••••••"
               style="width:100%;padding:11px 40px 11px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);outline:none;font-size:14px;color:var(--text);">
        <button type="button" onclick="togglePassword(this)" style="position:absolute;left:10px;top:34px;background:none;border:none;color:var(--text3);cursor:pointer;">
            <i class="fa-regular fa-eye"></i>
        </button>
    </div>

    <!-- كلمة المرور الجديدة -->
    <div class="form-group password-field" style="margin-bottom:14px;position:relative;">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--text2);margin-bottom:4px;">كلمة المرور الجديدة</label>
        <input type="password" id="settingsNewPassword" placeholder="6 أحرف على الأقل"
               style="width:100%;padding:11px 40px 11px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);outline:none;font-size:14px;color:var(--text);">
        <button type="button" onclick="togglePassword(this)" style="position:absolute;left:10px;top:34px;background:none;border:none;color:var(--text3);cursor:pointer;">
            <i class="fa-regular fa-eye"></i>
        </button>
    </div>

    <!-- زر الحفظ -->
    <button type="button" onclick="saveAccountSettings()" class="btn btn-lg btn-primary btn-block">
        <i class="fa-regular fa-floppy-disk"></i> حفظ التغييرات
    </button>
</form>
                </div>
            </div>
        </div>
        <!-- Language Settings -->
        <div class="settings-page" id="page-language-settings">
            <div class="detail-header">
                <button class="back-btn" onclick="closeSettingsPage('page-language-settings')">
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
                <h2>🌐 اللغة</h2>
            </div>
            <div class="settings-page-body">
                <div style="display:flex;flex-direction:column;gap:12px;padding:8px 0;">
                    <div class="lang-option" id="langOptionAr" onclick="setLanguage('ar')">
                        <div class="lang-icon"><i class="fa-solid fa-language"></i></div>
                        <div class="lang-text">
                            <div class="lang-name">العربية</div>
                            <div class="lang-sub">اللغة العربية (Arabic)</div>
                        </div>
                        <i class="fa-solid fa-circle-check lang-check"></i>
                    </div>

                    <div class="lang-option" id="langOptionEn" onclick="setLanguage('en')">
                        <div class="lang-icon"><i class="fa-solid fa-language"></i></div>
                        <div class="lang-text">
                            <div class="lang-name">English</div>
                            <div class="lang-sub">اللغة الإنجليزية (English)</div>
                        </div>
                        <i class="fa-solid fa-circle-check lang-check"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Auth Pages -->
        <div class="auth-page" id="loginPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeLoginPage()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>🔑 تسجيل الدخول</h2>
            </div>
            <div class="auth-body">
                <div class="auth-icon"><i class="fa-solid fa-user-circle"></i></div>
                <h2 style="text-align:center;font-size:22px;font-weight:800;color:var(--text2);">مرحباً بعودتك</h2>
                <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:20px;">سجل دخولك للوصول إلى حسابك والمزايا الحصرية</p>
                
                <div id="googleLoginContainer"></div>
                
                <div style="text-align:center;margin:12px 0;color:var(--text3);font-size:13px;">أو</div>
                
                <form id="loginForm">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">البريد الإلكتروني أو رقم الهاتف</label>
                        <input type="text" id="loginIdentifier" placeholder="example@email.com أو 07700000000" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">كلمة المرور</label>
                        <input type="password" id="loginPassword" placeholder="••••••••" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div id="loginError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;"></div>
                    <button type="button" class="btn-submit" onclick="handleLogin()">تسجيل الدخول</button>
                    <div style="text-align:left;margin-top:8px;font-size:13px;">
                        <a onclick="closeLoginPage();openForgotPasswordPage();" style="color:var(--text3);cursor:pointer;text-decoration:underline;">
                            🔑 نسيت كلمة المرور؟
                        </a>
                    </div>
                </form>
                <div class="auth-switch" style="text-align:center;margin-top:16px;font-size:14px;color:var(--text3);">
                    ليس لديك حساب؟ <a id="switchToRegister" style="color:var(--primary);font-weight:700;cursor:pointer;">سجل الآن</a>
                </div>
            </div>
        </div>

        <div class="auth-page" id="registerPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeRegisterPage()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>📝 إنشاء حساب</h2>
            </div>
            <div class="auth-body">
                <div class="auth-icon"><i class="fa-solid fa-user-plus"></i></div>
                <h2 style="text-align:center;font-size:22px;font-weight:800;color:var(--text2);">انضم إلينا</h2>
                <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:20px;">سجل الآن للاستفادة من جميع المزايا والعروض</p>
                
                <div id="googleRegisterContainer"></div>
                
                <div style="text-align:center;margin:12px 0;color:var(--text3);font-size:13px;">أو</div>
                
                <form id="registerForm">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">الاسم الكامل</label>
                        <input type="text" id="registerName" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">البريد الإلكتروني</label>
                        <input type="email" id="registerEmail" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">رقم الهاتف</label>
                        <input type="tel" id="registerPhone" placeholder="07XX XXX XXXX" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">كلمة المرور</label>
                        <input type="password" id="registerPassword" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div id="registerError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;"></div>
                    <button type="button" class="btn-submit" onclick="handleRegister()">إنشاء الحساب</button>
                </form>
                <div class="auth-switch" style="text-align:center;margin-top:16px;font-size:14px;color:var(--text3);">
                    لديك حساب؟ <a id="switchToLogin" style="color:var(--primary);font-weight:700;cursor:pointer;">سجل دخول</a>
                </div>
            </div>
        </div>

        <!-- Forgot Password -->
        <div class="auth-page" id="forgotPasswordPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeForgotPasswordPage()">
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
                <h2>🔐 نسيان كلمة المرور</h2>
            </div>
            <div class="auth-body">
                <div class="auth-icon"><i class="fa-solid fa-key"></i></div>
                
                <!-- ===== الخطوة 1: إدخال البريد ===== -->
                <div id="resetStep1">
                    <h2 style="text-align:center;font-size:20px;font-weight:800;color:var(--text2);">استعادة كلمة المرور</h2>
                    <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:20px;">أدخل بريدك الإلكتروني وسنرسل لك كود التحقق</p>
                    
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                            <i class="fa-solid fa-envelope"></i> البريد الإلكتروني
                        </label>
                        <input type="email" id="resetEmail" placeholder="example@email.com" required 
                               style="width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    
                    <div id="resetError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                    
                    <button type="button" class="btn-submit" onclick="requestPasswordReset()">
                        <i class="fa-solid fa-paper-plane"></i> إرسال كود التحقق
                    </button>
                    
                    <div class="auth-switch" style="text-align:center;margin-top:16px;font-size:14px;color:var(--text3);">
                        تذكرت كلمة المرور؟ <a onclick="closeForgotPasswordPage();openLoginPage();" style="color:var(--primary);font-weight:700;cursor:pointer;">تسجيل الدخول</a>
                    </div>
                </div>
                
                <!-- ===== الخطوة 2: إدخال الكود ===== -->
                <div id="resetStep2" style="display:none;">
                    <h2 style="text-align:center;font-size:20px;font-weight:800;color:var(--text2);">✅ تحقق من بريدك</h2>
                    <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:8px;">أدخل رمز التحقق المكون من 6 أرقام المرسل إلى</p>
                    <p style="text-align:center;font-size:14px;font-weight:700;color:var(--text2);" id="resetEmailDisplay">example@email.com</p>
                    <p style="text-align:center;font-size:12px;color:var(--text3);margin:4px 0 16px;">⏰ ينتهي خلال 10 دقائق</p>
                    
                    <div class="otp-container" id="resetOtpContainer">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 0)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 1)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 2)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 3)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 4)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 5)">
                    </div>
                    
                    <div id="resetCodeError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                    
                    <button type="button" class="btn-submit" onclick="verifyResetCode()">
                        <i class="fa-solid fa-check"></i> تحقق
                    </button>
                    
                    <div style="text-align:center;margin-top:16px;font-size:13px;color:var(--text3);">
                        لم يصل الكود؟ <a onclick="resendResetCode()" style="color:var(--primary);font-weight:700;cursor:pointer;">إعادة الإرسال</a>
                        <span id="resetResendTimer" style="color:var(--text3);font-size:12px;margin-right:4px;"></span>
                    </div>
                </div>
                
                <!-- ===== الخطوة 3: تعيين كلمة مرور جديدة ===== -->
                <div id="resetStep3" style="display:none;">
                    <h2 style="text-align:center;font-size:20px;font-weight:800;color:var(--text2);">🔑 كلمة مرور جديدة</h2>
                    <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:20px;">أدخل كلمة المرور الجديدة لحسابك</p>
                    
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                            <i class="fa-solid fa-lock"></i> كلمة المرور الجديدة
                        </label>
                        <input type="password" id="resetNewPassword" placeholder="••••••••" required 
                               style="width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                        <small style="color:var(--text3);font-size:11px;">يجب أن تكون 6 أحرف على الأقل</small>
                    </div>
                    
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                            <i class="fa-solid fa-lock"></i> تأكيد كلمة المرور
                        </label>
                        <input type="password" id="resetConfirmPassword" placeholder="••••••••" required 
                               style="width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    
                    <div id="resetPasswordError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                    
                    <button type="button" class="btn-submit" onclick="resetPassword()" style="background:var(--green);">
                        <i class="fa-solid fa-check"></i> تغيير كلمة المرور
                    </button>
                </div>
            </div>
        </div>

        <!-- OTP Verification -->
        <div class="auth-page" id="otpVerificationPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeOTPPage()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>🔐 التحقق من البريد</h2>
            </div>
            <div class="auth-body">
                <div class="auth-icon"><i class="fa-solid fa-envelope-circle-check"></i></div>
                <h2 style="text-align:center;font-size:22px;font-weight:800;color:var(--text2);">تحقق من بريدك</h2>
                <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:8px;">أدخل رمز التحقق المكون من 6 أرقام المرسل إلى</p>
                <p style="text-align:center;font-size:14px;font-weight:700;color:var(--text2);" id="otpEmailDisplay">example@email.com</p>
                <p style="text-align:center;font-size:12px;color:var(--text3);margin:4px 0 16px;">⏰ ينتهي خلال 10 دقائق</p>
                
                <div class="otp-container" id="otpContainer">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 0)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 1)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 2)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 3)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 4)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 5)">
                </div>
                
                <div id="otpError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                <div id="otpSuccess" style="color:var(--green);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                
                <button type="button" class="btn-submit" onclick="verifyOTP()">تحقق</button>
                
                <div style="text-align:center;margin-top:16px;font-size:13px;color:var(--text3);">
                    لم يصل الكود؟ <a id="resendOTP" style="color:var(--primary);font-weight:700;cursor:pointer;" onclick="resendOTP()">إعادة الإرسال</a>
                    <span id="resendTimer" style="color:var(--text3);font-size:12px;margin-right:4px;"></span>
                </div>
            </div>
        </div>

        <!-- User Chat -->
        <div class="chat-app-user" id="chatPageUser">
            <div class="chat-header">
                <button class="back-btn" onclick="closeUserChat()"><i class="fa-solid fa-arrow-right"></i></button>
                <div class="chat-info">
                    <div class="avatar"><i class="fa-solid fa-headset"></i></div>
                    <div class="details">
                        <div class="name">الدعم الفني</div>
                        <div class="status"><span class="dot"></span> متصل الآن</div>
                    </div>
                </div>
            </div>

            <div class="messages-container" id="userMessagesContainer">
                <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;" id="emptyChatMessage">
                    <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                    ابدأ محادثتك مع الدعم الفني
                </div>
                <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
                    <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
                    رسائل جديدة <span id="newMsgCount">0</span>
                </div>
            </div>

            <div class="chat-input-wrapper">
                <input type="text" id="userChatInput" placeholder="اكتب رسالتك هنا..." onkeypress="if(event.key==='Enter') sendUserMessage()" autofocus>
                <button class="send-btn" onclick="sendUserMessage()">
                    <i class="fa-solid fa-paper-plane"></i> إرسال
                </button>
            </div>
        </div>

        <!-- ============================================================
           ADMIN PAGE
           ============================================================ -->

        <!-- ============================================================
           OVERLAYS / MODALS
           ============================================================ -->
        
        <!-- Logout Bottom Sheet -->
        <div class="bottom-sheet-overlay" id="logoutSheetOverlay" onclick="closeLogoutSheet()"></div>
        <div class="logout-bottom-sheet" id="logoutBottomSheet">
            <div class="sheet-handle"></div>
            <div class="sheet-title">تسجيل الخروج</div>
            <div class="sheet-subtitle">هل تريد تسجيل الخروج من حسابك؟</div>
            <div class="sheet-actions">
                <button class="btn-cancel-sheet" onclick="closeLogoutSheet()">إلغاء</button>
                <button class="btn-confirm" onclick="performLogout()">تأكيد</button>
            </div>
        </div>

<!-- Order/Action Success Card -->
<div class="order-success-overlay" id="orderSuccessOverlay">
 <div class="order-success-box">
 <div class="success-icon"><i class="fa-regular fa-circle-check"></i></div>
<h3>✅ تم تقديم طلبكم</h3>
 <p class="order-sub">شكرا لستخدامكم خدماتنا </p>
 <button class="btn-close-success" id="closeSuccessBtn" onclick="closeSuccessModal()">اغلاق</button>
 </div>
</div>
        <!-- ============================================================
           BOTTOM NAVIGATION
           ============================================================ -->
        <div class="bottom-nav-wrapper" id="bottomNav">
            <nav class="bottom-nav">
                <button class="nav-item active" data-page="page-home" id="navHome">
                    <i class="fa-solid fa-house"></i>
                    <span>الرئيسية</span>
                </button>
                <button class="nav-item" data-page="page-categories" id="navCategories">
                    <span class="cat-icon"><i class="fa-solid fa-layer-group"></i></span>
                    <span>التصنيفات</span>
                </button>
                <button class="nav-item" data-page="page-cart" id="navCart">
                    <i class="fa-solid fa-cart-shopping"></i>
                    <span>السلة</span>
                    <span class="badge" id="cartBadge" style="display:none;">0</span>
                </button>
                <button class="nav-item" data-page="page-account" id="navAccount">
                    <i class="fa-solid fa-user"></i>
                    <span>الحساب</span>
                </button>
            </nav>
        </div>

        <!-- ===== Toast ===== -->
        <div id="toast"></div>
        
        <!-- ===== Lightbox ===== -->
        <div id="lightbox" onclick="if(event.target===this) closeLightbox()">
            <button class="lightbox-close" onclick="closeLightbox()">✕</button>
            <button class="lightbox-download" onclick="downloadLightboxImage()"><i class="fa-solid fa-download"></i> تحميل</button>
            <div class="lightbox-image-container"><img id="lightboxImage" src="" alt="صورة مكبرة"></div>
        </div>
    </div>
</div>

<script>
window.APP_CONFIG = {
    apiUrl: 'api/index.php',
    adminUrl: 'admin/',
    isLoggedIn: <?php echo $isLoggedIn ? 'true' : 'false'; ?>,
    isAdmin: <?php echo $isAdmin ? 'true' : 'false'; ?>,
    isVerified: <?php echo $isVerified ? 'true' : 'false'; ?>,
    payments: <?php echo json_encode($paymentMethods, JSON_UNESCAPED_UNICODE); ?>,
    rechargeMethods: <?php echo json_encode($rechargeMethods, JSON_UNESCAPED_UNICODE); ?>,
    googleClientId: <?php echo json_encode(GOOGLE_CLIENT_ID); ?>,
    googleEnabled: <?php echo json_encode((bool)GOOGLE_ENABLED); ?>
};
</script>
<?php foreach ($jsFiles as $jsFile): ?>
<script src="<?php echo $assetsBase; ?>/js/<?php echo $jsFile; ?>.js?v=<?php echo CACHE_VERSION; ?>"></script>
<?php endforeach; ?>
</body>
</html>

