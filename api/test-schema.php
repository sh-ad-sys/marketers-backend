<?php
/**
 * Test script to check database schema
 */

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
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    echo "Connected to database successfully!\n\n";
    
    // Get properties table structure
    echo "=== PROPERTIES TABLE STRUCTURE ===\n";
    $result = $conn->query("DESCRIBE properties");
    while ($row = $result->fetch()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
    
    echo "\n=== PROPERTY_ROOMS TABLE STRUCTURE ===\n";
    $result = $conn->query("DESCRIBE property_rooms");
    while ($row = $result->fetch()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
