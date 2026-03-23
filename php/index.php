<?php
/**
 * PlotConnect API - Main Entry Point
 * CORS enabled for cross-origin requests
 */

// CORS headers - must be at the very top before any output
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin", true);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS', true);
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization', true);
header('Access-Control-Allow-Credentials: true', true);
header('Access-Control-Max-Age: 86400', true);
header('Content-Type: application/json', true);

// Handle preflight immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// Get request parameter
$request = $_GET['request'] ?? '';

// Parse request (e.g., "login", "marketers", "marketers&id=5", "all-properties&id=5")
$parts = explode('&', $request);
$endpoint = $parts[0];
$params = [];
if (isset($parts[1])) {
    parse_str($parts[1], $params);
}

// Route handling
try {
    switch ($endpoint) {
        // Authentication
        case 'check-auth':
            checkAuth();
            break;
            
        case 'login':
            login();
            break;
            
        case 'logout':
            logout();
            break;
            
        // Marketer endpoints
        case 'submit-property':
            requireLogin();
            submitProperty();
            break;
            
        case 'my-properties':
            requireLogin();
            getMyProperties();
            break;
            
        case 'delete-property':
            requireLogin();
            deleteProperty($params['id'] ?? 0);
            break;
            
        // Admin endpoints
        case 'admin':
            requireAdmin();
            getAdminStats();
            break;
            
        case 'marketers':
            handleMarketers();
            break;
            
        case 'all-properties':
            handleAllProperties();
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown request: ' . $endpoint]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ========== Authentication Functions ==========

function checkAuth() {
    $user = getCurrentUser();
    echo json_encode([
        'success' => $user !== null,
        'data' => $user
    ]);
}

function login() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $type = $data['type'] ?? '';
    
    if ($type === 'admin') {
        // Admin login
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['user_name'] = $admin['full_name'];
            $_SESSION['username'] = $admin['username'];
            $_SESSION['full_name'] = $admin['full_name'];
            $_SESSION['user_type'] = 'admin';
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $admin['id'],
                    'username' => $admin['username'],
                    'full_name' => $admin['full_name'],
                    'user_type' => 'admin'
                ]
            ]);
        } elseif (!$admin) {
            echo json_encode(['success' => false, 'message' => 'Admin username not found']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Incorrect password']);
        }
    } else {
        // Marketer login - name and password only
        $name = $data['name'] ?? '';
        $password = $data['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM marketers WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $marketer = $stmt->fetch();
        
        if ($marketer && password_verify($password, $marketer['password'])) {
            $_SESSION['user_id'] = $marketer['id'];
            $_SESSION['user_name'] = $marketer['name'];
            $_SESSION['full_name'] = $marketer['name'];
            $_SESSION['user_type'] = 'marketer';
            $_SESSION['phone'] = $marketer['phone'];
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $marketer['id'],
                    'name' => $marketer['name'],
                    'phone' => $marketer['phone'],
                    'user_type' => 'marketer'
                ]
            ]);
        } elseif (!$marketer) {
            echo json_encode(['success' => false, 'message' => 'Marketer name not found']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Incorrect password']);
        }
    }
}

function logout() {
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
}

// ========== Property Functions ==========

