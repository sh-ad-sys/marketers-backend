<?php
/**
 * PlotConnect - Add Password to Marketer Script
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

// Check if password column exists
try {
    $conn->query("SELECT password FROM marketers LIMIT 1");
} catch (PDOException $e) {
    // Add password column if not exists
    $conn->query("ALTER TABLE marketers ADD COLUMN password VARCHAR(255)");
}

// Default password for all marketers
$defaultPassword = 'password123';
$hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

// Update all marketers with password
$stmt = $conn->prepare("UPDATE marketers SET password = ? WHERE password IS NULL OR password = ''");
$stmt->execute([$hashedPassword]);

echo json_encode([
    'success' => true, 
    'message' => 'Password added to all marketers',
    'default_password' => $defaultPassword
]);
