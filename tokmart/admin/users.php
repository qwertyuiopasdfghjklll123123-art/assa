<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireAdminPage();

$users = query("SELECT id, name, email, phone, balance, isAdmin, adminType, avatar_path, registeredAt, isVerified FROM users ORDER BY id DESC");

$pageTitle = 'المستخدمون';
$activeNav = 'users';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
    <h2><i class="fa-solid fa-users"></i> المستخدمون (<?php echo count($users); ?>)</h2>
    <div class="table-wrap">
        <?php if (empty($users)): ?>
            <div class="empty-state"><i class="fa-solid fa-users"></i>لا يوجد مستخدمون</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>الصورة</th><th>الاسم</th><th>البريد</th><th>الهاتف</th><th>الرصيد</th><th>النوع</th><th>موثق</th><th>تاريخ التسجيل</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><img class="thumb" style="border-radius:50%;" src="<?php echo htmlspecialchars(uploadUrl('avatars', $u['avatar_path']) ?: 'https://via.placeholder.com/40'); ?>" alt=""></td>
                    <td><?php echo htmlspecialchars($u['name']); ?></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td><?php echo htmlspecialchars($u['phone'] ?? '—'); ?></td>
                    <td><?php echo number_format((float)$u['balance'], 2); ?> د.ع</td>
                    <td><?php if ($u['isAdmin']): ?><span class="pill pill-admin">مدير</span><?php else: ?><span class="pill">مستخدم</span><?php endif; ?></td>
                    <td><?php echo $u['isVerified'] ? '✅' : '—'; ?></td>
                    <td><?php echo htmlspecialchars(substr((string)$u['registeredAt'], 0, 10)); ?></td>
                    <td>
                        <?php if (!$u['isAdmin'] && (int)$u['id'] !== 1): ?>
                        <button class="btn btn-danger btn-sm" onclick="adminDeleteRow('deleteUser', <?php echo (int)$u['id']; ?>, 'حذف هذا المستخدم نهائياً؟')"><i class="fa-solid fa-trash"></i></button>
                        <?php else: ?>
                        <span style="color:var(--text3);font-size:11px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
