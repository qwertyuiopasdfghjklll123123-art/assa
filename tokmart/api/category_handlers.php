<?php
/**
 * Category CRUD (admin only).
 */

function handleCategoryAction(string $action): void {
    switch ($action) {
        case 'addCategory':
            requireAdmin();

            $id = getVal('id');
            if (queryOne("SELECT id FROM categories WHERE id = :id", [':id' => $id])) {
                response(false, 'هذا المعرف موجود بالفعل');
            }

            $iconImageData = getImageData('iconImage');
            $iconImagePath = !empty($iconImageData) ? saveImageFromBase64($iconImageData, UPLOAD_DIR . '/categories', 200, 200, 70) : '';

            $bannerImageData = getImageData('bannerImage');
            $bannerImagePath = !empty($bannerImageData) ? saveImageFromBase64($bannerImageData, UPLOAD_DIR . '/categories', 1792, 1024, 80) : '';

            $sql = "INSERT INTO categories (id, name, nameEn, icon, icon_image_path, banner_image_path, sortOrder)
                    VALUES (:id, :name, :nameEn, :icon, :icon_image_path, :banner_image_path, :sortOrder)";

            if (execute($sql, [
                ':id' => $id, ':name' => getVal('name'), ':nameEn' => getVal('nameEn'),
                ':icon' => getVal('icon', 'fa-solid fa-tag'),
                ':icon_image_path' => $iconImagePath, ':banner_image_path' => $bannerImagePath,
                ':sortOrder' => intval(getVal('sortOrder', 0)),
            ])) {
                sendNotificationToAllUsers("📂 تصنيف جديد", 'تم إضافة تصنيف جديد: ' . getVal('name'), 'info', '/categories');
                response(true, 'تم إضافة التصنيف بنجاح');
            } else {
                response(false, 'فشل إضافة التصنيف');
            }
            break;

        case 'updateCategory':
            requireAdmin();

            $oldId = getVal('oldId');
            $newId = getVal('id');

            $category = queryOne("SELECT * FROM categories WHERE id = :id", [':id' => $oldId]);
            if (!$category) {
                response(false, 'التصنيف غير موجود');
            }

            $iconImageData = getImageData('iconImage');
            $iconImagePath = null;
            if (!empty($iconImageData)) {
                if ($category['icon_image_path']) {
                    deleteImageFile($category['icon_image_path'], 'categories');
                }
                $iconImagePath = saveImageFromBase64($iconImageData, UPLOAD_DIR . '/categories', 200, 200, 70);
            }

            $bannerImageData = getImageData('bannerImage');
            $bannerImagePath = null;
            if (!empty($bannerImageData)) {
                if ($category['banner_image_path']) {
                    deleteImageFile($category['banner_image_path'], 'categories');
                }
                $bannerImagePath = saveImageFromBase64($bannerImageData, UPLOAD_DIR . '/categories', 1792, 1024, 80);
            }

            $sql = "UPDATE categories SET id = :id, name = :name, nameEn = :nameEn, icon = :icon, sortOrder = :sortOrder";
            $params = [
                ':id' => $newId, ':name' => getVal('name'), ':nameEn' => getVal('nameEn'),
                ':icon' => getVal('icon', 'fa-solid fa-tag'), ':sortOrder' => intval(getVal('sortOrder', 0)),
                ':oldId' => $oldId,
            ];

            if ($iconImagePath !== null) {
                $sql .= ", icon_image_path = :icon_image_path";
                $params[':icon_image_path'] = $iconImagePath;
            }
            if ($bannerImagePath !== null) {
                $sql .= ", banner_image_path = :banner_image_path";
                $params[':banner_image_path'] = $bannerImagePath;
            }

            $sql .= " WHERE id = :oldId";

            if (execute($sql, $params)) {
                sendNotificationToAllUsers("📂 تحديث تصنيف", 'تم تحديث التصنيف: ' . getVal('name'), 'info', '/categories');
                response(true, 'تم تحديث التصنيف بنجاح');
            } else {
                response(false, 'فشل تحديث التصنيف');
            }
            break;

        case 'deleteCategory':
            requireAdmin();

            $id = getVal('id');
            $category = queryOne("SELECT icon_image_path, banner_image_path FROM categories WHERE id = :id", [':id' => $id]);
            if ($category) {
                if ($category['icon_image_path']) deleteImageFile($category['icon_image_path'], 'categories');
                if ($category['banner_image_path']) deleteImageFile($category['banner_image_path'], 'categories');
            }
            if (execute("DELETE FROM categories WHERE id = :id", [':id' => $id])) {
                response(true, 'تم حذف التصنيف بنجاح');
            } else {
                response(false, 'فشل حذف التصنيف');
            }
            break;
    }
}
