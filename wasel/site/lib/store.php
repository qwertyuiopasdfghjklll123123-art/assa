<?php
/**
 * قاعدة بيانات JSON بسيطة: كل جدول ملف مستقل داخل مجلد data/.
 * - القراءة بقفل مشترك، والتعديل بقفل حصري على الجدول (flock) حتى ما يتلف الملف
 *   لو وصلت طلبين بنفس اللحظة.
 * - الكتابة تتم بملف مؤقت ثم rename (عملية ذرّية) فما يبقى ملف نصّه مكتوب.
 *
 * شكل كل جدول: {"next_id": 5, "rows": {"1": {...}, "2": {...}}}
 * والجداول "الإعدادية" (مثل settings) تُخزن ككائن عادي عبر store_get_doc / store_update_doc.
 */

function store_path(string $table): string
{
    $table = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
    return DATA_DIR . '/' . $table . '.json';
}

function store_lock(string $table, int $mode)
{
    $fh = fopen(store_path($table) . '.lock', 'c');
    if (!$fh) {
        throw new RuntimeException('تعذر فتح ملف القفل - تأكد أن مجلد data قابل للكتابة');
    }
    flock($fh, $mode);
    return $fh;
}

function store_unlock($fh): void
{
    flock($fh, LOCK_UN);
    fclose($fh);
}

function store_load_raw(string $table, $default)
{
    $p = store_path($table);
    if (!is_file($p)) {
        return $default;
    }
    $raw = file_get_contents($p);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function store_save_raw(string $table, $data): void
{
    $p = store_path($table);
    $tmp = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('تعذرت الكتابة بمجلد data');
    }
    rename($tmp, $p);
}

function store_empty_table(): array
{
    return ['next_id' => 1, 'rows' => []];
}

/** كل صفوف الجدول (مصفوفة مرتبة حسب id) */
function store_all(string $table): array
{
    $fh = store_lock($table, LOCK_SH);
    $data = store_load_raw($table, store_empty_table());
    store_unlock($fh);
    $rows = array_values($data['rows'] ?? []);
    usort($rows, fn($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
    return $rows;
}

function store_find(string $table, $id): ?array
{
    $fh = store_lock($table, LOCK_SH);
    $data = store_load_raw($table, store_empty_table());
    store_unlock($fh);
    return $data['rows'][(string)$id] ?? null;
}

/** أول صف تتحقق فيه الدالة */
function store_first(string $table, callable $fn): ?array
{
    foreach (store_all($table) as $row) {
        if ($fn($row)) {
            return $row;
        }
    }
    return null;
}

function store_where(string $table, callable $fn): array
{
    return array_values(array_filter(store_all($table), $fn));
}

/** يضيف صف جديد ويرجع رقمه. لو الصف فيه id نصي (مثل حسابات واتساب) يُستخدم كما هو */
function store_insert(string $table, array $row)
{
    $fh = store_lock($table, LOCK_EX);
    try {
        $data = store_load_raw($table, store_empty_table());
        if (!isset($row['id'])) {
            $row['id'] = (int)$data['next_id'];
            $data['next_id'] = $row['id'] + 1;
        }
        $data['rows'][(string)$row['id']] = $row;
        store_save_raw($table, $data);
        return $row['id'];
    } finally {
        store_unlock($fh);
    }
}

/** تعديل صف واحد عبر دالة تستلم الصف وترجعه معدّلاً (أو null للحذف). يرجع الصف الجديد */
function store_update(string $table, $id, callable $fn): ?array
{
    $fh = store_lock($table, LOCK_EX);
    try {
        $data = store_load_raw($table, store_empty_table());
        $key = (string)$id;
        if (!isset($data['rows'][$key])) {
            return null;
        }
        $new = $fn($data['rows'][$key]);
        if ($new === null) {
            unset($data['rows'][$key]);
        } else {
            $data['rows'][$key] = $new;
        }
        store_save_raw($table, $data);
        return $new;
    } finally {
        store_unlock($fh);
    }
}

/** تعديل جماعي: الدالة تستلم كل الصفوف (بالمرجع) وتعدّل/تحذف بحرية */
function store_update_all(string $table, callable $fn)
{
    $fh = store_lock($table, LOCK_EX);
    try {
        $data = store_load_raw($table, store_empty_table());
        $result = $fn($data['rows']);
        store_save_raw($table, $data);
        return $result;
    } finally {
        store_unlock($fh);
    }
}

function store_delete(string $table, $id): void
{
    store_update($table, $id, fn($r) => null);
}

/** مستند واحد (كائن إعدادات) بدل جدول صفوف */
function store_get_doc(string $name, array $default = []): array
{
    $fh = store_lock($name, LOCK_SH);
    $data = store_load_raw($name, $default);
    store_unlock($fh);
    return array_replace($default, $data);
}

function store_update_doc(string $name, callable $fn, array $default = []): array
{
    $fh = store_lock($name, LOCK_EX);
    try {
        $data = array_replace($default, store_load_raw($name, $default));
        $data = $fn($data);
        store_save_raw($name, $data);
        return $data;
    } finally {
        store_unlock($fh);
    }
}
