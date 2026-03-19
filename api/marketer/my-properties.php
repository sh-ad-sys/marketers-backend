<?php
/**
 * PlotConnect - Marketer My Properties API
 */

require_once dirname(__DIR__, 2) . '/config.php';

// Check if marketer is logged in
if (!isMarketerLoggedIn()) {
    jsonResponse(false, 'Unauthorized', null, 401);
}

$conn = getDBConnection();
$marketerId = $_SESSION['marketer_id'];

// Get marketer's properties
$stmt = $conn->prepare("SELECT * FROM properties WHERE marketer_id = ? ORDER BY created_at DESC");
$stmt->execute([$marketerId]);
$properties = $stmt->fetchAll();

foreach ($properties as &$property) {
    // Get room categories for each property
    $roomStmt = $conn->prepare("SELECT * FROM room_categories WHERE property_id = ?");
    $roomStmt->execute([$property['id']]);
    $property['rooms'] = $roomStmt->fetchAll();
}

jsonResponse(true, 'Properties retrieved', $properties);
