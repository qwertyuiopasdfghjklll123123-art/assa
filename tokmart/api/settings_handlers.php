<?php
/**
 * Site branding, legal pages, and Google/SMTP integration settings (admin only).
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

            $value = json_encode(['client_id' => $clientId, 'client_secret' => $clientSecret, 'enabled' => $enabled], JSON_UNESCAPED_UNICODE);
            if (upsertSetting('google_oauth', $value)) {
                response(true, 'تم حفظ إعدادات Google OAuth');
            } else {
                response(false, 'فشل حفظ الإعدادات');
            }
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
            } else {
                response(false, 'فشل حفظ الإعدادات');
            }
            break;

        case 'saveSiteSettings':
            requireAdmin();

            $name = getVal('name', 'Tokmart');
            $description = getVal('description', '');
            $slogan = getVal('slogan', '🛍️ متجرك الإلكتروني المفضل');
            $logoData = getImageData('logo');

            // Merge into the existing settings row instead of overwriting it wholesale,
            // so saving the name/logo here never wipes the privacy policy / terms text.
            $existing = queryOne("SELECT value FROM settings WHERE `key` = 'site'");
            $siteData = $existing ? json_decode($existing['value'], true) : [];

            if (!empty($logoData)) {
                if (!empty($siteData['logo'])) {
                    deleteImageFile($siteData['logo'], 'site');
                }
                $siteData['logo'] = saveImageFromBase64($logoData, UPLOAD_DIR . '/site', 200, 200, 70);
            }

            $siteData['name'] = $name;
            $siteData['description'] = $description;
            $siteData['slogan'] = $slogan;

            if (upsertSetting('site', json_encode($siteData, JSON_UNESCAPED_UNICODE))) {
                response(true, 'تم حفظ إعدادات الموقع', $siteData);
            } else {
                response(false, 'فشل حفظ الإعدادات');
            }
            break;

        case 'savePolicy':
            requireAdmin();

            $type = getVal('type');
            $content = getVal('content');

            $existing = queryOne("SELECT value FROM settings WHERE `key` = 'site'");
            $siteData = $existing ? json_decode($existing['value'], true) : [];

            if ($type === 'privacy') {
                $siteData['privacy_policy'] = $content;
            } elseif ($type === 'terms') {
                $siteData['terms_conditions'] = $content;
            } else {
                response(false, 'نوع غير معروف');
            }

            if (upsertSetting('site', json_encode($siteData, JSON_UNESCAPED_UNICODE))) {
                response(true, 'تم حفظ الإعدادات');
            } else {
                response(false, 'فشل حفظ الإعدادات');
            }
            break;
    }
}
