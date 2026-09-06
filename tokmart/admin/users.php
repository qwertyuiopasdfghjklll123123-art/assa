<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'المستخدمين';
$activeNav = 'users';

$users = query("SELECT id, name, email, phone, balance, isAdmin, isVerified, avatar_path, registeredAt, lastActivity FROM users ORDER BY id DESC");
foreach ($users as &$u) {
    $u['avatar_url'] = $u['avatar_path'] ? uploadUrl('avatars', $u['avatar_path']) : '';
}
unset($u);

require __DIR__ . '/includes/header.php';
?>

<div class="admin-panel-section">
    <div class="admin-panel-section-header">
        <h3><i class="fa-solid fa-users"></i> المستخدمين (<?php echo count($users); ?>)</h3>
    </div>
    <div class="admin-table-wrap">
        <?php if (empty($users)): ?>
            <div class="admin-empty"><i class="fa-solid fa-users"></i>لا يوجد مستخدمين بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th></th><th>الاسم</th><th>البريد الإلكتروني</th><th>الهاتف</th><th>الرصيد</th><th>الحالة</th><th>تاريخ التسجيل</th><th>إجراءات</th></tr></thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div style="width:36px;height:36px;border-radius:50%;overflow:hidden;background:var(--acSh);display:grid;place-items:center;color:var(--primary);">
                            <?php if ($u['avatar_url']): ?>
                                <img src="<?php echo htmlspecialchars($u['avatar_url']); ?>" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <i class="fa-solid fa-user"></i>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($u['name']); ?></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td><?php echo htmlspecialchars($u['phone'] ?? '—'); ?></td>
                    <td><?php echo number_format((float)$u['balance'], 2); ?> د.ع</td>
                    <td>
                        <?php if ($u['isAdmin']): ?><span class="status-pill status-admin">مدير</span><?php else: ?><span class="status-pill status-user">مستخدم</span><?php endif; ?>
                        <?php if ($u['isVerified']): ?><span class="status-pill status-approved">موثق</span><?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($u['registeredAt'] ?? ''); ?></td>
                    <td class="cell-actions">
                        <?php if ((int)$u['id'] !== 1): ?>
                        <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['name'])); ?>')"><i class="fa-solid fa-trash"></i></button>
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
function deleteUser(id, name) {
    confirmAndRun('هل تريد حذف المستخدم "' + name + '"؟ هذا الإجراء لا يمكن التراجع عنه.', function() {
        adminApi('deleteUser', { id: id }, 'POST').then(function(res) {
            if (res.success) {
                adminToast('✅ ' + res.message);
                location.reload();
            } else {
                adminToast('❌ ' + (res.message || 'فشل الحذف'));
            }
        });
    });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
