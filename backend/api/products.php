<?php
require_once __DIR__ . '/bootstrap.php';


try {
    // 2. Initialize connection
    $pdo    = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // ── GET ────────────────────────────────────────────────
    if ($method === 'GET') {
        $id      = isset($_GET['id'])       ? (int)$_GET['id']       : null;
        $search  = isset($_GET['search'])   ? trim($_GET['search'])   : '';
        $page    = isset($_GET['page'])     ? (int)$_GET['page']     : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;

        if ($id) {
            $stmt = $pdo->prepare("
            SELECT p.*, c.category_name, s.supplier_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers  s ON p.supplier_id  = s.supplier_id
            WHERE p.product_id = :id
            ");
            $stmt->execute([':id' => $id]);
            $product = $stmt->fetch();
            if (!$product) respondError('Product not found', 404);
            respond($product);
        }

        $where  = $search ? "WHERE p.product_name LIKE :search OR p.sku LIKE :search" : '';
        $params = $search ? [':search' => "%$search%"] : [];

        $sql = "
        SELECT p.*, c.category_name, s.supplier_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        LEFT JOIN suppliers  s ON p.supplier_id  = s.supplier_id
        $where
        ORDER BY p.product_name ASC
        ";
        $countSql = "SELECT COUNT(*) FROM products p $where";
        respond(paginate($pdo, $sql, $countSql, $params, $page, $perPage));
    }

    // ── POST ───────────────────────────────────────────────
    if ($method === 'POST') {
        $body = getBody();
        $required = ['product_name','sku'];
        foreach ($required as $f) {
            if (empty($body[$f])) respondError("Field '$f' is required");
        }

        $stmt = $pdo->prepare("
        INSERT INTO products (category_id, supplier_id, product_name, sku, unit_price, cost_price, expiration_date)
        VALUES (:category_id, :supplier_id, :product_name, :sku, :unit_price, :cost_price, :expiration_date)
        ");
        $stmt->execute([
            ':category_id'     => $body['category_id']    ?: null,
            ':supplier_id'     => $body['supplier_id']    ?: null,
            ':product_name'    => $body['product_name'],
            ':sku'              => $body['sku'],
            ':unit_price'      => $body['unit_price']      ?? 0,
            ':cost_price'      => $body['cost_price']      ?? 0,
            ':expiration_date' => $body['expiration_date'] ?: null,
        ]);
        $newId = $pdo->lastInsertId();

        // Auto-create inventory record
        $pdo->prepare("INSERT INTO inventory_levels (product_id) VALUES (?)")->execute([$newId]);

        respond(['success' => true, 'product_id' => (int)$newId], 201);
    }

    // ── PUT ────────────────────────────────────────────────
    if ($method === 'PUT') {
        $body = getBody();
        $id   = (int)($body['id'] ?? 0);
        if (!$id) respondError('Product ID is required');

        $stmt = $pdo->prepare("
        UPDATE products SET
        category_id     = :category_id,
        supplier_id     = :supplier_id,
        product_name    = :product_name,
        sku             = :sku,
        unit_price      = :unit_price,
        cost_price      = :cost_price,
        expiration_date = :expiration_date
        WHERE product_id = :id
        ");
        $stmt->execute([
            ':category_id'     => $body['category_id']    ?: null,
            ':supplier_id'     => $body['supplier_id']    ?: null,
            ':product_name'    => $body['product_name']   ?? '',
            ':sku'              => $body['sku']            ?? '',
            ':unit_price'      => $body['unit_price']      ?? 0,
            ':cost_price'      => $body['cost_price']      ?? 0,
            ':expiration_date' => $body['expiration_date'] ?: null,
            ':id'              => $id,
        ]);
        respond(['success' => true]);
    }

    // ── DELETE ─────────────────────────────────────────────
    if ($method === 'DELETE') {
        // Look for ID in query string (?id=123) first, then body
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)(getBody()['id'] ?? 0);

        if (!$id) respondError('Product ID is required');

        $pdo->beginTransaction();

        // Delete all linked child records first to satisfy Foreign Key constraints
        $pdo->prepare("DELETE FROM purchase_order_items    WHERE product_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM sales_transaction_items WHERE product_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM inventory_levels        WHERE product_id = ?")->execute([$id]);

        // Finally delete the product
        $stmt = $pdo->prepare("DELETE FROM products WHERE product_id = ?");
        $stmt->execute([$id]);

        $pdo->commit();

        if ($stmt->rowCount() > 0) {
            respond(['success' => true, 'message' => 'Product deleted']);
        } else {
            respondError('Product not found', 404);
        }
    }

    respondError('Method not allowed', 405);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $msg = str_contains($e->getMessage(), 'Duplicate') ? 'SKU already exists' : $e->getMessage();
    respondError("Database Error: " . $msg, 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respondError("Server Error: " . $e->getMessage(), 500);
}
