<?php
/**
 * PlotConnect - Admin Dashboard API
 */

require_once dirname(__DIR__, 2) . '/php/config.php';

// Check if admin is logged in
if (!isAdminLoggedIn()) {
    jsonResponse(false, 'Unauthorized', null, 401);
}

$conn = getDBConnection();

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

jsonResponse(true, 'Dashboard data retrieved', $stats);
