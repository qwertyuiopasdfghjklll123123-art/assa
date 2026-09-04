<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireAdminPage();

$payments = query("SELECT * FROM payments ORDER BY id ASC");

$pageTitle = 'طرق الدفع';
$activeNav = 'payments';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
    <h2 style="display:flex;justify-content:space-between;align-items:center;">
        <span><i class="fa-solid fa-credit-card"></i> طرق الدفع (<?php echo count($payments); ?>)</span>
        <button class="btn btn-primary" onclick="openPaymentModal()"><i class="fa-solid fa-plus"></i> إضافة طريقة دفع</button>
    </h2>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>أيقونة</th><th>المعرف</th><th>الاسم</th><th>رقم الحساب</th><th>للشحن فقط</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><i class="fa-solid <?php echo htmlspecialchars($p['icon']); ?>" style="font-size:18px;color:var(--primary);"></i></td>
                    <td><code><?php echo htmlspecialchars($p['id']); ?></code></td>
                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                    <td><?php echo htmlspecialchars($p['accountNumber'] ?? '—'); ?></td>
                    <td><?php echo $p['isRechargeOnly'] ? 'نعم' : 'لا'; ?></td>
                    <td>
                        <button class="pill <?php echo $p['enabled'] ? 'pill-enabled' : 'pill-disabled'; ?>" style="border:none;cursor:pointer;" onclick="togglePaymentRow(<?php echo json_encode($p['id']); ?>)">
                            <?php echo $p['enabled'] ? 'مفعّل' : 'معطّل'; ?>
                        </button>
                    </td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick='openPaymentModal(<?php echo json_encode($p, JSON_UNESCAPED_UNICODE); ?>)'><i class="fa-solid fa-pen"></i></button>
                        <?php if (!in_array($p['id'], ['cash', 'electronic'], true)): ?>
                        <button class="btn btn-danger btn-sm" onclick="adminDeleteRow('deletePayment', <?php echo json_encode($p['id']); ?>, 'حذف طريقة الدفع هذه؟')"><i class="fa-solid fa-trash"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="paymentModal">
    <div class="modal-box">
        <h3 id="paymentModalTitle">إضافة طريقة دفع</h3>
        <form id="paymentForm">
            <input type="hidden" id="payOldId">
            <div class="form-group"><label>المعرف (بالإنجليزية) *</label><input type="text" id="payId" placeholder="paypal" required></div>
            <div class="form-row">
                <div class="form-group"><label>الاسم (عربي) *</label><input type="text" id="payName" required></div>
                <div class="form-group"><label>الاسم (إنجليزي) *</label><input type="text" id="payNameEn" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>رقم الحساب</label><input type="text" id="payAccount"></div>
                <div class="form-group"><label>اسم المستفيد</label><input type="text" id="payBeneficiary"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>الأيقونة</label><input type="text" id="payIcon" value="fa-solid fa-credit-card"></div>
                <div class="form-group"><label class="form-check" style="margin-top:24px;"><input type="checkbox" id="payRechargeOnly"> للشحن فقط</label></div>
            </div>
            <div class="form-group">
                <label>صورة</label>
                <input type="file" id="payImage" accept="image/*" onchange="previewImageInto(this,'payImagePreview')">
                <div class="img-preview" id="payImagePreview"><i class="fa-solid fa-image"></i></div>
            </div>
            <div id="paymentFormError" style="color:var(--red);font-size:12px;margin-bottom:10px;"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('paymentModal')">إلغاء</button>
                <button type="button" class="btn btn-primary" onclick="savePaymentForm()">حفظ</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePaymentRow(id) {
    adminPostAction('togglePayment', { id: id }).then(function(res) {
        showAdminToast(res.message || (res.success ? 'تم التحديث' : 'حدث خطأ'));
        if (res.success) setTimeout(() => window.location.reload(), 500);
    });
}

function openPaymentModal(payment) {
    document.getElementById('paymentForm').reset();
    document.getElementById('payImagePreview').innerHTML = '<i class="fa-solid fa-image"></i>';
    document.getElementById('paymentFormError').textContent = '';
    if (payment) {
        document.getElementById('paymentModalTitle').textContent = 'تعديل طريقة الدفع';
        document.getElementById('payOldId').value = payment.id;
        document.getElementById('payId').value = payment.id;
        document.getElementById('payName').value = payment.name || '';
        document.getElementById('payNameEn').value = payment.nameEn || '';
        document.getElementById('payAccount').value = payment.accountNumber || '';
        document.getElementById('payBeneficiary').value = payment.beneficiary || '';
        document.getElementById('payIcon').value = payment.icon || 'fa-solid fa-credit-card';
        document.getElementById('payRechargeOnly').checked = !!Number(payment.isRechargeOnly);
    } else {
        document.getElementById('paymentModalTitle').textContent = 'إضافة طريقة دفع';
        document.getElementById('payOldId').value = '';
    }
    openModal('paymentModal');
}

function savePaymentForm() {
    const oldId = document.getElementById('payOldId').value;
    const id = document.getElementById('payId').value.trim();
    const name = document.getElementById('payName').value.trim();
    const nameEn = document.getElementById('payNameEn').value.trim();
    if (!id || !name || !nameEn) {
        document.getElementById('paymentFormError').textContent = 'الرجاء تعبئة الحقول المطلوبة (*)';
        return;
    }

    const fd = new FormData();
    fd.append('id', id);
    if (oldId) fd.append('oldId', oldId);
    fd.append('name', name);
    fd.append('nameEn', nameEn);
    fd.append('accountNumber', document.getElementById('payAccount').value);
    fd.append('beneficiary', document.getElementById('payBeneficiary').value);
    fd.append('icon', document.getElementById('payIcon').value || 'fa-solid fa-credit-card');
    fd.append('isRechargeOnly', document.getElementById('payRechargeOnly').checked ? '1' : '0');
    const imgFile = document.getElementById('payImage').files[0];
    if (imgFile) fd.append('image', imgFile);

    adminPostAction(oldId ? 'updatePayment' : 'addPayment', fd).then(function(res) {
        if (res.success) {
            showAdminToast(res.message);
            closeModal('paymentModal');
            setTimeout(() => window.location.reload(), 500);
        } else {
            document.getElementById('paymentFormError').textContent = res.message || 'حدث خطأ';
        }
    });
}
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
