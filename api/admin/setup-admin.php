<?php
/**
 * PlotConnect - Setup Admin User Script
 * Run this once to create/update the admin user
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

// Check if admins table exists, if not create it
try {
    $conn->query("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add email column if it doesn't exist
    try {
        $conn->query("ALTER TABLE admins ADD COLUMN email VARCHAR(100) AFTER full_name");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to create admins table: ' . $e->getMessage()]);
    exit;
}

// Admin credentials
$username = 'admin';
$password = 'password'; // Change this to your desired password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$fullName = 'Shadrack Mutune';
$email = 'admin@plotconnectmarketers.com';

// Insert or update admin user
try {
    $stmt = $conn->prepare("
        INSERT INTO admins (username, password, full_name, email) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            username = VALUES(username),
            password = VALUES(password),
            full_name = VALUES(full_name),
            email = VALUES(email)
    ");
    $stmt->execute([$username, $hashedPassword, $fullName, $email]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Admin user created/updated successfully!',
        'admin' => [
            'username' => $username,
            'email' => $email,
            'full_name' => $fullName,
            'password' => $password // Note: In production, don't return the plain password
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to create admin: ' . $e->getMessage()]);
}
