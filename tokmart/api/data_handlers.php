<?php
/**
 * Bulk data loading for the SPA's initial render, plus admin data export.
 */

function handleDataAction(string $action): void {
    switch ($action) {
        case 'getData':
            $isLoggedIn = isset($_SESSION['user_id']);
            $userId = getSafeUserId();
            $isAdmin = $isLoggedIn && !empty($_SESSION['is_admin']);

            $limit = intval($_GET['limit'] ?? 20);
            $offset = intval($_GET['offset'] ?? 0);

            $products = query("SELECT * FROM products ORDER BY id DESC LIMIT $limit OFFSET $offset");
            $categories = query("SELECT * FROM categories ORDER BY sortOrder ASC");

            $orders = $isAdmin
                ? query("SELECT * FROM orders ORDER BY id DESC LIMIT 50")
                : query("SELECT * FROM orders WHERE userId = :userId ORDER BY id DESC LIMIT 10", [':userId' => $userId]);

            $payments = query("SELECT * FROM payments WHERE enabled = 1");
            $notifications = query("SELECT * FROM notifications WHERE userId = :userId ORDER BY id DESC LIMIT 20", [':userId' => $userId]);
            $chats = query("SELECT * FROM chats WHERE userId = :userId ORDER BY id DESC LIMIT 50", [':userId' => $userId]);
            $recharges = query("SELECT * FROM balance_recharges WHERE userId = :userId ORDER BY id DESC LIMIT 20", [':userId' => $userId]);

            $settings = [];
            foreach (query("SELECT * FROM settings") as $row) {
                $settings[$row['key']] = json_decode($row['value'], true);
            }

            $unreadNotif = queryOne("SELECT COUNT(*) as count FROM notifications WHERE userId = :userId AND isRead = 0", [':userId' => $userId]);

            $flashDeals = query("SELECT * FROM products WHERE isFlashDeal = 1 AND oldPrice > price ORDER BY id DESC LIMIT 10");
            $bestSellers = query("SELECT * FROM products WHERE isBestSeller = 1 ORDER BY orderCount DESC LIMIT 10");
            $newArrivals = query("SELECT * FROM products WHERE isNew = 1 ORDER BY id DESC LIMIT 10");
            $featured = query("SELECT * FROM products WHERE isFeatured = 1 ORDER BY id DESC LIMIT 10");

            $addProductUrls = function (array &$list) {
                foreach ($list as &$p) {
                    $p['image_url'] = $p['image_path'] ? uploadUrl('products', $p['image_path']) : '';
                    $p['images'] = json_decode($p['images'] ?? '[]', true);
                }
                unset($p);
            };
            $addProductUrls($products);
            $addProductUrls($flashDeals);
            $addProductUrls($bestSellers);
            $addProductUrls($newArrivals);
            $addProductUrls($featured);

            foreach ($categories as &$c) {
                $c['icon_image_url'] = $c['icon_image_path'] ? uploadUrl('categories', $c['icon_image_path']) : '';
                $c['banner_image_url'] = $c['banner_image_path'] ? uploadUrl('categories', $c['banner_image_path']) : '';
                $c['products'] = query("SELECT * FROM products WHERE category = :category ORDER BY id DESC LIMIT 10", [':category' => $c['id']]);
                foreach ($c['products'] as &$p) {
                    $p['image_url'] = $p['image_path'] ? uploadUrl('products', $p['image_path']) : '';
                }
                unset($p);
            }
            unset($c);

            response(true, 'تم جلب البيانات', [
                'products' => $products,
                'categories' => $categories,
                'orders' => $orders,
                'payments' => $payments,
                'notifications' => $notifications,
                'chats' => $chats,
                'recharges' => $recharges,
                'settings' => $settings,
                'siteUrl' => SITE_URL,
                'unreadCount' => $unreadNotif['count'] ?? 0,
                'flashDeals' => $flashDeals,
                'bestSellers' => $bestSellers,
                'newArrivals' => $newArrivals,
                'featured' => $featured,
                'totalProducts' => queryOne("SELECT COUNT(*) as count FROM products")['count'] ?? 0,
                'serverTime' => time(),
                'user' => $userId ? queryOne("SELECT id, name, email, phone, balance, isAdmin, adminType, avatar_path FROM users WHERE id = :id", [':id' => $userId]) : null,
            ]);
            break;

        case 'exportData':
            requireAdmin();

            $data = [
                'products' => query("SELECT * FROM products"),
                'categories' => query("SELECT * FROM categories"),
                'orders' => query("SELECT * FROM orders"),
                'users' => query("SELECT id, name, email, phone, balance, isAdmin, adminType, avatar_path, registeredAt FROM users"),
                'payments' => query("SELECT * FROM payments"),
                'chats' => query("SELECT * FROM chats"),
                'exportedAt' => date('Y-m-d H:i:s'),
            ];
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="Tokmart_data_' . date('Y-m-d') . '.json"');
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        case 'getSiteData':
            $site = getSiteSettings();
            response(true, 'تم جلب بيانات الموقع', ['site' => $site]);
            break;
    }
}
