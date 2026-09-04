<?php
/**
 * Order placement + status updates (with electronic-balance debit/refund logic).
 */

function handleOrderAction(string $action): void {
    global $pdo, $input;

    switch ($action) {
        case 'addOrderWithTransfer':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }

            $userId = intval(getVal('userId', 0));
            if ($userId != getSafeUserId()) {
                response(false, 'لا يمكنك إنشاء طلب باسم مستخدم آخر');
            }

            $address = trim(getVal('address', ''));
            $phone = trim(getVal('phone', ''));
            $payment = trim(getVal('payment', ''));

            $itemsRaw = $input['items'] ?? '[]';
            $items = [];
            if (is_string($itemsRaw)) {
                if (trim($itemsRaw) !== '' && trim($itemsRaw) !== 'null') {
                    $decoded = json_decode($itemsRaw, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $items = is_array($decoded) ? $decoded : [];
                    } else {
                        $decoded = json_decode(stripslashes(trim($itemsRaw)), true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $items = is_array($decoded) ? $decoded : [];
                        }
                    }
                }
            } elseif (is_array($itemsRaw)) {
                $items = $itemsRaw;
            }
            if (!is_array($items)) $items = [];

            $calculatedTotal = 0;
            foreach ($items as $item) {
                if (is_array($item)) {
                    $calculatedTotal += floatval($item['price'] ?? 0) * intval($item['qty'] ?? 1);
                }
            }

            $total = floatval(getVal('total', 0));
            if ($total <= 0 && $calculatedTotal > 0) $total = $calculatedTotal;

            if (empty($address) || empty($phone)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            if (empty($items)) {
                response(false, 'السلة فارغة أو بيانات المنتجات غير صحيحة');
            }
            if ($total <= 0) {
                response(false, 'المجموع غير صحيح');
            }

            $transferImage = '';

            if ($payment === 'electronic') {
                $user = queryOne("SELECT balance FROM users WHERE id = :id", [':id' => $userId]);
                if (!$user) {
                    response(false, 'المستخدم غير موجود');
                }
                if ($user['balance'] < $total) {
                    response(false, 'رصيدك غير كافي، يرجى شحن الرصيد');
                }
            } elseif ($payment === 'transfer' || $payment === 'bank_transfer') {
                if (!isset($_FILES['transferImage']) || $_FILES['transferImage']['error'] !== UPLOAD_ERR_OK) {
                    response(false, 'الرجاء رفع صورة التحويل');
                }
                $file = $_FILES['transferImage'];
                $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
                $mime = mime_content_type($file['tmp_name']);
                if (!in_array($mime, $allowed, true)) {
                    response(false, 'صيغة الملف غير مدعومة');
                }
                if ($file['size'] > 5 * 1024 * 1024) {
                    response(false, 'حجم الصورة كبير جداً (الحد الأقصى 5MB)');
                }
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = uniqid() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/transfers/' . $filename)) {
                    response(false, 'فشل حفظ صورة التحويل');
                }
                $transferImage = $filename;
            }

            $transferAmount = floatval(getVal('transferAmount', 0));
            $accountNumber = trim(getVal('accountNumber', ''));
            $beneficiary = trim(getVal('beneficiary', ''));

            $maxId = queryOne("SELECT MAX(id) as max FROM orders");
            $newId = intval($maxId['max'] ?? 0) + 1;
            $orderId = 'ORD-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT);

            $sql = "INSERT INTO orders (orderId, userId, address, phone, payment, items, total,
                    transferImage, transferAmount, accountNumber, beneficiary, status, date, createdAt)
                    VALUES (:orderId, :userId, :address, :phone, :payment, :items, :total,
                    :transferImage, :transferAmount, :accountNumber, :beneficiary, 'pending', :date, NOW())";

            $params = [
                ':orderId' => $orderId, ':userId' => $userId, ':address' => $address, ':phone' => $phone,
                ':payment' => $payment, ':items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                ':total' => $total, ':transferImage' => $transferImage, ':transferAmount' => $transferAmount,
                ':accountNumber' => $accountNumber, ':beneficiary' => $beneficiary, ':date' => date('Y-m-d'),
            ];

            if (!execute($sql, $params)) {
                response(false, 'فشل إنشاء الطلب - خطأ في قاعدة البيانات');
            }

            $id = getLastInsertId();

            if ($payment === 'electronic') {
                execute("UPDATE users SET balance = balance - :total WHERE id = :id", [':total' => $total, ':id' => $userId]);
                sendNotification($userId, '💰 خصم رصيد', 'تم خصم ' . number_format($total, 2) . " د.ع من رصيدك للطلب #$orderId", 'wallet', '/order/' . $id);
            }

            $paymentName = $payment === 'cash' ? 'الدفع عند الاستلام' : ($payment === 'electronic' ? 'الدفع الإلكتروني' : 'تحويل بنكي');
            sendNotificationToAllUsers("📋 طلب جديد: $orderId", "طريقة الدفع: $paymentName - المبلغ: " . number_format($total, 2) . ' د.ع', 'order', '/admin/orders.php');

            $order = queryOne("SELECT * FROM orders WHERE id = :id", [':id' => $id]);
            if ($order) {
                $decodedItems = json_decode($order['items'], true);
                $order['items'] = is_array($decodedItems) ? $decodedItems : [];
                if (!empty($order['transferImage'])) {
                    $order['transferImage_url'] = uploadUrl('transfers', $order['transferImage']);
                }
            }

            response(true, 'تم إنشاء الطلب بنجاح', $order);
            break;

        case 'updateOrderStatus':
            requireAdmin();

            $id = intval(getVal('id', 0));
            $status = getVal('status', 'pending');
            $order = queryOne("SELECT * FROM orders WHERE id = :id", [':id' => $id]);

            if (!$order) {
                response(false, 'الطلب غير موجود');
            }

            $oldStatus = $order['status'];
            $isRefunded = intval($order['refunded'] ?? 0);
            $payment = $order['payment'] ?? '';
            $userId = intval($order['userId']);
            $total = floatval($order['total']);

            $shouldRefund = ($status === 'cancelled' && $oldStatus !== 'cancelled' && $payment === 'electronic' && $isRefunded == 0);

            if (!execute("UPDATE orders SET status = :status WHERE id = :id", [':status' => $status, ':id' => $id])) {
                response(false, 'فشل تحديث حالة الطلب');
            }

            if ($shouldRefund && $total > 0 && $userId > 0) {
                if (execute("UPDATE users SET balance = balance + :amount WHERE id = :id", [':amount' => $total, ':id' => $userId])) {
                    execute("UPDATE orders SET refunded = 1 WHERE id = :id", [':id' => $id]);
                    sendNotification($userId, '💰 تم استرداد الرصيد', 'تم استرداد مبلغ ' . number_format($total, 2) . " د.ع إلى رصيدك بسبب إلغاء الطلب #{$order['orderId']}", 'wallet', '/account');
                }
            }

            $statusText = [
                'pending' => 'قيد المعالجة', 'shipped' => 'تم الشحن', 'completed' => 'مكتمل',
                'cancelled' => 'ملغي', 'approved' => 'تم الموافقة', 'rejected' => 'مرفوض',
            ];

            if ($userId > 0 && isset($statusText[$status])) {
                $message = "تم تحديث حالة طلبك #{$order['orderId']} إلى: " . $statusText[$status];
                if ($status === 'approved') {
                    $message = "✅ تم الموافقة على طلبك #{$order['orderId']}";
                } elseif ($status === 'rejected') {
                    $message = "❌ تم رفض طلبك #{$order['orderId']}";
                } elseif ($status === 'cancelled') {
                    $message = "❌ تم إلغاء طلبك #{$order['orderId']}";
                    if ($shouldRefund) {
                        $message .= "\n💰 تم استرداد المبلغ إلى رصيدك (مرة واحدة فقط)";
                    }
                }
                sendNotification($userId, '📦 تحديث حالة الطلب', $message, 'order', '/order/' . $id);
            }

            response(true, 'تم تحديث حالة الطلب');
            break;
    }
}
