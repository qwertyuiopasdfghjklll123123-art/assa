<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'الطلبات';
$activeNav = 'orders';

$orders = query("
    SELECT o.*, u.name as userName, u.phone as userPhone
    FROM orders o LEFT JOIN users u ON u.id = o.userId
    ORDER BY o.id DESC
");
foreach ($orders as &$o) {
    $items = json_decode($o['items'] ?? '[]', true);
    $o['items'] = is_array($items) ? $items : [];
    $o['transferImage_url'] = $o['transferImage'] ? uploadUrl('transfers', $o['transferImage']) : '';
}
unset($o);

$statusLabels = [
    'pending' => 'قيد المعالجة', 'shipped' => 'تم الشحن', 'completed' => 'مكتمل',
    'cancelled' => 'ملغي', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوض',
];

require __DIR__ . '/includes/header.php';
?>

<div class="admin-panel-section">
    <div class="admin-panel-section-header">
        <h3><i class="fa-solid fa-receipt"></i> الطلبات (<?php echo count($orders); ?>)</h3>
    </div>
    <div class="admin-table-wrap">
        <?php if (empty($orders)): ?>
            <div class="admin-empty"><i class="fa-regular fa-receipt"></i>لا توجد طلبات بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>رقم الطلب</th><th>الزبون</th><th>الهاتف</th><th>المبلغ</th><th>الدفع</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td>#<?php echo htmlspecialchars($o['orderId'] ?? $o['id']); ?></td>
                    <td><?php echo htmlspecialchars($o['userName'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($o['phone'] ?? ''); ?></td>
                    <td><?php echo number_format((float)$o['total'], 2); ?> د.ع</td>
                    <td><?php echo htmlspecialchars($o['payment'] ?? ''); ?></td>
                    <td>
                        <select class="status-select" onchange="updateOrderStatus(<?php echo (int)$o['id']; ?>, this.value)">
                            <?php foreach ($statusLabels as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $o['status'] === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><?php echo htmlspecialchars($o['date'] ?? ''); ?></td>
                    <td class="cell-actions">
                        <button class="btn btn-sm btn-outline" onclick="viewOrder(<?php echo (int)$o['id']; ?>)"><i class="fa-solid fa-eye"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="admin-modal-overlay" id="orderModalOverlay">
    <div class="admin-modal" id="orderModalContent"></div>
</div>

<script>
const ORDERS = <?php echo json_encode($orders, JSON_UNESCAPED_UNICODE); ?>;
const STATUS_LABELS = <?php echo json_encode($statusLabels, JSON_UNESCAPED_UNICODE); ?>;

function updateOrderStatus(id, status) {
    adminApi('updateOrderStatus', { id: id, status: status }, 'POST').then(function(res) {
        if (res.success) {
            adminToast('✅ ' + res.message);
        } else {
            adminToast('❌ ' + (res.message || 'فشل التحديث'));
        }
    });
}

function viewOrder(id) {
    const o = ORDERS.find(function(x) { return x.id === id; });
    if (!o) return;

    let itemsHtml = (o.items || []).map(function(it) {
        return '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed var(--border);font-size:13px;">' +
            '<span>' + (it.name || '') + ' × ' + (it.qty || 1) + '</span>' +
            '<span>' + (parseFloat(it.price || 0) * (it.qty || 1)).toFixed(2) + ' د.ع</span></div>';
    }).join('') || '<div style="color:var(--text3);">لا توجد عناصر</div>';

    let transferHtml = '';
    if (o.transferImage_url) {
        transferHtml = '<div class="form-group"><label>صورة التحويل</label>' +
            '<img src="' + o.transferImage_url + '" style="width:100%;max-height:260px;object-fit:contain;border-radius:10px;border:1px solid var(--border);">' +
            (o.transferAmount ? '<small>المبلغ المحول: ' + parseFloat(o.transferAmount).toFixed(2) + ' د.ع</small>' : '') +
            '</div>';
    }

    $('orderModalContent').innerHTML =
        '<h3>📋 طلب #' + (o.orderId || o.id) + '</h3>' +
        '<div class="form-group"><label>الزبون</label><div>' + (o.userName || '—') + ' — ' + (o.phone || '') + '</div></div>' +
        '<div class="form-group"><label>العنوان</label><div>' + (o.address || '—') + '</div></div>' +
        '<div class="form-group"><label>طريقة الدفع</label><div>' + (o.payment || '') + '</div></div>' +
        '<div class="form-group"><label>الحالة</label><div><span class="status-pill status-' + o.status + '">' + (STATUS_LABELS[o.status] || o.status) + '</span></div></div>' +
        '<div class="form-group"><label>العناصر</label>' + itemsHtml + '</div>' +
        '<div class="form-group"><label>الإجمالي</label><div style="font-weight:900;font-size:18px;color:var(--primary);">' + parseFloat(o.total).toFixed(2) + ' د.ع</div></div>' +
        transferHtml +
        '<div class="form-actions"><button type="button" class="btn btn-md btn-outline" onclick="closeModal(\'orderModalOverlay\')">إغلاق</button></div>';

    openModal('orderModalOverlay');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
