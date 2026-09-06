<?php
/**
 * Session/auth guards shared by the API and the admin panel pages.
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

/** For API actions: responds with a JSON error and exits if the caller isn't an admin. */
function requireAdmin(): bool {
    if (!validateSession() || empty($_SESSION['is_admin'])) {
        response(false, 'غير مصرح لك بهذا الإجراء');
    }
    return true;
}

/** For admin/*.php pages: redirects to the login flow instead of returning JSON. */
function requireAdminPage(): void {
    if (!validateSession() || empty($_SESSION['is_admin'])) {
        header('Location: ' . APP_BASE_PATH . '/index.php');
        exit;
    }
}

function getSafeUserId(): int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
}

function getSafeAdminId(): int {
    if (!empty($_SESSION['is_admin'])) {
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
