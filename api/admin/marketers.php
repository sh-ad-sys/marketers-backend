<?php
/**
 * Admin Marketers API (MongoDB)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Role, X-Auth-User, X-Auth-Marketer-Id, X-Auth-Portal');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../mongo/config.php';

$response = ['success' => false, 'message' => '', 'data' => []];

function generateTemporaryPassword($length = 10)
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

function nextMarketerId($collection)
{
    $last = $collection->findOne([], ['sort' => ['_id' => -1], 'projection' => ['_id' => 1]]);
    return $last ? (normalizeId($last['_id']) + 1) : 1;
}

try {
    $role = $_SERVER['HTTP_X_AUTH_ROLE'] ?? '';
    if ($role !== 'admin') {
        $response['message'] = 'Admin access required';
        echo json_encode($response);
        exit;
    }

    $db = mongoDb();
    $collection = $db->marketers;

    if ($_SERVER['REQUEST_METHOD'] === 'GET' || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'list')) {
        $cursor = $collection->find([], ['sort' => ['created_at' => -1, '_id' => -1]]);
        $data = [];
        foreach ($cursor as $doc) {
            $data[] = [
                'id' => normalizeId($doc['_id'] ?? 0),
                'name' => (string)($doc['name'] ?? ''),
                'phone' => (string)($doc['phone'] ?? ''),
                'email' => (string)($doc['email'] ?? ''),
                'is_active' => isset($doc['is_active']) ? (int)$doc['is_active'] : 1,
                'is_authorized' => isset($doc['is_authorized']) ? (int)$doc['is_authorized'] : 0,
                'is_blocked' => isset($doc['is_blocked']) ? (int)$doc['is_blocked'] : 0,
                'must_change_password' => isset($doc['must_change_password']) ? (int)$doc['must_change_password'] : 0,
                'failed_login_attempts' => isset($doc['failed_login_attempts']) ? (int)$doc['failed_login_attempts'] : 0,
                'lock_reason' => (string)($doc['lock_reason'] ?? ''),
                'created_at' => mongoDateToString($doc['created_at'] ?? null),
            ];
        }

        $response['success'] = true;
        $response['data'] = $data;
        echo json_encode($response);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? $_REQUEST;
    $action = trim($data['action'] ?? '');
    $id = isset($data['id']) ? (int)$data['id'] : 0;

    if ($action === 'delete') {
        if ($id <= 0) {
            $response['message'] = 'Invalid marketer ID';
            echo json_encode($response);
            exit;
        }

        $collection->deleteOne(['_id' => $id]);
        $response['success'] = true;
        $response['message'] = 'Marketer deleted successfully';
        echo json_encode($response);
        exit;
    }

    if (in_array($action, ['authorize', 'reject', 'block', 'unblock'], true)) {
        if ($id <= 0) {
            $response['message'] = 'Invalid marketer ID';
            echo json_encode($response);
            exit;
        }

        $set = [];
        if ($action === 'authorize') {
            $set = ['is_authorized' => 1, 'is_blocked' => 0, 'failed_login_attempts' => 0, 'lock_reason' => null, 'locked_at' => null];
        } elseif ($action === 'reject') {
            $set = ['is_authorized' => 0];
        } elseif ($action === 'block') {
            $set = ['is_blocked' => 1, 'is_authorized' => 0, 'lock_reason' => 'admin', 'locked_at' => mongoNow()];
        } elseif ($action === 'unblock') {
            $set = ['is_blocked' => 0, 'failed_login_attempts' => 0, 'lock_reason' => null, 'locked_at' => null];
        }

        $collection->updateOne(['_id' => $id], ['$set' => $set]);
        $response['success'] = true;
        $response['message'] = 'Marketer status updated successfully';
        echo json_encode($response);
        exit;
    }

    // add marketer
    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $temporaryPassword = trim($data['password'] ?? '');

    if ($name === '' || $phone === '') {
        $response['message'] = 'Name and phone are required';
        echo json_encode($response);
        exit;
    }

    if ($temporaryPassword === '') {
        $temporaryPassword = generateTemporaryPassword(10);
    }

    $hashedPassword = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $newId = nextMarketerId($collection);

    $collection->insertOne([
        '_id' => $newId,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'password' => $hashedPassword,
        'is_active' => 1,
        'is_authorized' => 0,
        'is_blocked' => 0,
        'must_change_password' => 1,
        'created_at' => mongoNow(),
    ]);

    $response['success'] = true;
    $response['message'] = 'Marketer added successfully. Share temporary password with marketer.';
    $response['data'] = ['id' => $newId, 'temporary_password' => $temporaryPassword];
} catch (Throwable $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Marketers API Error: ' . $e->getMessage());
}

echo json_encode($response);
