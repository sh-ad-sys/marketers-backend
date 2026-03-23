<?php
/**
 * PlotConnect - Test API
 * Use this to verify the API is working
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'API is working!',
    'timestamp' => time(),
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => [
        'x-auth-role' => $_SERVER['HTTP_X_AUTH_ROLE'] ?? 'not set',
        'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'not set'
    ]
]);
