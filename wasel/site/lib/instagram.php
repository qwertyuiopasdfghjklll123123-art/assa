<?php
/**
 * بوابة الاشتراك لقسم المحادثات (واتساب): يحتاج باقة فعّالة أو أدمن.
 * (قسم إنستغرام انحذف.)
 */

function chats_allowed(?array $u): bool
{
    return $u && (!empty($u['is_admin']) || effective_plan_active($u));
}

function require_chats(): int
{
    $id = require_login();
    if (!chats_allowed(user_by_id($id))) {
        json_err('قسم المحادثات يحتاج اشتراك فعّال - اشترك بباقة من قسم الباقات', 402, ['needs_plan' => true]);
    }
    return $id;
}
