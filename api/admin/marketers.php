<?php
/**
 * PlotConnect - Admin Marketers Management API (Standalone)
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

// Load JWT and OTP utilities
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../otp.php';
require_once __DIR__ . '/../email.php';

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
    // Get all marketers
    try {
        $stmt = $conn->query("SELECT id, name, phone, email, is_active, created_at FROM marketers ORDER BY created_at DESC");
        $marketers = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'message' => 'Marketers retrieved', 'data' => $marketers]);
    } catch (PDOException $e) {
        error_log("Get marketers error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get marketers: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    if ($action === 'delete') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        
        if ($id === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid marketer ID']);
            exit;
        }
        
        try {
            $stmt = $conn->prepare("DELETE FROM marketers WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Marketer deleted successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to delete marketer']);
        }
    } elseif ($action === 'resend_otp') {
        // Resend OTP to marketer
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        
        if ($id === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid marketer ID']);
            exit;
        }
        
        // Get marketer details
        $stmt = $conn->prepare("SELECT id, name, email, phone FROM marketers WHERE id = ?");
        $stmt->execute([$id]);
        $marketer = $stmt->fetch();
        
        if (!$marketer) {
            echo json_encode(['success' => false, 'message' => 'Marketer not found']);
            exit;
        }
        
        if (empty($marketer['email'])) {
            echo json_encode(['success' => false, 'message' => 'Marketer has no email address']);
            exit;
        }
        
        // Generate new OTP
        $otp = OTP::generate();
        $hashedOtp = OTP::hash($otp);
        $otpExpiry = OTP::getExpiry();
        
        // Update OTP in database
        $updateStmt = $conn->prepare("UPDATE marketers SET otp_code = ?, otp_expiry = ? WHERE id = ?");
        $updateStmt->execute([$hashedOtp, $otpExpiry, $id]);
        
        // Send OTP via email
        $emailSent = Email::sendOTP($marketer['email'], $marketer['name'], $otp);
        
        if ($emailSent) {
            echo json_encode(['success' => true, 'message' => 'OTP sent to ' . $marketer['email']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP email']);
        }
        exit;
    } else {
        // Add new marketer
        $name = htmlspecialchars(trim($data['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars(trim($data['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars(trim($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        
        if (empty($name) || empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Name and phone are required']);
            exit;
        }
        
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email is required for OTP login']);
            exit;
        }
        
        // Check if email already exists
        $checkStmt = $conn->prepare("SELECT id FROM marketers WHERE email = ?");
        $checkStmt->execute([$email]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already registered']);
            exit;
        }
        
        // Generate OTP
        $otp = OTP::generate();
        $hashedOtp = OTP::hash($otp);
        $otpExpiry = OTP::getExpiry();
        
        try {
            $stmt = $conn->prepare("INSERT INTO marketers (name, email, phone, otp_code, otp_expiry) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $phone, $hashedOtp, $otpExpiry]);
            
            // Send OTP via email
            $emailSent = Email::sendOTP($email, $name, $otp);
            
            if ($emailSent) {
                echo json_encode(['success' => true, 'message' => 'Marketer added successfully. OTP sent to ' . $email]);
            } else {
                echo json_encode(['success' => true, 'message' => 'Marketer added but failed to send OTP email. Marketer can request OTP on login.']);
            }
        } catch (PDOException $e) {
            error_log("Add marketer error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to add marketer: ' . $e->getMessage()]);
        }
    }
}
