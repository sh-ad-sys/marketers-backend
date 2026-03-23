<?php
/**
 * PlotConnect - Marketer Submit Property API
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Log request info
error_log("submit-property.php called. Session: " . session_id() . ", User type: " . ($_SESSION['user_type'] ?? 'not set') . ", Header X-Auth-Role: " . ($_SERVER['HTTP_X_AUTH_ROLE'] ?? 'not set') . ", Header X-Auth-Marketer-Id: " . ($_SERVER['HTTP_X_AUTH_MARKETER_ID'] ?? 'not set'));

try {
    require_once dirname(__DIR__, 2) . '/config.php';
} catch (Exception $e) {
    error_log("Config load error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server configuration error']);
    exit;
}

// Check if marketer is logged in - using header-based auth
$currentUser = getCurrentUserType();
$marketerId = $_SESSION['marketer_id'] ?? $_SERVER['HTTP_X_AUTH_MARKETER_ID'] ?? null;

if (!$currentUser && !$marketerId) {
    jsonResponse(false, 'Unauthorized - Please login first', null, 401);
}

// If we have the header but no session, set it up
if ($marketerId && !$currentUser) {
    $_SESSION['marketer_id'] = $marketerId;
    $_SESSION['user_type'] = 'marketer';
    $currentUser = 'marketer';
}

if ($currentUser !== 'marketer') {
    jsonResponse(false, 'Unauthorized - Not a marketer', null, 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

try {
    $conn = getDBConnection();
} catch (Exception $e) {
    error_log("DB connection error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}
// Get marketer ID from session or header
$marketerId = $_SESSION['marketer_id'] ?? null;
if (!$marketerId) {
    $marketerId = $_SERVER['HTTP_X_AUTH_MARKETER_ID'] ?? null;
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);

$ownerName = sanitize($data['owner_name'] ?? '');
$ownerEmail = sanitize($data['owner_email'] ?? '');
$phoneNumber = sanitize($data['phone_number'] ?? '');
$propertyName = sanitize($data['property_name'] ?? '');
$propertyLocation = sanitize($data['property_location'] ?? '');
$propertyType = $data['property_type'] ?? '';
// Convert array to comma-separated string if needed
if (is_array($propertyType)) {
    $propertyType = implode(', ', $propertyType);
}
$propertyType = sanitize($propertyType);
$propertyDescription = sanitize($data['property_description'] ?? '');
$bookingType = sanitize($data['booking_type'] ?? '');
$packageSelected = sanitize($data['package_selected'] ?? '');
$rooms = $data['rooms'] ?? [];

// Validate required fields
if (empty($ownerName) || empty($phoneNumber) || empty($propertyLocation) || 
    empty($propertyType) || empty($bookingType) || empty($packageSelected)) {
    jsonResponse(false, 'Please fill in all required fields');
}

// Insert property
$stmt = $conn->prepare("INSERT INTO properties (marketer_id, owner_name, owner_email, phone_number, property_name, property_location, property_type, property_description, booking_type, package_selected, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
$stmt->execute([$marketerId, $ownerName, $ownerEmail, $phoneNumber, $propertyName, $propertyLocation, $propertyType, $propertyDescription, $bookingType, $packageSelected]);

$propertyId = $conn->lastInsertId();

// Insert room categories
if (!empty($rooms)) {
    $roomStmt = $conn->prepare("INSERT INTO room_categories (property_id, room_type, room_size, price, availability, amenities) VALUES (?, ?, ?, ?, ?, ?)");
    
    foreach ($rooms as $room) {
        $roomType = sanitize($room['room_type'] ?? '');
        $roomSize = sanitize($room['room_size'] ?? '');
        $price = floatval($room['price'] ?? 0);
        $availability = intval($room['availability'] ?? 0);
        $amenities = sanitize($room['amenities'] ?? '');
        
        if (!empty($roomType) && $price > 0) {
            $roomStmt->execute([$propertyId, $roomType, $roomSize, $price, $availability, $amenities]);
        }
    }
}

jsonResponse(true, 'Property submitted successfully', ['property_id' => $propertyId]);
