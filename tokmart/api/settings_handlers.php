<?php
/**
 * Admin settings: Google OAuth, SMTP, site info, policy pages.
 */

function handleSettingsAction(string $action): void {
    switch ($action) {
        case 'saveGoogleOAuth':
            requireAdmin();

            $clientId = getVal('client_id');
            $clientSecret = getVal('client_secret');
            $enabled = isTruthy(getVal('enabled'));

            if (empty($clientId) || empty($clientSecret)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            if (!preg_match('/^[0-9]+-[a-zA-Z0-9_]+\.apps\.googleusercontent\.com$/', $clientId)) {
                response(false, 'Client ID غير صحيح');
            }

            $value = json_encode([
                'client_id' => $clientId, 'client_secret' => $clientSecret, 'enabled' => $enabled,
            ], JSON_UNESCAPED_UNICODE);

            if (upsertSetting('google_oauth', $value)) {
                $_SESSION['google_client_id'] = $clientId;
                $_SESSION['google_client_secret'] = $clientSecret;
                response(true, 'تم حفظ إعدادات Google OAuth');
            }
            response(false, 'فشل حفظ الإعدادات');
            break;

        case 'saveSMTPSettings':
            requireAdmin();

            $smtpData = [
                'host' => getVal('host'),
                'port' => intval(getVal('port', 465)),
                'username' => getVal('username'),
                'password' => getVal('password'),
                'encryption' => getVal('encryption', 'ssl'),
                'from_email' => getVal('from_email'),
                'from_name' => getVal('from_name', 'Tokmart'),
                'enabled' => isTruthy(getVal('enabled')),
            ];

            if (upsertSetting('smtp', json_encode($smtpData, JSON_UNESCAPED_UNICODE))) {
                response(true, 'تم حفظ إعدادات البريد');
            }
            response(false, 'فشل حفظ الإعدادات');
            break;

        case 'saveSiteSettings':
            requireAdmin();

            $name = getVal('name', 'Tokmart');
            $description = getVal('description', '');
            $slogan = getVal('slogan', '🛍️ متجرك الإلكتروني المفضل');

            $logoPath = null;
            $logoData = getImageData('logo');
            if (!empty($logoData)) {
                $existing = getSiteSettings();
                if (!empty($existing['logo'])) {
                    deleteImageFile($existing['logo'], 'site');
                }
                $logoPath = saveImageFromBase64($logoData, UPLOAD_DIR . '/site', 200, 200, 70);
            }

            $current = getSiteSettings();
            unset($current['logo_url']);
            $siteData = array_merge($current, [
                'name' => $name,
                'description' => $description,
                'slogan' => $slogan,
            ]);
            if ($logoPath !== null) {
                $siteData['logo'] = $logoPath;
            }

            if (upsertSetting('site', json_encode($siteData, JSON_UNESCAPED_UNICODE))) {
                response(true, 'تم حفظ إعدادات الموقع', $siteData);
            }
            response(false, 'فشل حفظ الإعدادات');
            break;

        case 'savePolicy':
            requireAdmin();

            $type = getVal('type');
            $content = getVal('content');

            $siteData = getSiteSettings();
            unset($siteData['logo_url']);

            if ($type === 'privacy') {
                $siteData['privacy_policy'] = $content;
            } elseif ($type === 'terms') {
                $siteData['terms_conditions'] = $content;
            } else {
                response(false, 'نوع غير معروف');
            }

            if (upsertSetting('site', json_encode($siteData, JSON_UNESCAPED_UNICODE))) {
                response(true, 'تم حفظ الإعدادات');
            }
            response(false, 'فشل حفظ الإعدادات');
            break;
    }
}
