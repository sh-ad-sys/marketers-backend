<?php
/**
 * PlotConnect - Add Test Marketer Script
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// Database connection - Aiven
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
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Check if marketers table has required columns
try {
    // Add email column if not exists
    $conn->query("ALTER TABLE marketers ADD COLUMN IF NOT EXISTS email VARCHAR(100)");
} catch (PDOException $e) {
    // Column might exist, ignore
}

// Add OTP columns if not exist
try {
    $conn->query("ALTER TABLE marketers ADD COLUMN IF NOT EXISTS otp_code VARCHAR(255)");
    $conn->query("ALTER TABLE marketers ADD COLUMN IF NOT EXISTS otp_expiry DATETIME");
} catch (PDOException $e) {
    // Column might exist, ignore
}

// Test marketer data
$marketers = [
    ['name' => 'John Doe', 'phone' => '0712345678', 'email' => 'john@demo.com'],
    ['name' => 'Jane Smith', 'phone' => '0723456789', 'email' => 'jane@demo.com'],
];

require_once __DIR__ . '/../otp.php';
require_once __DIR__ . '/../email.php';

$added = 0;
foreach ($marketers as $m) {
    // Check if already exists
    $stmt = $conn->prepare("SELECT id FROM marketers WHERE email = ?");
    $stmt->execute([$m['email']]);
    if ($stmt->fetch()) {
        continue;
    }
    
    // Generate OTP
    $otp = OTP::generate();
    $hashedOtp = OTP::hash($otp);
    $otpExpiry = OTP::getExpiry();
    
    try {
        $stmt = $conn->prepare("INSERT INTO marketers (name, phone, email, otp_code, otp_expiry) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$m['name'], $m['phone'], $m['email'], $hashedOtp, $otpExpiry]);
        $added++;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode([
    'success' => true, 
    'message' => "Added $added marketers",
    'marketers' => $marketers
]);
