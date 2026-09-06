<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'طرق الدفع';
$activeNav = 'payments';

$payments = query("SELECT * FROM payments ORDER BY id ASC");
foreach ($payments as &$p) {
    $p['image_url'] = $p['image_path'] ? uploadUrl('payments', $p['image_path']) : '';
}
unset($p);

require __DIR__ . '/includes/header.php';
?>

<div class="admin-panel-section">
    <div class="admin-panel-section-header">
        <h3><i class="fa-solid fa-credit-card"></i> طرق الدفع (<?php echo count($payments); ?>)</h3>
        <button class="btn btn-sm btn-primary" onclick="openAddPayment()"><i class="fa-solid fa-plus"></i> إضافة طريقة دفع</button>
    </div>
    <div class="admin-table-wrap">
        <?php if (empty($payments)): ?>
            <div class="admin-empty"><i class="fa-solid fa-credit-card"></i>لا توجد طرق دفع بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th></th><th>المعرف</th><th>الاسم</th><th>رقم الحساب</th><th>للشحن فقط</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td>
                        <?php if ($p['image_url']): ?>
                            <img class="thumb" src="<?php echo htmlspecialchars($p['image_url']); ?>" alt="">
                        <?php else: ?>
                            <i class="<?php echo htmlspecialchars($p['icon']); ?>" style="font-size:20px;color:var(--primary);"></i>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo htmlspecialchars($p['id']); ?></code></td>
                    <td><?php echo htmlspecialchars($p['name']); ?><br><small style="color:var(--text3);"><?php echo htmlspecialchars($p['nameEn']); ?></small></td>
                    <td><?php echo htmlspecialchars($p['accountNumber'] ?? '—'); ?></td>
                    <td><?php echo $p['isRechargeOnly'] ? 'نعم' : 'لا'; ?></td>
                    <td>
                        <button class="btn btn-sm <?php echo $p['enabled'] ? 'btn-success' : 'btn-outline'; ?>" onclick="togglePayment('<?php echo htmlspecialchars(addslashes($p['id'])); ?>')">
                            <?php echo $p['enabled'] ? 'مفعّل' : 'معطّل'; ?>
                        </button>
                    </td>
                    <td class="cell-actions">
                        <button class="btn btn-sm btn-outline" onclick="editPayment('<?php echo htmlspecialchars(addslashes($p['id'])); ?>')"><i class="fa-solid fa-pen"></i></button>
                        <?php if (!in_array($p['id'], ['cash', 'electronic'], true)): ?>
                        <button class="btn btn-sm btn-danger" onclick="deletePayment('<?php echo htmlspecialchars(addslashes($p['id'])); ?>', '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')"><i class="fa-solid fa-trash"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="admin-modal-overlay" id="paymentModalOverlay">
    <div class="admin-modal">
        <h3 id="paymentModalTitle">💳 إضافة طريقة دفع</h3>
        <form id="paymentForm">
            <input type="hidden" id="editPaymentOldId">
            <div class="form-group"><label>المعرف (ID) *</label><input type="text" id="pmId" placeholder="مثل: zaincash" required></div>
            <div class="form-row">
                <div class="form-group"><label>الاسم (بالعربية) *</label><input type="text" id="pmNameAr" required></div>
                <div class="form-group"><label>الاسم (بالإنجليزية) *</label><input type="text" id="pmNameEnField" required></div>
            </div>
            <div class="form-group"><label>الأيقونة (FontAwesome)</label><input type="text" id="pmIcon" value="fa-solid fa-credit-card"></div>
            <div class="form-row">
                <div class="form-group"><label>رقم الحساب</label><input type="text" id="pmAccountNumber"></div>
                <div class="form-group"><label>المستفيد</label><input type="text" id="pmBeneficiary"></div>
            </div>
            <div class="form-group form-check"><input type="checkbox" id="pmIsRechargeOnly"><label for="pmIsRechargeOnly" style="margin:0;">للشحن فقط (لا يظهر عند الدفع للطلبات)</label></div>
            <div class="form-group">
                <label>صورة الشعار</label>
                <input type="file" id="pmImageFile" accept="image/*">
                <div class="img-preview" id="pmImagePreview"><i class="fa-solid fa-image"></i></div>
            </div>
            <div class="form-error" id="paymentFormError"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-md btn-outline" onclick="closeModal('paymentModalOverlay')">إلغاء</button>
                <button type="button" class="btn btn-md btn-primary" onclick="savePaymentMethod()">حفظ</button>
            </div>
        </form>
    </div>
