<?php
require_once __DIR__ . '/bootstrap.php';

try {
    // 2. Initialize connection
    $pdo = getConnection();

    // ── Revenue & Sales counts ──────────────────────────────
    // Using COALESCE to ensure we return 0 instead of NULL
    $row = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS total_revenue, COUNT(*) AS total_sales FROM sales_transactions WHERE status='Completed'")->fetch();

    // Monthly revenue (current month)
    $monthly = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS monthly_revenue FROM sales_transactions WHERE status='Completed' AND MONTH(transaction_date)=MONTH(NOW()) AND YEAR(transaction_date)=YEAR(NOW())")->fetchColumn();

    // ── General Counts ──────────────────────────────────────
    $productCount  = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $customerCount = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $supplierCount = $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();

    // ── Inventory & PO Alerts ───────────────────────────────
    $lowStockCount = $pdo->query("SELECT COUNT(*) FROM inventory_levels WHERE quantity_on_hand <= reorder_level")->fetchColumn();

    $pendingPos = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Draft','Sent','Partial')")->fetchColumn();

    // ── Recent Activity Lists ───────────────────────────────
    // Recent sales (last 10)
    $recentSales = $pdo->query("
    SELECT st.transaction_id, st.total_amount, st.payment_method, st.status, st.transaction_date,
    COALESCE(CONCAT(c.first_name,' ',c.last_name), 'Walk-in') AS customer_name
    FROM sales_transactions st
    LEFT JOIN customers c ON st.customer_id = c.customer_id
    ORDER BY st.transaction_date DESC
    LIMIT 10
    ")->fetchAll();

    // Low stock items details
    $lowStock = $pdo->query("
    SELECT p.product_name, il.quantity_on_hand, il.reorder_level, s.supplier_name
    FROM inventory_levels il
    JOIN products p  ON il.product_id  = p.product_id
    JOIN suppliers s ON p.supplier_id  = s.supplier_id
    WHERE il.quantity_on_hand <= il.reorder_level
    ORDER BY (il.quantity_on_hand / NULLIF(il.reorder_level, 0)) ASC
    LIMIT 10
    ")->fetchAll();

    // ── Final Response ──────────────────────────────────────
    respond([
        'stats' => [
            'total_revenue'   => (float)($row['total_revenue'] ?? 0),
            'total_sales'     => (int)($row['total_sales'] ?? 0),
            'monthly_revenue' => (float)($monthly ?? 0),
            'total_products'  => (int)$productCount,
            'total_customers' => (int)$customerCount,
            'total_suppliers' => (int)$supplierCount,
            'low_stock_count' => (int)$lowStockCount,
            'pending_pos'     => (int)$pendingPos,
        ],
        'recent_sales' => $recentSales,
        'low_stock'    => $lowStock,
    ]);

} catch (PDOException $e) {
    // If a specific table is missing or a query is wrong, show the SQL error
    respondError("Database Error: " . $e->getMessage(), 500);
} catch (Exception $e) {
    respondError("Server Error: " . $e->getMessage(), 500);
}
