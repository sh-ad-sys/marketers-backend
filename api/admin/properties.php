<?php
/**
 * PlotConnect - Admin Properties Management API
 */

require_once dirname(__DIR__, 2) . '/config.php';

// Check if admin is logged in
if (!isAdminLoggedIn()) {
    jsonResponse(false, 'Unauthorized', null, 401);
}

$conn = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Get all properties with marketer info
    $marketerId = isset($_GET['marketer_id']) ? (int)$_GET['marketer_id'] : null;
    
    if ($marketerId) {
        $stmt = $conn->prepare("SELECT p.*, m.name as marketer_name, m.phone as marketer_phone 
                                FROM properties p 
                                JOIN marketers m ON p.marketer_id = m.id 
                                WHERE p.marketer_id = ?
                                ORDER BY p.created_at DESC");
        $stmt->execute([$marketerId]);
    } else {
        $stmt = $conn->query("SELECT p.*, m.name as marketer_name, m.phone as marketer_phone 
                                FROM properties p 
                                JOIN marketers m ON p.marketer_id = m.id 
                                ORDER BY p.created_at DESC");
    }
    
    $properties = $stmt->fetchAll();
    
    foreach ($properties as &$property) {
        // Get room categories for each property - try both table names
        try {
            $roomStmt = $conn->prepare("SELECT * FROM property_rooms WHERE property_id = ?");
            $roomStmt->execute([$property['id']]);
            $property['rooms'] = $roomStmt->fetchAll();
        } catch (Exception $e) {
            $property['rooms'] = [];
        }
    }
    
    jsonResponse(true, 'Properties retrieved', $properties);
    
} elseif ($method === 'POST') {
    // Update property status or delete
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    if ($action === 'update_status') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $status = sanitize($data['status'] ?? '');
        
        if ($id === 0 || empty($status)) {
            jsonResponse(false, 'Invalid data');
        }
        
        $stmt = $conn->prepare("UPDATE properties SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        
        jsonResponse(true, 'Property status updated');
        
    } elseif ($action === 'delete') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        
        if ($id === 0) {
            jsonResponse(false, 'Invalid property ID');
        }
        
        $stmt = $conn->prepare("DELETE FROM properties WHERE id = ?");
        $stmt->execute([$id]);
        
        jsonResponse(true, 'Property deleted successfully');
    }
}
