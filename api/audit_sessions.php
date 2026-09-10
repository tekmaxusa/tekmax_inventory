<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = array_merge($_POST, read_json_body());
$action = $body['action'] ?? ($_GET['action'] ?? '');

if ($method === 'GET') {
    $id = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM audit_sessions WHERE session_id = ? AND store_id = ?');
        $stmt->execute([$id, $storeId]);
        $session = $stmt->fetch();
        if (!$session) {
            json_response(['ok' => false, 'error' => 'Session not found.'], 404);
        }
        json_response(['ok' => true, 'session' => $session]);
    }

    $active = active_audit_session();
    $hist = $pdo->prepare(
        "SELECT s.*, u.name AS created_by_name
         FROM audit_sessions s
         LEFT JOIN users u ON u.user_id = s.created_by
         WHERE s.store_id = ?
         ORDER BY s.session_id DESC
         LIMIT 20"
    );
    $hist->execute([$storeId]);
    $history = $hist->fetchAll();
    json_response(['ok' => true, 'active' => $active, 'sessions' => $history]);
}

if ($method === 'POST' && $action === 'start') {
    $existing = active_audit_session();
    if ($existing) {
        json_response([
            'ok' => false,
            'error' => 'An audit is already in progress.',
            'session' => $existing,
        ], 409);
    }

    $store = current_store();
    $storeLabel = trim((string) ($body['store_name'] ?? ($store['store_name'] ?? 'My Store')));
    if ($storeLabel === '') {
        $storeLabel = 'My Store';
    }
    $date = trim((string) ($body['audit_date'] ?? date('Y-m-d')));

    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare(
            "INSERT INTO audit_sessions (store_id, store_name, audit_date, status, created_by)
             VALUES (?, ?, ?, 'in_progress', ?)"
        );
        $ins->execute([$storeId, $storeLabel, $date, (int) $_SESSION['user_id']]);
        $sessionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO audit_counts (session_id, product_id, physical_qty, count_method, counted_at)
             SELECT ?, product_id, NULL, NULL, NULL
             FROM products WHERE is_active = 1 AND store_id = ?"
        )->execute([$sessionId, $storeId]);

        $pdo->commit();

        $stmt = $pdo->prepare('SELECT * FROM audit_sessions WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        json_response(['ok' => true, 'session' => $stmt->fetch()], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Failed to start audit.'], 500);
    }
}

if ($method === 'POST' && $action === 'save') {
    $sessionId = (int) ($body['session_id'] ?? 0);
    if ($sessionId <= 0) {
        json_response(['ok' => false, 'error' => 'session_id is required.'], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM audit_sessions WHERE session_id = ? AND store_id = ? AND status IN ('in_progress','saved')");
    $stmt->execute([$sessionId, $storeId]);
    $session = $stmt->fetch();
    if (!$session) {
        json_response(['ok' => false, 'error' => 'Active audit session not found.'], 404);
    }

    $upd = $pdo->prepare("UPDATE audit_sessions SET status = 'saved', saved_at = NOW() WHERE session_id = ?");
    $upd->execute([$sessionId]);
    json_response([
        'ok' => true,
        'message' => 'Audit saved. Stock In/Out is unlocked. Resume counting anytime.',
    ]);
}

if ($method === 'POST' && $action === 'close') {
    if (!is_admin()) {
        forbid('Only admins can close an audit and reconcile stock.');
    }
    $sessionId = (int) ($body['session_id'] ?? 0);
    if ($sessionId <= 0) {
        json_response(['ok' => false, 'error' => 'session_id is required.'], 422);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM audit_sessions WHERE session_id = ? AND store_id = ? AND status IN ('in_progress','saved') FOR UPDATE");
        $stmt->execute([$sessionId, $storeId]);
        $session = $stmt->fetch();
        if (!$session) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'Active audit session not found.'], 404);
        }

        $counts = $pdo->prepare(
            "SELECT ac.*, p.system_qty, p.product_name
             FROM audit_counts ac
             JOIN products p ON p.product_id = ac.product_id
             WHERE ac.session_id = ? AND ac.physical_qty IS NOT NULL AND p.store_id = ?"
        );
        $counts->execute([$sessionId, $storeId]);
        $rows = $counts->fetchAll();
        $userId = (int) $_SESSION['user_id'];
        $adjusted = 0;

        $updProd = $pdo->prepare('UPDATE products SET system_qty = ? WHERE product_id = ?');
        $insMove = $pdo->prepare(
            "INSERT INTO stock_movements (store_id, product_id, movement_type, quantity, reason, reference_no, performed_by)
             VALUES (?, ?, ?, ?, 'Audit Adjustment', ?, ?)"
        );

        foreach ($rows as $row) {
            $physical = (int) $row['physical_qty'];
            $system = (int) $row['system_qty'];
            $diff = $physical - $system;
            if ($diff === 0) {
                $updProd->execute([$physical, (int) $row['product_id']]);
                continue;
            }

            $updProd->execute([$physical, (int) $row['product_id']]);
            $type = $diff > 0 ? 'in' : 'out';
            $qty = abs($diff);
            $insMove->execute([
                $storeId,
                (int) $row['product_id'],
                $type,
                $qty,
                'AUDIT-' . $sessionId,
                $userId,
            ]);
            $adjusted++;
        }

        $pdo->prepare("UPDATE audit_sessions SET status = 'closed', saved_at = COALESCE(saved_at, NOW()) WHERE session_id = ?")
            ->execute([$sessionId]);

        $pdo->commit();
        json_response([
            'ok' => true,
            'message' => 'Audit closed and stock reconciled.',
            'adjusted' => $adjusted,
            'counted' => count($rows),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Failed to close audit.'], 500);
    }
}

json_response(['ok' => false, 'error' => 'Unknown action. Use start, save, or close.'], 400);
