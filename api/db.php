<?php
/**
 * Tiny JSON-file database. One file per collection: { "<id>": { ...fields } }.
 * Every read-modify-write runs under an exclusive lock and the file is replaced atomically.
 *
 * Files are named <collection>.json.php and start with a PHP exit guard, so even on a server
 * that ignores .htaccess, opening one in a browser shows nothing.
 */
final class JsonDB
{
    private const GUARD = "<?php http_response_code(404); exit; ?>\n";

    private static function decode(string $raw): array
    {
        if (str_starts_with($raw, '<?php')) $raw = substr($raw, strpos($raw, "\n") + 1);
        $data = json_decode($raw ?: '{}', true);
        return is_array($data) ? $data : [];
    }

    public function __construct(private string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('cannot-create-data-dir');
        }
    }

    private function path(string $col): string
    {
        if (!preg_match('/^[a-z_]{1,32}$/', $col)) throw new InvalidArgumentException('bad-collection');
        return $this->dir . '/' . $col . '.json.php';
    }

    /** @return array<string, array> */
    public function all(string $col): array
    {
        $file = $this->path($col);
        if (!is_file($file)) return [];
        $fh = fopen($file, 'r');
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return self::decode((string) $raw);
    }

    public function get(string $col, string $id): ?array
    {
        return $this->all($col)[$id] ?? null;
    }

    /** Runs $fn(array $rows): array under an exclusive lock and saves the result. */
    public function mutate(string $col, callable $fn): mixed
    {
        $file = $this->path($col);
        $lock = fopen($file . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $rows = is_file($file) ? self::decode((string) file_get_contents($file)) : [];
            $result = null;
            $rows = $fn($rows, $result);
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, self::GUARD . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            rename($tmp, $file);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function merge(string $col, string $id, array $data): void
    {
        $this->mutate($col, function (array $rows) use ($id, $data) {
            $rows[$id] = array_merge($rows[$id] ?? [], $data);
            return $rows;
        });
    }

    public function delete(string $col, string $id): void
    {
        $this->mutate($col, function (array $rows) use ($id) {
            unset($rows[$id]);
            return $rows;
        });
    }

    public function exists(string $col): bool
    {
        return is_file($this->path($col));
    }
}

function new_id(): string
{
    return base_convert((string) (int) (microtime(true) * 1000), 10, 36) . bin2hex(random_bytes(4));
}

function now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}
