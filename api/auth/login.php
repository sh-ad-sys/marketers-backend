<?php
/**
 * PlotConnect - Login API (PRODUCTION FIXED)
 */

// 🔥 MUST BE FIRST (prevents HTML output issues)
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// 🔥 FIXED PATH (Render safe)
require_once __DIR__ . '/../../config.php';

// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ========================
 * ONLY POST ALLOWED
 * ========================
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Invalid request method"
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON input"
    ]);
    exit;
}

$type = $data['type'] ?? '';
$username = $data['username'] ?? '';
$password = $data['password'] ?? '';

// Debug: log what was received
error_log('Login attempt: type=' . $type . ', username=' . $username . ', password_len=' . strlen($password));
error_log('ADMIN_USERNAME constant: ' . ADMIN_USERNAME);
error_log('ADMIN_PASSWORD hash: ' . substr(ADMIN_PASSWORD, 0, 20) . '...');

if (empty($type) || empty($password)) {
    echo json_encode([
        "success" => false,
        "message" => "Please fill in all required fields"
    ]);
    exit;
}

$conn = getDBConnection();

if (!$conn) {
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

/**
 * ========================
 * ADMIN LOGIN
 * ========================
 */
if ($type === 'admin') {

    if (empty($username)) {
        echo json_encode([
            "success" => false,
            "message" => "Username is required"
        ]);
        exit;
    }

    // Debug: check password verification
$passwordCheck = password_verify($password, ADMIN_PASSWORD);
error_log('Password check result: ' . ($passwordCheck ? 'true' : 'false'));

if ($username === ADMIN_USERNAME && $passwordCheck) {

        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_username'] = $username;
        $_SESSION['user_type'] = 'admin';

        echo json_encode([
            "success" => true,
            "message" => "Login successful",
            "data" => [
                "user_type" => "admin",
                "username" => $username,
                "full_name" => "Administrator"
            ]
        ]);
        exit;

    } else {
        echo json_encode([
            "success" => false,
            "message" => "Invalid credentials"
        ]);
        exit;
    }
}

/**
 * ========================
 * MARKETER LOGIN
 * ========================
 */
if ($type === 'marketer') {
    $name = $data['name'] ?? '';
    $phone = $data['phone'] ?? '';
    
    if (empty($name) && empty($phone)) {
        echo json_encode([
            "success" => false,
            "message" => "Please enter name or phone number"
        ]);
        exit;
    }
    
    // Try to find by name or phone
    if (!empty($name)) {
        $stmt = $conn->prepare("SELECT id, name, phone, password FROM marketers WHERE name = ? AND is_active = 1");
        $stmt->execute([$name]);
    } else {
        $stmt = $conn->prepare("SELECT id, name, phone, password FROM marketers WHERE phone = ? AND is_active = 1");
        $stmt->execute([$phone]);
    }
    $result = $stmt->fetch();
    
    if ($result) {
        if (password_verify($password, $result['password'])) {
            $_SESSION['marketer_id'] = $result['id'];
            $_SESSION['marketer_name'] = $result['name'];
            $_SESSION['marketer_phone'] = $result['phone'];
            $_SESSION['user_type'] = 'marketer';
            
            echo json_encode([
                "success" => true,
                "message" => "Login successful",
                "data" => [
                    "user_type" => "marketer",
                    "name" => $result['name'],
                    "phone" => $result['phone']
                ]
            ]);
            exit;
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Invalid password"
            ]);
            exit;
        }
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Invalid credentials. Please contact admin to register."
        ]);
        exit;
    }
}

echo json_encode([
    "success" => false,
    "message" => "Invalid user type"
]);
