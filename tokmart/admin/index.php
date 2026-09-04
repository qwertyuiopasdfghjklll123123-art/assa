<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireAdminPage();

$stats = [
    'products' => queryOne("SELECT COUNT(*) as c FROM products")['c'] ?? 0,
    'categories' => queryOne("SELECT COUNT(*) as c FROM categories")['c'] ?? 0,
    'orders' => queryOne("SELECT COUNT(*) as c FROM orders")['c'] ?? 0,
    'users' => queryOne("SELECT COUNT(*) as c FROM users WHERE isAdmin = 0")['c'] ?? 0,
    'pendingOrders' => queryOne("SELECT COUNT(*) as c FROM orders WHERE status = 'pending'")['c'] ?? 0,
    'pendingRecharges' => queryOne("SELECT COUNT(*) as c FROM balance_recharges WHERE status = 'pending'")['c'] ?? 0,
    'revenue' => queryOne("SELECT COALESCE(SUM(total),0) as s FROM orders WHERE status IN ('completed','approved','shipped')")['s'] ?? 0,
];

$recentOrders = query("SELECT o.*, u.name as userName FROM orders o LEFT JOIN users u ON u.id = o.userId ORDER BY o.id DESC LIMIT 8");

$statusLabels = [
    'pending' => 'قيد المعالجة', 'shipped' => 'تم الشحن', 'completed' => 'مكتمل',
    'cancelled' => 'ملغي', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوض',
];

$pageTitle = 'لوحة القيادة';
$activeNav = 'dashboard';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="icon" style="background:linear-gradient(135deg,#058693,#0AA6B5);"><i class="fa-solid fa-box"></i></div>
        <div><div class="num"><?php echo (int)$stats['products']; ?></div><div class="label">المنتجات</div></div>
    </div>
    <div class="stat-card">
        <div class="icon" style="background:linear-gradient(135deg,#D8B07A,#C49A5E);"><i class="fa-solid fa-tags"></i></div>
        <div><div class="num"><?php echo (int)$stats['categories']; ?></div><div class="label">التصنيفات</div></div>
    </div>
    <div class="stat-card">
        <div class="icon" style="background:linear-gradient(135deg,#3498DB,#2980B9);"><i class="fa-solid fa-receipt"></i></div>
        <div><div class="num"><?php echo (int)$stats['orders']; ?></div><div class="label">الطلبات (<?php echo (int)$stats['pendingOrders']; ?> قيد الانتظار)</div></div>
    </div>
    <div class="stat-card">
        <div class="icon" style="background:linear-gradient(135deg,#6C5CE7,#4834D4);"><i class="fa-solid fa-users"></i></div>
        <div><div class="num"><?php echo (int)$stats['users']; ?></div><div class="label">المستخدمون</div></div>
    </div>
    <div class="stat-card">
        <div class="icon" style="background:linear-gradient(135deg,#2ECC71,#27AE60);"><i class="fa-solid fa-sack-dollar"></i></div>
        <div><div class="num"><?php echo number_format((float)$stats['revenue'], 0); ?></div><div class="label">إجمالي المبيعات (د.ع)</div></div>
    </div>
    <div class="stat-card">
        <div class="icon" style="background:linear-gradient(135deg,#FF9A3D,#E67E22);"><i class="fa-solid fa-wallet"></i></div>
        <div><div class="num"><?php echo (int)$stats['pendingRecharges']; ?></div><div class="label">طلبات شحن بانتظار المراجعة</div></div>
    </div>
</div>

<div class="card">
    <h2><i class="fa-solid fa-clock-rotate-left"></i> أحدث الطلبات</h2>
    <div class="table-wrap">
        <?php if (empty($recentOrders)): ?>
            <div class="empty-state"><i class="fa-solid fa-inbox"></i>لا توجد طلبات بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>#</th><th>العميل</th><th>الإجمالي</th><th>الدفع</th><th>الحالة</th><th>التاريخ</th></tr></thead>
            <tbody>
            <?php foreach ($recentOrders as $o): ?>
                <tr>
                    <td><?php echo htmlspecialchars($o['orderId']); ?></td>
                    <td><?php echo htmlspecialchars($o['userName'] ?? '—'); ?></td>
                    <td><?php echo number_format((float)$o['total'], 2); ?> د.ع</td>
                    <td><?php echo htmlspecialchars($o['payment']); ?></td>
                    <td><span class="pill pill-<?php echo htmlspecialchars($o['status']); ?>"><?php echo $statusLabels[$o['status']] ?? $o['status']; ?></span></td>
                    <td><?php echo htmlspecialchars((string)$o['date']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div style="margin-top:14px;"><a href="orders.php" class="btn btn-outline">عرض جميع الطلبات <i class="fa-solid fa-arrow-left"></i></a></div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
