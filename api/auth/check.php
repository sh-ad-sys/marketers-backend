<?php
/**
 * Check Auth API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/storage.php';
apiEnsureSessionStarted();

$response = ['success' => false, 'message' => ''];

try {
    if (apiUsesMongoStorage()) {
        require_once __DIR__ . '/../mongo/config.php';
        ensureSession();

        if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
            $response['success'] = true;
            $response['message'] = 'User is logged in';
            $response['data'] = [
                'user_id' => normalizeId($_SESSION['user_id']),
                'username' => $_SESSION['username'] ?? '',
                'full_name' => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? ''),
                'name' => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? ''),
                'email' => $_SESSION['email'] ?? '',
                'user_type' => $_SESSION['user_type'] ?? '',
                'marketer_id' => isset($_SESSION['marketer_id']) ? normalizeId($_SESSION['marketer_id']) : null,
            ];
        } else {
            $response['message'] = 'Not logged in';
        }

        echo json_encode($response);
        exit;
    }

    $user = apiMySqlCurrentUser(apiMysql());
    if ($user) {
        $response['success'] = true;
        $response['message'] = 'User is logged in';
        $response['data'] = $user;
    } else {
        $response['message'] = 'Not logged in';
    }
} catch (Throwable $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
