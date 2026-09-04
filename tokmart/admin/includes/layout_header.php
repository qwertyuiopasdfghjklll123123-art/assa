<?php
/**
 * Shared admin layout header. The calling page must set $pageTitle, $activeNav
 * before requiring this file, and require bootstrap.php + requireAdminPage() first.
 */

$pendingOrders = queryOne("SELECT COUNT(*) as c FROM orders WHERE status = 'pending'")['c'] ?? 0;
$pendingRecharges = queryOne("SELECT COUNT(*) as c FROM balance_recharges WHERE status = 'pending'")['c'] ?? 0;
$unreadChats = queryOne("SELECT COUNT(*) as c FROM chats WHERE sender = 'user' AND isRead = 0")['c'] ?? 0;

$adminSite = getSiteSettings();
$navItems = [
    ['key' => 'dashboard', 'href' => 'index.php', 'icon' => 'fa-chart-line', 'label' => 'لوحة القيادة'],
    ['key' => 'products', 'href' => 'products.php', 'icon' => 'fa-box', 'label' => 'المنتجات'],
    ['key' => 'categories', 'href' => 'categories.php', 'icon' => 'fa-tags', 'label' => 'التصنيفات'],
    ['key' => 'orders', 'href' => 'orders.php', 'icon' => 'fa-receipt', 'label' => 'الطلبات', 'badge' => $pendingOrders],
    ['key' => 'recharges', 'href' => 'recharges.php', 'icon' => 'fa-wallet', 'label' => 'طلبات الشحن', 'badge' => $pendingRecharges],
    ['key' => 'payments', 'href' => 'payments.php', 'icon' => 'fa-credit-card', 'label' => 'طرق الدفع'],
    ['key' => 'users', 'href' => 'users.php', 'icon' => 'fa-users', 'label' => 'المستخدمون'],
    ['key' => 'chats', 'href' => 'chats.php', 'icon' => 'fa-headset', 'label' => 'الدردشة', 'badge' => $unreadChats],
    ['key' => 'settings', 'href' => 'settings.php', 'icon' => 'fa-gear', 'label' => 'الإعدادات'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle . ' - لوحة تحكم ' . $adminSite['name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo APP_BASE_PATH; ?>/assets/css/admin-panel.css?v=<?php echo CACHE_VERSION; ?>">
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="brand">
            <?php if ($adminSite['logo_url']): ?>
                <img src="<?php echo htmlspecialchars($adminSite['logo_url']); ?>" alt="">
            <?php else: ?>
                <i class="fa-solid fa-store"></i>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($adminSite['name']); ?></span>
        </div>
        <nav class="admin-nav">
            <?php foreach ($navItems as $item): ?>
            <a href="<?php echo $item['href']; ?>" class="<?php echo $activeNav === $item['key'] ? 'active' : ''; ?>">
                <i class="fa-solid <?php echo $item['icon']; ?>"></i>
                <span><?php echo $item['label']; ?></span>
                <?php if (!empty($item['badge'])): ?><span class="badge"><?php echo (int)$item['badge']; ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <a href="<?php echo APP_BASE_PATH; ?>/index.php" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> عرض المتجر</a>
            <a href="#" onclick="adminLogout(); return false;"><i class="fa-solid fa-right-from-bracket"></i> تسجيل الخروج</a>
        </div>
    </aside>

    <div class="admin-main">
        <div class="admin-topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="menu-toggle" onclick="document.getElementById('adminSidebar').classList.toggle('open')"><i class="fa-solid fa-bars"></i></button>
                <div>
                    <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                    <div class="sub">مرحباً، <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'المدير'); ?></div>
                </div>
            </div>
        </div>
        <div class="admin-content">
