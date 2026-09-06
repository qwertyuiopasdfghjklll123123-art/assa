<?php
/**
 * Wallet balance recharge requests (receipt upload + admin approval).
 */

function handleRechargeAction(string $action): void {
    switch ($action) {
        case 'requestRecharge':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }

            $userId = getSafeUserId();
            $amount = floatval(getVal('amount', 0));
            $paymentMethod = getVal('paymentMethod');

            if (empty($paymentMethod)) {
                response(false, 'الرجاء اختيار طريقة الدفع');
            }
            if ($amount < 1000) {
                response(false, 'الحد الأدنى للشحن هو 1000 دينار عراقي');
            }

            if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
                response(false, 'الرجاء رفع صورة الإيصال');
            }

            $file = $_FILES['receipt'];
            $mime = mime_content_type($file['tmp_name']);
            $ext = extensionForMime($mime);
            if ($ext === null) {
                response(false, 'صيغة الملف غير مدعومة');
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                response(false, 'حجم الصورة كبير جداً (الحد الأقصى 5MB)');
            }
            $receiptImage = uniqid() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/receipts/' . $receiptImage)) {
                response(false, 'فشل حفظ صورة الإيصال');
            }

            $sql = "INSERT INTO balance_recharges (userId, amount, paymentMethod, receiptImage, status, createdAt)
                    VALUES (:userId, :amount, :paymentMethod, :receiptImage, 'pending', NOW())";

            if (execute($sql, [
                ':userId' => $userId, ':amount' => $amount,
                ':paymentMethod' => $paymentMethod, ':receiptImage' => $receiptImage,
            ])) {
                // Notify every admin account - a made-up userId of 0 (as in the
                // original) would silently vanish, since no user has that id.
                sendNotificationToAdmins('💰 طلب شحن رصيد جديد', 'المبلغ: ' . number_format($amount, 2) . ' د.ع', 'wallet', '/admin/recharges.php');
                response(true, 'تم إرسال طلب الشحن بنجاح');
            } else {
                response(false, 'فشل إرسال الطلب');
            }
            break;

        case 'getRecharges':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            $recharges = query("SELECT * FROM balance_recharges WHERE userId = :userId ORDER BY id DESC", [':userId' => getSafeUserId()]);
            foreach ($recharges as &$recharge) {
                $recharge['receiptImage_url'] = $recharge['receiptImage'] ? uploadUrl('receipts', $recharge['receiptImage']) : '';
            }
            unset($recharge);
            response(true, 'تم جلب سجل الشحن', $recharges);
            break;

        case 'updateRechargeStatus':
            requireAdmin();

            $id = intval(getVal('id', 0));
            $status = getVal('status', 'pending');

            $recharge = queryOne("SELECT * FROM balance_recharges WHERE id = :id", [':id' => $id]);
            if (!$recharge) {
                response(false, 'طلب الشحن غير موجود');
            }

            execute("UPDATE balance_recharges SET status = :status, updatedAt = NOW() WHERE id = :id", [
                ':status' => $status, ':id' => $id,
            ]);

            if ($status === 'approved') {
                execute("UPDATE users SET balance = balance + :amount WHERE id = :id", [
                    ':amount' => $recharge['amount'], ':id' => $recharge['userId'],
                ]);
                sendNotification($recharge['userId'], '💰 تم شحن الرصيد', 'تم إضافة ' . number_format($recharge['amount'], 2) . ' د.ع إلى رصيدك', 'wallet', '/account');
            } elseif ($status === 'rejected') {
                sendNotification($recharge['userId'], '❌ تم رفض طلب الشحن', 'تم رفض طلب شحن الرصيد الخاص بك', 'wallet', '/account');
            }

            response(true, 'تم تحديث حالة طلب الشحن');
            break;
    }
}
