<?php
/**
 * PlotConnect - Admin Marketers Management API
 */

require_once dirname(__DIR__, 2) . '/config.php';

// Check if admin is logged in
if (getCurrentUserType() !== 'admin') {
    jsonResponse(false, 'Unauthorized', null, 401);
}

$conn = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Handle different HTTP methods
if ($method === 'GET') {
    // Get all marketers
    $stmt = $conn->query("SELECT id, name, email, phone, is_active, created_at FROM marketers ORDER BY created_at DESC");
    $marketers = $stmt->fetchAll();
    jsonResponse(true, 'Marketers retrieved', $marketers);
    
} elseif ($method === 'POST') {
    // Add new marketer
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    if ($action === 'delete') {
        // Delete marketer
        $id = intval($data['id'] ?? 0);
        if ($id === 0) {
            jsonResponse(false, 'Invalid marketer ID');
        }
        
        $stmt = $conn->prepare("DELETE FROM marketers WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(true, 'Marketer deleted successfully');
    }
    
    // Add new marketer
    $name = sanitize($data['name'] ?? '');
    $phone = sanitize($data['phone'] ?? '');
    $email = sanitize($data['email'] ?? '');
    $password = $data['password'] ?? '';
    
    if (empty($name) || empty($phone) || empty($password)) {
        jsonResponse(false, 'All fields are required');
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO marketers (name, phone, email, password) VALUES (?, ?, ?, ?)");
    
    try {
        $stmt->execute([$name, $phone, $email, $hashedPassword]);
        jsonResponse(true, 'Marketer added successfully', ['id' => $conn->lastInsertId()]);
    } catch (Exception $e) {
        jsonResponse(false, 'Error adding marketer. Phone number may already exist.');
    }
    
} elseif ($method === 'PUT') {
    // Update marketer status
    $data = json_decode(file_get_contents('php://input'), true);
    $id = intval($data['id'] ?? 0);
    $isActive = intval($data['is_active'] ?? 1);
    
    if ($id === 0) {
        jsonResponse(false, 'Invalid marketer ID');
    }
    
    $stmt = $conn->prepare("UPDATE marketers SET is_active = ? WHERE id = ?");
    $stmt->execute([$isActive, $id]);
    jsonResponse(true, 'Marketer status updated');
    
} else {
    jsonResponse(false, 'Method not allowed', null, 405);
}
