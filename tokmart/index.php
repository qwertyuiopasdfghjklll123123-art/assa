<?php
/**
 * Single entry point for the whole storefront - before login, after login,
 * and (via a small in-app link) into the separate /admin panel. There is no
 * separate welcome/landing page: this same document renders the home feed
 * whether or not a session is active, matching the original single-file app.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$site = getSiteSettings();
$siteName = $site['name'];
$siteDescription = $site['description'];
$siteLogoUrl = $site['logo_url'];
$privacyPolicy = $site['privacy_policy'];
$termsConditions = $site['terms_conditions'];
$appVersion = $site['app_version'];

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = $isLoggedIn && !empty($_SESSION['is_admin']);
$isVerified = $isLoggedIn && !empty($_SESSION['is_verified']);

$paymentMethods = query("SELECT * FROM payments WHERE enabled = 1 ORDER BY id ASC");
$rechargeMethods = query("SELECT * FROM payments WHERE enabled = 1 AND isRechargeOnly = 1 ORDER BY id ASC");
$googleOAuth = getGoogleOAuthConfig();

$accountUser = null;
if ($isLoggedIn) {
    $accountUser = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
}

// Relative (not APP_BASE_PATH-prefixed) on purpose: this document is always
// the reference point browsers resolve these against, however deep/shallow
// the app is installed, so it can't be thrown off by a wrong base-path guess.
$assetsBase = 'assets';
$cssFiles = ['base', 'layout', 'notifications', 'catalog', 'pages', 'chat', 'auth', 'misc', 'responsive'];
$jsFiles = ['core', 'auth', 'cart', 'catalog', 'chat', 'checkout', 'recharge', 'ui-utils', 'init'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0f3d1c">
    <link rel="manifest" href="<?php echo APP_BASE_PATH; ?>/manifest.php">
    <link rel="icon" href="<?php echo APP_BASE_PATH; ?>/icon.php?size=192">
    <link rel="apple-touch-icon" href="<?php echo APP_BASE_PATH; ?>/icon.php?size=192">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($siteName); ?>">
    <title><?php echo htmlspecialchars($siteName . ' - ' . ($siteDescription ?: 'المتجر')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <?php if (!empty($googleOAuth['enabled'])): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
    <?php foreach ($cssFiles as $cssFile): ?>
    <link rel="stylesheet" href="<?php echo $assetsBase; ?>/css/<?php echo $cssFile; ?>.css?v=<?php echo CACHE_VERSION; ?>">
    <?php endforeach; ?>
</head>
<body>

<div id="app">
    <div id="mainContent" class="visible">

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
                        <img class="logo-icon" id="siteLogo" src="<?php echo htmlspecialchars($siteLogoUrl); ?>" alt="Logo" style="<?php echo $siteLogoUrl ? '' : 'display:none;'; ?>">
                        <i class="fa-solid fa-store logo-icon" id="siteLogoFallback" style="<?php echo $siteLogoUrl ? 'display:none;' : ''; ?>"></i>
                    </div>
                </div>
            </div>
        </header>

        <div class="scroll-area" id="scrollArea">

            <!-- Home -->
            <div class="page active" id="page-home">
                <div class="countdown-section" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 14px;margin:10px 0 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;box-shadow:var(--shadow);">
                    <div class="label" style="font-size:var(--fs-sm);font-weight:700;color:var(--text2);display:flex;align-items:center;gap:6px;">
                        <i class="fa-solid fa-clock" style="color:var(--secondary);font-size:var(--fs-md);"></i>
                        <span data-i18n="offers_end_in">عروض الخصم ينتهى خلال</span>
                    </div>
                    <div class="discount-timer" style="display:flex;gap:10px;direction:ltr;">
                        <span class="t-hours">02</span>:<span class="t-minutes">00</span>:<span class="t-seconds">00</span>
                    </div>
                </div>
                <div id="categoryBannersContainer"></div>
            </div>

            <!-- Categories -->
            <div class="page" id="page-categories">
                <div class="category-search-wrapper" style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:16px;box-shadow:var(--shadow);">
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="category-title" style="font-size:var(--fs-xl);font-weight:800;color:var(--text2);white-space:nowrap;">
                                <i class="fa-solid fa-grid-2" style="color:var(--primary);"></i>
                            </span>
                            <div class="category-search" style="flex:1;display:flex;align-items:center;gap:8px;background:var(--bg2);border:2px solid var(--border);border-radius:10px;padding:6px 12px;transition:border-color 0.3s;">
                                <i class="fa-solid fa-search" style="color:var(--text3);"></i>
                                <input type="text" id="categorySearchInput" placeholder="ابحث عن تصنيف أو منتج..." style="flex:1;border:none;background:transparent;outline:none;color:var(--text);font-size:var(--fs-base);padding:6px 0;">
                                <button id="clearSearchBtn" style="background:none;border:none;color:var(--text3);cursor:pointer;padding:4px 8px;display:none;font-size:var(--fs-md);" onclick="document.getElementById('categorySearchInput').value='';performCategorySearch();">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span style="font-size:var(--fs-sm);font-weight:600;color:var(--text3);">السعر:</span>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="font-size:var(--fs-xs);color:var(--text3);">من</span>
                                <input type="number" id="minPriceInput" placeholder="0" min="0" step="1"
                                       style="width:70px;padding:4px 8px;border:2px solid var(--border);border-radius:6px;background:var(--bg2);font-size:var(--fs-sm);outline:none;">
                                <span style="font-size:var(--fs-xs);color:var(--text3);">إلى</span>
                                <input type="number" id="maxPriceInput" placeholder="1000" min="0" step="1"
                                       style="width:70px;padding:4px 8px;border:2px solid var(--border);border-radius:6px;background:var(--bg2);font-size:var(--fs-sm);outline:none;">
                                <span style="font-size:var(--fs-xs);color:var(--text3);">د.ع</span>
                            </div>
                            <button id="searchBtn" onclick="performCategorySearch()" style="padding:4px 14px;border:none;border-radius:8px;background:var(--primary-light);color:#fff;font-size:var(--fs-sm);font-weight:700;cursor:pointer;">
                                <i class="fa-solid fa-magnifying-glass"></i> بحث
                            </button>
                        </div>
                    </div>
                </div>
                <div class="categories-grid" id="categoriesGrid"></div>
            </div>

            <!-- Cart -->
            <div class="page" id="page-cart">
                <h2 style="font-size:var(--fs-xl);font-weight:800;margin-bottom:14px;color:var(--text2);">
                    <span id="cartTitle" data-i18n="cart"></span>
                </h2>
                <div id="cartContent"></div>
                <div id="checkoutSection" style="display:none;"></div>
            </div>

            <!-- Account -->
            <div class="page" id="page-account">
                <h2 style="font-size:var(--fs-xl);font-weight:800;margin-bottom:14px;color:var(--text2);">
                    <span id="accountTitle" data-i18n="account"></span>
                </h2>

                <div class="balance-row" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                    <div class="balance-info" style="display:flex;align-items:center;gap:12px;">
                        <div class="bal-icon" style="width:40px;height:40px;border-radius:50%;background:var(--acSh);display:grid;place-items:center;font-size:var(--fs-xl);color:var(--primary);">
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

                <div class="settings-item" id="favoritesMenuItem">
                    <div class="left">
                        <div class="icon-box" style="background:linear-gradient(135deg, #E91E63, #C2185B);">
                            <i class="fa-solid fa-heart"></i>
                        </div>
                        <div class="text-block">
                            <div class="title" data-i18n="favorites">المفضلة</div>
                            <div class="subtitle" data-i18n="favorites_sub">منتجاتك المفضلة</div>
                        </div>
                    </div>
                    <div class="right">
                        <span class="count-badge" id="favCount"></span>
                        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
                    </div>
                </div>

                <div class="settings-item" id="ordersMenuItem">
                    <div class="left">
                        <div class="icon-box" style="background:linear-gradient(135deg, #058693, #0AA6B5);">
                            <i class="fa-solid fa-box"></i>
                        </div>
                        <div class="text-block">
                            <div class="title" data-i18n="my_orders">طلباتي</div>
                            <div class="subtitle" data-i18n="track_orders">تتبع طلباتك</div>
                        </div>
                    </div>
                    <div class="right">
                        <span class="count-badge" id="orderCount"></span>
                        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
                    </div>
                </div>

                <div class="settings-item" id="rechargeMenuItem">
                    <div class="left">
                        <div class="icon-box" style="background:linear-gradient(135deg, #D8B07A, #C49A5E);">
                            <i class="fa-solid fa-wallet"></i>
                        </div>
                        <div class="text-block">
                            <div class="title" data-i18n="recharge_balance">شحن الرصيد</div>
                            <div class="subtitle" data-i18n="recharge_sub">إضافة رصيد لحسابك</div>
                        </div>
                    </div>
                    <div class="right">
                        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
                    </div>
                </div>

                <div class="settings-item" id="accountSettingsMenuItem">
                    <div class="left">
                        <div class="icon-box" style="background:linear-gradient(135deg, #6C5CE7, #4834D4);">
                            <i class="fa-solid fa-gear"></i>
                        </div>
                        <div class="text-block">
                            <div class="title" data-i18n="account_settings">إعدادات الحساب</div>
                            <div class="subtitle" data-i18n="edit_profile">تعديل الملف الشخصي</div>
                        </div>
                    </div>
                    <div class="right">
                        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
                    </div>
                </div>

                <div class="settings-item" id="chatSupportMenuItem">
                    <div class="left">
                        <div class="icon-box" style="background:linear-gradient(135deg, #00B894, #00A381);">
                            <i class="fa-solid fa-headset"></i>
                        </div>
                        <div class="text-block">
                            <div class="title" data-i18n="support">الدعم الفني</div>
                            <div class="subtitle" data-i18n="contact_support">تواصل مع فريق الدعم</div>
                        </div>
                    </div>
                    <div class="right">
                        <span class="status-pill" data-i18n="connected">متصل</span>
                        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
                    </div>
                </div>

                <div class="settings-item admin-item" id="adminPanelMenuItem" style="display:none;">
                    <div class="left">
                        <div class="icon-box" style="background:linear-gradient(135deg, #D8B07A, #C49A5E);">
                            <i class="fa-solid fa-chart-simple"></i>
                        </div>
                        <div class="text-block">
                            <div class="title" data-i18n="admin_panel">لوحة الإدارة</div>
                            <div class="subtitle" data-i18n="admin_panel_sub">📊 التحكم الكامل بالمتجر</div>
                        </div>
                    </div>
                    <div class="right">
                        <span class="status-pill" style="background:#D8B07A;color:#1a1a2e;">Admin</span>
                        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
                    </div>
                </div>

                <div class="settings-item" id="languageMenuItem" style="margin-top:4px;">
                    <div class="left">
                        <div class="icon-box" style="background:linear-gradient(135deg, #058693, #0AA6B5);">
                            <i class="fa-solid fa-globe"></i>
                        </div>
                        <div class="text-block">
                            <div class="title" data-i18n="language">اللغة</div>
                            <div class="subtitle" data-i18n="language_sub">اختر لغة التطبيق المفضلة</div>
                        </div>
                    </div>
                    <div class="right">
                        <span class="account-lang-display" id="currentLangDisplay"></span>
                        <div class="chevron"><i class="fa-solid fa-chevron-left"></i></div>
                    </div>
                </div>

                <div style="margin-top:20px;border-top:1px solid var(--border);padding-top:16px;">
                    <div class="policy-section">
                        <div class="policy-title" onclick="togglePolicy('privacy')" style="font-size:var(--fs-md);font-weight:700;color:var(--text2);margin-bottom:6px;display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <i class="fa-solid fa-shield-halved" style="color:var(--primary);"></i>
                            <span data-i18n="privacy_policy">سياسة الخصوصية</span>
                            <i class="fa-solid fa-chevron-down" style="margin-right:auto;font-size:var(--fs-sm);color:var(--text3);"></i>
                        </div>
                        <div class="policy-content" id="privacyPolicyContent" style="font-size:var(--fs-base);color:var(--text3);line-height:1.8;max-height:0;overflow:hidden;transition:max-height 0.4s var(--ease-out);">
                            <?php echo nl2br(htmlspecialchars($privacyPolicy)); ?>
                        </div>
                    </div>

                    <div class="policy-section">
                        <div class="policy-title" onclick="togglePolicy('terms')" style="font-size:var(--fs-md);font-weight:700;color:var(--text2);margin-bottom:6px;display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <i class="fa-solid fa-file-contract" style="color:var(--secondary);"></i>
                            <span data-i18n="terms">الشروط والأحكام</span>
                            <i class="fa-solid fa-chevron-down" style="margin-right:auto;font-size:var(--fs-sm);color:var(--text3);"></i>
                        </div>
                        <div class="policy-content" id="termsPolicyContent" style="font-size:var(--fs-base);color:var(--text3);line-height:1.8;max-height:0;overflow:hidden;transition:max-height 0.4s var(--ease-out);">
                            <?php echo nl2br(htmlspecialchars($termsConditions)); ?>
                        </div>
                    </div>

                    <div class="app-version" style="text-align:center;padding:16px 0;color:var(--text3);font-size:var(--fs-sm);">
                        <i class="fa-solid fa-code"></i> <span data-i18n="version">الإصدار</span> <span style="font-weight:700;color:var(--primary);"><?php echo htmlspecialchars($appVersion); ?></span>
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
                    <h2 data-i18n="notifications">الإشعارات</h2>
                </div>
                <div class="settings-page-body">
                    <div id="notificationsList"></div>
                </div>
            </div>

            <!-- Favorites -->
            <div class="favorites-page" id="favoritesPage">
                <div class="detail-header">
                    <button class="back-btn" onclick="closeFavorites()"><i class="fa-solid fa-arrow-right"></i></button>
                    <h2>❤️ <span data-i18n="favorites">المفضلة</span></h2>
                </div>
                <div class="favorites-grid" id="favoritesGrid"></div>
            </div>

            <!-- Orders -->
            <div class="orders-page" id="ordersPage">
                <div class="detail-header">
                    <button class="back-btn" onclick="closeOrders()"><i class="fa-solid fa-arrow-right"></i></button>
                    <h2>📦 <span data-i18n="my_orders">طلباتي</span></h2>
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
                    <h2>💰 <span data-i18n="recharge_balance">شحن الرصيد</span></h2>
                </div>
                <div class="recharge-body" id="rechargeBody">
                    <div id="rechargeForm"></div>
                </div>
            </div>

            <!-- Chat Support -->
            <div class="settings-page" id="page-chat-support">
                <div class="detail-header">
                    <button class="back-btn" onclick="closeChatPage()"><i class="fa-solid fa-arrow-right"></i></button>
                    <h2>💬 <span data-i18n="chat_support">الدردشة مع الدعم</span></h2>
                </div>
                <div class="settings-page-body" id="chatSupportBody"></div>
            </div>

            <!-- Account Settings -->
            <div class="settings-page" id="page-account-settings">
                <div class="detail-header">
                    <button class="back-btn" onclick="closeSettingsPage('page-account-settings')"><i class="fa-solid fa-arrow-right"></i></button>
                    <h2>⚙️ <span data-i18n="account_settings">إعدادات الحساب</span></h2>
                </div>
                <div class="settings-page-body">
<form id="settingsForm" method="POST" enctype="multipart/form-data">
    <div style="text-align:center;margin-bottom:25px;">
        <div style="position:relative;width:90px;height:90px;border-radius:50%;margin:0 auto;overflow:hidden;border:3px solid var(--primary);background:var(--bg2);box-shadow:0 4px 20px rgba(5,134,147,.15);">
            <?php if (!empty($accountUser['avatar_path'])): ?>
                <img src="<?php echo htmlspecialchars(uploadUrl('avatars', $accountUser['avatar_path'])); ?>" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:38px;color:var(--text3);">
                    <i class="fa-regular fa-user"></i>
                </div>
            <?php endif; ?>
            <label for="avatarInput" style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.6);color:#fff;text-align:center;padding:5px;font-size:var(--fs-xs);cursor:pointer;transition:0.3s;">
                <i class="fa-solid fa-camera"></i> تغيير
            </label>
            <input type="file" id="avatarInput" name="avatar" accept="image/*" style="display:none;" onchange="previewAvatar(this)">
        </div>
        <div style="font-size:var(--fs-sm);color:var(--text3);margin-top:5px;">اضغط على الصورة لتغييرها</div>
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label style="display:block;font-size:var(--fs-base);font-weight:600;color:var(--text2);margin-bottom:4px;">
            <i class="fa-regular fa-user" style="color:var(--primary);margin-left:5px;"></i> اسم المستخدم
        </label>
        <input type="text" name="username" value="<?php echo htmlspecialchars($accountUser['name'] ?? ''); ?>" required
               style="width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);outline:none;font-size:var(--fs-md);transition:0.3s;color:var(--text);">
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label style="display:block;font-size:var(--fs-base);font-weight:600;color:var(--text2);margin-bottom:4px;">
            <i class="fa-solid fa-phone" style="color:var(--primary);margin-left:5px;"></i> رقم الهاتف <span style="color:var(--red);">*</span>
        </label>
        <input type="text" name="phone" value="<?php echo htmlspecialchars($accountUser['phone'] ?? ''); ?>" placeholder="مثال: 07700000000" required
               style="width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);outline:none;font-size:var(--fs-md);transition:0.3s;color:var(--text);">
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label style="display:block;font-size:var(--fs-base);font-weight:600;color:var(--text2);margin-bottom:4px;">
            <i class="fa-regular fa-envelope" style="color:var(--primary);margin-left:5px;"></i> البريد الإلكتروني
        </label>
        <input type="email" value="<?php echo htmlspecialchars($accountUser['email'] ?? ''); ?>" disabled
               style="width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:10px;background:#f0f0f0;outline:none;font-size:var(--fs-md);color:#999;cursor:not-allowed;">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($accountUser['email'] ?? ''); ?>">
    </div>

    <div style="display:flex;align-items:center;gap:12px;margin:22px 0 16px;">
        <div style="flex:1;height:1px;background:var(--border);"></div>
        <span style="font-size:var(--fs-sm);color:var(--text3);font-weight:600;white-space:nowrap;">
            <i class="fa-solid fa-lock" style="color:var(--primary);margin-left:4px;"></i> شكرا لثقتكم
        </span>
        <div style="flex:1;height:1px;background:var(--border);"></div>
    </div>

    <button type="button" onclick="saveAccountSettings()" class="btn btn-lg btn-primary btn-block">
        <i class="fa-regular fa-floppy-disk"></i> حفظ التغييرات
    </button>
</form>
                </div>
            </div>

            <!-- Language Settings -->
            <div class="settings-page" id="page-language-settings">
                <div class="detail-header">
                    <button class="back-btn" onclick="closeSettingsPage('page-language-settings')">
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <h2>🌐 <span data-i18n="language">اللغة</span></h2>
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
                    <h2 data-i18n="login">تسجيل الدخول</h2>
                </div>
                <div class="auth-body">
                    <div class="auth-icon"><i class="fa-solid fa-user-circle"></i></div>
                    <h2 style="text-align:center;font-size:var(--fs-2xl);font-weight:800;color:var(--text2);">مرحباً بعودتك</h2>
                    <p style="text-align:center;font-size:var(--fs-md);color:var(--text3);margin-bottom:20px;">سجل دخولك للوصول إلى حسابك والمزايا الحصرية</p>

                    <div id="googleLoginContainer"></div>

                    <div style="text-align:center;margin:12px 0;color:var(--text3);font-size:var(--fs-base);">أو</div>

                    <form id="loginForm">
                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="display:block;font-size:var(--fs-base);font-weight:700;color:var(--text2);margin-bottom:4px;">البريد الإلكتروني أو رقم الهاتف</label>
                            <input type="text" id="loginIdentifier" placeholder="example@email.com أو 07700000000" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:var(--fs-md);outline:none;">
                        </div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="display:block;font-size:var(--fs-base);font-weight:700;color:var(--text2);margin-bottom:4px;">كلمة المرور</label>
                            <input type="password" id="loginPassword" placeholder="••••••••" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:var(--fs-md);outline:none;">
                        </div>
                        <div id="loginError" style="color:var(--red);font-size:var(--fs-base);margin-bottom:12px;display:none;"></div>
                        <button type="button" class="btn-submit" onclick="handleLogin()"><span data-i18n="login">تسجيل الدخول</span></button>
                        <div style="text-align:left;margin-top:8px;font-size:var(--fs-base);">
                            <a onclick="closeLoginPage();openForgotPasswordPage();" style="color:var(--text3);cursor:pointer;text-decoration:underline;">
                                🔑 نسيت كلمة المرور؟
                            </a>
                        </div>
                    </form>
                    <div class="auth-switch" style="text-align:center;margin-top:16px;font-size:var(--fs-md);color:var(--text3);">
                        ليس لديك حساب؟ <a id="switchToRegister" style="color:var(--primary);font-weight:700;cursor:pointer;">سجل الآن</a>
                    </div>
                </div>
            </div>

            <div class="auth-page" id="registerPage">
                <div class="detail-header">
                    <button class="back-btn" onclick="closeRegisterPage()"><i class="fa-solid fa-arrow-right"></i></button>
                    <h2 data-i18n="register">إنشاء حساب</h2>
                </div>
                <div class="auth-body">
                    <div class="auth-icon"><i class="fa-solid fa-user-plus"></i></div>
                    <h2 style="text-align:center;font-size:var(--fs-2xl);font-weight:800;color:var(--text2);">انضم إلينا</h2>
                    <p style="text-align:center;font-size:var(--fs-md);color:var(--text3);margin-bottom:20px;">سجل الآن للاستفادة من جميع المزايا والعروض</p>

                    <div id="googleRegisterContainer"></div>

                    <div style="text-align:center;margin:12px 0;color:var(--text3);font-size:var(--fs-base);">أو</div>

                    <form id="registerForm">
                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="display:block;font-size:var(--fs-base);font-weight:700;color:var(--text2);margin-bottom:4px;">الاسم الكامل</label>
                            <input type="text" id="registerName" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:var(--fs-md);outline:none;">
                        </div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="display:block;font-size:var(--fs-base);font-weight:700;color:var(--text2);margin-bottom:4px;">البريد الإلكتروني</label>
                            <input type="email" id="registerEmail" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:var(--fs-md);outline:none;">
                        </div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="display:block;font-size:var(--fs-base);font-weight:700;color:var(--text2);margin-bottom:4px;">رقم الهاتف</label>
                            <input type="tel" id="registerPhone" placeholder="07XX XXX XXXX" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:var(--fs-md);outline:none;">
                        </div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="display:block;font-size:var(--fs-base);font-weight:700;color:var(--text2);margin-bottom:4px;">كلمة المرور</label>
                            <input type="password" id="registerPassword" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:var(--fs-md);outline:none;">
                        </div>
                        <div id="registerError" style="color:var(--red);font-size:var(--fs-base);margin-bottom:12px;display:none;"></div>
                        <button type="button" class="btn-submit" onclick="handleRegister()"><span data-i18n="register">إنشاء الحساب</span></button>
                    </form>
                    <div class="auth-switch" style="text-align:center;margin-top:16px;font-size:var(--fs-md);color:var(--text3);">
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

                    <div id="resetStep1">
                        <h2 style="text-align:center;font-size:var(--fs-3xl);font-weight:800;color:var(--text2);">استعادة كلمة المرور</h2>
                        <p style="text-align:center;font-size:var(--fs-md);color:var(--text3);margin-bottom:20px;">أدخل بريدك الإلكتروني وسنرسل لك كود التحقق</p>

                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="display:block;font-size:var(--fs-base);font-weight:700;color:var(--text2);margin-bottom:4px;">
                                <i class="fa-solid fa-envelope"></i> البريد الإلكتروني
                            </label>
                            <input type="email" id="resetEmail" placeholder="example@email.com" required
                                   style="width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:var(--fs-md);outline:none;">
                        </div>

                        <div id="resetError" style="color:var(--red);font-size:var(--fs-base);margin-bottom:12px;display:none;text-align:center;"></div>

                        <button type="button" class="btn-submit" onclick="requestPasswordReset()">
                            <i class="fa-solid fa-paper-plane"></i> إرسال كود التحقق
                        </button>

                        <div class="auth-switch" style="text-align:center;margin-top:16px;font-size:var(--fs-md);color:var(--text3);">
                            تذكرت كلمة المرور؟ <a onclick="closeForgotPasswordPage();openLoginPage();" style="color:var(--primary);font-weight:700;cursor:pointer;">تسجيل الدخول</a>
                        </div>
                    </div>

                    <div id="resetStep2" style="display:none;">
                        <h2 style="text-align:center;font-size:var(--fs-3xl);font-weight:800;color:var(--text2);">✅ تحقق من بريدك</h2>
                        <p style="text-align:center;font-size:var(--fs-md);color:var(--text3);margin-bottom:8px;">أدخل رمز التحقق المكون من 6 أرقام المرسل إلى</p>
                        <p style="text-align:center;font-size:var(--fs-md);font-weight:700;color:var(--text2);" id="resetEmailDisplay">example@email.com</p>
                        <p style="text-align:center;font-size:var(--fs-sm);color:var(--text3);margin:4px 0 16px;">⏰ ينتهي خلال 10 دقائق</p>

                        <div class="otp-container" id="resetOtpContainer">
                            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 0)">
                            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 1)">
                            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 2)">
                            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 3)">
                            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 4)">
                            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 5)">
                        </div>

                        <div id="resetCodeError" style="color:var(--red);font-size:var(--fs-base);margin-bottom:12px;display:none;text-align:center;"></div>

                        <button type="button" class="btn-submit" onclick="verifyResetCode()">
                            <i class="fa-solid fa-check"></i> تحقق
                        </button>

                        <div style="text-align:center;margin-top:16px;font-size:var(--fs-base);color:var(--text3);">
                            لم يصل الكود؟ <a onclick="resendResetCode()" style="color:var(--primary);font-weight:700;cursor:pointer;">إعادة الإرسال</a>
                            <span id="resetResendTimer" style="color:var(--text3);font-size:var(--fs-sm);margin-right:4px;"></span>
                        </div>
                    </div>

                    <div id="resetStep3" style="display:none;">
                        <h2 style="text-align:center;font-size:var(--fs-3xl);font-weight:800;color:var(--text2);">🔑 كلمة مرور جديدة</h2>
                        <p style="text-align:center;font-size:var(--fs-md);color:var(--text3);margin-bottom:20px;">أدخل كلمة المرور الجديدة لحسابك</p>

                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="display:block;font-size:var(--fs-base);font-weight:700;color:var(--text2);margin-bottom:4px;">
                                <i class="fa-solid fa-lock"></i> كلمة المرور الجديدة
                            </label>
                            <input type="password" id="resetNewPassword" placeholder="••••••••" required
                                   style="width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:var(--fs-md);outline:none;">
                            <small style="color:var(--text3);font-size:var(--fs-xs);">يجب أن تكون 6 أحرف على الأقل</small>
                        </div>

                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="display:block;font-size:var(--fs-base);font-weight:700;color:var(--text2);margin-bottom:4px;">
                                <i class="fa-solid fa-lock"></i> تأكيد كلمة المرور
                            </label>
                            <input type="password" id="resetConfirmPassword" placeholder="••••••••" required
                                   style="width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:var(--fs-md);outline:none;">
                        </div>

                        <div id="resetPasswordError" style="color:var(--red);font-size:var(--fs-base);margin-bottom:12px;display:none;text-align:center;"></div>

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
                    <h2 style="text-align:center;font-size:var(--fs-2xl);font-weight:800;color:var(--text2);">تحقق من بريدك</h2>
                    <p style="text-align:center;font-size:var(--fs-md);color:var(--text3);margin-bottom:8px;">أدخل رمز التحقق المكون من 6 أرقام المرسل إلى</p>
                    <p style="text-align:center;font-size:var(--fs-md);font-weight:700;color:var(--text2);" id="otpEmailDisplay">example@email.com</p>
                    <p style="text-align:center;font-size:var(--fs-sm);color:var(--text3);margin:4px 0 16px;">⏰ ينتهي خلال 10 دقائق</p>

                    <div class="otp-container" id="otpContainer">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 0)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 1)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 2)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 3)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 4)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 5)">
                    </div>

                    <div id="otpError" style="color:var(--red);font-size:var(--fs-base);margin-bottom:12px;display:none;text-align:center;"></div>
                    <div id="otpSuccess" style="color:var(--green);font-size:var(--fs-base);margin-bottom:12px;display:none;text-align:center;"></div>

                    <button type="button" class="btn-submit" onclick="verifyOTP()">تحقق</button>

                    <div style="text-align:center;margin-top:16px;font-size:var(--fs-base);color:var(--text3);">
                        لم يصل الكود؟ <a id="resendOTP" style="color:var(--primary);font-weight:700;cursor:pointer;" onclick="resendOTP()">إعادة الإرسال</a>
                        <span id="resendTimer" style="color:var(--text3);font-size:var(--fs-sm);margin-right:4px;"></span>
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
                    <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:var(--fs-base);" id="emptyChatMessage">
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

            <!-- Order Success -->
            <div class="order-success-overlay" id="orderSuccessOverlay">
                <div class="order-success-box">
                    <div class="success-icon"><i class="fa-regular fa-circle-check"></i></div>
                    <h3>✅ تم تقديم طلبكم</h3>
                    <p class="order-sub">شكراً لاستخدامكم خدماتنا</p>
                    <button class="btn-close-success" id="closeSuccessBtn" onclick="closeSuccessModal()">اغلاق</button>
                </div>
            </div>

            <!-- Bottom Navigation -->
            <div class="bottom-nav-wrapper" id="bottomNav">
                <nav class="bottom-nav">
                    <button class="nav-item active" data-page="page-home" id="navHome">
                        <i class="fa-solid fa-house"></i>
                        <span data-i18n="home">الرئيسية</span>
                    </button>
                    <button class="nav-item" data-page="page-categories" id="navCategories">
                        <span class="cat-icon"><i class="fa-solid fa-layer-group"></i></span>
                        <span data-i18n="categories">التصنيفات</span>
                    </button>
                    <button class="nav-item" data-page="page-cart" id="navCart">
                        <i class="fa-solid fa-cart-shopping"></i>
                        <span data-i18n="cart">السلة</span>
                        <span class="badge" id="cartBadge" style="display:none;">0</span>
                    </button>
                    <button class="nav-item" data-page="page-account" id="navAccount">
                        <i class="fa-solid fa-user"></i>
                        <span data-i18n="account">الحساب</span>
                    </button>
                </nav>
            </div>

            <!-- PWA install suggestion - appears on its own once the browser
                 signals the site is installable, not a menu item to find. -->
            <div id="pwaInstallBanner" class="pwa-install-banner">
                <div class="icon-box"><i class="fa-solid fa-download"></i></div>
                <div class="text-block">
                    <div class="title">ثبّت التطبيق</div>
                    <div class="subtitle">وصول أسرع من شاشتك الرئيسية</div>
                </div>
                <button class="pwa-install-btn" onclick="installPWA()">تثبيت</button>
                <button class="pwa-dismiss-btn" onclick="dismissPWABanner()" aria-label="إغلاق"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div id="toast"></div>

            <div id="lightbox" onclick="if(event.target===this) closeLightbox()">
                <button class="lightbox-close" onclick="closeLightbox()">✕</button>
                <button class="lightbox-download" onclick="downloadLightboxImage()"><i class="fa-solid fa-download"></i> تحميل</button>
                <div class="lightbox-image-container"><img id="lightboxImage" src="" alt="صورة مكبرة"></div>
            </div>
        </div>
    </div>
</div>

<script>
window.APP_CONFIG = {
    apiUrl: 'api/index.php',
    basePath: '<?php echo APP_BASE_PATH; ?>',
    adminUrl: '<?php echo APP_BASE_PATH; ?>/admin',
    isLoggedIn: <?php echo $isLoggedIn ? 'true' : 'false'; ?>,
    isAdmin: <?php echo $isAdmin ? 'true' : 'false'; ?>,
    isVerified: <?php echo $isVerified ? 'true' : 'false'; ?>,
    paymentMethods: <?php echo json_encode(array_map(fn($p) => $p['image_path'] ? $p + ['image_url' => uploadUrl('payments', $p['image_path'])] : $p, $paymentMethods), JSON_UNESCAPED_UNICODE); ?>,
    rechargeMethods: <?php echo json_encode(array_map(fn($p) => $p['image_path'] ? $p + ['image_url' => uploadUrl('payments', $p['image_path'])] : $p, $rechargeMethods), JSON_UNESCAPED_UNICODE); ?>,
    googleClientId: <?php echo json_encode($googleOAuth['client_id'] ?? ''); ?>,
    googleEnabled: <?php echo !empty($googleOAuth['enabled']) ? 'true' : 'false'; ?>
};
</script>
<?php foreach ($jsFiles as $jsFile): ?>
<script src="<?php echo $assetsBase; ?>/js/<?php echo $jsFile; ?>.js?v=<?php echo CACHE_VERSION; ?>"></script>
<?php endforeach; ?>
</body>
</html>
