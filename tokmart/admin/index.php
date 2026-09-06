<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'لوحة القيادة';
$activeNav = 'dashboard';

$stats = [
    'products' => (int)(queryOne("SELECT COUNT(*) as c FROM products")['c'] ?? 0),
    'categories' => (int)(queryOne("SELECT COUNT(*) as c FROM categories")['c'] ?? 0),
    'orders' => (int)(queryOne("SELECT COUNT(*) as c FROM orders")['c'] ?? 0),
    'users' => (int)(queryOne("SELECT COUNT(*) as c FROM users WHERE isAdmin = 0")['c'] ?? 0),
];

$recentOrders = query("SELECT * FROM orders ORDER BY id DESC LIMIT 8");
$recentRecharges = query("
    SELECT r.*, u.name as userName FROM balance_recharges r
    LEFT JOIN users u ON u.id = r.userId
    ORDER BY r.id DESC LIMIT 8
");

$statusLabels = [
    'pending' => 'قيد المعالجة', 'shipped' => 'تم الشحن', 'completed' => 'مكتمل',
    'cancelled' => 'ملغي', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوض',
];

require __DIR__ . '/includes/header.php';
?>

<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="icon"><i class="fa-solid fa-box"></i></div>
        <div><div class="num"><?php echo $stats['products']; ?></div><div class="label">المنتجات</div></div>
    </div>
    <div class="admin-stat-card">
        <div class="icon"><i class="fa-solid fa-tags"></i></div>
        <div><div class="num"><?php echo $stats['categories']; ?></div><div class="label">التصنيفات</div></div>
    </div>
    <div class="admin-stat-card">
        <div class="icon"><i class="fa-solid fa-receipt"></i></div>
        <div><div class="num"><?php echo $stats['orders']; ?></div><div class="label">الطلبات</div></div>
    </div>
    <div class="admin-stat-card">
        <div class="icon"><i class="fa-solid fa-users"></i></div>
        <div><div class="num"><?php echo $stats['users']; ?></div><div class="label">المستخدمين</div></div>
    </div>
</div>

<div class="admin-panel-section">
    <div class="admin-panel-section-header">
        <h3><i class="fa-solid fa-receipt"></i> أحدث الطلبات</h3>
        <a href="orders.php" class="btn btn-sm btn-outline">عرض الكل</a>
    </div>
    <div class="admin-table-wrap">
        <?php if (empty($recentOrders)): ?>
            <div class="admin-empty"><i class="fa-regular fa-receipt"></i>لا توجد طلبات بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>#</th><th>الزبون</th><th>المبلغ</th><th>الدفع</th><th>الحالة</th><th>التاريخ</th></tr></thead>
            <tbody>
                <?php foreach ($recentOrders as $o): ?>
                <tr>
                    <td>#<?php echo htmlspecialchars($o['orderId'] ?? $o['id']); ?></td>
                    <td><?php echo htmlspecialchars($o['phone'] ?? ''); ?></td>
                    <td><?php echo number_format((float)$o['total'], 2); ?> د.ع</td>
                    <td><?php echo htmlspecialchars($o['payment'] ?? ''); ?></td>
                    <td><span class="status-pill status-<?php echo htmlspecialchars($o['status']); ?>"><?php echo $statusLabels[$o['status']] ?? htmlspecialchars($o['status']); ?></span></td>
                    <td><?php echo htmlspecialchars($o['date'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="admin-panel-section">
    <div class="admin-panel-section-header">
        <h3><i class="fa-solid fa-wallet"></i> أحدث طلبات الشحن</h3>
        <a href="recharges.php" class="btn btn-sm btn-outline">عرض الكل</a>
    </div>
    <div class="admin-table-wrap">
        <?php if (empty($recentRecharges)): ?>
            <div class="admin-empty"><i class="fa-regular fa-clock"></i>لا توجد طلبات شحن بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>المستخدم</th><th>المبلغ</th><th>الحالة</th><th>التاريخ</th></tr></thead>
            <tbody>
                <?php foreach ($recentRecharges as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['userName'] ?? '—'); ?></td>
                    <td><?php echo number_format((float)$r['amount'], 2); ?> د.ع</td>
                    <td><span class="status-pill status-<?php echo htmlspecialchars($r['status']); ?>"><?php echo $statusLabels[$r['status']] ?? htmlspecialchars($r['status']); ?></span></td>
                    <td><?php echo htmlspecialchars($r['createdAt']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
