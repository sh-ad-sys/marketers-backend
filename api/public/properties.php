<?php
/**
 * PlotConnect - Public Properties API (Standalone)
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

// Database connection - direct
$dbHost = 'plotconnect-shadrackmutua081-64f3.k.aivencloud.com';
$dbPort = '27258';
$dbName = 'defaultdb';
$dbUser = 'avnadmin';
$dbPass = 'AVNS_Q-OTx-X8_9pxJLFsNY4';

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
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get approved properties
$result = $conn->query("SELECT p.*, m.name as marketer_name, m.phone as marketer_phone 
                        FROM properties p 
                        JOIN marketers m ON p.marketer_id = m.id 
                        WHERE p.status = 'approved'
                        ORDER BY p.created_at DESC");

$properties = $result->fetchAll();

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

echo json_encode(['success' => true, 'message' => 'Properties retrieved', 'data' => $properties]);