function submitProperty() {
    global $pdo;
    
    $user = getCurrentUser();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $pdo->prepare("
        INSERT INTO properties (
            marketer_id, owner_name, owner_email, phone_number, property_name,
            property_location, property_type, booking_type, package_selected, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    
    $stmt->execute([
        $user['id'],
        $data['owner_name'] ?? '',
        $data['owner_email'] ?? '',
        $data['phone_number'] ?? '',
        $data['property_name'] ?? '',
        $data['property_location'] ?? '',
        $data['property_type'] ?? '',
        $data['booking_type'] ?? '',
        $data['package_selected'] ?? ''
    ]);
    
    $propertyId = $pdo->lastInsertId();
    
    // Insert rooms if provided
    if (!empty($data['rooms']) && is_array($data['rooms'])) {
        $roomStmt = $pdo->prepare("
            INSERT INTO property_rooms (property_id, room_type, room_size, price, availability)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($data['rooms'] as $room) {
            $roomStmt->execute([
                $propertyId,
                $room['room_type'] ?? '',
                $room['room_size'] ?? '',
                $room['price'] ?? 0,
                $room['availability'] ?? ''
            ]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Property submitted successfully',
        'data' => ['id' => $propertyId]
    ]);
}

function getMyProperties() {
    global $pdo;
    
    $user = getCurrentUser();
    
    $stmt = $pdo->prepare("
        SELECT p.*, m.name as marketer_name
        FROM properties p
        LEFT JOIN marketers m ON p.marketer_id = m.id
        WHERE p.marketer_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $properties = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $properties
    ]);
}

function deleteProperty($id) {
    global $pdo;
    
    $user = getCurrentUser();
    
    // Check ownership (admin can delete any, marketer only their own)
    if ($user['user_type'] === 'marketer') {
        $stmt = $pdo->prepare("SELECT * FROM properties WHERE id = ? AND marketer_id = ?");
        $stmt->execute([$id, $user['id']]);
        $property = $stmt->fetch();
        
        if (!$property) {
            echo json_encode(['success' => false, 'message' => 'Property not found or access denied']);
            return;
        }
    }
    
    // Delete rooms first
    $stmt = $pdo->prepare("DELETE FROM property_rooms WHERE property_id = ?");
    $stmt->execute([$id]);
    
    // Delete property
    $stmt = $pdo->prepare("DELETE FROM properties WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Property deleted successfully'
    ]);
}

// ========== Admin Functions ==========

function getAdminStats() {
    global $pdo;
    
    // Total marketers
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM marketers");
    $totalMarketers = $stmt->fetch()['count'];
    
    // Total properties
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM properties");
    $totalProperties = $stmt->fetch()['count'];
    
    // Pending
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM properties WHERE status = 'pending'");
    $pendingProperties = $stmt->fetch()['count'];
    
    // Approved
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM properties WHERE status = 'approved'");
    $approvedProperties = $stmt->fetch()['count'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total_marketers' => $totalMarketers,
            'total_properties' => $totalProperties,
            'pending_properties' => $pendingProperties,
            'approved_properties' => $approvedProperties
        ]
    ]);
}

function handleMarketers() {
    global $pdo;
    global $params;
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        // List all marketers
        $stmt = $pdo->query("SELECT id, name, phone, email, is_active, created_at FROM marketers ORDER BY created_at DESC");
        $marketers = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $marketers
        ]);
    } elseif ($method === 'POST') {
        // Add new marketer
        $data = json_decode(file_get_contents('php://input'), true);
        
        $passwordHash = password_hash($data['password'] ?? 'password123', PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO marketers (name, phone, email, password, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        
        $stmt->execute([
            $data['name'] ?? '',
            $data['phone'] ?? '',
            $data['email'] ?? '',
            $passwordHash
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Marketer added successfully'
        ]);
    } elseif ($method === 'DELETE') {
        // Delete marketer
        $id = $params['id'] ?? 0;
        
        $stmt = $pdo->prepare("DELETE FROM marketers WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Marketer deleted successfully'
        ]);
    }
}

function handleAllProperties() {
    global $pdo;
    global $params;
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        // List all properties
        $stmt = $pdo->query("
            SELECT p.*, m.name as marketer_name
            FROM properties p
            LEFT JOIN marketers m ON p.marketer_id = m.id
            ORDER BY p.created_at DESC
        ");
        $properties = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $properties
        ]);
    } elseif ($method === 'PUT') {
        // Update property status
        $data = json_decode(file_get_contents('php://input'), true);
        
        $stmt = $pdo->prepare("UPDATE properties SET status = ? WHERE id = ?");
        $stmt->execute([$data['status'], $data['id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Property status updated'
        ]);
    } elseif ($method === 'DELETE') {
        // Delete property (admin can delete any)
        $id = $params['id'] ?? 0;
        
        $stmt = $pdo->prepare("DELETE FROM property_rooms WHERE property_id = ?");
        $stmt->execute([$id]);
        
        $stmt = $pdo->prepare("DELETE FROM properties WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Property deleted successfully'
        ]);
    }
}
