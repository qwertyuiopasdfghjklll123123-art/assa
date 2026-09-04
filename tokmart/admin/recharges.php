<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireAdminPage();

$recharges = query(
    "SELECT r.*, u.name as userName FROM balance_recharges r LEFT JOIN users u ON u.id = r.userId ORDER BY r.id DESC LIMIT 200"
);

$statusLabels = ['pending' => 'قيد الانتظار', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوض'];

$pageTitle = 'طلبات الشحن';
$activeNav = 'recharges';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
    <h2><i class="fa-solid fa-wallet"></i> طلبات شحن الرصيد (<?php echo count($recharges); ?>)</h2>
    <div class="table-wrap">
        <?php if (empty($recharges)): ?>
            <div class="empty-state"><i class="fa-solid fa-wallet"></i>لا توجد طلبات شحن</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>المستخدم</th><th>المبلغ</th><th>طريقة الدفع</th><th>الإيصال</th><th>التاريخ</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($recharges as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['userName'] ?? '—'); ?></td>
                    <td><?php echo number_format((float)$r['amount'], 2); ?> د.ع</td>
                    <td><?php echo htmlspecialchars($r['paymentMethod']); ?></td>
                    <td>
                        <?php if ($r['receiptImage']): ?>
                        <a href="<?php echo htmlspecialchars(uploadUrl('receipts', $r['receiptImage'])); ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fa-solid fa-image"></i> عرض</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars(substr((string)$r['createdAt'], 0, 16)); ?></td>
                    <td><span class="pill pill-<?php echo htmlspecialchars($r['status']); ?>"><?php echo $statusLabels[$r['status']] ?? $r['status']; ?></span></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <button class="btn btn-success btn-sm" onclick="reviewRecharge(<?php echo (int)$r['id']; ?>, 'approved')"><i class="fa-solid fa-check"></i> قبول</button>
                        <button class="btn btn-danger btn-sm" onclick="reviewRecharge(<?php echo (int)$r['id']; ?>, 'rejected')"><i class="fa-solid fa-xmark"></i> رفض</button>
                        <?php else: ?>
                        <span style="color:var(--text3);font-size:11px;">تمت المراجعة</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
function reviewRecharge(id, status) {
    const msg = status === 'approved' ? 'تأكيد إضافة الرصيد لحساب المستخدم؟' : 'تأكيد رفض طلب الشحن؟';
    if (!adminConfirm(msg)) return;
    adminPostAction('updateRechargeStatus', { id: id, status: status }).then(function(res) {
        showAdminToast(res.message || (res.success ? 'تم التحديث' : 'حدث خطأ'));
        if (res.success) setTimeout(() => window.location.reload(), 600);
    });
}
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
