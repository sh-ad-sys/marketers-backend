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
    $data = json_decode(file_get_contents('php://input'), true);
    $type = trim($data['type'] ?? '');
    $email = trim($data['email'] ?? '');
    $token = trim($data['token'] ?? '');
    $newPassword = (string)($data['new_password'] ?? '');
    $portal = $type === 'admin' ? apiResolveAdminPortalFromRequest(is_array($data) ? $data : []) : '';

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

    $validReset = null;

    if (passwordResetUsesMongoStorage()) {
        $db = mongoDb();
        ensurePasswordResetTable($db);

        $rows = $db->password_resets->find([
            'user_type' => $type,
            'admin_portal' => $type === 'admin' ? $portal : null,
            'email' => $email,
            'used_at' => null,
            'expires_at' => ['$gte' => mongoNow()],
        ], ['sort' => ['_id' => -1], 'limit' => 10]);

        foreach ($rows as $row) {
            if (password_verify($token, (string)($row['token_hash'] ?? ''))) {
                $validReset = $row;
                break;
            }
        }
    } else {
        $conn = apiMysql();
        ensurePasswordResetTable($conn);

        $stmt = $conn->prepare('
            SELECT id, user_id, token_hash
            FROM password_resets
            WHERE user_type = ?
              AND (admin_portal <=> ?)
              AND email = ?
              AND used_at IS NULL
              AND expires_at >= NOW()
            ORDER BY id DESC
            LIMIT 10
        ');
        $stmt->execute([$type, $type === 'admin' ? $portal : null, $email]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if (password_verify($token, (string)($row['token_hash'] ?? ''))) {
                $validReset = $row;
                break;
            }
        }
    }

    if (!$validReset) {
        $response['message'] = 'Reset link is invalid or expired';
        echo json_encode($response);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    if (passwordResetUsesMongoStorage()) {
        $db = isset($db) ? $db : mongoDb();
        $userId = normalizeId($validReset['user_id'] ?? 0);

        if ($type === 'admin' && $portal === 'ledger') {
            apiPersistAdminPortalPasswordHash('ledger', $newHash);
        } elseif ($type === 'admin') {
            $db->admins->updateOne(['_id' => $userId, 'email' => $email], ['$set' => ['password' => $newHash]]);
        } else {
            $db->marketers->updateOne(['_id' => $userId, 'email' => $email], ['$set' => ['password' => $newHash, 'must_change_password' => 0]]);
        }

        $db->password_resets->updateOne(['_id' => normalizeId($validReset['_id'] ?? 0)], ['$set' => ['used_at' => mongoNow()]]);
    } else {
        $conn = isset($conn) ? $conn : apiMysql();
        $userId = (int)($validReset['user_id'] ?? 0);

        if ($type === 'admin' && $portal === 'ledger') {
            apiPersistAdminPortalPasswordHash('ledger', $newHash);
        } elseif ($type === 'admin') {
            $updateUser = $conn->prepare('UPDATE admins SET password = ? WHERE id = ? AND email = ?');
            $updateUser->execute([$newHash, $userId, $email]);
        } else {
            $updateUser = $conn->prepare('UPDATE marketers SET password = ?, must_change_password = 0 WHERE id = ? AND email = ?');
            $updateUser->execute([$newHash, $userId, $email]);
        }

        $markUsed = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
        $markUsed->execute([(int)($validReset['id'] ?? 0)]);
    }

    $response['success'] = true;
    $response['message'] = 'Password reset successful. Please login.';
} catch (Throwable $e) {
    error_log('Reset password error: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = passwordResetSanitizeExceptionMessage($e);
}

echo json_encode($response);
