<?php
/**
 * PlotConnect - Login API (PRODUCTION FIXED)
 */

// 🔥 MUST BE FIRST (prevents HTML output issues)
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

session_start();

// 🔥 FIXED PATH (Render safe)
require_once __DIR__ . '/../../config.php';

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

/**
 * ========================
 * GET INPUT
 * ========================
 */
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

    if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD)) {

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
elseif ($type === 'marketer') {

    $name = $data['name'] ?? '';
    $phone = $data['phone'] ?? '';

    if (empty($name) && empty($phone)) {
        echo json_encode([
            "success" => false,
            "message" => "Please enter name or phone number"
        ]);
        exit;
    }

    try {

        if (!empty($name)) {
            $stmt = $conn->prepare("
                SELECT id, name, phone, password 
                FROM marketers 
                WHERE name = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$name]);
        } else {
            $stmt = $conn->prepare("
                SELECT id, name, phone, password 
                FROM marketers 
                WHERE phone = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$phone]);
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && password_verify($password, $result['password'])) {

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
                "message" => "Invalid credentials"
            ]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Server error"
        ]);
        exit;
    }
}

/**
 * ========================
 * INVALID TYPE
 * ========================
 */
else {
    echo json_encode([
        "success" => false,
        "message" => "Invalid user type"
    ]);
    exit;
}