<?php
/**
 * Small cross-cutting helpers used across the API handlers and pages.
 */

/**
 * DB columns of type DECIMAL come back from PDO/MySQL as strings (e.g. "0.00"),
 * unlike INT columns which PDO already returns as native ints. The frontend does
 * arithmetic (.toFixed(), comparisons) on these, so cast them to float before
 * every JSON response - recursively, since getData() nests products inside
 * categories, items inside orders, etc.
 */
const NUMERIC_RESPONSE_FIELDS = ['price', 'oldPrice', 'balance', 'total', 'transferAmount', 'amount', 'rating'];

function castNumericFields($data) {
    if (!is_array($data)) return $data;
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = castNumericFields($value);
        } elseif (in_array($key, NUMERIC_RESPONSE_FIELDS, true) && $value !== null && is_numeric($value)) {
            $data[$key] = (float)$value;
        }
    }
    return $data;
}

function response(bool $success, string $message, $data = null): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => castNumericFields($data)], JSON_UNESCAPED_UNICODE);
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
