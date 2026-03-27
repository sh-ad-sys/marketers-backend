<?php
/**
 * Reset password using token (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/password-reset-common.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

try {
    $db = mongoDb();
    ensurePasswordResetTable($db);

    $data = json_decode(file_get_contents('php://input'), true);
    $type = trim($data['type'] ?? '');
    $email = trim($data['email'] ?? '');
    $token = trim($data['token'] ?? '');
    $newPassword = (string)($data['new_password'] ?? '');

    if (!in_array($type, ['admin', 'marketer'], true)) {
        $response['message'] = 'Invalid account type';
        echo json_encode($response);
        exit;
    }

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Valid email is required';
        echo json_encode($response);
        exit;
    }

    if ($token === '') {
        $response['message'] = 'Reset token is required';
        echo json_encode($response);
        exit;
    }

    if ($newPassword === '' || strlen($newPassword) < 6) {
        $response['message'] = 'New password must be at least 6 characters';
        echo json_encode($response);
        exit;
    }

    $rows = $db->password_resets->find([
        'user_type' => $type,
        'email' => $email,
        'used_at' => null,
        'expires_at' => ['$gte' => mongoNow()],
    ], ['sort' => ['_id' => -1], 'limit' => 10]);

    $validReset = null;
    foreach ($rows as $row) {
        if (password_verify($token, (string)($row['token_hash'] ?? ''))) {
            $validReset = $row;
            break;
        }
    }

    if (!$validReset) {
        $response['message'] = 'Reset link is invalid or expired';
        echo json_encode($response);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $userId = normalizeId($validReset['user_id'] ?? 0);

    if ($type === 'admin') {
        $db->admins->updateOne(['_id' => $userId, 'email' => $email], ['$set' => ['password' => $newHash]]);
    } else {
        $db->marketers->updateOne(['_id' => $userId, 'email' => $email], ['$set' => ['password' => $newHash, 'must_change_password' => 0]]);
    }

    $db->password_resets->updateOne(['_id' => normalizeId($validReset['_id'] ?? 0)], ['$set' => ['used_at' => mongoNow()]]);

    $response['success'] = true;
    $response['message'] = 'Password reset successful. Please login.';
} catch (Throwable $e) {
    error_log('Reset password error: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = 'Unable to reset password. Please request a new link.';
}

echo json_encode($response);
