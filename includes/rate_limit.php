<?php
/**
 * File-based rate limiter (login brute-force protection).
 */
declare(strict_types=1);

function rate_limit_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ims_rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
}

/**
 * @return array{ok:bool,retry_after:int,remaining:int}
 */
function rate_limit_status(string $bucket, int $max, int $windowSeconds): array
{
    $file = rate_limit_dir() . DIRECTORY_SEPARATOR . hash('sha256', $bucket) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $hits = array_values(array_filter($decoded, static function ($t) use ($now, $windowSeconds) {
                return is_int($t) && $t >= ($now - $windowSeconds);
            }));
        }
    }
    $count = count($hits);
    if ($count >= $max) {
        $oldest = $hits[0] ?? $now;
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
    $file = rate_limit_dir() . DIRECTORY_SEPARATOR . hash('sha256', $bucket) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $hits = array_values(array_filter($decoded, static function ($t) use ($now, $windowSeconds) {
                return is_int($t) && $t >= ($now - $windowSeconds);
            }));
        }
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
}

function rate_limit_clear(string $bucket): void
{
    $file = rate_limit_dir() . DIRECTORY_SEPARATOR . hash('sha256', $bucket) . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
}
