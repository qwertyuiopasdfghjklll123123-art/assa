<?php
/**
 * Small cross-cutting helpers used across the API handlers and pages.
 */

function response(bool $success, string $message, $data = null): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Reads a field from the request payload ($input, populated by api/index.php), sanitized. */
function getVal(string $key, $default = '') {
    global $input;
    $value = $input[$key] ?? $default;
    if (is_string($value)) {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    return $value;
}

function isTruthy($value): bool {
    return $value === true || $value === 'true' || $value === '1' || $value === 1;
}

/** Builds a public URL for a file under data/uploads/<folder>/, or '' if no filename. */
function uploadUrl(string $folder, ?string $filename): string {
    if (empty($filename)) return '';
    return SITE_URL . APP_BASE_PATH . '/data/uploads/' . $folder . '/' . $filename;
}
