<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireAdminPage();

$products = query("SELECT * FROM products ORDER BY id DESC");
$categories = query("SELECT * FROM categories ORDER BY sortOrder ASC");

$pageTitle = 'المنتجات';
$activeNav = 'products';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
    <h2 style="display:flex;justify-content:space-between;align-items:center;">
        <span><i class="fa-solid fa-box"></i> المنتجات (<?php echo count($products); ?>)</span>
        <button class="btn btn-primary" onclick="openProductModal()"><i class="fa-solid fa-plus"></i> إضافة منتج</button>
    </h2>
    <div class="table-wrap">
        <?php if (empty($products)): ?>
            <div class="empty-state"><i class="fa-solid fa-box-open"></i>لا توجد منتجات بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>صورة</th><th>الاسم</th><th>التصنيف</th><th>السعر</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><img class="thumb" src="<?php echo htmlspecialchars(uploadUrl('products', $p['image_path']) ?: 'https://via.placeholder.com/40'); ?>" alt=""></td>
                    <td class="wrap"><?php echo htmlspecialchars($p['name']); ?><br><span style="color:var(--text3);font-size:11px;"><?php echo htmlspecialchars($p['nameEn']); ?></span></td>
                    <td><?php echo htmlspecialchars($p['category'] ?? '—'); ?></td>
                    <td>
                        <?php echo number_format((float)$p['price'], 2); ?> د.ع
                        <?php if ($p['oldPrice']): ?><br><span style="text-decoration:line-through;color:var(--text3);font-size:11px;"><?php echo number_format((float)$p['oldPrice'], 2); ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['isFeatured']): ?><span class="pill pill-approved">مميز</span><?php endif; ?>
                        <?php if ($p['isNew']): ?><span class="pill pill-pending">جديد</span><?php endif; ?>
                        <?php if ($p['isFlashDeal']): ?><span class="pill pill-rejected">عرض</span><?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick='openProductModal(<?php echo json_encode($p, JSON_UNESCAPED_UNICODE); ?>)'><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-danger btn-sm" onclick="adminDeleteRow('deleteProduct', <?php echo (int)$p['id']; ?>, 'حذف هذا المنتج؟')"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="productModal">
    <div class="modal-box">
        <h3 id="productModalTitle">إضافة منتج جديد</h3>
        <form id="productForm">
            <input type="hidden" id="pId">
            <div class="form-row">
                <div class="form-group"><label>الاسم (عربي) *</label><input type="text" id="pName" required></div>
                <div class="form-group"><label>الاسم (إنجليزي) *</label><input type="text" id="pNameEn" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>الوصف (عربي)</label><textarea id="pDesc" rows="2"></textarea></div>
                <div class="form-group"><label>الوصف (إنجليزي)</label><textarea id="pDescEn" rows="2"></textarea></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>التصنيف *</label>
                    <select id="pCategory" required>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['id']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>العلامة التجارية</label><input type="text" id="pBrand"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>السعر *</label><input type="number" id="pPrice" step="0.01" min="0" required></div>
                <div class="form-group"><label>السعر القديم (للخصم)</label><input type="number" id="pOldPrice" step="0.01" min="0"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>وقت التوصيل</label><input type="text" id="pDelivery" value="خلال 24 ساعة"></div>
                <div class="form-group">
                    <label class="form-check" style="margin-top:24px;flex-wrap:wrap;gap:12px;">
                        <span><input type="checkbox" id="pIsNew"> جديد</span>
                        <span><input type="checkbox" id="pIsBestSeller"> الأكثر مبيعاً</span>
                        <span><input type="checkbox" id="pIsFlashDeal"> عرض خاطف</span>
                        <span><input type="checkbox" id="pIsFeatured"> مميز</span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label>الصورة الرئيسية</label>
                <input type="file" id="pImage" accept="image/*" onchange="previewImageInto(this,'pImagePreview')">
                <div class="img-preview" id="pImagePreview"><i class="fa-solid fa-image"></i></div>
            </div>
            <div id="productFormError" style="color:var(--red);font-size:12px;margin-bottom:10px;"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('productModal')">إلغاء</button>
                <button type="button" class="btn btn-primary" onclick="saveProductForm()">حفظ</button>
            </div>
        </form>
    </div>
</div>

<script>
function openProductModal(product) {
    document.getElementById('productForm').reset();
    document.getElementById('pImagePreview').innerHTML = '<i class="fa-solid fa-image"></i>';
    document.getElementById('productFormError').textContent = '';
    if (product) {
        document.getElementById('productModalTitle').textContent = 'تعديل المنتج';
        document.getElementById('pId').value = product.id;
        document.getElementById('pName').value = product.name || '';
        document.getElementById('pNameEn').value = product.nameEn || '';
        document.getElementById('pDesc').value = product.desc || '';
        document.getElementById('pDescEn').value = product.descEn || '';
        document.getElementById('pCategory').value = product.category || '';
        document.getElementById('pBrand').value = product.brand || '';
        document.getElementById('pPrice').value = product.price || '';
        document.getElementById('pOldPrice').value = product.oldPrice || '';
        document.getElementById('pDelivery').value = product.deliveryTime || '';
        document.getElementById('pIsNew').checked = !!Number(product.isNew);
        document.getElementById('pIsBestSeller').checked = !!Number(product.isBestSeller);
        document.getElementById('pIsFlashDeal').checked = !!Number(product.isFlashDeal);
        document.getElementById('pIsFeatured').checked = !!Number(product.isFeatured);
    } else {
        document.getElementById('productModalTitle').textContent = 'إضافة منتج جديد';
        document.getElementById('pId').value = '';
    }
    openModal('productModal');
}

function saveProductForm() {
    const id = document.getElementById('pId').value;
    const name = document.getElementById('pName').value.trim();
    const nameEn = document.getElementById('pNameEn').value.trim();
    const price = document.getElementById('pPrice').value;
    if (!name || !nameEn || !price) {
        document.getElementById('productFormError').textContent = 'الرجاء تعبئة الحقول المطلوبة (*)';
        return;
    }

    const fd = new FormData();
    if (id) fd.append('id', id);
    fd.append('name', name);
    fd.append('nameEn', nameEn);
    fd.append('desc', document.getElementById('pDesc').value);
    fd.append('descEn', document.getElementById('pDescEn').value);
    fd.append('category', document.getElementById('pCategory').value);
    fd.append('brand', document.getElementById('pBrand').value);
    fd.append('price', price);
    fd.append('oldPrice', document.getElementById('pOldPrice').value);
    fd.append('deliveryTime', document.getElementById('pDelivery').value);
    fd.append('isNew', document.getElementById('pIsNew').checked ? '1' : '0');
    fd.append('isBestSeller', document.getElementById('pIsBestSeller').checked ? '1' : '0');
    fd.append('isFlashDeal', document.getElementById('pIsFlashDeal').checked ? '1' : '0');
    fd.append('isFeatured', document.getElementById('pIsFeatured').checked ? '1' : '0');
    const imgFile = document.getElementById('pImage').files[0];
    if (imgFile) fd.append('image', imgFile);

    adminPostAction(id ? 'updateProduct' : 'addProduct', fd).then(function(res) {
        if (res.success) {
            showAdminToast(res.message);
            closeModal('productModal');
            setTimeout(() => window.location.reload(), 500);
        } else {
            document.getElementById('productFormError').textContent = res.message || 'حدث خطأ';
        }
    });
}
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
