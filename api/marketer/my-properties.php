<?php
/**
 * PlotConnect - Marketer My Properties API
 */

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Log request info for debugging
error_log("my-properties.php called. Session: " . session_id() . ", Session user_type: " . ($_SESSION['user_type'] ?? 'not set') . ", Header X-Auth-Role: " . ($_SERVER['HTTP_X_AUTH_ROLE'] ?? 'not set'));

require_once dirname(__DIR__, 2) . '/config.php';

// Check if marketer is logged in via session or header
$currentUser = getCurrentUserType();

// Also check session directly as fallback
$sessionUserType = $_SESSION['user_type'] ?? null;
$headerRole = $_SERVER['HTTP_X_AUTH_ROLE'] ?? '';

error_log("After config. Current user: " . var_export($currentUser, true) . ", Session user_type: " . var_export($sessionUserType, true) . ", Header role: " . $headerRole);

if (!$currentUser) {
    jsonResponse(false, 'Unauthorized - Please login first. Session: ' . var_export($sessionUserType, true) . ', Header: ' . $headerRole, null, 401);
}

if ($currentUser !== 'marketer') {
    jsonResponse(false, 'Unauthorized - Not a marketer. Current user: ' . $currentUser, null, 401);
}

$conn = getDBConnection();
$marketerId = $_SESSION['marketer_id'] ?? null;

// If no session ID but have header auth, we need to look up the marketer
if (!$marketerId && $headerRole === 'marketer') {
    // For header-based auth, we need the marketer ID from somewhere
    // This is a limitation - we need session for proper auth
    error_log("Header auth but no session ID");
}

if (!$marketerId) {
    jsonResponse(false, 'Session expired. Please login again.', null, 401);
}

// Get marketer's properties
$stmt = $conn->prepare("SELECT * FROM properties WHERE marketer_id = ? ORDER BY created_at DESC");
$stmt->execute([$marketerId]);
$properties = $stmt->fetchAll();

foreach ($properties as &$property) {
    // Get room categories for each property
    try {
        $roomStmt = $conn->prepare("SELECT * FROM property_rooms WHERE property_id = ?");
        $roomStmt->execute([$property['id']]);
        $property['rooms'] = $roomStmt->fetchAll();
    } catch (Exception $e) {
        $property['rooms'] = [];
    }
}

jsonResponse(true, 'Properties retrieved', $properties);
