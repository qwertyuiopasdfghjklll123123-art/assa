<?php
/**
 * Site-wide branding/policy settings, shared by index.php and every admin page.
 */

function getSiteSettings(): array {
    $row = queryOne("SELECT value FROM settings WHERE `key` = 'site'");
    $data = $row ? json_decode($row['value'], true) : [];

    $defaultPrivacy = "سياسة الخصوصية - Tokmart\n\nنلتزم بحماية خصوصية بياناتك.";
    $defaultTerms = "الشروط والأحكام - Tokmart\n\nباستخدامك للمنصة توافق على هذه الشروط.";

    $name = $data['name'] ?? 'Tokmart';
    $logo = $data['logo'] ?? '';

    return [
        'name' => $name,
        'description' => $data['description'] ?? '',
        'slogan' => $data['slogan'] ?? '🛍️ متجرك الإلكتروني المفضل',
        'logo_url' => $logo ? uploadUrl('site', $logo) : '',
        'privacy_policy' => $data['privacy_policy'] ?? $defaultPrivacy,
        'terms_conditions' => $data['terms_conditions'] ?? $defaultTerms,
        'app_version' => $data['app_version'] ?? '1.0.0',
    ];
}
