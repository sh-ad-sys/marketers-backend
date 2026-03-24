<?php
/**
 * Script to add OTP columns to the marketers table
 * Run this once to add the required columns
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain');

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
    ]);
    
    // Check if columns already exist
    $stmt = $conn->query("SHOW COLUMNS FROM marketers LIKE 'otp_code'");
    $otpCodeExists = $stmt->fetch() !== false;
    
    $stmt = $conn->query("SHOW COLUMNS FROM marketers LIKE 'otp_expiry'");
    $otpExpiryExists = $stmt->fetch() !== false;
    
    if (!$otpCodeExists) {
        $conn->exec("ALTER TABLE marketers ADD COLUMN otp_code VARCHAR(255) AFTER password");
        echo "Added otp_code column\n";
    } else {
        echo "otp_code column already exists\n";
    }
    
    if (!$otpExpiryExists) {
        $conn->exec("ALTER TABLE marketers ADD COLUMN otp_expiry DATETIME AFTER otp_code");
        echo "Added otp_expiry column\n";
    } else {
        echo "otp_expiry column already exists\n";
    }
    
    echo "\nDatabase updated successfully!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}