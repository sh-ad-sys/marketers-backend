<?php
/**
 * PlotConnect API Router
 * Routes /api/* requests to appropriate handlers
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get request path (remove /api/ prefix)
$request = isset($_GET['request']) ? $_GET['request'] : '';

// Also check PATH_INFO for direct PHP file access (e.g., /api/test.php)
if (empty($request) && isset($_SERVER['PATH_INFO'])) {
    $request = ltrim($_SERVER['PATH_INFO'], '/');
    // Remove .php extension if present
    $request = preg_replace('/\.php$/', '', $request);
}

// Parse request (e.g., "auth/login", "admin/marketers", "marketer/my-properties")
$parts = explode('/', trim($request, '/'));
$controller = isset($parts[0]) ? $parts[0] : '';
$action = isset($parts[1]) ? $parts[1] : '';

// Route the request
switch ($controller) {
    // Test endpoint
    case 'test':
        require_once __DIR__ . '/test.php';
        break;
    
    // Auth endpoints
    case 'auth':
        if ($action === 'login') {
            require_once __DIR__ . '/auth/login.php';
        } elseif ($action === 'logout') {
            require_once __DIR__ . '/auth/logout.php';
        } elseif ($action === 'check') {
            require_once __DIR__ . '/auth/check.php';
        } else {
            echo json_encode(['success' => false, 'message' => 'Auth endpoint not found']);
        }
        break;
    
    // Admin endpoints
    case 'admin':
        if ($action === 'dashboard') {
            require_once __DIR__ . '/admin/dashboard.php';
        } elseif ($action === 'marketers') {
            require_once __DIR__ . '/admin/marketers.php';
        } elseif ($action === 'properties') {
            require_once __DIR__ . '/admin/properties.php';
        } else {
            echo json_encode(['success' => false, 'message' => 'Admin endpoint not found']);
        }
        break;
    
    // Marketer endpoints
    case 'marketer':
        if ($action === 'my-properties') {
            require_once __DIR__ . '/marketer/my-properties.php';
        } elseif ($action === 'submit-property') {
            require_once __DIR__ . '/marketer/submit-property.php';
        } elseif ($action === 'delete-property') {
            require_once __DIR__ . '/marketer/delete-property.php';
        } else {
            echo json_encode(['success' => false, 'message' => 'Marketer endpoint not found']);
        }
        break;
    
    // Public endpoints
    case 'public':
        if ($action === 'properties') {
            require_once __DIR__ . '/public/properties.php';
        } else {
            echo json_encode(['success' => false, 'message' => 'Public endpoint not found']);
        }
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'API endpoint not found']);
        break;
}
