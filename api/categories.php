<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = array_merge($_POST, read_json_body());
if (isset($body['_method'])) {
    $method = strtoupper((string) $body['_method']);
}

if ($method === 'GET') {
    $stmt = $pdo->prepare(
        "SELECT c.*, COUNT(p.product_id) AS product_count
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.category_id AND p.is_active = 1 AND p.store_id = c.store_id
         WHERE c.store_id = ?
         GROUP BY c.category_id
         ORDER BY c.category_name ASC"
    );
    $stmt->execute([$storeId]);
    $rows = $stmt->fetchAll();
    json_response(['ok' => true, 'categories' => $rows]);
}

if ($method === 'POST') {
    // Any logged-in user can create categories (needed from Add Item); edit/delete stay admin-only.
    $name = trim((string) ($body['category_name'] ?? ''));
    if ($name === '') {
        json_response(['ok' => false, 'error' => 'Category name is required.'], 422);
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO categories (store_id, category_name) VALUES (?, ?)');
        $stmt->execute([$storeId, $name]);
        $id = (int) $pdo->lastInsertId();
        json_response([
            'ok' => true,
            'category' => ['category_id' => $id, 'category_name' => $name, 'product_count' => 0],
        ], 201);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            // Already exists — return the existing row so Add Item can still select it
            $existing = $pdo->prepare(
                'SELECT category_id, category_name FROM categories WHERE store_id = ? AND category_name = ? LIMIT 1'
            );
            $existing->execute([$storeId, $name]);
            $row = $existing->fetch();
            if ($row) {
                json_response([
                    'ok' => true,
                    'existing' => true,
                    'category' => [
                        'category_id' => (int) $row['category_id'],
                        'category_name' => $row['category_name'],
                        'product_count' => 0,
                    ],
                ]);
            }
            json_response(['ok' => false, 'error' => 'Category already exists.'], 409);
        }
        json_response(['ok' => false, 'error' => 'Failed to create category.'], 500);
    }
}

if ($method === 'PUT' || $method === 'PATCH') {
    if (!is_admin()) {
        forbid('Only admins can manage categories.');
    }
    $id = (int) ($body['category_id'] ?? 0);
    $name = trim((string) ($body['category_name'] ?? ''));
    if ($id <= 0 || $name === '') {
        json_response(['ok' => false, 'error' => 'category_id and category_name are required.'], 422);
    }
    try {
        $stmt = $pdo->prepare('UPDATE categories SET category_name = ? WHERE category_id = ? AND store_id = ?');
        $stmt->execute([$name, $id, $storeId]);
        json_response(['ok' => true, 'category' => ['category_id' => $id, 'category_name' => $name]]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            json_response(['ok' => false, 'error' => 'Category already exists.'], 409);
        }
        json_response(['ok' => false, 'error' => 'Failed to update category.'], 500);
    }
}

if ($method === 'DELETE') {
    if (!is_admin()) {
        forbid('Only admins can manage categories.');
    }
    $id = (int) ($body['category_id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'category_id is required.'], 422);
    }
    $used = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ? AND store_id = ? AND is_active = 1');
    $used->execute([$id, $storeId]);
    if ((int) $used->fetchColumn() > 0) {
        json_response(['ok' => false, 'error' => 'Cannot delete: category has active products.'], 409);
    }
    try {
        $pdo->beginTransaction();
        // Soft-deleted products may still reference this category (FK)
        $pdo->prepare('UPDATE products SET category_id = NULL WHERE category_id = ? AND store_id = ? AND is_active = 0')
            ->execute([$id, $storeId]);
        $stmt = $pdo->prepare('DELETE FROM categories WHERE category_id = ? AND store_id = ?');
        $stmt->execute([$id, $storeId]);
        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Failed to delete category.'], 500);
    }
}

json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
