<?php
/**
 * Session / authorization helpers.
 */

function validateSession(): bool {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $user = queryOne("SELECT id, isAdmin, isVerified FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
    if (!$user) {
        session_destroy();
        return false;
    }
    $_SESSION['is_admin'] = $user['isAdmin'];
    $_SESSION['is_verified'] = $user['isVerified'];
    return true;
}

/** Used inside API handlers: replies with a JSON error and stops execution. */
function requireAdmin(): bool {
    if (!validateSession() || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
        response(false, 'غير مصرح لك بهذا الإجراء');
    }
    return true;
}

/** Used at the top of admin/*.php pages: redirects to the login screen instead of returning JSON. */
function requireAdminPage(): void {
    if (!validateSession() || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
        header('Location: ' . APP_BASE_PATH . '/app.php?page=account');
        exit;
    }
}

function getSafeUserId(): int {
    return isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
}

function getSafeAdminId(): int {
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        return getSafeUserId();
    }
    return 1;
}

function generateOTP(int $length = 6): string {
    return str_pad((string)random_int(0, 999999), $length, '0', STR_PAD_LEFT);
}

function generateResetCode(int $length = 6): string {
    return str_pad((string)random_int(0, 999999), $length, '0', STR_PAD_LEFT);
}
