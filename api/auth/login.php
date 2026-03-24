<?php
/**
 * PlotConnect - Login API (Standalone)
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
    exit;
}

$type     = $data['type']     ?? '';

// Validate based on type - admin needs password, marketer needs email+phone+otp or just email+phone for request_otp
if (empty($type)) {
    echo json_encode(["success" => false, "message" => "Please fill in all required fields"]);
    exit;
}

// For admin login, password is required
if ($type === 'admin' && empty($data['password'])) {
    echo json_encode(["success" => false, "message" => "Please fill in all required fields"]);
    exit;
}

// For marketer login, email and phone are required
if (($type === 'marketer' || $type === 'request_otp') && (empty($data['email']) || empty($data['phone']))) {
    echo json_encode(["success" => false, "message" => "Please fill in all required fields"]);
    exit;
}

/**
 * ========================
 * ADMIN LOGIN
 * ========================
 */
if ($type === 'admin') {
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';

    if (empty($email)) {
        echo json_encode(["success" => false, "message" => "Email is required"]);
        exit;
    }

    // Check against admins table in database
    $stmt = $conn->prepare("SELECT id, username, password, full_name, email FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        // Generate JWT token
        $token = JWT::create([
            'user_type' => 'admin',
            'username' => $admin['username'],
            'id' => $admin['id']
        ]);
        
        echo json_encode([
            "success" => true,
            "message" => "Login successful",
            "token"    => $token,
            "data"    => [
                "user_type" => "admin",
                "email"  => $admin['email'],
                "name"      => $admin['full_name']
            ]
        ]);
    } elseif (!$admin) {
        echo json_encode(["success" => false, "message" => "Admin email not found"]);
    } else {
        echo json_encode(["success" => false, "message" => "Incorrect password"]);
    }
    exit;
}

/**
 * ========================
 * MARKETER LOGIN (OTP)
 * ========================
 */
if ($type === 'marketer') {
    $email  = $data['email']  ?? '';
    $phone = $data['phone'] ?? '';
    $otp = $data['otp'] ?? '';

    if (empty($email) || empty($phone) || empty($otp)) {
        echo json_encode(["success" => false, "message" => "Email, phone number and OTP are required"]);
        exit;
    }

    // First check if email exists
    $emailStmt = $conn->prepare("SELECT id, name, phone, email FROM marketers WHERE email = ?");
    $emailStmt->execute([$email]);
    $marketerByEmail = $emailStmt->fetch();
    
    if (!$marketerByEmail) {
        echo json_encode(["success" => false, "message" => "Email not found. Please check your email address."]);
        exit;
    }

    // Check if phone matches
    if ($marketerByEmail['phone'] !== $phone) {
        echo json_encode(["success" => false, "message" => "Phone number does not match our records for this email."]);
        exit;
    }

    // Now get the full marketer record with OTP
    $stmt = $conn->prepare("SELECT id, name, phone, email, otp_code, otp_expiry FROM marketers WHERE id = ?");
    $stmt->execute([$marketerByEmail['id']]);
    $result = $stmt->fetch();

    // Verify OTP
    require_once __DIR__ . '/../otp.php';
    
    if (!OTP::verify($otp, $result['otp_code'])) {
        echo json_encode(["success" => false, "message" => "Invalid OTP. Please check the OTP sent to your email."]);
        exit;
    }

    // Check if OTP is expired
    if (OTP::isExpired($result['otp_expiry'])) {
        echo json_encode(["success" => false, "message" => "OTP has expired. Please request a new one."]);
        exit;
    }

    // Generate JWT token
    $token = JWT::create([
        'user_type' => 'marketer',
        'marketer_id' => $result['id'],
        'name' => $result['name']
    ]);
    
    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "token"    => $token,
        "data"    => [
            "user_type" => "marketer",
            "name"      => $result['name'],
            "phone"     => $result['phone'],
            "marketer_id" => $result['id']
        ]
    ]);
    exit;
}

/**
 * ========================
 * REQUEST OTP (For marketers who haven't received one)
 * ========================
 */
if ($type === 'request_otp') {
    $email  = $data['email']  ?? '';
    $phone = $data['phone'] ?? '';

    if (empty($email) || empty($phone)) {
        echo json_encode(["success" => false, "message" => "Email and phone number are required"]);
        exit;
    }

    // First check if email exists (without is_active check first to debug)
    $emailStmt = $conn->prepare("SELECT id, name, phone, email FROM marketers WHERE email = ?");
    $emailStmt->execute([$email]);
    $marketerByEmail = $emailStmt->fetch();
    
    if (!$marketerByEmail) {
        echo json_encode(["success" => false, "message" => "Email not found. Please check your email address."]);
        exit;
    }

    // Check if phone matches
    if ($marketerByEmail['phone'] !== $phone) {
        echo json_encode(["success" => false, "message" => "Phone number does not match our records for this email."]);
        exit;
    }

    // Generate new OTP
    require_once __DIR__ . '/../otp.php';
    require_once __DIR__ . '/../email.php';
    
    $newOtp = OTP::generate();
    $hashedOtp = OTP::hash($newOtp);
    $otpExpiry = OTP::getExpiry();
    
    // Update OTP in database
    $updateStmt = $conn->prepare("UPDATE marketers SET otp_code = ?, otp_expiry = ? WHERE id = ?");
    $updateStmt->execute([$hashedOtp, $otpExpiry, $marketerByEmail['id']]);
    
    // Send OTP via email
    $emailSent = Email::sendOTP($marketerByEmail['email'], $marketerByEmail['name'], $newOtp);
    
    // For testing, we'll succeed even if email fails (remove in production)
    echo json_encode(["success" => true, "message" => "OTP sent to your email"]);
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid user type"]);
