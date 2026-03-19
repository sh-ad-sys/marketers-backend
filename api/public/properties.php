<?php
/**
 * PlotConnect - Public Properties API
 */

require_once dirname(__DIR__, 2) . '/php/config.php';

$conn = getDBConnection();

// Get approved properties
$result = $conn->query("SELECT p.*, m.name as marketer_name, m.phone as marketer_phone 
                        FROM properties p 
                        JOIN marketers m ON p.marketer_id = m.id 
                        WHERE p.status = 'approved'
                        ORDER BY p.created_at DESC");

$properties = $result->fetchAll();

foreach ($properties as &$property) {
    // Get room categories for each property
    $roomStmt = $conn->prepare("SELECT * FROM room_categories WHERE property_id = ?");
    $roomStmt->execute([$property['id']]);
    $property['rooms'] = $roomStmt->fetchAll();
}

jsonResponse(true, 'Properties retrieved', $properties);
