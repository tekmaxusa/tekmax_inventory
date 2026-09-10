<?php
/**
 * Shared DB-backed rate limiter (works across multiple app instances).
 */
declare(strict_types=1);

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
}

function rate_limit_bucket_hash(string $bucket): string
{
    return hash('sha256', $bucket);
}

function rate_limit_ensure_table(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS rate_limit_hits (
          hit_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          bucket_hash CHAR(64) NOT NULL,
          hit_at     INT UNSIGNED NOT NULL,
          INDEX idx_rate_bucket_time (bucket_hash, hit_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ready = true;
}

/**
 * Drop hits older than the window (and a safety buffer) to keep the table small.
 */
function rate_limit_prune(string $bucketHash, int $windowSeconds): void
{
    $cutoff = time() - max($windowSeconds, 3600) - 3600;
    $stmt = db()->prepare('DELETE FROM rate_limit_hits WHERE bucket_hash = ? AND hit_at < ?');
    $stmt->execute([$bucketHash, $cutoff]);
}

/**
 * @return array{ok:bool,retry_after:int,remaining:int}
 */
function rate_limit_status(string $bucket, int $max, int $windowSeconds): array
{
    rate_limit_ensure_table();
    $hash = rate_limit_bucket_hash($bucket);
    $now = time();
    $since = $now - $windowSeconds;

    $stmt = db()->prepare(
        'SELECT hit_at FROM rate_limit_hits
         WHERE bucket_hash = ? AND hit_at >= ?
         ORDER BY hit_at ASC'
    );
    $stmt->execute([$hash, $since]);
    $hits = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $count = count($hits);

    if ($count >= $max) {
        $oldest = (int) ($hits[0] ?? $now);
        return [
            'ok' => false,
            'retry_after' => max(1, ($oldest + $windowSeconds) - $now),
            'remaining' => 0,
        ];
    }

    return [
        'ok' => true,
        'retry_after' => 0,
        'remaining' => $max - $count,
    ];
}

function rate_limit_hit(string $bucket, int $windowSeconds = 900): void
{
    rate_limit_ensure_table();
    $hash = rate_limit_bucket_hash($bucket);
    $now = time();

    db()->prepare('INSERT INTO rate_limit_hits (bucket_hash, hit_at) VALUES (?, ?)')
        ->execute([$hash, $now]);

    // Opportunistic prune (~10% of hits) so cleanup does not run every request.
    if (random_int(1, 10) === 1) {
        rate_limit_prune($hash, $windowSeconds);
    }
}

function rate_limit_clear(string $bucket): void
{
    rate_limit_ensure_table();
    $hash = rate_limit_bucket_hash($bucket);
    db()->prepare('DELETE FROM rate_limit_hits WHERE bucket_hash = ?')->execute([$hash]);
}
