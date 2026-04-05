<?php
require_once __DIR__ . '/bootstrap.php';

try {
    // 2. Initialize connection
    $pdo = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // ── GET ────────────────────────────────────────────────
    if ($method === 'GET') {
        $id      = isset($_GET['id'])       ? (int)$_GET['id']     : null;
        $search  = isset($_GET['search'])   ? trim($_GET['search']) : '';
        $page    = isset($_GET['page'])     ? (int)$_GET['page']   : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page']: 10;

        if ($id) {
            $stmt = $pdo->prepare("SELECT s.*, COUNT(p.product_id) AS product_count FROM suppliers s LEFT JOIN products p ON s.supplier_id=p.supplier_id WHERE s.supplier_id=:id GROUP BY s.supplier_id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) respondError('Supplier not found', 404);
            respond($row);
        }

        $where  = $search ? "WHERE s.supplier_name LIKE :search OR s.contact_person LIKE :search" : '';
        $params = $search ? [':search' => "%$search%"] : [];

        $sql = "SELECT s.*, COUNT(p.product_id) AS product_count FROM suppliers s LEFT JOIN products p ON s.supplier_id=p.supplier_id $where GROUP BY s.supplier_id ORDER BY s.supplier_name ASC";
        $countSql = "SELECT COUNT(*) FROM suppliers s $where";
        respond(paginate($pdo, $sql, $countSql, $params, $page, $perPage));
    }

    // ── POST ───────────────────────────────────────────────
    if ($method === 'POST') {
        $body = getBody();
        if (empty($body['supplier_name'])) respondError('Supplier name is required');

        $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_name,contact_person,phone,email,address) VALUES (:name,:contact,:phone,:email,:address)");
        $stmt->execute([
            ':name'    => $body['supplier_name'],
            ':contact' => $body['contact_person'] ?? null,
            ':phone'   => $body['phone'] ?? null,
            ':email'   => $body['email'] ?? null,
            ':address' => $body['address'] ?? null
        ]);
        respond(['success' => true, 'supplier_id' => (int)$pdo->lastInsertId()], 201);
    }

    // ── PUT ────────────────────────────────────────────────
    if ($method === 'PUT') {
        $body = getBody();
        $id   = (int)($body['id'] ?? 0);
        if (!$id) respondError('Supplier ID is required');

        $stmt = $pdo->prepare("UPDATE suppliers SET supplier_name=:name,contact_person=:contact,phone=:phone,email=:email,address=:address WHERE supplier_id=:id");
        $stmt->execute([
            ':name'    => $body['supplier_name'] ?? '',
            ':contact' => $body['contact_person'] ?? null,
            ':phone'   => $body['phone'] ?? null,
            ':email'   => $body['email'] ?? null,
            ':address' => $body['address'] ?? null,
            ':id'      => $id
        ]);
        respond(['success' => true]);
    }

    // ── DELETE ─────────────────────────────────────────────
    if ($method === 'DELETE') {
        // We check BOTH query string (?id=1) and JSON body for the ID
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)(getBody()['id'] ?? 0);

        if (!$id) respondError('Supplier ID is required');

        $pdo->beginTransaction();

        // 1. Get products under this supplier
        $prods = $pdo->prepare("SELECT product_id FROM products WHERE supplier_id = ?");
        $prods->execute([$id]);
        $productIds = $prods->fetchAll(PDO::FETCH_COLUMN);

        // 2. Clean up child records
        foreach ($productIds as $pid) {
            $pdo->prepare("DELETE FROM purchase_order_items    WHERE product_id = ?")->execute([$pid]);
            $pdo->prepare("DELETE FROM sales_transaction_items WHERE product_id = ?")->execute([$pid]);
            $pdo->prepare("DELETE FROM inventory_levels        WHERE product_id = ?")->execute([$pid]);
        }

        // 3. Delete linked orders
        $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id IN (SELECT po_id FROM purchase_orders WHERE supplier_id = ?)")->execute([$id]);
        $pdo->prepare("DELETE FROM purchase_orders      WHERE supplier_id = ?")->execute([$id]);

        // 4. Delete the supplier and products
        $pdo->prepare("DELETE FROM products  WHERE supplier_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?")->execute([$id]);

        $pdo->commit();
        respond(['success' => true, 'message' => 'Supplier and all related data deleted']);
    }

    // If no method matched
    respondError('Method not allowed', 405);

} catch (PDOException $e) {
    // This catches database errors and sends them as JSON (fixes CORS error)
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respondError("Database Error: " . $e->getMessage(), 500);
} catch (Exception $e) {
    // This catches general PHP errors (fixes CORS error)
    respondError("General Error: " . $e->getMessage(), 500);
}
