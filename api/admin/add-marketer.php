<?php
/**
 * PlotConnect - Add Specific Marketer Script
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

// Add password column if not exists
try {
    $conn->query("ALTER TABLE marketers ADD COLUMN password VARCHAR(255)");
} catch (PDOException $e) {
    // Column exists, ignore
}

// Marketer data
$name = 'Kimani Ouma';
$email = 'kimaniouma@gmail.com';
$phone = '0712345678';
$password = 'password';
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Check if already exists
$stmt = $conn->prepare("SELECT id FROM marketers WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    // Update existing
    $updateStmt = $conn->prepare("UPDATE marketers SET name = ?, phone = ?, password = ? WHERE email = ?");
    $updateStmt->execute([$name, $phone, $hashedPassword, $email]);
    echo json_encode([
        'success' => true, 
        'message' => 'Marketer updated with password',
        'marketer' => [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password
        ]
    ]);
} else {
    // Insert new
    $insertStmt = $conn->prepare("INSERT INTO marketers (name, email, phone, password) VALUES (?, ?, ?, ?)");
    $insertStmt->execute([$name, $email, $phone, $hashedPassword]);
    echo json_encode([
        'success' => true, 
        'message' => 'Marketer created with password',
        'marketer' => [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password
        ]
    ]);
}
