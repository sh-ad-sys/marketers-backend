<?php
/**
 * PlotConnect - Admin Properties Management API (Standalone)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id, Accept, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection - direct
$dbHost = 'plotconnect-shadrackmutua081-64f3.k.aivencloud.com';
$dbPort = '27258';
$dbName = 'defaultdb';
$dbUser = 'avnadmin';
$dbPass = 'AVNS_Q-OTx-X8_9pxJLFsNY4';

// Load JWT utility
require_once __DIR__ . '/../jwt.php';

// Authenticate using JWT
$payload = JWT::authenticate();
if (!$payload || $payload['user_type'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Invalid or missing token']);
    exit;
}

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
} catch (PDOException $e) {
    error_log("DB Connection Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Get all properties with marketer info
    $marketerId = isset($_GET['marketer_id']) ? (int)$_GET['marketer_id'] : null;
    
    try {
        if ($marketerId) {
            $stmt = $conn->prepare("SELECT p.*, m.name as marketer_name, m.phone as marketer_phone 
                                    FROM properties p 
                                    JOIN marketers m ON p.marketer_id = m.id 
                                    WHERE p.marketer_id = ?
                                    ORDER BY p.id DESC");
            $stmt->execute([$marketerId]);
        } else {
            $stmt = $conn->query("SELECT p.*, m.name as marketer_name, m.phone as marketer_phone 
                                    FROM properties p 
                                    JOIN marketers m ON p.marketer_id = m.id 
                                    ORDER BY p.id DESC");
        }
        
        $properties = $stmt->fetchAll();
        
        foreach ($properties as &$property) {
            // Get room categories for each property
            try {
                $roomStmt = $conn->prepare("SELECT * FROM property_rooms WHERE property_id = ?");
                $roomStmt->execute([$property['id']]);
                $property['rooms'] = $roomStmt->fetchAll();
            } catch (Exception $e) {
                $property['rooms'] = [];
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Properties retrieved', 'data' => $properties]);
        
    } catch (PDOException $e) {
        error_log("Get properties error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get properties: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    if ($action === 'update_status') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $status = htmlspecialchars(trim($data['status'] ?? ''), ENT_QUOTES, 'UTF-8');
        
        if ($id === 0 || empty($status)) {
            echo json_encode(['success' => false, 'message' => 'Invalid data']);
            exit;
        }
        
        try {
            $stmt = $conn->prepare("UPDATE properties SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            echo json_encode(['success' => true, 'message' => 'Property status updated']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to update status']);
        }
        
    } elseif ($action === 'delete') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        
        if ($id === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid property ID']);
            exit;
        }
        
        try {
            $stmt = $conn->prepare("DELETE FROM properties WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Property deleted successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to delete property']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
