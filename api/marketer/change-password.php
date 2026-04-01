<?php
/**
 * Marketer Change Password API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';
apiEnsureSessionStarted();

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

try {
    $data = apiJsonInput();
    $currentPassword = (string)($data['current_password'] ?? '');
    $newPassword = (string)($data['new_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '') {
        $response['message'] = 'Current password and new password are required';
        echo json_encode($response);
        exit;
    }

    if (strlen($newPassword) < 6) {
        $response['message'] = 'New password must be at least 6 characters';
        echo json_encode($response);
        exit;
    }

    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';
        ensureSession();

        $db = mongoDb();
        $marketerId = isset($_SERVER['HTTP_X_AUTH_MARKETER_ID']) ? (int)$_SERVER['HTTP_X_AUTH_MARKETER_ID'] : 0;
        if ($marketerId <= 0 && isset($_SESSION['marketer_id'])) {
            $marketerId = normalizeId($_SESSION['marketer_id']);
        }

        if ($marketerId <= 0) {
            $response['message'] = 'Authentication required';
            echo json_encode($response);
            exit;
        }

        $marketer = $db->marketers->findOne(['_id' => $marketerId], ['projection' => ['password' => 1]]);
        if (!$marketer) {
            $response['message'] = 'Marketer account not found';
            echo json_encode($response);
            exit;
        }

        if (!password_verify($currentPassword, (string)($marketer['password'] ?? ''))) {
            $response['message'] = 'Temporary/current password is incorrect';
            echo json_encode($response);
            exit;
        }

        $db->marketers->updateOne(
            ['_id' => $marketerId],
            ['$set' => ['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'must_change_password' => 0]]
        );

        echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
        exit;
    }

    $conn = apiMysql();
    $marketerId = apiResolveMySqlMarketerId($conn);
    if ($marketerId <= 0) {
        $response['message'] = 'Authentication required';
        echo json_encode($response);
        exit;
    }

    $stmt = $conn->prepare('SELECT password FROM marketers WHERE id = ? LIMIT 1');
    $stmt->execute([$marketerId]);
    $marketer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$marketer) {
        $response['message'] = 'Marketer account not found';
        echo json_encode($response);
        exit;
    }

    if (!password_verify($currentPassword, (string)$marketer['password'])) {
        $response['message'] = 'Temporary/current password is incorrect';
        echo json_encode($response);
        exit;
    }

    $stmt = $conn->prepare('UPDATE marketers SET password = ?, must_change_password = 0 WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $marketerId]);

    $response['success'] = true;
    $response['message'] = 'Password changed successfully';
} catch (Throwable $e) {
    error_log('Change password error: ' . $e->getMessage());
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
