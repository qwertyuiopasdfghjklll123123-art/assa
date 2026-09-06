<?php
/**
 * Product CRUD (admin only).
 */

function handleProductAction(string $action): void {
    global $input;

    switch ($action) {
        case 'addProduct':
            requireAdmin();

            $name = getVal('name');
            $nameEn = getVal('nameEn');
            $category = getVal('category');
            $price = floatval(getVal('price', 0));

            if (empty($name) || empty($nameEn) || empty($category) || $price <= 0) {
                response(false, 'جميع الحقول المطلوبة غير مكتملة: الاسم، الاسم بالإنجليزية، التصنيف، السعر');
            }

            $imageData = getImageData('image');
            $imagePath = '';
            if (!empty($imageData)) {
                $imagePath = saveImageFromBase64($imageData, UPLOAD_DIR . '/products', 400, 400, 70);
                if (empty($imagePath)) {
                    response(false, 'فشل حفظ الصورة الرئيسية');
                }
            }

            $images = [];
            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
                    if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpFile = $_FILES['images']['tmp_name'][$i];
                        if (!file_exists($tmpFile)) continue;
                        $content = file_get_contents($tmpFile);
                        if ($content === false) continue;
                        $imgPath = saveImageFromBase64(base64_encode($content), UPLOAD_DIR . '/products', 400, 400, 70);
                        if ($imgPath) {
                            $images[] = $imgPath;
                        }
                    }
                }
            }
            $imagesJson = json_encode($images);

            $oldPrice = getVal('oldPrice') ? floatval(getVal('oldPrice')) : null;
            if ($oldPrice !== null && $oldPrice <= 0) {
                $oldPrice = null;
            }

            $isNew = isTruthy(getVal('isNew')) ? 1 : 0;
            $isBestSeller = isTruthy(getVal('isBestSeller')) ? 1 : 0;
            $isFlashDeal = isTruthy(getVal('isFlashDeal')) ? 1 : 0;
            $isFeatured = isTruthy(getVal('isFeatured')) ? 1 : 0;

            $sql = "INSERT INTO products (
                        name, nameEn, `desc`, descEn, category, brand,
                        price, oldPrice, image_path, images,
                        deliveryTime, rating, ratingCount,
                        isNew, isBestSeller, isFlashDeal, isFeatured
                    ) VALUES (
                        :name, :nameEn, :desc, :descEn, :category, :brand,
                        :price, :oldPrice, :image_path, :images,
                        :deliveryTime, :rating, :ratingCount,
                        :isNew, :isBestSeller, :isFlashDeal, :isFeatured
                    )";

            $params = [
                ':name' => $name, ':nameEn' => $nameEn,
                ':desc' => getVal('desc'), ':descEn' => getVal('descEn'),
                ':category' => $category, ':brand' => getVal('brand'),
                ':price' => $price, ':oldPrice' => $oldPrice,
                ':image_path' => $imagePath, ':images' => $imagesJson,
                ':deliveryTime' => getVal('deliveryTime', 'خلال 24 ساعة'),
                ':rating' => floatval(getVal('rating', 4.5)),
                ':ratingCount' => intval(getVal('ratingCount', 0)),
                ':isNew' => $isNew, ':isBestSeller' => $isBestSeller,
                ':isFlashDeal' => $isFlashDeal, ':isFeatured' => $isFeatured,
            ];

            if (!execute($sql, $params)) {
                response(false, 'فشل إضافة المنتج - خطأ في قاعدة البيانات');
            }

            $id = getLastInsertId();

            if ($oldPrice && $oldPrice > $price) {
                $discount = round((($oldPrice - $price) / $oldPrice) * 100) . '%';
                execute("UPDATE products SET discount = :discount WHERE id = :id", [':discount' => $discount, ':id' => $id]);
            }

            sendNotificationToAllUsers("📦 منتج جديد", 'تم إضافة منتج جديد إلى المتجر', 'offer', '/product/' . $id);

            $product = queryOne("SELECT * FROM products WHERE id = :id", [':id' => $id]);
            if ($product) {
                $product['image_url'] = $product['image_path'] ? uploadUrl('products', $product['image_path']) : '';
                $product['images'] = json_decode($product['images'] ?? '[]', true);
            }

            response(true, 'تم إضافة المنتج بنجاح', $product);
            break;

        case 'updateProduct':
            requireAdmin();

            $id = intval(getVal('id', 0));

            $imageData = getImageData('image');
            $imagePath = null;
            if (!empty($imageData)) {
                $oldProduct = queryOne("SELECT image_path FROM products WHERE id = :id", [':id' => $id]);
                if ($oldProduct && $oldProduct['image_path']) {
                    deleteImageFile($oldProduct['image_path'], 'products');
                }
                $imagePath = saveImageFromBase64($imageData, UPLOAD_DIR . '/products', 400, 400, 70);
            }

            $existingImages = queryOne("SELECT images FROM products WHERE id = :id", [':id' => $id]);
            $currentImages = $existingImages ? json_decode($existingImages['images'], true) : [];

            $deleteImages = $input['delete_images'] ?? [];
            if (is_array($deleteImages)) {
                foreach ($deleteImages as $imgToDelete) {
                    if (($key = array_search($imgToDelete, $currentImages)) !== false) {
                        deleteImageFile($imgToDelete, 'products');
                        unset($currentImages[$key]);
                    }
                }
                $currentImages = array_values($currentImages);
            }

            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
                    if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                        $content = file_get_contents($_FILES['images']['tmp_name'][$i]);
                        $imgPath = saveImageFromBase64(base64_encode($content), UPLOAD_DIR . '/products', 400, 400, 70);
                        if ($imgPath) {
                            $currentImages[] = $imgPath;
                        }
                    }
                }
            }

            $imagesJson = json_encode($currentImages);
            $price = floatval(getVal('price', 0));
            $oldPrice = getVal('oldPrice') ? floatval(getVal('oldPrice')) : null;

            $sql = "UPDATE products SET
                        name = :name, nameEn = :nameEn, `desc` = :desc, descEn = :descEn,
                        category = :category, brand = :brand, price = :price, oldPrice = :oldPrice,
                        images = :images, deliveryTime = :deliveryTime, rating = :rating, ratingCount = :ratingCount,
                        isNew = :isNew, isBestSeller = :isBestSeller, isFlashDeal = :isFlashDeal,
                        isFeatured = :isFeatured, discount = :discount";

            $params = [
                ':id' => $id,
                ':name' => getVal('name'), ':nameEn' => getVal('nameEn'),
                ':desc' => getVal('desc'), ':descEn' => getVal('descEn'),
                ':category' => getVal('category'), ':brand' => getVal('brand'),
                ':price' => $price, ':oldPrice' => ($oldPrice && $oldPrice > $price) ? $oldPrice : null,
                ':images' => $imagesJson,
                ':deliveryTime' => getVal('deliveryTime', 'خلال 24 ساعة'),
                ':rating' => floatval(getVal('rating', 4.5)),
                ':ratingCount' => intval(getVal('ratingCount', 0)),
                ':isNew' => isTruthy(getVal('isNew')) ? 1 : 0,
                ':isBestSeller' => isTruthy(getVal('isBestSeller')) ? 1 : 0,
                ':isFlashDeal' => isTruthy(getVal('isFlashDeal')) ? 1 : 0,
                ':isFeatured' => isTruthy(getVal('isFeatured')) ? 1 : 0,
                ':discount' => ($oldPrice && $oldPrice > $price) ? (round((($oldPrice - $price) / $oldPrice) * 100) . '%') : null,
            ];

            if ($imagePath !== null) {
                $sql .= ", image_path = :image_path";
                $params[':image_path'] = $imagePath;
            }

            $sql .= " WHERE id = :id";

            if (execute($sql, $params)) {
                sendNotificationToAllUsers("📦 تحديث منتج", 'تم تحديث منتج في المتجر', 'info', '/product/' . $id);
                response(true, 'تم تحديث المنتج بنجاح');
            } else {
                response(false, 'فشل تحديث المنتج');
            }
            break;

        case 'deleteProduct':
            requireAdmin();

            $id = intval(getVal('id', 0));
            $product = queryOne("SELECT image_path, images FROM products WHERE id = :id", [':id' => $id]);
            if ($product) {
                if ($product['image_path']) {
                    deleteImageFile($product['image_path'], 'products');
                }
                $images = json_decode($product['images'] ?? '[]', true);
                foreach ($images as $img) {
                    deleteImageFile($img, 'products');
                }
            }
            if (execute("DELETE FROM products WHERE id = :id", [':id' => $id])) {
                response(true, 'تم حذف المنتج بنجاح');
            } else {
                response(false, 'فشل حذف المنتج');
            }
            break;
    }
}
