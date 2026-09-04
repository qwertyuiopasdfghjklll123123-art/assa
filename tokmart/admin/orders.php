<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireAdminPage();

$statusFilter = $_GET['status'] ?? '';
$sql = "SELECT o.*, u.name as userName, u.phone as userPhone FROM orders o LEFT JOIN users u ON u.id = o.userId";
$params = [];
if ($statusFilter !== '') {
    $sql .= " WHERE o.status = :status";
    $params[':status'] = $statusFilter;
}
$sql .= " ORDER BY o.id DESC LIMIT 200";
$orders = query($sql, $params);

$statusLabels = [
    'pending' => 'قيد المعالجة', 'shipped' => 'تم الشحن', 'completed' => 'مكتمل',
    'cancelled' => 'ملغي', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوض',
];

$pageTitle = 'الطلبات';
$activeNav = 'orders';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
    <h2 style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <span><i class="fa-solid fa-receipt"></i> الطلبات (<?php echo count($orders); ?>)</span>
        <form method="get" style="display:flex;gap:8px;align-items:center;">
            <select name="status" onchange="this.form.submit()" style="padding:7px 10px;border:2px solid var(--border);border-radius:8px;background:var(--bg2);font-size:12px;">
                <option value="">كل الحالات</option>
                <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $statusFilter === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </h2>
    <div class="table-wrap">
        <?php if (empty($orders)): ?>
            <div class="empty-state"><i class="fa-solid fa-inbox"></i>لا توجد طلبات</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>رقم الطلب</th><th>العميل</th><th>الهاتف</th><th>الإجمالي</th><th>الدفع</th><th>التاريخ</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?php echo htmlspecialchars($o['orderId']); ?></td>
                    <td><?php echo htmlspecialchars($o['userName'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($o['userPhone'] ?? $o['phone'] ?? '—'); ?></td>
                    <td><?php echo number_format((float)$o['total'], 2); ?> د.ع</td>
                    <td><?php echo htmlspecialchars($o['payment']); ?></td>
                    <td><?php echo htmlspecialchars((string)$o['date']); ?></td>
                    <td><span class="pill pill-<?php echo htmlspecialchars($o['status']); ?>"><?php echo $statusLabels[$o['status']] ?? $o['status']; ?></span></td>
                    <td>
                        <select onchange="updateOrderStatus(<?php echo (int)$o['id']; ?>, this.value)" style="padding:5px 8px;border:2px solid var(--border);border-radius:6px;font-size:11px;">
                            <option value="">تغيير الحالة</option>
                            <?php foreach ($statusLabels as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline btn-sm" onclick='viewOrderDetail(<?php echo json_encode($o, JSON_UNESCAPED_UNICODE); ?>)'><i class="fa-solid fa-eye"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="orderModal">
    <div class="modal-box" style="max-width:640px;">
        <h3>تفاصيل الطلب</h3>
        <div id="orderDetailBody"></div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('orderModal')">إغلاق</button>
        </div>
    </div>
</div>

<script>
const STATUS_LABELS = <?php echo json_encode($statusLabels, JSON_UNESCAPED_UNICODE); ?>;

function updateOrderStatus(id, status) {
    if (!status) return;
    adminPostAction('updateOrderStatus', { id: id, status: status }).then(function(res) {
        showAdminToast(res.message || (res.success ? 'تم التحديث' : 'حدث خطأ'));
        if (res.success) setTimeout(() => window.location.reload(), 600);
    });
}

function viewOrderDetail(order) {
    let items = [];
    try { items = JSON.parse(order.items || '[]'); } catch (e) { items = []; }
    let itemsHtml = items.map(function(it) {
        return '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">' +
            '<span>' + (it.name || it.nameEn || 'منتج') + ' × ' + (it.qty || 1) + '</span>' +
            '<span>' + (Number(it.price || 0) * Number(it.qty || 1)).toFixed(2) + ' د.ع</span></div>';
    }).join('') || '<p style="color:var(--text3);font-size:13px;">لا توجد تفاصيل منتجات</p>';

    document.getElementById('orderDetailBody').innerHTML =
        '<div style="font-size:13px;line-height:2;">' +
        '<p><b>رقم الطلب:</b> ' + order.orderId + '</p>' +
        '<p><b>العنوان:</b> ' + (order.address || '—') + '</p>' +
        '<p><b>الهاتف:</b> ' + (order.phone || '—') + '</p>' +
        '<p><b>طريقة الدفع:</b> ' + order.payment + '</p>' +
        '<p><b>الحالة:</b> ' + (STATUS_LABELS[order.status] || order.status) + '</p>' +
        '</div><hr style="margin:12px 0;border-color:var(--border);">' + itemsHtml +
        '<div style="display:flex;justify-content:space-between;padding-top:10px;font-weight:800;">' +
        '<span>الإجمالي</span><span>' + Number(order.total).toFixed(2) + ' د.ع</span></div>';

    openModal('orderModal');
}
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
