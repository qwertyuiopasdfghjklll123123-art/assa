<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireAdminPage();

$categories = query("SELECT * FROM categories ORDER BY sortOrder ASC");
foreach ($categories as &$c) {
    $c['productCount'] = queryOne("SELECT COUNT(*) as c FROM products WHERE category = :cat", [':cat' => $c['id']])['c'] ?? 0;
}
unset($c);

$pageTitle = 'التصنيفات';
$activeNav = 'categories';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
    <h2 style="display:flex;justify-content:space-between;align-items:center;">
        <span><i class="fa-solid fa-tags"></i> التصنيفات (<?php echo count($categories); ?>)</span>
        <button class="btn btn-primary" onclick="openCategoryModal()"><i class="fa-solid fa-plus"></i> إضافة تصنيف</button>
    </h2>
    <div class="table-wrap">
        <?php if (empty($categories)): ?>
            <div class="empty-state"><i class="fa-solid fa-tags"></i>لا توجد تصنيفات بعد</div>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>أيقونة</th><th>المعرف</th><th>الاسم</th><th>عدد المنتجات</th><th>الترتيب</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td>
                        <?php if ($c['icon_image_path']): ?>
                            <img class="thumb" src="<?php echo htmlspecialchars(uploadUrl('categories', $c['icon_image_path'])); ?>" alt="">
                        <?php else: ?>
                            <i class="fa-solid <?php echo htmlspecialchars($c['icon']); ?>" style="font-size:20px;color:var(--primary);"></i>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo htmlspecialchars($c['id']); ?></code></td>
                    <td><?php echo htmlspecialchars($c['name']); ?> <span style="color:var(--text3);font-size:11px;">(<?php echo htmlspecialchars($c['nameEn']); ?>)</span></td>
                    <td><?php echo (int)$c['productCount']; ?></td>
                    <td><?php echo (int)$c['sortOrder']; ?></td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick='openCategoryModal(<?php echo json_encode($c, JSON_UNESCAPED_UNICODE); ?>)'><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-danger btn-sm" onclick="adminDeleteRow('deleteCategory', <?php echo json_encode($c['id']); ?>, 'حذف هذا التصنيف؟')"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="categoryModal">
    <div class="modal-box">
        <h3 id="categoryModalTitle">إضافة تصنيف جديد</h3>
        <form id="categoryForm">
            <input type="hidden" id="cOldId">
            <div class="form-group"><label>المعرف (بالإنجليزية، بدون مسافات) *</label><input type="text" id="cId" placeholder="electronics" required></div>
            <div class="form-row">
                <div class="form-group"><label>الاسم (عربي) *</label><input type="text" id="cName" required></div>
                <div class="form-group"><label>الاسم (إنجليزي) *</label><input type="text" id="cNameEn" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>أيقونة FontAwesome</label><input type="text" id="cIcon" value="fa-solid fa-tag"></div>
                <div class="form-group"><label>الترتيب</label><input type="number" id="cSort" value="0" min="0"></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>صورة الأيقونة</label>
                    <input type="file" id="cIconImage" accept="image/*" onchange="previewImageInto(this,'cIconPreview')">
                    <div class="img-preview" id="cIconPreview"><i class="fa-solid fa-image"></i></div>
                </div>
                <div class="form-group">
                    <label>صورة البانر</label>
                    <input type="file" id="cBannerImage" accept="image/*" onchange="previewImageInto(this,'cBannerPreview')">
                    <div class="img-preview" id="cBannerPreview" style="width:100%;height:90px;"><i class="fa-solid fa-image"></i></div>
                </div>
            </div>
            <div id="categoryFormError" style="color:var(--red);font-size:12px;margin-bottom:10px;"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('categoryModal')">إلغاء</button>
                <button type="button" class="btn btn-primary" onclick="saveCategoryForm()">حفظ</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCategoryModal(cat) {
    document.getElementById('categoryForm').reset();
    document.getElementById('cIconPreview').innerHTML = '<i class="fa-solid fa-image"></i>';
    document.getElementById('cBannerPreview').innerHTML = '<i class="fa-solid fa-image"></i>';
    document.getElementById('categoryFormError').textContent = '';
    if (cat) {
        document.getElementById('categoryModalTitle').textContent = 'تعديل التصنيف';
        document.getElementById('cOldId').value = cat.id;
        document.getElementById('cId').value = cat.id;
        document.getElementById('cName').value = cat.name || '';
        document.getElementById('cNameEn').value = cat.nameEn || '';
        document.getElementById('cIcon').value = cat.icon || 'fa-solid fa-tag';
        document.getElementById('cSort').value = cat.sortOrder || 0;
    } else {
        document.getElementById('categoryModalTitle').textContent = 'إضافة تصنيف جديد';
        document.getElementById('cOldId').value = '';
    }
    openModal('categoryModal');
}

function saveCategoryForm() {
    const oldId = document.getElementById('cOldId').value;
    const id = document.getElementById('cId').value.trim();
    const name = document.getElementById('cName').value.trim();
    const nameEn = document.getElementById('cNameEn').value.trim();
    if (!id || !name || !nameEn) {
        document.getElementById('categoryFormError').textContent = 'الرجاء تعبئة الحقول المطلوبة (*)';
        return;
    }

    const fd = new FormData();
    fd.append('id', id);
    if (oldId) fd.append('oldId', oldId);
    fd.append('name', name);
    fd.append('nameEn', nameEn);
    fd.append('icon', document.getElementById('cIcon').value || 'fa-solid fa-tag');
    fd.append('sortOrder', document.getElementById('cSort').value || '0');
    const iconFile = document.getElementById('cIconImage').files[0];
    if (iconFile) fd.append('iconImage', iconFile);
    const bannerFile = document.getElementById('cBannerImage').files[0];
    if (bannerFile) fd.append('bannerImage', bannerFile);

    adminPostAction(oldId ? 'updateCategory' : 'addCategory', fd).then(function(res) {
        if (res.success) {
            showAdminToast(res.message);
            closeModal('categoryModal');
            setTimeout(() => window.location.reload(), 500);
        } else {
            document.getElementById('categoryFormError').textContent = res.message || 'حدث خطأ';
        }
    });
}
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
