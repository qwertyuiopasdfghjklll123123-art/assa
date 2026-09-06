<?php
/**
 * Payment method CRUD (admin only).
 */

function handlePaymentAction(string $action): void {
    switch ($action) {
        case 'addPayment':
            requireAdmin();

            $id = getVal('id');
            if (queryOne("SELECT id FROM payments WHERE id = :id", [':id' => $id])) {
                response(false, 'هذا المعرف موجود بالفعل');
            }

            $imageData = getImageData('image');
            $imagePath = !empty($imageData) ? saveImageFromBase64($imageData, UPLOAD_DIR . '/payments', 200, 200, 70) : '';

            $sql = "INSERT INTO payments (id, name, nameEn, icon, image_path, enabled, isRechargeOnly, accountNumber, beneficiary)
                    VALUES (:id, :name, :nameEn, :icon, :image_path, 1, :isRechargeOnly, :accountNumber, :beneficiary)";

            if (execute($sql, [
                ':id' => $id, ':name' => getVal('name'), ':nameEn' => getVal('nameEn'),
                ':icon' => getVal('icon', 'fa-solid fa-credit-card'), ':image_path' => $imagePath,
                ':isRechargeOnly' => isTruthy(getVal('isRechargeOnly')) ? 1 : 0,
                ':accountNumber' => getVal('accountNumber'), ':beneficiary' => getVal('beneficiary'),
            ])) {
                response(true, 'تم إضافة طريقة الدفع');
            } else {
                response(false, 'فشل إضافة طريقة الدفع');
            }
            break;

        case 'updatePayment':
            requireAdmin();

            $oldId = getVal('oldId');
            $imageData = getImageData('image');
            $imagePath = null;

            if (!empty($imageData)) {
                $oldPayment = queryOne("SELECT image_path FROM payments WHERE id = :id", [':id' => $oldId]);
                if ($oldPayment && $oldPayment['image_path']) {
                    deleteImageFile($oldPayment['image_path'], 'payments');
                }
                $imagePath = saveImageFromBase64($imageData, UPLOAD_DIR . '/payments', 200, 200, 70);
            }

            $sql = "UPDATE payments SET id = :id, name = :name, nameEn = :nameEn,
                    icon = :icon, accountNumber = :accountNumber, beneficiary = :beneficiary,
                    isRechargeOnly = :isRechargeOnly";

            $params = [
                ':id' => getVal('id'), ':name' => getVal('name'), ':nameEn' => getVal('nameEn'),
                ':icon' => getVal('icon', 'fa-solid fa-credit-card'),
                ':accountNumber' => getVal('accountNumber'), ':beneficiary' => getVal('beneficiary'),
                ':isRechargeOnly' => isTruthy(getVal('isRechargeOnly')) ? 1 : 0,
                ':oldId' => $oldId,
            ];

            if ($imagePath !== null) {
                $sql .= ", image_path = :image_path";
                $params[':image_path'] = $imagePath;
            }

            $sql .= " WHERE id = :oldId";

            if (execute($sql, $params)) {
                response(true, 'تم تحديث طريقة الدفع');
            } else {
                response(false, 'فشل تحديث طريقة الدفع');
            }
            break;

        case 'deletePayment':
            requireAdmin();

            $id = getVal('id');
            if ($id === 'cash' || $id === 'electronic') {
                response(false, 'لا يمكن حذف طريقة الدفع الأساسية');
            }
            $payment = queryOne("SELECT image_path FROM payments WHERE id = :id", [':id' => $id]);
            if ($payment && $payment['image_path']) {
                deleteImageFile($payment['image_path'], 'payments');
            }
            if (execute("DELETE FROM payments WHERE id = :id", [':id' => $id])) {
                response(true, 'تم حذف طريقة الدفع');
            } else {
                response(false, 'فشل حذف طريقة الدفع');
            }
            break;

        case 'togglePayment':
            requireAdmin();

            $id = getVal('id');
            $payment = queryOne("SELECT * FROM payments WHERE id = :id", [':id' => $id]);
            if (!$payment) {
                response(false, 'طريقة الدفع غير موجودة');
            }
            $newStatus = $payment['enabled'] ? 0 : 1;
            if (execute("UPDATE payments SET enabled = :enabled WHERE id = :id", [':enabled' => $newStatus, ':id' => $id])) {
                response(true, 'تم تحديث حالة طريقة الدفع');
            } else {
                response(false, 'فشل تحديث حالة طريقة الدفع');
            }
            break;
    }
}
