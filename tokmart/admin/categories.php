<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'التصنيفات';
$activeNav = 'categories';

$categories = query("SELECT * FROM categories ORDER BY sortOrder ASC");
foreach ($categories as &$c) {
    $c['icon_image_url'] = $c['icon_image_path'] ? uploadUrl('categories', $c['icon_image_path']) : '';
    $c['banner_image_url'] = $c['banner_image_path'] ? uploadUrl('categories', $c['banner_image_path']) : '';
    $c['product_count'] = (int)(queryOne("SELECT COUNT(*) as c FROM products WHERE category = :id", [':id' => $c['id']])['c'] ?? 0);
}
unset($c);

require __DIR__ . '/includes/header.php';
?>

<div class="admin-panel-section">
    <div class="admin-panel-section-header">
        <h3><i class="fa-solid fa-tags"></i> التصنيفات (<?php echo count($categories); ?>)</h3>
        <button class="btn btn-sm btn-primary" onclick="openAddCategory()"><i class="fa-solid fa-plus"></i> إضافة تصنيف</button>
    </div>
    <div class="admin-table-wrap">
        <?php if (empty($categories)): ?>
            <div class="admin-empty"><i class="fa-solid fa-tags"></i>لا توجد تصنيفات بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>الأيقونة</th><th>المعرف</th><th>الاسم</th><th>عدد المنتجات</th><th>الترتيب</th><th>إجراءات</th></tr></thead>
            <tbody>
                <?php foreach ($categories as $c): ?>
                <tr>
                    <td>
                        <?php if ($c['icon_image_url']): ?>
                            <img class="thumb" src="<?php echo htmlspecialchars($c['icon_image_url']); ?>" alt="">
                        <?php else: ?>
                            <i class="<?php echo htmlspecialchars($c['icon']); ?>" style="font-size:22px;color:var(--primary);"></i>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo htmlspecialchars($c['id']); ?></code></td>
                    <td><?php echo htmlspecialchars($c['name']); ?><br><small style="color:var(--text3);"><?php echo htmlspecialchars($c['nameEn']); ?></small></td>
                    <td><?php echo $c['product_count']; ?></td>
                    <td><?php echo (int)$c['sortOrder']; ?></td>
                    <td class="cell-actions">
                        <button class="btn btn-sm btn-outline" onclick="editCategory('<?php echo htmlspecialchars(addslashes($c['id'])); ?>')"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-danger" onclick="deleteCategory('<?php echo htmlspecialchars(addslashes($c['id'])); ?>', '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div class="admin-modal-overlay" id="categoryModalOverlay">
    <div class="admin-modal">
        <h3 id="categoryModalTitle">📂 إضافة تصنيف جديد</h3>
        <form id="categoryForm">
            <input type="hidden" id="editCategoryOldId">

            <div class="form-group">
                <label>معرف التصنيف (ID) *</label>
                <input type="text" id="categoryId" placeholder="مثل: electronics" required>
                <small>💡 أحرف إنجليزية صغيرة وبدون مسافات</small>
            </div>
            <div class="form-row">
                <div class="form-group"><label>الاسم (بالعربية) *</label><input type="text" id="categoryNameAr" required></div>
                <div class="form-group"><label>الاسم (بالإنجليزية) *</label><input type="text" id="categoryNameEn" required></div>
            </div>
            <div class="form-group">
                <label>أيقونة التصنيف (FontAwesome)</label>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="text" id="categoryIcon" value="fa-solid fa-tag" style="flex:1;">
                    <div class="img-preview" id="categoryIconTextPreview" style="width:44px;height:44px;flex-shrink:0;"><i class="fa-solid fa-tag"></i></div>
                </div>
                <small>💡 اختر من <a href="https://fontawesome.com/icons" target="_blank">FontAwesome</a></small>
            </div>
            <div class="form-group">
                <label>صورة الأيقونة (اختياري)</label>
                <input type="file" id="categoryIconImage" accept="image/*">
                <div class="img-preview" id="categoryIconImagePreview"><i class="fa-solid fa-image"></i></div>
            </div>
            <div class="form-group">
                <label>صورة البانر (اختياري)</label>
                <input type="file" id="categoryBannerImage" accept="image/*">
                <div class="img-preview" id="categoryBannerPreview" style="width:100%;height:120px;"><i class="fa-solid fa-image"></i></div>
            </div>
            <div class="form-group">
                <label>ترتيب الظهور</label>
                <input type="number" id="categorySort" value="0" min="0">
                <small>💡 الأرقام الأصغر تظهر أولاً</small>
            </div>

            <div class="form-error" id="categoryFormError"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-md btn-outline" onclick="closeModal('categoryModalOverlay')">إلغاء</button>
                <button type="button" class="btn btn-md btn-primary" onclick="saveCategory()">حفظ التصنيف</button>
            </div>
        </form>
    </div>
</div>

<script>
const CATEGORIES = <?php echo json_encode($categories, JSON_UNESCAPED_UNICODE); ?>;

previewImageInput($('categoryIconImage'), 'categoryIconImagePreview');
previewImageInput($('categoryBannerImage'), 'categoryBannerPreview');

$('categoryIcon').addEventListener('input', function() {
    $('categoryIconTextPreview').innerHTML = '<i class="' + (this.value.trim() || 'fa-solid fa-tag') + '"></i>';
});

function openAddCategory() {
    $('categoryModalTitle').textContent = '📂 إضافة تصنيف جديد';
    $('categoryForm').reset();
    $('editCategoryOldId').value = '';
    $('categoryId').disabled = false;
    $('categoryIconTextPreview').innerHTML = '<i class="fa-solid fa-tag"></i>';
    $('categoryIconImagePreview').innerHTML = '<i class="fa-solid fa-image"></i>';
    $('categoryBannerPreview').innerHTML = '<i class="fa-solid fa-image"></i>';
    hideFormError('categoryFormError');
    openModal('categoryModalOverlay');
}

function editCategory(id) {
    const c = CATEGORIES.find(function(x) { return x.id === id; });
    if (!c) return;

    $('categoryModalTitle').textContent = '📂 تعديل التصنيف';
    $('editCategoryOldId').value = c.id;
    $('categoryId').value = c.id;
    $('categoryNameAr').value = c.name || '';
    $('categoryNameEn').value = c.nameEn || '';
    $('categoryIcon').value = c.icon || 'fa-solid fa-tag';
    $('categoryIconTextPreview').innerHTML = '<i class="' + (c.icon || 'fa-solid fa-tag') + '"></i>';
    $('categorySort').value = c.sortOrder || 0;
    $('categoryIconImage').value = '';
    $('categoryBannerImage').value = '';
    $('categoryIconImagePreview').innerHTML = c.icon_image_url ? '<img src="' + c.icon_image_url + '">' : '<i class="fa-solid fa-image"></i>';
    $('categoryBannerPreview').innerHTML = c.banner_image_url ? '<img src="' + c.banner_image_url + '">' : '<i class="fa-solid fa-image"></i>';

    hideFormError('categoryFormError');
    openModal('categoryModalOverlay');
}

function saveCategory() {
    hideFormError('categoryFormError');
    const oldId = $('editCategoryOldId').value;
    const id = $('categoryId').value.trim();
    const name = $('categoryNameAr').value.trim();
    const nameEn = $('categoryNameEn').value.trim();

    if (!id || !name || !nameEn) {
        showFormError('categoryFormError', '⚠️ الرجاء تعبئة جميع الحقول المطلوبة');
        return;
    }

    const formData = new FormData();
    formData.append('id', id);
    if (oldId) formData.append('oldId', oldId);
    formData.append('name', name);
    formData.append('nameEn', nameEn);
    formData.append('icon', $('categoryIcon').value.trim() || 'fa-solid fa-tag');
    formData.append('sortOrder', $('categorySort').value || 0);

    const iconImage = $('categoryIconImage').files[0];
    if (iconImage) formData.append('iconImage', iconImage);
    const bannerImage = $('categoryBannerImage').files[0];
    if (bannerImage) formData.append('bannerImage', bannerImage);

    adminApi(oldId ? 'updateCategory' : 'addCategory', formData, 'POST').then(function(res) {
        if (res.success) {
            adminToast('✅ ' + res.message);
            closeModal('categoryModalOverlay');
            location.reload();
        } else {
            showFormError('categoryFormError', '❌ ' + (res.message || 'حدث خطأ'));
        }
    });
}

function deleteCategory(id, name) {
    confirmAndRun('هل تريد حذف التصنيف "' + name + '"؟ سيتم فك ارتباط منتجاته.', function() {
        adminApi('deleteCategory', { id: id }, 'POST').then(function(res) {
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
