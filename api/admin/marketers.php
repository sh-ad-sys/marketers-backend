<?php
/**
 * PlotConnect - Marketers API
 * Handles GET (list) and POST (add/delete)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list all marketers ──────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $conn = getDBConnection();
        $stmt = $conn->query("SELECT id, name, email, phone, created_at FROM marketers WHERE is_active = 1 ORDER BY name ASC");
        $marketers = $stmt->fetchAll();

        echo json_encode(["success" => true, "data" => $marketers]);
    } catch (Exception $e) {
        error_log('Marketers GET error: ' . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Failed to fetch marketers"]);
    }
    exit;
}

// ── POST: add or delete marketer ─────────────────────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
        exit;
    }

    $action = $input['action'] ?? 'add';

    // DELETE action
    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(["success" => false, "message" => "Invalid marketer ID"]);
            exit;
        }
        try {
            $conn = getDBConnection();
            $stmt = $conn->prepare("UPDATE marketers SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(["success" => true, "message" => "Marketer removed"]);
        } catch (Exception $e) {
            error_log('Marketer delete error: ' . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Failed to remove marketer"]);
        }
        exit;
    }

    // ADD action (default)
    $name     = trim($input['name']     ?? '');
    $email    = trim($input['email']    ?? '');
    $phone    = trim($input['phone']    ?? '');
    $password = trim($input['password'] ?? '');

    if (!$name || !$email || !$phone || !$password) {
        echo json_encode(["success" => false, "message" => "All fields are required"]);
        exit;
    }

    try {
        $conn = getDBConnection();

        // Check for duplicate email or name
        $check = $conn->prepare("SELECT id FROM marketers WHERE (email = ? OR name = ?) AND is_active = 1");
        $check->execute([$email, $name]);
        if ($check->fetch()) {
            echo json_encode(["success" => false, "message" => "A marketer with this name or email already exists"]);
            exit;
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare(
            "INSERT INTO marketers (name, email, phone, password, is_active, created_at)
             VALUES (?, ?, ?, ?, 1, NOW())"
        );
        $stmt->execute([$name, $email, $phone, $hashed]);

        echo json_encode([
            "success" => true,
            "message" => "Marketer added successfully",
            "data"    => [
                "id"    => $conn->lastInsertId(),
                "name"  => $name,
                "email" => $email,
                "phone" => $phone
            ]
        ]);
    } catch (Exception $e) {
        error_log('Add marketer error: ' . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Failed to add marketer: " . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(["success" => false, "message" => "Method not allowed"]);