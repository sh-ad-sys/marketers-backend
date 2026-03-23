<?php
/**
 * PlotConnect - Marketer Submit Property API (Debug Version)
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
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

try {
    require_once dirname(__DIR__, 2) . '/config.php';
} catch (Exception $e) {
    error_log("Config load error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server configuration error: ' . $e->getMessage()]);
    exit;
}

// For debugging - accept any request
$marketerId = $_SERVER['HTTP_X_AUTH_MARKETER_ID'] ?? '1';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $conn = getDBConnection();
} catch (Exception $e) {
    error_log("DB connection error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);

$ownerName = sanitize($data['owner_name'] ?? '');
$ownerEmail = sanitize($data['owner_email'] ?? '');
$phoneNumber = sanitize($data['phone_number'] ?? '');
$propertyName = sanitize($data['property_name'] ?? '');
$propertyLocation = sanitize($data['property_location'] ?? '');
$propertyType = $data['property_type'] ?? '';
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
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields', 'received' => $data]);
    exit;
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

echo json_encode(['success' => true, 'message' => 'Property submitted successfully', 'property_id' => $propertyId]);
