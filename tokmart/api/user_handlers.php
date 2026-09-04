<?php
/**
 * Admin user management.
 */

function handleUserAction(string $action): void {
    switch ($action) {
        case 'deleteUser':
            requireAdmin();

            $id = intval(getVal('id', 0));
            if ($id == 1) {
                response(false, 'لا يمكن حذف المستخدم الرئيسي');
            }
            if (execute("DELETE FROM users WHERE id = :id", [':id' => $id])) {
                response(true, 'تم حذف المستخدم بنجاح');
            }
            response(false, 'فشل حذف المستخدم');
            break;
    }
}
