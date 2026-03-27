<?php
/**
 * PlotConnect - Add Country and Area Columns to Properties Table
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection
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

// Check if country column exists, if not add it
try {
    $stmt = $conn->query("SHOW COLUMNS FROM properties LIKE 'country'");
    if ($stmt->rowCount() === 0) {
        $conn->exec("ALTER TABLE properties ADD COLUMN country VARCHAR(100) DEFAULT 'Kenya' AFTER property_location");
    }
} catch (PDOException $e) {
    error_log("Add country column error: " . $e->getMessage());
}

// Check if area column exists, if not add it
try {
    $stmt = $conn->query("SHOW COLUMNS FROM properties LIKE 'area'");
    if ($stmt->rowCount() === 0) {
        $conn->exec("ALTER TABLE properties ADD COLUMN area VARCHAR(200) AFTER country");
    }
} catch (PDOException $e) {
    error_log("Add area column error: " . $e->getMessage());
}

echo json_encode(['success' => true, 'message' => 'Database updated with country and area columns']);