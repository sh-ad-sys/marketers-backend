<?php
/**
 * PlotConnect - Marketer Submit Property API
 */

require_once dirname(__DIR__, 2) . '/config.php';

// Check if marketer is logged in
if (!isMarketerLoggedIn()) {
    jsonResponse(false, 'Unauthorized', null, 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$conn = getDBConnection();
$marketerId = $_SESSION['marketer_id'];

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
