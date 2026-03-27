<?php
/**
 * PlotConnect - Database Migration Script
 * Run this to add missing columns to the properties table
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
    
    // Check if country column exists
    $result = $conn->query("SHOW COLUMNS FROM properties LIKE 'country'");
    $countryExists = $result->fetch() !== false;
    
    if (!$countryExists) {
        echo "Adding 'country' column to properties table...\n";
        $conn->exec("ALTER TABLE properties ADD COLUMN country VARCHAR(100) DEFAULT 'Kenya' AFTER property_location");
        echo "Done!\n";
    } else {
        echo "'country' column already exists.\n";
    }
    
    // Check if area column exists
    $result = $conn->query("SHOW COLUMNS FROM properties LIKE 'area'");
    $areaExists = $result->fetch() !== false;
    
    if (!$areaExists) {
        echo "Adding 'area' column to properties table...\n";
        $conn->exec("ALTER TABLE properties ADD COLUMN area VARCHAR(100) DEFAULT '' AFTER country");
        echo "Done!\n";
    } else {
        echo "'area' column already exists.\n";
    }
    
    echo "\nMigration complete!";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}