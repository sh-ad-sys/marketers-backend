<?php
/**
 * PlotConnect - Get OTP for testing
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
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Simple OTP generator for testing (same as OTP::generate)
function generateTestOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

$email = $_GET['email'] ?? 'john@demo.com';

// Get marketer
$stmt = $conn->prepare("SELECT id, name, phone, email, otp_code, otp_expiry FROM marketers WHERE email = ?");
$stmt->execute([$email]);
$marketer = $stmt->fetch();

if (!$marketer) {
    echo json_encode(['success' => false, 'message' => 'Marketer not found']);
    exit;
}

// Generate new OTP
$otp = generateTestOTP();
require_once __DIR__ . '/../otp.php';
$hashedOtp = OTP::hash($otp);
$otpExpiry = OTP::getExpiry();

// Update OTP in database
$updateStmt = $conn->prepare("UPDATE marketers SET otp_code = ?, otp_expiry = ? WHERE id = ?");
$updateStmt->execute([$hashedOtp, $otpExpiry, $marketer['id']]);

echo json_encode([
    'success' => true, 
    'message' => 'OTP generated',
    'otp' => $otp,
    'email' => $email,
    'marketer' => $marketer['name']
]);
