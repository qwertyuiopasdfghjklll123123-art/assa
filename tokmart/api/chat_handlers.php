<?php
/**
 * User <-> admin support chat (both the account-wide thread and the
 * per-order support shortcut).
 */

function handleChatAction(string $action): void {
    global $pdo;

    switch ($action) {
        case 'getUserChatMessages':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            $userId = intval(getVal('userId', getSafeUserId()));
            $messages = query("SELECT * FROM chats WHERE userId = :userId ORDER BY createdAt ASC", [':userId' => $userId]);
            response(true, 'تم جلب الرسائل بنجاح', $messages);
            break;

        case 'getAdminChats':
            requireAdmin();
            $adminId = getSafeUserId();

            $chats = query("
                SELECT
                    u.id, u.name, u.avatar_path, u.isAdmin,
                    c.message as lastMessage, c.createdAt as lastTime, c.id as lastChatId,
                    c.messageType as lastMessageType, c.fileData as lastFileData,
                    (
                        SELECT COUNT(*) FROM chats c2
                        WHERE c2.userId = u.id AND c2.adminId = :adminId
                        AND c2.sender = 'user' AND c2.isRead = 0
                    ) as unreadCount
                FROM users u
                LEFT JOIN (
                    SELECT userId, message, createdAt, id, adminId, messageType, fileData
                    FROM chats WHERE adminId = :adminId2
                    ORDER BY id DESC LIMIT 1
                ) c ON c.userId = u.id
                WHERE u.id IN (SELECT DISTINCT userId FROM chats WHERE adminId = :adminId3)
                AND u.id != :adminId4 AND u.isAdmin = 0
                ORDER BY c.id DESC
            ", [':adminId' => $adminId, ':adminId2' => $adminId, ':adminId3' => $adminId, ':adminId4' => $adminId]);

            if (empty($chats)) {
                $allUsers = query(
                    "SELECT id, name, avatar_path, isAdmin FROM users WHERE id != :adminId AND isAdmin = 0 ORDER BY id ASC",
                    [':adminId' => $adminId]
                );
                foreach ($allUsers as &$u) {
                    $u['lastMessage'] = 'لا توجد رسائل';
                    $u['lastTime'] = null;
                    $u['unreadCount'] = 0;
                    $u['lastMessageType'] = 'text';
                    $u['lastFileData'] = '';
                }
                unset($u);
                $chats = $allUsers;
            }

            foreach ($chats as &$chatUser) {
                $chatUser['avatar_url'] = $chatUser['avatar_path'] ? uploadUrl('avatars', $chatUser['avatar_path']) : '';
            }
            unset($chatUser);

            response(true, 'تم جلب المحادثات', $chats);
            break;

        case 'getChatMessages':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }

            $userId = intval(getVal('userId', 0));
            $adminId = intval(getVal('adminId', 0));
            $currentUserId = getSafeUserId();
            $isAdmin = !empty($_SESSION['is_admin']);

            if ($isAdmin) {
                if ($adminId != $currentUserId) {
                    response(false, 'غير مصرح لك بعرض هذه الرسائل');
                }
            } elseif ($userId != $currentUserId) {
                response(false, 'غير مصرح لك بعرض هذه الرسائل');
            }

            $messages = query(
                "SELECT * FROM chats WHERE userId = :userId AND adminId = :adminId ORDER BY id ASC LIMIT 500",
                [':userId' => $userId, ':adminId' => $adminId]
            );

            $pdo->beginTransaction();
            try {
                if ($isAdmin) {
                    execute("UPDATE chats SET isRead = 1, readAt = NOW() WHERE userId = :userId AND sender = 'user' AND isRead = 0", [':userId' => $userId]);
                } else {
                    execute("UPDATE chats SET isRead = 1, readAt = NOW() WHERE userId = :userId AND sender = 'admin' AND isRead = 0", [':userId' => $userId]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log("Error updating read status: " . $e->getMessage());
            }

            response(true, 'تم جلب الرسائل', $messages);
            break;

        case 'sendChatMessage':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }

            $userId = intval(getVal('userId', 0));
            $adminId = intval(getVal('adminId', 0));
            $message = trim(getVal('message', ''));
            $messageType = getVal('messageType', 'text');
            $sender = getVal('sender', 'user');

            if (empty($message)) {
                response(false, 'الرسالة فارغة');
            }
            if (strlen($message) > 5000) {
                response(false, 'الرسالة طويلة جداً (الحد الأقصى 5000 حرف)');
            }

            $isAdmin = !empty($_SESSION['is_admin']);
            $currentUserId = getSafeUserId();

            if (!$isAdmin && $sender !== 'user') {
                response(false, 'غير مصرح لك بإرسال رسائل كأدمن');
            }
            if ($isAdmin && $sender !== 'admin') {
                response(false, 'غير مصرح لك بإرسال رسائل كمستخدم');
            }

            if (!$isAdmin) {
                if ($userId != $currentUserId) {
                    response(false, 'لا يمكنك إرسال رسائل باسم مستخدم آخر');
                }
                if ($adminId === 0) {
                    $adminId = 1;
                }
            } else {
                if ($adminId != $currentUserId) {
                    response(false, 'لا يمكنك إرسال رسائل كأدمن آخر');
                }
                if ($userId === 0) {
                    response(false, 'يرجى تحديد المستخدم المستلم');
                }
            }

            if ($isAdmin) {
                $userExists = queryOne("SELECT id, isAdmin FROM users WHERE id = :id", [':id' => $userId]);
                if (!$userExists) {
                    response(false, 'المستخدم غير موجود');
                }
                if ($userExists['isAdmin'] == 1) {
                    response(false, 'لا يمكن إرسال رسائل لأدمن آخر');
                }
            }

            $adminExists = queryOne("SELECT id FROM users WHERE id = :id AND isAdmin = 1", [':id' => $adminId]);
            if (!$adminExists) {
                response(false, 'الأدمن غير موجود');
            }

            $pdo->beginTransaction();
            try {
                $sql = "INSERT INTO chats (userId, adminId, message, messageType, sender, isRead, createdAt)
                        VALUES (:userId, :adminId, :message, :messageType, :sender, 0, NOW())";

                if (execute($sql, [
                    ':userId' => $userId, ':adminId' => $adminId, ':message' => $message,
                    ':messageType' => $messageType, ':sender' => $sender,
                ])) {
                    $id = getLastInsertId();
                    $msg = queryOne("SELECT * FROM chats WHERE id = :id", [':id' => $id]);

                    if ($sender === 'user') {
                        $userName = $_SESSION['user_name'] ?? 'مستخدم';
                        sendNotification($adminId, "💬 رسالة جديدة من " . $userName, $message, 'chat', '/admin/chats');
                    } else {
                        sendNotification($userId, "💬 رد من الدعم الفني", $message, 'chat', '/chat');
                    }

                    execute("UPDATE users SET lastActivity = NOW() WHERE id = :id", [':id' => $currentUserId]);

                    $pdo->commit();
                    response(true, 'تم إرسال الرسالة', $msg);
                } else {
                    $pdo->rollBack();
                    response(false, 'فشل إرسال الرسالة');
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log("Error sending message: " . $e->getMessage());
                response(false, 'حدث خطأ أثناء إرسال الرسالة');
            }
            break;

        case 'sendOrderSupportMessage':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }

            $userId = getSafeUserId();
            $orderId = getVal('orderId');
            $message = getVal('message');

            if (empty($orderId) || empty($message)) {
                response(false, 'بيانات غير مكتملة');
            }

            $order = queryOne("SELECT * FROM orders WHERE orderId = :orderId AND userId = :userId", [
                ':orderId' => $orderId, ':userId' => $userId,
            ]);
            if (!$order) {
                response(false, 'الطلب غير موجود');
            }

            $adminId = 1;
            $sql = "INSERT INTO chats (userId, adminId, message, messageType, sender, isRead, createdAt)
                    VALUES (:userId, :adminId, :message, 'text', 'user', 0, NOW())";

            if (execute($sql, [':userId' => $userId, ':adminId' => $adminId, ':message' => $message])) {
                sendNotification($adminId, "💬 رسالة دعم حول الطلب", $message, 'chat', '/admin/chats');
                response(true, 'تم إرسال رسالتك');
            } else {
                response(false, 'فشل إرسال الرسالة');
            }
            break;
    }
}
