<?php
/**
 * MySQL (PDO) connection + lightweight query helpers.
 * Mirrors the original query()/queryOne()/execute()/getLastInsertId() signatures
 * so the rest of the app can use the exact same calling convention it always did,
 * just backed by MySQL instead of SQLite.
 */

function db_connect(array $dbConfig): PDO {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['port'] ?? 3306,
        $dbConfig['name'],
        $dbConfig['charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Emulated prepares (not native) so that a named placeholder can be reused
            // more than once in the same query (several handlers below rely on this,
            // e.g. "WHERE email = :identifier OR phone = :identifier"). Values are
            // still safely quoted/escaped by the driver - this does not weaken
            // protection against SQL injection.
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '" . ($dbConfig['charset'] ?? 'utf8mb4') . "'",
        ]);
    } catch (PDOException $e) {
        error_log('❌ Database connection failed: ' . $e->getMessage());
        die('❌ فشل الاتصال بقاعدة البيانات. تحقق من إعدادات الاتصال في config/config.php');
    }

    return $pdo;
}

function query(string $sql, array $params = []): array {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('SQL Error: ' . $e->getMessage() . ' | SQL: ' . $sql);
        }
        return [];
    }
}

function queryOne(string $sql, array $params = []): ?array {
    $rows = query($sql, $params);
    return $rows ? $rows[0] : null;
}

function execute(string $sql, array $params = []): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('SQL Error: ' . $e->getMessage() . ' | SQL: ' . $sql);
        }
        return false;
    }
}

function getLastInsertId(): int {
    global $pdo;
    return (int)$pdo->lastInsertId();
}

/** UPSERT helper: replaces the SQLite "INSERT OR REPLACE INTO settings" idiom. */
function upsertSetting(string $key, string $value): bool {
    return execute(
        "INSERT INTO settings (`key`, value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        [':key' => $key, ':value' => $value]
    );
}
