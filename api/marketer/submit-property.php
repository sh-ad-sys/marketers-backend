<?php
/**
 * PlotConnect - Marketer Submit Property API (Standalone Version)
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id, Accept, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection - direct
$dbHost = 'plotconnect-shadrackmutua081-64f3.k.aivencloud.com';
$dbPort = '27258';
$dbName = 'defaultdb';
$dbUser = 'avnadmin';
$dbPass = 'AVNS_Q-OTx-X8_9pxJLFsNY4';

// Load JWT utility
require_once __DIR__ . '/../jwt.php';

// Authenticate using JWT
$payload = JWT::authenticate();
if (!$payload || $payload['user_type'] !== 'marketer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Invalid or missing token']);
    exit;
}

// Get marketer ID from JWT payload
$marketerId = $payload['marketer_id'];

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbHost, $dbPort, $dbName
    );
    $conn = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log("DB Connection Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'No data received']);
    exit;
}

$ownerName = htmlspecialchars(trim($data['owner_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$ownerEmail = htmlspecialchars(trim($data['owner_email'] ?? ''), ENT_QUOTES, 'UTF-8');
$phoneNumber = htmlspecialchars(trim($data['phone_number'] ?? ''), ENT_QUOTES, 'UTF-8');
$propertyName = htmlspecialchars(trim($data['property_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$propertyLocation = htmlspecialchars(trim($data['property_location'] ?? ''), ENT_QUOTES, 'UTF-8');
$propertyType = $data['property_type'] ?? '';
if (is_array($propertyType)) {
    $propertyType = implode(', ', $propertyType);
}
$propertyType = htmlspecialchars(trim($propertyType), ENT_QUOTES, 'UTF-8');
$propertyDescription = htmlspecialchars(trim($data['property_description'] ?? ''), ENT_QUOTES, 'UTF-8');
$bookingType = htmlspecialchars(trim($data['booking_type'] ?? ''), ENT_QUOTES, 'UTF-8');
$packageSelected = htmlspecialchars(trim($data['package_selected'] ?? ''), ENT_QUOTES, 'UTF-8');
$rooms = $data['rooms'] ?? [];

// Validate required fields
if (empty($ownerName) || empty($phoneNumber) || empty($propertyLocation) || 
    empty($propertyType) || empty($bookingType) || empty($packageSelected)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Please fill in all required fields',
        'received' => [
            'owner_name' => $ownerName,
            'phone_number' => $phoneNumber,
            'property_location' => $propertyLocation,
            'property_type' => $propertyType,
            'booking_type' => $bookingType,
            'package_selected' => $packageSelected
        ]
    ]);
    exit;
}

// Insert property
try {
    // Try with minimal columns - let the database tell us what's wrong
    $stmt = $conn->prepare("INSERT INTO properties (marketer_id, owner_name, phone, property_name, property_location, property_type, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$marketerId, $ownerName, $phoneNumber, $propertyName, $propertyLocation, $propertyType]);
    $propertyId = $conn->lastInsertId();
} catch (PDOException $e) {
    error_log("Insert property error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to insert property: ' . $e->getMessage()]);
    exit;
}

// Insert room categories
if (!empty($rooms)) {
    try {
        // Use property_rooms table as per database schema
        $roomStmt = $conn->prepare("INSERT INTO property_rooms (property_id, room_type, room_size, price, availability) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($rooms as $room) {
            $roomType = htmlspecialchars(trim($room['room_type'] ?? ''), ENT_QUOTES, 'UTF-8');
            $roomSize = htmlspecialchars(trim($room['room_size'] ?? ''), ENT_QUOTES, 'UTF-8');
            $price = floatval($room['price'] ?? 0);
            $availability = htmlspecialchars(trim($room['availability'] ?? ''), ENT_QUOTES, 'UTF-8');
            
            if (!empty($roomType) && $price > 0) {
                $roomStmt->execute([$propertyId, $roomType, $roomSize, $price, $availability]);
            }
        }
    } catch (PDOException $e) {
        error_log("Insert rooms error: " . $e->getMessage());
    }
}

echo json_encode(['success' => true, 'message' => 'Property submitted successfully', 'property_id' => $propertyId]);
