<?php
$basePath = dirname(__DIR__);
require_once $basePath . '/config.php';
require_once $basePath . '/helpers.php';

if (!function_exists('respond')) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(["error" => "Bootstrap failed: Helpers not found."]);
    exit;
}
