<?php
require_once __DIR__ . '/bootstrap.php';


// 2. Wrap in try-catch to prevent the browser from seeing a raw PHP crash
// (which causes the CORS error in React)
try {
    $pdo    = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $rows = $pdo->query("SELECT * FROM categories ORDER BY category_name ASC")->fetchAll();
        respond($rows);
    }

    // If the React app sends POST/PUT/DELETE to this file,
    // it will get a clean 405 error instead of a crash.
    respondError('Method not allowed', 405);

} catch (Exception $e) {
    // Sends the error as JSON so your React 'catch' block can read it
    respondError($e->getMessage(), 500);
}
