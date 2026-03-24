<?php
/**
 * PlotConnect - Logout API (Standalone)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Destroy session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_destroy();

echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
