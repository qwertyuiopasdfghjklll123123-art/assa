<?php
/**
 * Site-wide settings (name/description/logo/slogan/policies) with sane defaults.
 */

function getSiteSettings(): array {
    $row = queryOne("SELECT value FROM settings WHERE `key` = 'site'");
    $data = $row ? json_decode($row['value'], true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    $defaults = [
        'name' => 'Tokmart',
        'description' => 'متجر إلكتروني متكامل يوفر أفضل المنتجات بأفضل الأسعار',
        'slogan' => '🛍️ متجرك الإلكتروني المفضل',
        'logo' => '',
        'privacy_policy' => 'سياسة الخصوصية الخاصة بمتجر Tokmart...',
        'terms_conditions' => 'الشروط والأحكام الخاصة بمتجر Tokmart...',
        'app_version' => '1.0.0',
    ];

    $site = array_merge($defaults, $data);
    $site['logo_url'] = uploadUrl('site', $site['logo']);
    return $site;
}
