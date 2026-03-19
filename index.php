<?php
/**
 * PlotConnect - Main API Router
 */

require_once 'config.php';

// Get request method and endpoint
$method = $_SERVER['REQUEST_METHOD'];
$request = isset($_GET['request']) ? $_GET['request'] : '';

// Parse request
$parts = explode('/', trim($request, '/'));
$endpoint = isset($parts[0]) ? $parts[0] : '';
$id = isset($parts[1]) ? (int)$parts[1] : null;

// Route the request
switch ($endpoint) {
    // Auth endpoints
    case 'login':
        require_once 'api/auth/login.php';
        break;
    case 'logout':
        require_once 'api/auth/logout.php';
        break;
    case 'register':
        require_once 'api/auth/register.php';
        break;
    case 'check-auth':
        require_once 'api/auth/check.php';
        break;
    
    // Admin endpoints
    case 'admin':
        require_once 'api/admin/dashboard.php';
        break;
    case 'marketers':
        require_once 'api/admin/marketers.php';
        break;
    case 'all-properties':
        require_once 'api/admin/properties.php';
        break;
    
    // Marketer endpoints
    case 'properties':
        require_once 'api/marketer/properties.php';
        break;
    case 'my-properties':
        require_once 'api/marketer/my-properties.php';
        break;
    case 'submit-property':
        require_once 'api/marketer/submit-property.php';
        break;
    case 'delete-property':
        require_once 'api/marketer/delete-property.php';
        break;
    
    // Public endpoints
    case 'public-properties':
        require_once 'api/public/properties.php';
        break;
    
    // Default response
    default:
        jsonResponse(false, 'API endpoint not found', null, 404);
        break;
}
