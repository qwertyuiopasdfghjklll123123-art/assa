<?php
/**
 * In-app notification helpers.
 */

function sendNotificationToAllUsers(string $title, string $message, string $type = 'info', string $link = ''): bool {
    $users = query("SELECT id FROM users");
    foreach ($users as $user) {
        execute(
            "INSERT INTO notifications (userId, title, message, type, link, isRead, isPushSent)
             VALUES (:userId, :title, :message, :type, :link, 0, 0)",
            [
                ':userId' => $user['id'],
                ':title' => $title,
                ':message' => $message,
                ':type' => $type,
                ':link' => $link,
            ]
        );
    }
    return true;
}

function sendNotificationToAdmins(string $title, string $message, string $type = 'info', string $link = ''): bool {
    $admins = query("SELECT id FROM users WHERE isAdmin = 1");
    foreach ($admins as $admin) {
        execute(
            "INSERT INTO notifications (userId, title, message, type, link, isRead, isPushSent)
             VALUES (:userId, :title, :message, :type, :link, 0, 0)",
            [
                ':userId' => $admin['id'],
                ':title' => $title,
                ':message' => $message,
                ':type' => $type,
                ':link' => $link,
            ]
        );
    }
    return true;
}

function sendNotification(int $userId, string $title, string $message, string $type = 'info', string $link = ''): int {
    execute(
        "INSERT INTO notifications (userId, title, message, type, link, isRead, isPushSent)
         VALUES (:userId, :title, :message, :type, :link, 0, 0)",
        [
            ':userId' => $userId,
            ':title' => $title,
            ':message' => $message,
            ':type' => $type,
            ':link' => $link,
        ]
    );
    return getLastInsertId();
}

function getReadStatusText(array $msg): string {
    if (empty($msg['isRead']) || empty($msg['readAt'])) {
        return '<span style="color:#999;font-size:10px;">🟡 لم تُقرأ</span>';
    }

    $time = new DateTime($msg['readAt']);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $time->getTimestamp();
    $minutes = floor($diff / 60);

    if ($minutes < 1) {
        return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة الآن</span>';
    } elseif ($minutes < 5) {
        return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة منذ ' . $minutes . ' دقيقة</span>';
    } elseif ($minutes < 60) {
        return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة منذ ' . $minutes . ' دقائق</span>';
    }

    $hours = floor($minutes / 60);
    if ($hours < 24) {
        return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة منذ ' . $hours . ' ساعة</span>';
    }
    $days = floor($hours / 24);
    if ($days == 1) {
        return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة أمس</span>';
    }
    return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة الساعة ' . $time->format('h:i A') . '</span>';
}

function getUserStatus(int $userId): array {
    $user = queryOne("SELECT isAdmin, lastActivity FROM users WHERE id = :id", [':id' => $userId]);
    if (!$user) return ['status' => 'offline', 'text' => '⚪ غير متصل'];

    if ($user['isAdmin'] == 1) {
        return ['status' => 'online', 'text' => '🟢 متصل الآن'];
    }

    if (empty($user['lastActivity'])) {
        return ['status' => 'offline', 'text' => '⚪ غير متصل'];
    }

    $time = new DateTime($user['lastActivity']);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $time->getTimestamp();
    $minutes = floor($diff / 60);

    if ($minutes < 5) {
        return ['status' => 'online', 'text' => '🟢 متصل الآن'];
    } elseif ($minutes < 30) {
        return ['status' => 'away', 'text' => '🟡 غير نشط منذ ' . $minutes . ' دقيقة'];
    }
    return ['status' => 'offline', 'text' => '⚪ غير متصل'];
}
