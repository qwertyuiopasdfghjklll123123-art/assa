<?php
/**
 * Shared bootstrap for every admin/*.php page: loads the app, enforces the
 * admin-only guard, and exposes the values the sidebar/layout need.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
requireAdminPage();

$adminName = $_SESSION['user_name'] ?? 'المدير';
$pendingRechargesCount = (int)(queryOne("SELECT COUNT(*) as count FROM balance_recharges WHERE status = 'pending'")['count'] ?? 0);
$pendingOrdersCount = (int)(queryOne("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'")['count'] ?? 0);

// Relative on purpose (mirrors the root index.php's own reasoning): these
// pages live one level under the app root, so "../assets" reaches the same
// shared folder regardless of how deep/shallow the app is installed.
$assetsBase = '../assets';
$apiUrl = '../api/index.php';
$homeUrl = '../index.php';
