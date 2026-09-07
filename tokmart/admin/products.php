<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'المنتجات';
$activeNav = 'products';

$products = query("SELECT * FROM products ORDER BY id DESC");
$categories = query("SELECT id, name FROM categories ORDER BY sortOrder ASC");

foreach ($products as &$p) {
    $p['image_url'] = $p['image_path'] ? uploadUrl('products', $p['image_path']) : '';
    $imgs = json_decode($p['images'] ?? '[]', true) ?: [];
    $p['images'] = array_map(function ($file) {
        return ['file' => $file, 'url' => uploadUrl('products', $file)];
    }, $imgs);
}
unset($p);

require __DIR__ . '/includes/header.php';
?>

<div class="admin-panel-section">
    <div class="admin-panel-section-header">
        <h3><i class="fa-solid fa-box"></i> المنتجات (<?php echo count($products); ?>)</h3>
        <button class="btn btn-sm btn-primary" onclick="openAddProduct()"><i class="fa-solid fa-plus"></i> إضافة منتج</button>
    </div>
    <div class="admin-table-wrap">
        <?php if (empty($products)): ?>
            <div class="admin-empty"><i class="fa-solid fa-box"></i>لا توجد منتجات بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>الصورة</th><th>الاسم</th><th>التصنيف</th><th>السعر</th><th>السمات</th><th>إجراءات</th></tr></thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td><img class="thumb" src="<?php echo htmlspecialchars($p['image_url'] ?: 'https://via.placeholder.com/60'); ?>" alt=""></td>
                    <td class="cell-wrap"><strong><?php echo htmlspecialchars($p['name']); ?></strong><br><small style="color:var(--text3);"><?php echo htmlspecialchars($p['nameEn']); ?></small></td>
                    <td><?php echo htmlspecialchars($p['category'] ?? '—'); ?></td>
                    <td>
                        <?php echo number_format((float)$p['price'], 2); ?> د.ع
                        <?php if ($p['oldPrice']): ?><br><small style="text-decoration:line-through;color:var(--text3);"><?php echo number_format((float)$p['oldPrice'], 2); ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['isNew']): ?><span class="status-pill status-approved">جديد</span><?php endif; ?>
                        <?php if ($p['isBestSeller']): ?><span class="status-pill status-pending">الأكثر مبيعاً</span><?php endif; ?>
                        <?php if ($p['isFlashDeal']): ?><span class="status-pill status-rejected">عرض خاص</span><?php endif; ?>
                        <?php if ($p['isFeatured']): ?><span class="status-pill status-admin">مميز</span><?php endif; ?>
                    </td>
                    <td class="cell-actions">
                        <button class="btn btn-sm btn-outline" onclick="editProduct(<?php echo (int)$p['id']; ?>)"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-danger" onclick="deleteProduct(<?php echo (int)$p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Product Modal -->
<div class="admin-modal-overlay" id="productModalOverlay">
    <div class="admin-modal">
        <h3 id="productModalTitle">📦 إضافة منتج جديد</h3>
        <form id="productForm">
            <input type="hidden" id="editProductId">

            <div class="form-group">
                <label>الصورة الرئيسية</label>
                <input type="file" id="productImageFile" name="image" accept="image/*">
                <div class="img-preview" id="productImagePreview"><i class="fa-solid fa-image"></i></div>
            </div>

            <div class="form-group" id="existingImagesGroup" style="display:none;">
                <label>الصور الإضافية الحالية</label>
                <div id="existingImagesList" style="display:flex;gap:10px;flex-wrap:wrap;"></div>
            </div>

            <div class="form-group">
                <label>صور إضافية (يمكن اختيار أكثر من صورة)</label>
                <input type="file" id="productImagesFile" name="images[]" accept="image/*" multiple>
            </div>

            <div class="form-row">
                <div class="form-group"><label>الاسم (بالعربية) *</label><input type="text" id="productNameAr" required></div>
                <div class="form-group"><label>الاسم (بالإنجليزية) *</label><input type="text" id="productNameEn" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>الوصف (بالعربية)</label><textarea id="productDescAr" rows="3"></textarea></div>
                <div class="form-group"><label>الوصف (بالإنجليزية)</label><textarea id="productDescEn" rows="3"></textarea></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>التصنيف *</label>
                    <select id="productCategory" required>
                        <option value="">-- اختر تصنيف --</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['id']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>العلامة التجارية</label><input type="text" id="productBrand"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>السعر *</label><input type="number" id="productPrice" step="0.01" min="0" required></div>
                <div class="form-group"><label>السعر القديم (للخصم)</label><input type="number" id="productOldPrice" step="0.01" min="0"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>وقت التوصيل</label><input type="text" id="productDelivery" value="خلال 24 ساعة"></div>
                <div class="form-group"><label>التقييم</label><input type="number" id="productRating" step="0.1" min="0" max="5" value="4.5"></div>
            </div>
            <div class="form-group form-check"><input type="checkbox" id="productIsNew"><label for="productIsNew" style="margin:0;">جديد</label></div>
            <div class="form-group form-check"><input type="checkbox" id="productIsBestSeller"><label for="productIsBestSeller" style="margin:0;">الأكثر مبيعاً</label></div>
            <div class="form-group form-check"><input type="checkbox" id="productIsFlashDeal"><label for="productIsFlashDeal" style="margin:0;">عرض خاص</label></div>
            <div class="form-group form-check"><input type="checkbox" id="productIsFeatured"><label for="productIsFeatured" style="margin:0;">مميز</label></div>

            <div class="form-error" id="productFormError"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-md btn-outline" onclick="closeModal('productModalOverlay')">إلغاء</button>
                <button type="button" class="btn btn-md btn-primary" onclick="saveProduct()">حفظ المنتج</button>
            </div>
        </form>
    </div>
</div>

<script>
const PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
let deleteImagesList = [];

previewImageInput($('productImageFile'), 'productImagePreview');

function openAddProduct() {
    $('productModalTitle').textContent = '📦 إضافة منتج جديد';
    $('productForm').reset();
    $('editProductId').value = '';
    $('productImagePreview').innerHTML = '<i class="fa-solid fa-image"></i>';
    $('existingImagesGroup').style.display = 'none';
    $('existingImagesList').innerHTML = '';
    deleteImagesList = [];
    hideFormError('productFormError');
    openModal('productModalOverlay');
}

function editProduct(id) {
    const p = PRODUCTS.find(function(x) { return x.id == id; });
    if (!p) return;

    $('productModalTitle').textContent = '📦 تعديل المنتج';
    $('editProductId').value = p.id;
    $('productNameAr').value = p.name || '';
    $('productNameEn').value = p.nameEn || '';
    $('productDescAr').value = p.desc || '';
    $('productDescEn').value = p.descEn || '';
    $('productCategory').value = p.category || '';
    $('productBrand').value = p.brand || '';
    $('productPrice').value = p.price || '';
    $('productOldPrice').value = p.oldPrice || '';
    $('productDelivery').value = p.deliveryTime || 'خلال 24 ساعة';
    $('productRating').value = p.rating || 4.5;
    $('productIsNew').checked = !!(p.isNew * 1);
    $('productIsBestSeller').checked = !!(p.isBestSeller * 1);
    $('productIsFlashDeal').checked = !!(p.isFlashDeal * 1);
    $('productIsFeatured').checked = !!(p.isFeatured * 1);
    $('productImagesFile').value = '';

    $('productImagePreview').innerHTML = p.image_url ? '<img src="' + p.image_url + '">' : '<i class="fa-solid fa-image"></i>';

    deleteImagesList = [];
    const list = $('existingImagesList');
    list.innerHTML = '';
    if (p.images && p.images.length) {
        $('existingImagesGroup').style.display = 'block';
        p.images.forEach(function(img) {
            const div = document.createElement('div');
            div.style.cssText = 'width:70px;height:70px;border-radius:8px;overflow:hidden;position:relative;border:2px solid var(--border);';
            div.innerHTML = '<img src="' + img.url + '" style="width:100%;height:100%;object-fit:cover;">' +
                '<span style="position:absolute;top:-4px;right:-4px;background:var(--red);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;cursor:pointer;">×</span>';
            div.querySelector('span').addEventListener('click', function() {
                deleteImagesList.push(img.file);
                div.remove();
            });
            list.appendChild(div);
        });
    } else {
        $('existingImagesGroup').style.display = 'none';
    }

    hideFormError('productFormError');
    openModal('productModalOverlay');
}

function saveProduct() {
    hideFormError('productFormError');
    const id = $('editProductId').value;
    const name = $('productNameAr').value.trim();
    const nameEn = $('productNameEn').value.trim();
    const category = $('productCategory').value;
    const price = parseFloat($('productPrice').value || 0);

    if (!name || !nameEn || !category || price <= 0) {
        showFormError('productFormError', '⚠️ الرجاء تعبئة جميع الحقول المطلوبة: الاسم، الاسم بالإنجليزية، التصنيف، السعر');
        return;
    }

    const formData = new FormData();
    if (id) formData.append('id', id);
    formData.append('name', name);
    formData.append('nameEn', nameEn);
    formData.append('desc', $('productDescAr').value);
    formData.append('descEn', $('productDescEn').value);
    formData.append('category', category);
    formData.append('brand', $('productBrand').value);
    formData.append('price', price);
    formData.append('oldPrice', $('productOldPrice').value || '');
    formData.append('deliveryTime', $('productDelivery').value);
    formData.append('rating', $('productRating').value);
    formData.append('isNew', $('productIsNew').checked ? '1' : '0');
    formData.append('isBestSeller', $('productIsBestSeller').checked ? '1' : '0');
    formData.append('isFlashDeal', $('productIsFlashDeal').checked ? '1' : '0');
    formData.append('isFeatured', $('productIsFeatured').checked ? '1' : '0');

    const imageFile = $('productImageFile').files[0];
    if (imageFile) formData.append('image', imageFile);

    const imagesFiles = $('productImagesFile').files;
    for (let i = 0; i < imagesFiles.length; i++) formData.append('images[]', imagesFiles[i]);

    deleteImagesList.forEach(function(f) { formData.append('delete_images[]', f); });

    adminApi(id ? 'updateProduct' : 'addProduct', formData, 'POST').then(function(res) {
        if (res.success) {
            adminToast('✅ ' + res.message);
            closeModal('productModalOverlay');
            location.reload();
        } else {
            showFormError('productFormError', '❌ ' + (res.message || 'حدث خطأ'));
        }
    });
}

function deleteProduct(id, name) {
    confirmAndRun('هل تريد حذف المنتج "' + name + '"؟', function() {
        adminApi('deleteProduct', { id: id }, 'POST').then(function(res) {
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
