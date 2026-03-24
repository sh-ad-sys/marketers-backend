<?php
/**
 * PlotConnect - Admin Dashboard API (Standalone)
 */

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
if (!$payload || $payload['user_type'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Invalid or missing token']);
    exit;
}

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

// Get statistics
$stats = [
    'total_marketers' => 0,
    'total_properties' => 0,
    'pending_properties' => 0,
    'approved_properties' => 0
];

// Count marketers
$result = $conn->query("SELECT COUNT(*) as count FROM marketers");
if ($result) {
    $row = $result->fetch();
    $stats['total_marketers'] = $row['count'];
}

// Count total properties
$result = $conn->query("SELECT COUNT(*) as count FROM properties");
if ($result) {
    $row = $result->fetch();
    $stats['total_properties'] = $row['count'];
}

// Count pending properties
$result = $conn->query("SELECT COUNT(*) as count FROM properties WHERE status = 'pending'");
if ($result) {
    $row = $result->fetch();
    $stats['pending_properties'] = $row['count'];
}

// Count approved properties
$result = $conn->query("SELECT COUNT(*) as count FROM properties WHERE status = 'approved'");
if ($result) {
    $row = $result->fetch();
    $stats['approved_properties'] = $row['count'];
}

echo json_encode(['success' => true, 'message' => 'Dashboard data retrieved', 'data' => $stats]);
