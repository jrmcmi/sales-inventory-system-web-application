<?php
require_once __DIR__ . '/bootstrap.php';

try {
    // 2. Initialize connection
    $pdo    = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // ── GET ────────────────────────────────────────────────
    if ($method === 'GET') {
        $search  = isset($_GET['search'])   ? trim($_GET['search'])  : '';
        $filter  = isset($_GET['filter'])   ? $_GET['filter']        : 'all';
        $page    = isset($_GET['page'])     ? (int)$_GET['page']    : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page']: 20;

        $conditions = [];
        $params     = [];

        if ($search) {
            $conditions[] = "(p.product_name LIKE :search OR p.sku LIKE :search)";
            $params[':search'] = "%$search%";
        }

        if ($filter === 'low_stock') {
            $conditions[] = "il.quantity_on_hand <= il.reorder_level";
        } elseif ($filter === 'critical') {
            $conditions[] = "il.quantity_on_hand <= 5";
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
        SELECT il.*, p.product_name, p.sku, c.category_name
        FROM inventory_levels il
        JOIN products p    ON il.product_id  = p.product_id
        LEFT JOIN categories c ON p.category_id = c.category_id
        $where
        ORDER BY il.quantity_on_hand ASC
        ";

        $countSql = "SELECT COUNT(*) FROM inventory_levels il JOIN products p ON il.product_id=p.product_id $where";

        respond(paginate($pdo, $sql, $countSql, $params, $page, $perPage));
    }

    // ── PUT — Update stock ─────────────────────────────────
    if ($method === 'PUT') {
        $body = getBody();
        // Allow ID from body (standard for PUT) or URL
        $id = (int)($body['id'] ?? ($_GET['id'] ?? 0));

        if (!$id) respondError('Inventory ID is required');

        $stmt = $pdo->prepare("
        UPDATE inventory_levels
        SET quantity_on_hand=:qty, reorder_level=:reorder, reorder_quantity=:reorder_qty
        WHERE inventory_id=:id
        ");

        $stmt->execute([
            ':qty'         => (int)($body['quantity_on_hand']  ?? 0),
                       ':reorder'     => (int)($body['reorder_level']      ?? 10),
                       ':reorder_qty' => (int)($body['reorder_quantity']  ?? 50),
                       ':id'          => $id,
        ]);

        if ($stmt->rowCount() > 0) {
            respond(['success' => true, 'message' => 'Inventory updated']);
        } else {
            // Note: rowCount() is 0 if you send the exact same data as already exists in the DB
            respond(['success' => true, 'message' => 'No changes made or ID not found']);
        }
    }

    // If no method matched
    respondError('Method not allowed', 405);

} catch (PDOException $e) {
    respondError("Database Error: " . $e->getMessage(), 500);
} catch (Exception $e) {
    respondError("Server Error: " . $e->getMessage(), 500);
}
