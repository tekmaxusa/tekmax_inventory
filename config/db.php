<?php
/**
 * PDO MySQL/MariaDB connection for Inventory Management System.
 * Target: PHP 8.2+ (XAMPP 8.2.12), MariaDB 10.4+
 *
 * Production: set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS via environment
 * (or Apache SetEnv). Do not use root with an empty password on a public server.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'inventory_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a shared PDO instance (singleton).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection failed. Check config/db.php or DB_* environment variables.']);
        exit;
    }

    require_once dirname(__DIR__) . '/includes/schema.php';
    try {
        ensure_schema($pdo);
    } catch (Throwable $e) {
        // Schema ensure is best-effort; APIs still run on the base tables.
    }

    return $pdo;
}
