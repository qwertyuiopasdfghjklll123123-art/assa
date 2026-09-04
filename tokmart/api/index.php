<?php
/**
 * Single JSON API entry point. The frontend always calls this file with
 * ?action=<name>, e.g. POST /api/index.php?action=login
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === '') {
    response(false, 'إجراء غير محدد');
}

$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (is_string($contentType) && strpos($contentType, 'application/json') !== false) {
    $rawInput = file_get_contents('php://input');
    $decoded = json_decode($rawInput, true);
    $input = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
} else {
    $input = $_POST ?: $_GET;
}

if (!empty($_FILES)) {
    foreach ($_FILES as $key => $file) {
        if ($file['error'] === UPLOAD_ERR_OK) {
            $input[$key] = $file;
        }
    }
}

require_once __DIR__ . '/auth_handlers.php';
require_once __DIR__ . '/product_handlers.php';
require_once __DIR__ . '/category_handlers.php';
require_once __DIR__ . '/order_handlers.php';
require_once __DIR__ . '/chat_handlers.php';
require_once __DIR__ . '/data_handlers.php';
require_once __DIR__ . '/user_handlers.php';
require_once __DIR__ . '/recharge_handlers.php';
require_once __DIR__ . '/notification_handlers.php';
require_once __DIR__ . '/payment_handlers.php';
require_once __DIR__ . '/settings_handlers.php';

$routes = [
    'forgotPassword' => 'handleAuthAction', 'sendResetCode' => 'handleAuthAction',
    'updateProfile' => 'handleAuthAction', 'googleLogin' => 'handleAuthAction',
    'registerWithOTP' => 'handleAuthAction', 'verifyOTP' => 'handleAuthAction',
    'resendOTP' => 'handleAuthAction', 'login' => 'handleAuthAction',
    'logout' => 'handleAuthAction', 'updateUser' => 'handleAuthAction',
    'requestPasswordReset' => 'handleAuthAction', 'verifyResetCode' => 'handleAuthAction',
    'resetPassword' => 'handleAuthAction',

    'addProduct' => 'handleProductAction', 'updateProduct' => 'handleProductAction',
    'deleteProduct' => 'handleProductAction',

    'addCategory' => 'handleCategoryAction', 'updateCategory' => 'handleCategoryAction',
    'deleteCategory' => 'handleCategoryAction',

    'addOrderWithTransfer' => 'handleOrderAction', 'updateOrderStatus' => 'handleOrderAction',

    'getUserChatMessages' => 'handleChatAction', 'getAdminChats' => 'handleChatAction',
    'getChatMessages' => 'handleChatAction', 'sendChatMessage' => 'handleChatAction',
    'sendOrderSupportMessage' => 'handleChatAction',

    'getData' => 'handleDataAction', 'exportData' => 'handleDataAction',
    'getSiteData' => 'handleDataAction',

    'deleteUser' => 'handleUserAction',

    'requestRecharge' => 'handleRechargeAction', 'getRecharges' => 'handleRechargeAction',
    'updateRechargeStatus' => 'handleRechargeAction',

    'markAllNotificationsRead' => 'handleNotificationAction',
    'deleteAllNotifications' => 'handleNotificationAction',
    'deleteNotification' => 'handleNotificationAction',
    'markNotificationRead' => 'handleNotificationAction',

    'addPayment' => 'handlePaymentAction', 'updatePayment' => 'handlePaymentAction',
    'deletePayment' => 'handlePaymentAction', 'togglePayment' => 'handlePaymentAction',

    'saveGoogleOAuth' => 'handleSettingsAction', 'saveSMTPSettings' => 'handleSettingsAction',
    'saveSiteSettings' => 'handleSettingsAction', 'savePolicy' => 'handleSettingsAction',
];

if (!isset($routes[$action])) {
    response(false, 'إجراء غير معروف');
}

$dispatcher = $routes[$action];
$dispatcher($action);
response(false, 'إجراء غير معروف');
