<?php
require_once __DIR__ . '/bootstrap.php';


try {
    // 2. Initialize connection
    $pdo = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // ── GET ────────────────────────────────────────────────
    if ($method === 'GET') {
        $id      = isset($_GET['id'])       ? (int)$_GET['id']      : null;
        $search  = isset($_GET['search'])   ? trim($_GET['search'])  : '';
        $status  = isset($_GET['status'])   ? $_GET['status']        : '';
        $page    = isset($_GET['page'])     ? (int)$_GET['page']    : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page']: 10;

        if ($id) {
            $stmt = $pdo->prepare("
            SELECT st.*, COALESCE(CONCAT(c.first_name,' ',c.last_name),'Walk-in') AS customer_name
            FROM sales_transactions st
            LEFT JOIN customers c ON st.customer_id=c.customer_id
            WHERE st.transaction_id=:id
            ");
            $stmt->execute([':id' => $id]);
            $sale = $stmt->fetch();
            if (!$sale) respondError('Transaction not found', 404);

            // Fetch items for this sale
            $itemStmt = $pdo->prepare("
            SELECT sti.*, p.product_name, p.sku
            FROM sales_transaction_items sti
            JOIN products p ON sti.product_id=p.product_id
            WHERE sti.transaction_id=:id
            ");
            $itemStmt->execute([':id' => $id]);
            $sale['items'] = $itemStmt->fetchAll();
            respond($sale);
        }

        $conditions = [];
        $params     = [];

        if ($search) {
            $conditions[] = "(c.first_name LIKE :search OR c.last_name LIKE :search)";
            $params[':search'] = "%$search%";
        }
        if ($status) {
            $conditions[] = "st.status = :status";
            $params[':status'] = $status;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
        SELECT st.*, COALESCE(CONCAT(c.first_name,' ',c.last_name),'Walk-in') AS customer_name,
        COUNT(sti.item_id) AS item_count
        FROM sales_transactions st
        LEFT JOIN customers c ON st.customer_id=c.customer_id
        LEFT JOIN sales_transaction_items sti ON st.transaction_id=sti.transaction_id
        $where
        GROUP BY st.transaction_id
        ORDER BY st.transaction_date DESC
        ";
        $countSql = "SELECT COUNT(DISTINCT st.transaction_id) FROM sales_transactions st LEFT JOIN customers c ON st.customer_id=c.customer_id $where";
        respond(paginate($pdo, $sql, $countSql, $params, $page, $perPage));
    }

    // ── POST — Record new sale ─────────────────────────────
    if ($method === 'POST') {
        $body  = getBody();
        $items = $body['items'] ?? [];

        if (empty($items)) respondError('At least one item is required');

        $pdo->beginTransaction();

        $total = array_reduce($items, fn($s,$i) => $s + (($i['quantity']??0)*($i['unit_price']??0)), 0);

        $stmt = $pdo->prepare("
        INSERT INTO sales_transactions (customer_id,total_amount,tax_amount,discount_amount,payment_method,status,notes)
        VALUES (:cust,:total,:tax,:disc,:pay,:status,:notes)
        ");
        $stmt->execute([
            ':cust'   => $body['customer_id']     ?: null,
            ':total'  => $total,
            ':tax'    => $body['tax_amount']        ?? 0,
            ':disc'   => $body['discount_amount']   ?? 0,
            ':pay'    => $body['payment_method']    ?? 'Cash',
            ':status' => 'Completed',
            ':notes'  => $body['notes']             ?? null,
        ]);
        $transId = $pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
        INSERT INTO sales_transaction_items (transaction_id,product_id,quantity,unit_price,subtotal)
        VALUES (:tid,:pid,:qty,:price,:sub)
        ");
        foreach ($items as $item) {
            $sub = ($item['quantity']??0) * ($item['unit_price']??0);
            $itemStmt->execute([
                ':tid'   => $transId,
                ':pid'   => $item['product_id'],
                ':qty'   => $item['quantity']  ?? 1,
                ':price' => $item['unit_price'] ?? 0,
                ':sub'   => $sub,
            ]);
        }

        $pdo->commit();
        respond(['success'=>true, 'transaction_id'=>(int)$transId], 201);
    }

    // ── PUT — Update status ────────────────────────────────
    if ($method === 'PUT') {
        $body = getBody();
        $id   = (int)($body['id'] ?? 0);
        if (!$id) respondError('Transaction ID is required');

        $stmt = $pdo->prepare("UPDATE sales_transactions SET status=:status WHERE transaction_id=:id");
        $stmt->execute([':status'=>$body['status']??'Completed', ':id'=>$id]);
        respond(['success'=>true]);
    }

    // ── DELETE ─────────────────────────────────────────────
    if ($method === 'DELETE') {
        // Checking both URL query and Body for maximum compatibility
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)(getBody()['id'] ?? 0);

        if (!$id) respondError('Transaction ID is required');

        // Delete (Note: triggers on your DB should handle inventory restoration)
        $stmt = $pdo->prepare("DELETE FROM sales_transactions WHERE transaction_id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            respond(['success' => true, 'message' => 'Sale deleted']);
        } else {
            respondError('Sale not found', 404);
        }
    }

    respondError('Method not allowed', 405);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respondError("Database Error: " . $e->getMessage(), 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respondError("Server Error: " . $e->getMessage(), 500);
}
