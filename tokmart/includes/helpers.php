<?php
/**
 * Small request/response helpers shared by every API handler.
 */

/**
 * MySQL DECIMAL columns (price, balance, total, ...) come back from PDO as
 * strings, not numbers - unlike SQLite, which returned them as floats. Cast
 * the known numeric fields back to real numbers before they hit JSON, so the
 * frontend's arithmetic (e.g. balance.toFixed()) keeps working unchanged.
 */
const NUMERIC_RESPONSE_FIELDS = ['price', 'oldPrice', 'balance', 'total', 'transferAmount', 'amount', 'rating'];

function castNumericFields($data) {
    if (!is_array($data)) {
        return $data;
    }
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
    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

/** Absolute URL for a file under data/uploads/<folder>/<filename>. */
function uploadUrl(string $folder, ?string $filename): string {
    if (empty($filename)) {
        return '';
    }
    return SITE_URL . APP_BASE_PATH . '/data/uploads/' . $folder . '/' . $filename;
}
