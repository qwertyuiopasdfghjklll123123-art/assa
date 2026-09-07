<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'طلبات الشحن';
$activeNav = 'recharges';

$recharges = query("
    SELECT r.*, u.name as userName, u.phone as userPhone
    FROM balance_recharges r LEFT JOIN users u ON u.id = r.userId
    ORDER BY r.id DESC
");
foreach ($recharges as &$r) {
    $r['receiptImage_url'] = $r['receiptImage'] ? uploadUrl('receipts', $r['receiptImage']) : '';
}
unset($r);

$statusLabels = ['pending' => 'قيد المعالجة', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوض'];

require __DIR__ . '/includes/header.php';
?>

<div class="admin-panel-section">
    <div class="admin-panel-section-header">
        <h3><i class="fa-solid fa-wallet"></i> طلبات شحن الرصيد (<?php echo count($recharges); ?>)</h3>
    </div>
    <div class="admin-table-wrap">
        <?php if (empty($recharges)): ?>
            <div class="admin-empty"><i class="fa-regular fa-clock"></i>لا توجد طلبات شحن بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>المستخدم</th><th>المبلغ</th><th>طريقة الدفع</th><th>الإيصال</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
            <tbody>
                <?php foreach ($recharges as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['userName'] ?? '—'); ?><br><small style="color:var(--text3);"><?php echo htmlspecialchars($r['userPhone'] ?? ''); ?></small></td>
                    <td><strong><?php echo number_format((float)$r['amount'], 0); ?> د.ع</strong></td>
                    <td><?php echo htmlspecialchars($r['paymentMethod'] ?? ''); ?></td>
                    <td>
                        <?php if ($r['receiptImage_url']): ?>
                        <a href="<?php echo htmlspecialchars($r['receiptImage_url']); ?>" target="_blank"><img class="thumb" src="<?php echo htmlspecialchars($r['receiptImage_url']); ?>" alt=""></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><span class="status-pill status-<?php echo htmlspecialchars($r['status']); ?>" id="rstatus-<?php echo (int)$r['id']; ?>"><?php echo $statusLabels[$r['status']] ?? htmlspecialchars($r['status']); ?></span></td>
                    <td><?php echo htmlspecialchars($r['createdAt']); ?></td>
                    <td class="cell-actions">
                        <?php if ($r['status'] === 'pending'): ?>
                        <button class="btn btn-sm btn-success" onclick="setRechargeStatus(<?php echo (int)$r['id']; ?>, 'approved')"><i class="fa-solid fa-check"></i> قبول</button>
                        <button class="btn btn-sm btn-danger" onclick="setRechargeStatus(<?php echo (int)$r['id']; ?>, 'rejected')"><i class="fa-solid fa-xmark"></i> رفض</button>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
const STATUS_LABELS = <?php echo json_encode($statusLabels, JSON_UNESCAPED_UNICODE); ?>;

function setRechargeStatus(id, status) {
    const label = status === 'approved' ? 'قبول' : 'رفض';
    confirmAndRun('هل تريد ' + label + ' طلب الشحن هذا؟', function() {
        adminApi('updateRechargeStatus', { id: id, status: status }, 'POST').then(function(res) {
            if (res.success) {
                adminToast('✅ ' + res.message);
                location.reload();
            } else {
                adminToast('❌ ' + (res.message || 'فشل التحديث'));
            }
        });
    });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