</div>

<script>
const PAYMENTS = <?php echo json_encode($payments, JSON_UNESCAPED_UNICODE); ?>;
previewImageInput($('pmImageFile'), 'pmImagePreview');

function openAddPayment() {
    $('paymentModalTitle').textContent = '💳 إضافة طريقة دفع';
    $('paymentForm').reset();
    $('editPaymentOldId').value = '';
    $('pmId').disabled = false;
    $('pmImagePreview').innerHTML = '<i class="fa-solid fa-image"></i>';
    hideFormError('paymentFormError');
    openModal('paymentModalOverlay');
}

function editPayment(id) {
    const p = PAYMENTS.find(function(x) { return x.id === id; });
    if (!p) return;

    $('paymentModalTitle').textContent = '💳 تعديل طريقة الدفع';
    $('editPaymentOldId').value = p.id;
    $('pmId').value = p.id;
    $('pmNameAr').value = p.name || '';
    $('pmNameEnField').value = p.nameEn || '';
    $('pmIcon').value = p.icon || 'fa-solid fa-credit-card';
    $('pmAccountNumber').value = p.accountNumber || '';
    $('pmBeneficiary').value = p.beneficiary || '';
    $('pmIsRechargeOnly').checked = !!(p.isRechargeOnly * 1);
    $('pmImageFile').value = '';
    $('pmImagePreview').innerHTML = p.image_url ? '<img src="' + p.image_url + '">' : '<i class="fa-solid fa-image"></i>';

    hideFormError('paymentFormError');
    openModal('paymentModalOverlay');
}

function savePaymentMethod() {
    hideFormError('paymentFormError');
    const oldId = $('editPaymentOldId').value;
    const id = $('pmId').value.trim();
    const name = $('pmNameAr').value.trim();
    const nameEn = $('pmNameEnField').value.trim();

    if (!id || !name || !nameEn) {
        showFormError('paymentFormError', '⚠️ الرجاء تعبئة المعرف والاسم بالعربية والإنجليزية');
        return;
    }

    const formData = new FormData();
    formData.append('id', id);
    if (oldId) formData.append('oldId', oldId);
    formData.append('name', name);
    formData.append('nameEn', nameEn);
    formData.append('icon', $('pmIcon').value.trim() || 'fa-solid fa-credit-card');
    formData.append('accountNumber', $('pmAccountNumber').value);
    formData.append('beneficiary', $('pmBeneficiary').value);
    formData.append('isRechargeOnly', $('pmIsRechargeOnly').checked ? '1' : '0');

    const imageFile = $('pmImageFile').files[0];
    if (imageFile) formData.append('image', imageFile);

    adminApi(oldId ? 'updatePayment' : 'addPayment', formData, 'POST').then(function(res) {
        if (res.success) {
            adminToast('✅ ' + res.message);
            closeModal('paymentModalOverlay');
            location.reload();
        } else {
            showFormError('paymentFormError', '❌ ' + (res.message || 'حدث خطأ'));
        }
    });
}

function togglePayment(id) {
    adminApi('togglePayment', { id: id }, 'POST').then(function(res) {
        if (res.success) {
            adminToast('✅ ' + res.message);
            location.reload();
        } else {
            adminToast('❌ ' + (res.message || 'فشل التحديث'));
        }
    });
}

function deletePayment(id, name) {
    confirmAndRun('هل تريد حذف طريقة الدفع "' + name + '"؟', function() {
        adminApi('deletePayment', { id: id }, 'POST').then(function(res) {
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
