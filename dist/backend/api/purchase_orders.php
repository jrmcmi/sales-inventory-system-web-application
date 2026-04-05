<?php
require_once __DIR__ . '/bootstrap.php';


try {
    // 2. Initialize connection inside try block
    $pdo    = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // ── GET ────────────────────────────────────────────────
    if ($method === 'GET') {
        $id      = isset($_GET['id'])       ? (int)$_GET['id']      : null;
        $search  = isset($_GET['search'])   ? trim($_GET['search'])  : '';
        $status  = isset($_GET['status'])   ? $_GET['status']        : '';
        $page    = isset($_GET['page'])     ? (int)$_GET['page']    : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page']: 10;

        if ($id) {
            $stmt = $pdo->prepare("SELECT po.*, s.supplier_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.supplier_id WHERE po.po_id=:id");
            $stmt->execute([':id' => $id]);
            $order = $stmt->fetch();
            if (!$order) respondError('Purchase order not found', 404);

            $iStmt = $pdo->prepare("SELECT poi.*, p.product_name, p.sku FROM purchase_order_items poi JOIN products p ON poi.product_id=p.product_id WHERE poi.po_id=:id");
            $iStmt->execute([':id' => $id]);
            $order['items'] = $iStmt->fetchAll();
            respond($order);
        }

        $conditions = [];
        $params     = [];

        if ($search) {
            $conditions[] = "(s.supplier_name LIKE :search OR po.po_number LIKE :search)";
            $params[':search'] = "%$search%";
        }
        if ($status) {
            $conditions[] = "po.status = :status";
            $params[':status'] = $status;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT po.*, s.supplier_name, COUNT(poi.poi_id) AS item_count FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.supplier_id LEFT JOIN purchase_order_items poi ON po.po_id=poi.po_id $where GROUP BY po.po_id ORDER BY po.created_at DESC";
        $countSql = "SELECT COUNT(DISTINCT po.po_id) FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.supplier_id $where";
        respond(paginate($pdo, $sql, $countSql, $params, $page, $perPage));
    }

    // ── POST ───────────────────────────────────────────────
    if ($method === 'POST') {
        $body  = getBody();
        $items = $body['items'] ?? [];

        if (empty($body['supplier_id']) || empty($body['po_number'])) respondError('Supplier and PO number are required');

        $pdo->beginTransaction();

        $total = array_reduce($items, fn($s,$i) => $s + (($i['quantity_ordered']??0)*($i['unit_cost']??0)), 0);

        $stmt = $pdo->prepare("INSERT INTO purchase_orders (supplier_id,po_number,status,total_amount,order_date,expected_date,notes) VALUES (:sup,:po,'Draft',:total,:ord,:exp,:notes)");
        $stmt->execute([
            ':sup'   => $body['supplier_id'],
            ':po'    => $body['po_number'],
            ':total' => $total,
            ':ord'   => $body['order_date']    ?: date('Y-m-d'),
                       ':exp'   => $body['expected_date'] ?: null,
                       ':notes' => $body['notes']         ?? null,
        ]);
        $poId = $pdo->lastInsertId();

        if (!empty($items)) {
            $iStmt = $pdo->prepare("INSERT INTO purchase_order_items (po_id,product_id,quantity_ordered,unit_cost) VALUES (:po,:pid,:qty,:cost)");
            foreach ($items as $item) {
                $iStmt->execute([':po'=>$poId,':pid'=>$item['product_id'],':qty'=>$item['quantity_ordered']??0,':cost'=>$item['unit_cost']??0]);
            }
        }

        $pdo->commit();
        respond(['success'=>true,'po_id'=>(int)$poId], 201);
    }

    // ── PUT ────────────────────────────────────────────────
    if ($method === 'PUT') {
        $body = getBody();
        $id   = (int)($body['id'] ?? 0);
        if (!$id) respondError('PO ID is required');

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE purchase_orders SET supplier_id=:sup,po_number=:po,status=:status,order_date=:ord,expected_date=:exp,notes=:notes WHERE po_id=:id");
        $stmt->execute([
            ':sup'    => $body['supplier_id']   ?? '',
            ':po'     => $body['po_number']      ?? '',
            ':status' => $body['status']        ?? 'Draft',
            ':ord'    => $body['order_date']    ?: date('Y-m-d'),
                       ':exp'    => $body['expected_date'] ?: null,
                       ':notes'  => $body['notes']         ?? null,
                       ':id'     => $id,
        ]);

        if (($body['status'] ?? '') === 'Received') {
            $pdo->prepare("UPDATE purchase_orders SET received_date=NOW() WHERE po_id=? AND received_date IS NULL")->execute([$id]);
            $iStmt = $pdo->prepare("UPDATE purchase_order_items SET quantity_received=quantity_ordered WHERE po_id=?");
            $iStmt->execute([$id]);
        }

        $pdo->commit();
        respond(['success'=>true]);
    }

    // ── DELETE ─────────────────────────────────────────────
    if ($method === 'DELETE') {
        // Accepts ID from URL (?id=5) or JSON body
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)(getBody()['id'] ?? 0);

        if (!$id) respondError('PO ID is required');

        // Delete (Cascading deletes in DB should handle purchase_order_items)
        $stmt = $pdo->prepare("DELETE FROM purchase_orders WHERE po_id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            respond(['success' => true, 'message' => 'Purchase order deleted']);
        } else {
            respondError('Purchase order not found', 404);
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
