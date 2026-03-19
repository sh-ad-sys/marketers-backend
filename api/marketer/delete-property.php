<?php
/**
 * PlotConnect - Marketer Delete Property API
 */

require_once dirname(__DIR__, 2) . '/php/config.php';

// Check if marketer is logged in
if (!isMarketerLoggedIn()) {
    jsonResponse(false, 'Unauthorized', null, 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$conn = getDBConnection();
$marketerId = $_SESSION['marketer_id'];
$data = json_decode(file_get_contents('php://input'), true);
$propertyId = intval($data['id'] ?? 0);

if ($propertyId === 0) {
    jsonResponse(false, 'Invalid property ID');
}

// Verify property belongs to this marketer
$stmt = $conn->prepare("SELECT id FROM properties WHERE id = ? AND marketer_id = ?");
$stmt->execute([$propertyId, $marketerId]);

if ($stmt->rowCount() === 0) {
    jsonResponse(false, 'Property not found or unauthorized');
}

// Delete property
$delStmt = $conn->prepare("DELETE FROM properties WHERE id = ?");
$delStmt->execute([$propertyId]);

jsonResponse(true, 'Property deleted successfully');
