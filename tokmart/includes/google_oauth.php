<?php
/**
 * Google Sign-In: server-side ID token verification + login/registration.
 */

function getGoogleOAuthConfig(): array {
    $row = queryOne("SELECT value FROM settings WHERE `key` = 'google_oauth'");
    if ($row) {
        $data = json_decode($row['value'], true);
        if ($data && isset($data['client_id']) && isset($data['client_secret'])) {
            return $data;
        }
    }
    // No real credentials ship in source - configure this from the admin
    // panel's settings page after install.
    return [
        'client_id' => '',
        'client_secret' => '',
        'enabled' => false,
    ];
}

function verifyGoogleToken(string $idToken): ?array {
    $config = getGoogleOAuthConfig();
    if (empty($config['enabled']) || empty($idToken)) {
        return null;
    }

    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $idToken;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("Google token verification failed: HTTP $httpCode");
            return null;
        }
    } else {
        $response = @file_get_contents($url);
        if (!$response) {
            error_log("Google token verification failed: file_get_contents failed");
            return null;
        }
    }

    $data = json_decode($response, true);
    if (!$data || isset($data['error'])) {
        error_log("Google token verification error: " . ($data['error'] ?? 'Unknown error'));
        return null;
    }

    if ($data['aud'] != $config['client_id']) {
        error_log("Google token audience mismatch: " . $data['aud'] . " vs " . $config['client_id']);
        return null;
    }

    if (isset($data['exp']) && $data['exp'] < time()) {
        error_log("Google token expired");
        return null;
    }

    return $data;
}

function loginWithGoogle(string $idToken): array {
    if (empty($idToken)) {
        return ['success' => false, 'message' => 'لم يتم إرسال التوكن'];
    }

    $config = getGoogleOAuthConfig();
    if (empty($config['enabled'])) {
        return ['success' => false, 'message' => 'تسجيل الدخول عبر Google معطل حالياً'];
    }

    $userData = verifyGoogleToken($idToken);
    if (!$userData) {
        return ['success' => false, 'message' => 'توكن Google غير صالح أو منتهي الصلاحية'];
    }

    $email = $userData['email'] ?? '';
    $name = $userData['name'] ?? $userData['given_name'] ?? 'مستخدم Google';
    $googleId = $userData['sub'] ?? '';
    $avatar = $userData['picture'] ?? '';

    if (empty($email) || empty($googleId)) {
        return ['success' => false, 'message' => 'بيانات Google غير مكتملة'];
    }

    $user = queryOne("SELECT * FROM users WHERE email = :email OR google_id = :google_id", [
        ':email' => $email,
        ':google_id' => $googleId,
    ]);

    if ($user) {
        $sql = "UPDATE users SET google_id = :google_id, lastActivity = NOW(), isVerified = 1";
        $params = [':google_id' => $googleId, ':id' => $user['id']];

        if (!empty($avatar) && empty($user['avatar_path'])) {
            $avatarContent = @file_get_contents($avatar);
            if ($avatarContent) {
                $avatarPath = saveImageFromBase64(base64_encode($avatarContent), UPLOAD_DIR . '/avatars', 200, 200, 70);
                if ($avatarPath) {
                    $sql .= ", avatar_path = :avatar_path";
                    $params[':avatar_path'] = $avatarPath;
                }
            }
        }

        $sql .= " WHERE id = :id";

        if (execute($sql, $params)) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['is_admin'] = $user['isAdmin'];
            $_SESSION['is_verified'] = 1;

            $updatedUser = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $user['id']]);
            return ['success' => true, 'message' => 'تم تسجيل الدخول', 'user' => $updatedUser];
        }

        return ['success' => false, 'message' => 'فشل تحديث بيانات المستخدم'];
    }

    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $phone = '07' . random_int(10000000, 99999999);

    $sql = "INSERT INTO users (name, email, password, phone, google_id, isVerified, lastActivity)
            VALUES (:name, :email, :password, :phone, :google_id, 1, NOW())";

    if (!execute($sql, [
        ':name' => $name,
        ':email' => $email,
        ':password' => $password,
        ':phone' => $phone,
        ':google_id' => $googleId,
    ])) {
        return ['success' => false, 'message' => 'حدث خطأ أثناء التسجيل'];
    }

    $id = getLastInsertId();

    if (!empty($avatar)) {
        $avatarContent = @file_get_contents($avatar);
        if ($avatarContent) {
            $avatarPath = saveImageFromBase64(base64_encode($avatarContent), UPLOAD_DIR . '/avatars', 200, 200, 70);
            if ($avatarPath) {
                execute("UPDATE users SET avatar_path = :avatar_path WHERE id = :id", [
                    ':avatar_path' => $avatarPath,
                    ':id' => $id,
                ]);
            }
        }
    }

    $user = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $id]);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['is_admin'] = $user['isAdmin'];
    $_SESSION['is_verified'] = 1;

    sendNotification((int)$id, "🎉 مرحباً بك", "تم إنشاء حسابك بواسطة Google", 'info', '/app');

    return ['success' => true, 'message' => 'تم إنشاء الحساب', 'user' => $user];
}
