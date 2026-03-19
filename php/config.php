<?php
/**
 * Database Configuration
 */

// CORS headers for cross-origin requests
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'plotconnect');
define('DB_USER', 'root');
define('DB_PASS', 'Shadrack2024.');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

// Session management
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '',
        'username' => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'user_type' => $_SESSION['user_type'],
        'phone' => $_SESSION['phone'] ?? ''
    ];
}

function requireLogin() {
    if (!isLoggedIn()) {
        http_response_code(401);
        die(json_encode(['success' => false, 'message' => 'Authentication required']));
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['user_type'] !== 'admin') {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Admin access required']));
    }
}

function isAdminLoggedIn() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

function isMarketerLoggedIn() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'marketer';
}

// Helper functions
function getDBConnection() {
    global $pdo;
    return $pdo;
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function jsonResponse($success, $message, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    $response = [
        'success' => $success,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

function getCurrentUserType() {
    if (isset($_SESSION['user_type'])) {
        return $_SESSION['user_type'];
    }
    return null;
}
