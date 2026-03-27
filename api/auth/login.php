<?php
/**
 * Login API (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../mongo/config.php';

$response = ['success' => false, 'message' => ''];
ensureSession();

try {
    $db = mongoDb();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $response['message'] = 'Invalid request data';
        echo json_encode($response);
        exit;
    }

    $type = trim($data['type'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $password = (string)($data['password'] ?? '');

    if ($type === 'admin') {
        if ($email === '' || $password === '') {
            $response['message'] = 'Email and password are required';
            echo json_encode($response);
            exit;
        }

        $admin = $db->admins->findOne([
            '$or' => [
                ['username' => $email],
                ['email' => $email],
            ],
        ]);

        if ($admin && password_verify($password, (string)($admin['password'] ?? ''))) {
            $token = bin2hex(random_bytes(32));

            $_SESSION['user_id'] = normalizeId($admin['_id'] ?? $admin['id'] ?? 0);
            $_SESSION['username'] = (string)($admin['username'] ?? '');
            $_SESSION['full_name'] = (string)($admin['full_name'] ?? '');
            $_SESSION['user_type'] = 'admin';
            $_SESSION['token'] = $token;

            $response['success'] = true;
            $response['message'] = 'Login successful';
            $response['data'] = [
                'id' => normalizeId($admin['_id'] ?? $admin['id'] ?? 0),
                'username' => (string)($admin['username'] ?? ''),
                'full_name' => (string)($admin['full_name'] ?? ''),
                'email' => (string)($admin['email'] ?? ''),
                'user_type' => 'admin',
                'token' => $token,
            ];
        } else {
            $response['message'] = 'Invalid admin credentials';
        }

        echo json_encode($response);
        exit;
    }

    // marketer login
    if (($email === '' && $phone === '') || $password === '') {
        $response['message'] = 'Email or phone number and password are required';
        echo json_encode($response);
        exit;
    }

    if ($email !== '') {
        $marketer = $db->marketers->findOne([
            '$or' => [
                ['email' => $email],
                ['name' => $email],
            ],
        ]);
    } else {
        $marketer = $db->marketers->findOne(['phone' => $phone]);
    }

    if (!$marketer) {
        $response['message'] = 'Invalid marketer credentials';
        echo json_encode($response);
        exit;
    }

    $isActive = isset($marketer['is_active']) ? (int)$marketer['is_active'] : 1;
    if ($isActive !== 1) {
        $response['message'] = 'Your account is inactive.';
        echo json_encode($response);
        exit;
    }

    if (isset($marketer['is_blocked']) && (int)$marketer['is_blocked'] === 1) {
        $response['message'] = 'Your account has been blocked. Please contact admin.';
        echo json_encode($response);
        exit;
    }

    if (!password_verify($password, (string)($marketer['password'] ?? ''))) {
        $response['message'] = 'Invalid marketer credentials';
        echo json_encode($response);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $marketerId = normalizeId($marketer['_id'] ?? $marketer['id'] ?? 0);

    $_SESSION['user_id'] = $marketerId;
    $_SESSION['username'] = (string)($marketer['name'] ?? '');
    $_SESSION['full_name'] = (string)($marketer['name'] ?? '');
    $_SESSION['user_type'] = 'marketer';
    $_SESSION['marketer_id'] = $marketerId;
    $_SESSION['phone'] = (string)($marketer['phone'] ?? '');
    $_SESSION['token'] = $token;

    $response['success'] = true;
    $response['message'] = 'Login successful';
    $response['data'] = [
        'id' => $marketerId,
        'marketer_id' => $marketerId,
        'name' => (string)($marketer['name'] ?? ''),
        'email' => (string)($marketer['email'] ?? ''),
        'phone' => (string)($marketer['phone'] ?? ''),
        'user_type' => 'marketer',
        'token' => $token,
        'is_authorized' => isset($marketer['is_authorized']) ? (int)$marketer['is_authorized'] : 0,
        'is_blocked' => isset($marketer['is_blocked']) ? (int)$marketer['is_blocked'] : 0,
        'must_change_password' => isset($marketer['must_change_password']) ? (int)$marketer['must_change_password'] : 0,
    ];

} catch (Throwable $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('Login Error: ' . $e->getMessage());
}

echo json_encode($response);
