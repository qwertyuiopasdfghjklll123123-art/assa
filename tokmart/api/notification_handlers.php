<?php
/**
 * User notification list management.
 */

function handleNotificationAction(string $action): void {
    if (!validateSession()) {
        response(false, 'يرجى تسجيل الدخول');
    }
    $userId = getSafeUserId();

    switch ($action) {
        case 'markAllNotificationsRead':
            execute("UPDATE notifications SET isRead = 1 WHERE userId = :userId", [':userId' => $userId]);
            response(true, 'تم تحديث جميع الإشعارات كمقروءة');
            break;

        case 'deleteAllNotifications':
            execute("DELETE FROM notifications WHERE userId = :userId", [':userId' => $userId]);
            response(true, 'تم حذف جميع الإشعارات');
            break;

        case 'deleteNotification':
            $id = intval(getVal('id', 0));
            execute("DELETE FROM notifications WHERE id = :id AND userId = :userId", [':id' => $id, ':userId' => $userId]);
            response(true, 'تم حذف الإشعار');
            break;

        case 'markNotificationRead':
            $id = intval(getVal('id', 0));
            execute("UPDATE notifications SET isRead = 1 WHERE id = :id AND userId = :userId", [':id' => $id, ':userId' => $userId]);
            response(true, 'تم تحديث الإشعار كمقروء');
            break;
    }
}
