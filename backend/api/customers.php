<?php
require_once __DIR__ . '/bootstrap.php';

try {
    // 2. Initialize connection safely
    $pdo    = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // ── GET ────────────────────────────────────────────────
    if ($method === 'GET') {
        $id      = isset($_GET['id'])       ? (int)$_GET['id']      : null;
        $search  = isset($_GET['search'])   ? trim($_GET['search'])  : '';
        $page    = isset($_GET['page'])     ? (int)$_GET['page']    : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page']: 10;

        if ($id) {
            $stmt = $pdo->prepare("SELECT c.*, COUNT(st.transaction_id) AS total_purchases FROM customers c LEFT JOIN sales_transactions st ON c.customer_id=st.customer_id WHERE c.customer_id=:id GROUP BY c.customer_id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) respondError('Customer not found', 404);
            respond($row);
        }

        $where  = $search ? "WHERE c.first_name LIKE :search OR c.last_name LIKE :search OR c.email LIKE :search" : '';
        $params = $search ? [':search' => "%$search%"] : [];

        $sql = "SELECT c.*, COUNT(st.transaction_id) AS total_purchases FROM customers c LEFT JOIN sales_transactions st ON c.customer_id=st.customer_id $where GROUP BY c.customer_id ORDER BY c.first_name, c.last_name ASC";
        $countSql = "SELECT COUNT(*) FROM customers c $where";
        respond(paginate($pdo, $sql, $countSql, $params, $page, $perPage));
    }

    // ── POST ───────────────────────────────────────────────
    if ($method === 'POST') {
        $body = getBody();
        if (empty($body['first_name']) || empty($body['last_name'])) {
            respondError('First and last name are required');
        }

        $stmt = $pdo->prepare("INSERT INTO customers (first_name, last_name, email, phone, address) VALUES (:fn, :ln, :email, :phone, :address)");
        $stmt->execute([
            ':fn'      => $body['first_name'],
            ':ln'      => $body['last_name'],
            ':email'   => $body['email']   ?? null,
            ':phone'   => $body['phone']   ?? null,
            ':address' => $body['address'] ?? null
        ]);
        respond(['success' => true, 'customer_id' => (int)$pdo->lastInsertId()], 201);
    }

    // ── PUT ────────────────────────────────────────────────
    if ($method === 'PUT') {
        $body = getBody();
        $id   = (int)($body['id'] ?? 0);
        if (!$id) respondError('Customer ID is required');

        $stmt = $pdo->prepare("UPDATE customers SET first_name=:fn, last_name=:ln, email=:email, phone=:phone, address=:address WHERE customer_id=:id");
        $stmt->execute([
            ':fn'      => $body['first_name'] ?? '',
            ':ln'      => $body['last_name']  ?? '',
            ':email'   => $body['email']      ?? null,
            ':phone'   => $body['phone']      ?? null,
            ':address' => $body['address']    ?? null,
            ':id'      => $id
        ]);
        respond(['success' => true]);
    }

    // ── DELETE ─────────────────────────────────────────────
    if ($method === 'DELETE') {
        // Detect ID from either the URL (?id=5) or the JSON body
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)(getBody()['id'] ?? 0);

        if (!$id) respondError('Customer ID is required');

        // Note: If you have sales linked to this customer,
        // you may need to handle them (e.g. set customer_id to NULL in sales_transactions)
        // or the database will prevent deletion due to Foreign Keys.
        $stmt = $pdo->prepare("DELETE FROM customers WHERE customer_id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            respond(['success' => true, 'message' => 'Customer deleted']);
        } else {
            respondError('Customer not found', 404);
        }
    }

    respondError('Method not allowed', 405);

} catch (PDOException $e) {
    // If you try to delete a customer who has existing sales, this catch block
    // will now send back a clear message rather than a CORS error.
    respondError("Database Error: " . $e->getMessage(), 500);
} catch (Exception $e) {
    respondError("Server Error: " . $e->getMessage(), 500);
}
