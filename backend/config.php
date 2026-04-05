<?php
// ── Database Configuration — AwardSpace ──────────────────
// Replace these values with your AwardSpace MySQL credentials
// Found in: AwardSpace Control Panel → MySQL Databases

define('DB_HOST', 'your_host_here');         // Usually 'localhost' on AwardSpace
define('DB_NAME', 'secret');      // e.g. 12345678_inventory
define('DB_USER', 'secret');      // e.g. 12345678_invuser
define('DB_PASS', 'jearimheheTHISWRONGPASS');  // Your database password
define('DB_CHARSET', 'utf8mb4');

// ── CORS — Allow your frontend domain ────────────────────
// Replace with your actual AwardSpace site URL
define('ALLOWED_ORIGIN', '*'); // Change to 'https://yourdomain.awardspace.net' for security

function getConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}
