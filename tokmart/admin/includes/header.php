<?php
/**
 * Shared admin chrome (sidebar + topbar). Include after admin/includes/bootstrap.php,
 * with $pageTitle and $activeNav already set. admin/includes/footer.php closes it.
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f3d1c">
    <title><?php echo htmlspecialchars($pageTitle); ?> - لوحة التحكم</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $assetsBase; ?>/css/base.css?v=<?php echo CACHE_VERSION; ?>">
    <link rel="stylesheet" href="<?php echo $assetsBase; ?>/css/admin-panel.css?v=<?php echo CACHE_VERSION; ?>">
</head>
<body>
<script>
window.APP_CONFIG = {
    apiUrl: '<?php echo $apiUrl; ?>'
};
</script>
<script src="<?php echo $assetsBase; ?>/js/admin-panel.js?v=<?php echo CACHE_VERSION; ?>"></script>
<div id="adminApp">
    <div id="adminToast"></div>
    <button class="admin-sidebar-toggle" id="sidebarToggle" aria-label="القائمة"><i class="fa-solid fa-bars"></i></button>
    <div class="admin-sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="admin-sidebar" id="adminSidebar">
        <div class="admin-brand">
            <i class="fa-solid fa-store"></i>
            <span><?php echo htmlspecialchars(getSiteSettings()['name']); ?></span>
        </div>

        <nav class="admin-nav">
            <a href="<?php echo $adminBase; ?>" class="admin-nav-item <?php echo $activeNav === 'dashboard' ? 'active' : ''; ?>">
                <i class="fa-solid fa-gauge"></i> <span>لوحة القيادة</span>
            </a>
            <a href="<?php echo $adminBase; ?>/products" class="admin-nav-item <?php echo $activeNav === 'products' ? 'active' : ''; ?>">
                <i class="fa-solid fa-box"></i> <span>المنتجات</span>
            </a>
            <a href="<?php echo $adminBase; ?>/categories" class="admin-nav-item <?php echo $activeNav === 'categories' ? 'active' : ''; ?>">
                <i class="fa-solid fa-tags"></i> <span>التصنيفات</span>
            </a>
            <a href="<?php echo $adminBase; ?>/orders" class="admin-nav-item <?php echo $activeNav === 'orders' ? 'active' : ''; ?>">
                <i class="fa-solid fa-receipt"></i> <span>الطلبات</span>
                <?php if ($pendingOrdersCount > 0): ?><span class="admin-nav-badge"><?php echo $pendingOrdersCount; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo $adminBase; ?>/recharges" class="admin-nav-item <?php echo $activeNav === 'recharges' ? 'active' : ''; ?>">
                <i class="fa-solid fa-wallet"></i> <span>طلبات الشحن</span>
                <?php if ($pendingRechargesCount > 0): ?><span class="admin-nav-badge"><?php echo $pendingRechargesCount; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo $adminBase; ?>/payments" class="admin-nav-item <?php echo $activeNav === 'payments' ? 'active' : ''; ?>">
                <i class="fa-solid fa-credit-card"></i> <span>طرق الدفع</span>
            </a>
            <a href="<?php echo $adminBase; ?>/users" class="admin-nav-item <?php echo $activeNav === 'users' ? 'active' : ''; ?>">
                <i class="fa-solid fa-users"></i> <span>المستخدمين</span>
            </a>
            <a href="<?php echo $adminBase; ?>/chats" class="admin-nav-item <?php echo $activeNav === 'chats' ? 'active' : ''; ?>">
                <i class="fa-solid fa-headset"></i> <span>الدردشة</span>
            </a>
            <a href="<?php echo $adminBase; ?>/settings" class="admin-nav-item <?php echo $activeNav === 'settings' ? 'active' : ''; ?>">
                <i class="fa-solid fa-gear"></i> <span>الإعدادات</span>
            </a>
        </nav>

        <div class="admin-sidebar-footer">
            <a href="<?php echo $homeUrl; ?>" class="admin-nav-item">
                <i class="fa-solid fa-arrow-right-from-bracket fa-rotate-180"></i> <span>العودة للمتجر</span>
            </a>
            <a href="<?php echo $adminBase; ?>/logout" class="admin-nav-item admin-logout" onclick="return confirm('هل تريد تسجيل الخروج؟');">
                <i class="fa-solid fa-right-from-bracket"></i> <span>تسجيل الخروج</span>
            </a>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
            <div class="admin-topbar-user">
                <i class="fa-solid fa-circle-user"></i>
                <span><?php echo htmlspecialchars($adminName); ?></span>
            </div>
        </header>
        <div class="admin-content">
